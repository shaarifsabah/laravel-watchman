<?php

declare(strict_types=1);

namespace Watchman\Download;

use InvalidArgumentException;
use RuntimeException;
use Throwable;
use Watchman\Index\IndexStore;
use Watchman\Index\TfIdf;
use Watchman\Search\SourceList;
use Watchman\Sources\CslUs\CslUsSource;
use Watchman\Sources\FinCen311\FinCen311Source;
use Watchman\Sources\Ofac\NonSdnSource;
use Watchman\Sources\Ofac\OfacSource;
use Watchman\Sources\Senzing\SenzingSource;
use Watchman\Support\GoTime;
use Watchman\Version;

/**
 * Port of internal/download RefreshAll: downloads every configured list,
 * parses and normalizes it, and writes a new "lists" corpus generation that
 * becomes visible atomically when everything succeeded.
 *
 * Config keys (see config/watchman.php "download"):
 *   included_lists, ignored_download_errors, initial_data_directory, error_on_empty_list,
 *   open_sanctions => [api_key, lists => [[source_list, location], …]],
 *   senzing => [[source_list, location], …], urls => [source => url/template], tfidf => […]
 */
final class ListRefresher
{
    /** @var callable(string,string):void|null */
    private $logger;

    /** @var array<string, callable():ListSource> */
    private array $custom = [];

    public function __construct(
        private IndexStore $store,
        private Downloader $downloader,
        private array $config = [],
        ?callable $logger = null,
    ) {
        $this->logger = $logger;
    }

    /** Register an additional list source (name => factory). */
    public function extend(string $name, callable $factory): void
    {
        $this->custom[strtolower($name)] = $factory;
    }

    /** @return list<string> */
    public function includedLists(): array
    {
        $out = [];
        foreach ((array) ($this->config['included_lists'] ?? []) as $v) {
            if (($n = self::normalizeName((string) $v)) !== '') {
                $out[] = $n;
            }
        }
        foreach ($this->senzingLists() as [$name]) {
            $out[] = $name;
        }
        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    /** @return list<array{0:string,1:string,2:?string}> name, location, apiKey */
    private function senzingLists(): array
    {
        $out = [];
        $os = (array) ($this->config['open_sanctions'] ?? []);
        $key = (string) ($os['api_key'] ?? '');
        foreach ((array) ($os['lists'] ?? []) as $l) {
            $name = self::normalizeName((string) ($l['source_list'] ?? $l['SourceList'] ?? ''));
            if ($name !== '') {
                $out[] = [$name, (string) ($l['location'] ?? $l['Location'] ?? ''), $key];
            }
        }
        foreach ((array) ($this->config['senzing'] ?? []) as $l) {
            $name = self::normalizeName((string) ($l['source_list'] ?? $l['SourceList'] ?? ''));
            if ($name !== '') {
                $out[] = [$name, (string) ($l['location'] ?? $l['Location'] ?? ''), null];
            }
        }

        return $out;
    }

    /** @return array<string, ListSource> */
    public function sources(): array
    {
        $urls = (array) ($this->config['urls'] ?? []);
        $builtin = [
            SourceList::US_OFAC => fn () => new OfacSource($urls[SourceList::US_OFAC] ?? null),
            SourceList::US_NON_SDN => fn () => new NonSdnSource($urls[SourceList::US_NON_SDN] ?? null),
            SourceList::US_CSL => fn () => new CslUsSource($urls[SourceList::US_CSL] ?? null),
            SourceList::US_FINCEN_311 => fn () => new FinCen311Source($urls[SourceList::US_FINCEN_311] ?? null),
            SourceList::EU_CSL => fn () => self::optional('Watchman\Sources\CslEu\CslEuSource', $urls[SourceList::EU_CSL] ?? null),
            SourceList::UK_CSL => fn () => self::optional('Watchman\Sources\CslUk\CslUkSource', $urls[SourceList::UK_CSL] ?? null),
            SourceList::UN_CSL => fn () => self::optional('Watchman\Sources\CslUn\CslUnSource', $urls[SourceList::UN_CSL] ?? null),
            SourceList::US_TEL => fn () => self::optional('Watchman\Sources\UsTel\UsTelSource', $urls[SourceList::US_TEL] ?? null),
        ];
        $senzing = [];
        foreach ($this->senzingLists() as [$name, $location, $key]) {
            $senzing[$name] = fn () => new SenzingSource($name, $location, $key);
        }

        $out = [];
        $unknown = [];
        foreach ($this->includedLists() as $name) {
            $factory = $senzing[$name] ?? $this->custom[$name] ?? $builtin[$name] ?? null;
            if ($factory === null) {
                $unknown[] = $name;
                continue;
            }
            $out[$name] = $factory();
        }
        if ($unknown !== []) {
            throw new InvalidArgumentException('unknown lists: ' . implode(', ', $unknown));
        }

        return $out;
    }

    private static function optional(string $class, ?string $url): ListSource
    {
        if (!class_exists($class)) {
            throw new RuntimeException("$class is not available");
        }

        return new $class($url);
    }

    /** @return list<string> */
    public function ignoredLists(): array
    {
        $out = [];
        foreach ((array) ($this->config['ignored_download_errors'] ?? []) as $v) {
            if (($n = self::normalizeName((string) $v)) !== '') {
                $out[] = $n;
            }
        }
        $out = array_values(array_unique($out));
        $valid = array_merge(SourceList::DOWNLOADABLE, array_map(static fn ($l) => $l[0], $this->senzingLists()), array_keys($this->custom));
        foreach ($out as $ignored) {
            if (!in_array($ignored, $valid, true)) {
                throw new InvalidArgumentException("unknown list \"$ignored\" in ignored_download_errors: not a known downloadable SourceList or configured OpenSanctions/Senzing list");
            }
        }

        return $out;
    }

    /**
     * Download, parse and index every configured list.
     *
     * @return array{lists: array<string,int>, listHashes: array<string,string>, startedAt: string, endedAt: string, version: string, warnings: list<string>}
     */
    public function refresh(): array
    {
        $started = new \DateTimeImmutable('now', GoTime::utc());
        $ignored = $this->ignoredLists();
        $sources = $this->sources();
        if ($sources === []) {
            $this->log('warning', 'no lists have been configured!');
        }
        $initialDir = (string) ($this->config['initial_data_directory'] ?? '');
        $errorOnEmpty = (bool) ($this->config['error_on_empty_list'] ?? false);

        $tfidfCfg = (array) ($this->config['tfidf'] ?? []);
        $tfidf = !empty($tfidfCfg['enabled']) ? TfIdf::fromConfig($tfidfCfg) : null;
        $writer = $this->store->writer(\Watchman\Index\IndexStore::LISTS, $tfidf);
        $lists = [];
        $hashes = [];
        $warnings = [];

        try {
            foreach ($sources as $name => $source) {
                $t = microtime(true);
                try {
                    $result = $source->load($this->downloader, $initialDir);
                    $count = 0;
                    foreach ($result->entities as $entity) {
                        if ($entity->preparedFields->name === '' || $entity->preparedFields->nameFields === []) {
                            $entity = $entity->normalize();
                        }
                        $writer->add($entity);
                        $count++;
                    }
                    if ($count === 0 && $errorOnEmpty) {
                        throw new RuntimeException("no entities parsed from $name");
                    }
                    $writer->finishList($name, $count, $result->hash);
                    $lists[$name] = $count;
                    $hashes[$name] = $result->hash;
                    $this->log('info', sprintf('adding %d entities from %s (%.1fs)', $count, $name, microtime(true) - $t));
                } catch (Throwable $e) {
                    if (in_array($name, $ignored, true)) {
                        $warnings[] = "ignoring error loading $name: " . $e->getMessage();
                        $this->log('warning', end($warnings));
                        continue;
                    }
                    throw new RuntimeException("loading $name: " . $e->getMessage(), 0, $e);
                }
            }
            $ended = new \DateTimeImmutable('now', GoTime::utc());
            $meta = [
                'startedAt' => GoTime::toJson($started),
                'endedAt' => GoTime::toJson($ended),
                'version' => Version::string(),
            ];
            $writer->commit($meta);
        } catch (Throwable $e) {
            $writer->abort();
            throw $e;
        } finally {
            $this->downloader->cleanup();
        }

        return ['lists' => $lists, 'listHashes' => $hashes] + $meta + ['warnings' => $warnings];
    }

    public static function normalizeName(string $s): string
    {
        $t = trim($s);

        return $t === '' ? '' : strtolower($t);
    }

    private function log(string $level, string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $message);
        }
    }
}
