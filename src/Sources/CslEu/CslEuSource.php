<?php

declare(strict_types=1);

namespace Watchman\Sources\CslEu;

use RuntimeException;
use Watchman\Download\Downloader;
use Watchman\Download\ListResult;
use Watchman\Download\ListSource;
use Watchman\Search\SourceList;
use Watchman\Support\Go;

/**
 * EU Consolidated Financial Sanctions List (eu_csl).
 * Port of pkg/sources/csl_eu (DownloadEU, ParseEU, ConvertEUCSLData) and
 * internal/download/download_eu.go (loadEUCSLRecords).
 */
final class CslEuSource implements ListSource
{
    public const FILENAME = 'eu_csl.csv';
    public const DEFAULT_TOKEN = 'dG9rZW4tMjAxNw';
    public const PUBLIC_URL_FORMAT = 'https://webgate.ec.europa.eu/fsd/fsf/public/files/csvFullSanctionsList_1_1/content?token=%s';

    public function __construct(private ?string $url = null)
    {
    }

    public function name(): string
    {
        return SourceList::EU_CSL;
    }

    /** euDownloadURL = strx.Or(EU_CSL_DOWNLOAD_URL, publicEUDownloadURL(strx.Or(EU_CSL_TOKEN, default))) */
    public static function defaultUrl(): string
    {
        $token = Go::strxOr((string) getenv('EU_CSL_TOKEN'), self::DEFAULT_TOKEN);

        return Go::strxOr((string) getenv('EU_CSL_DOWNLOAD_URL'), sprintf(self::PUBLIC_URL_FORMAT, $token));
    }

    public function load(Downloader $downloader, string $initialDir): ListResult
    {
        $url = $this->url ?: self::defaultUrl();
        $files = $downloader->getFiles($initialDir, [self::FILENAME => $url]);
        if ($files === []) {
            throw new RuntimeException('unexpected 0 EU CSL files found');
        }

        $records = [];
        $ctx = hash_init('sha256');
        foreach ($files as $filename => $path) {
            if (!hash_update_file($ctx, $path)) {
                throw new RuntimeException("reading EU CSL $filename failed");
            }
            try {
                $parsed = CslEuReader::parse($path);
            } catch (RuntimeException $e) {
                throw new RuntimeException("parsing EU CSL $filename: " . $e->getMessage(), 0, $e);
            }
            foreach ($parsed as $record) {
                $records[] = $record;
            }
        }

        return new ListResult($this->name(), CslEuMapper::convert($records), hash_final($ctx));
    }
}
