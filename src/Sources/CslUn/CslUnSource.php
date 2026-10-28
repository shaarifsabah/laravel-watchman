<?php

declare(strict_types=1);

namespace Watchman\Sources\CslUn;

use RuntimeException;
use Watchman\Download\Downloader;
use Watchman\Download\ListResult;
use Watchman\Download\ListSource;
use Watchman\Search\Entity;
use Watchman\Search\SourceList;
use Watchman\Support\Go;

/**
 * UN Security Council Consolidated List (un_csl). Port of pkg/sources/csl_un
 * (DownloadSanctionsList_UN, Reader, ToEntity) and internal/download/download_un.go.
 *
 * The download URL comes from UN_CONSOLIDATED_LIST_URL (Go's comment mentions
 * UN_SANCTIONS_LIST_URL, but the code reads UN_CONSOLIDATED_LIST_URL).
 */
final class CslUnSource implements ListSource
{
    public const FILENAME = 'un_consolidated.xml';
    public const DEFAULT_URL = 'https://scsanctions.un.org/resources/xml/en/consolidated.xml';

    public function __construct(private ?string $url = null)
    {
    }

    public function name(): string
    {
        return SourceList::UN_CSL;
    }

    public static function defaultUrl(): string
    {
        return Go::strxOr((string) getenv('UN_CONSOLIDATED_LIST_URL'), self::DEFAULT_URL);
    }

    public function load(Downloader $downloader, string $initialDir): ListResult
    {
        $url = $this->url ?: self::defaultUrl();
        $files = $downloader->getFiles($initialDir, [self::FILENAME => $url]);
        if ($files === []) {
            throw new RuntimeException('unexpected 0 UN CSL files found');
        }

        $entities = [];
        $digests = '';
        foreach ($files as $filename => $path) {
            try {
                CslUnReader::read(
                    $path,
                    static function (array $p) use (&$entities): void {
                        $entities[] = CslUnMapper::individualToEntity($p);
                    },
                    static function (array $e) use (&$entities): void {
                        $entities[] = CslUnMapper::entityToEntity($e);
                    },
                );
            } catch (RuntimeException $e) {
                throw new RuntimeException("parsing UN list $filename: " . $e->getMessage(), 0, $e);
            }
            // Go hashes each file while streaming (TeeReader) and then hashes the concatenated digests.
            $digests .= (string) hash_file('sha256', $path, true);
        }

        return new ListResult($this->name(), $entities, hash('sha256', $digests));
    }

    /**
     * Parse a UN consolidated list XML document into entities (helper for tests/tools).
     *
     * @return list<Entity>
     */
    public static function entitiesFromXml(string $xml): array
    {
        $out = [];
        CslUnReader::read(
            $xml,
            static function (array $p) use (&$out): void {
                $out[] = CslUnMapper::individualToEntity($p);
            },
            static function (array $e) use (&$out): void {
                $out[] = CslUnMapper::entityToEntity($e);
            },
            true,
        );

        return $out;
    }
}
