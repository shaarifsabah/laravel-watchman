<?php

declare(strict_types=1);

namespace Watchman\Sources\UsTel;

use RuntimeException;
use Watchman\Download\Downloader;
use Watchman\Download\ListResult;
use Watchman\Download\ListSource;
use Watchman\Search\SourceList;
use Watchman\Support\Go;

/**
 * US State Department Terrorist Exclusion List (us_tel), via OpenSanctions.
 * Port of pkg/sources/us_tel and internal/download/download_us_tel.go.
 */
final class UsTelSource implements ListSource
{
    public const FILENAME = 'us_tel.json';
    public const DEFAULT_URL = 'https://data.opensanctions.org/datasets/latest/us_state_terrorist_exclusion/targets.nested.json';

    public function __construct(private ?string $url = null)
    {
    }

    public function name(): string
    {
        return SourceList::US_TEL;
    }

    public static function defaultUrl(): string
    {
        return Go::strxOr((string) getenv('US_TEL_URL'), self::DEFAULT_URL);
    }

    public function load(Downloader $downloader, string $initialDir): ListResult
    {
        $url = $this->url ?: self::defaultUrl();
        $files = $downloader->getFiles($initialDir, [self::FILENAME => $url]);
        if ($files === []) {
            throw new RuntimeException('unexpected 0 US TEL files found');
        }

        $entities = [];
        $ctx = hash_init('sha256');
        foreach ($files as $filename => $path) {
            $content = @file_get_contents($path);
            if ($content === false) {
                throw new RuntimeException("reading US TEL $filename failed");
            }
            hash_update($ctx, $content);
            try {
                UsTelReader::read($content, static function (array $record) use (&$entities): void {
                    $entities[] = UsTelMapper::toEntity($record);
                });
            } catch (RuntimeException $e) {
                throw new RuntimeException("parsing US TEL $filename: " . $e->getMessage(), 0, $e);
            }
        }

        return new ListResult($this->name(), $entities, hash_final($ctx));
    }
}
