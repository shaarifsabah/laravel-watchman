<?php

declare(strict_types=1);

namespace Watchman\Sources\CslUk;

use RuntimeException;
use Watchman\Download\Downloader;
use Watchman\Download\ListResult;
use Watchman\Download\ListSource;
use Watchman\Search\SourceList;
use Watchman\Support\Go;

/**
 * UK Sanctions List (uk_csl). Port of pkg/sources/csl_uk (DownloadSanctionsList,
 * ReadSanctionsListFile, ConvertSanctionsListData) and internal/download/download_uk.go.
 *
 * Go (since b42f95c5) only reads the CSV published at sanctionslist.fcdo.gov.uk, fetched
 * as "UK_Sanctions_List.csv" from UK_SANCTIONS_LIST_URL (there is no UK_CSL_DOWNLOAD_URL
 * and no .ods/.csv fallback in Go). PHP-only additions, applied only where Go would fail:
 *  - if no CSV could be obtained but the initial directory holds UK_Sanctions_List.ods,
 *    that spreadsheet is read instead (Go would error "unexpected 0 UK CSL files found");
 *  - if the obtained file is a zip/ODS (e.g. UK_SANCTIONS_LIST_URL points at an .ods),
 *    it is parsed as ODS rather than as CSV.
 */
final class CslUkSource implements ListSource
{
    public const FILENAME = 'UK_Sanctions_List.csv';
    public const ODS_FILENAME = 'UK_Sanctions_List.ods';
    public const DEFAULT_URL = 'https://sanctionslist.fcdo.gov.uk/docs/UK-Sanctions-List.csv';

    public function __construct(private ?string $url = null, private bool $odsFallback = true)
    {
    }

    public function name(): string
    {
        return SourceList::UK_CSL;
    }

    public static function defaultUrl(): string
    {
        return Go::strxOr((string) getenv('UK_SANCTIONS_LIST_URL'), self::DEFAULT_URL);
    }

    public function load(Downloader $downloader, string $initialDir): ListResult
    {
        $url = $this->url ?: self::defaultUrl();
        $files = $downloader->getFiles($initialDir, [self::FILENAME => $url]);
        if ($files === [] && $this->odsFallback && ($ods = self::findLocal($initialDir, self::ODS_FILENAME)) !== null) {
            $files = [self::ODS_FILENAME => $ods];
        }
        if ($files === []) {
            throw new RuntimeException('unexpected 0 UK CSL files found');
        }

        $records = [];
        $ctx = hash_init('sha256');
        foreach ($files as $filename => $path) {
            if (!hash_update_file($ctx, $path)) {
                throw new RuntimeException("reading UK CSL $filename failed");
            }
            try {
                $parsed = ($this->odsFallback && self::isZip($path))
                    ? CslUkReader::readSanctionsListOds($path)
                    : CslUkReader::readSanctionsList($path);
            } catch (RuntimeException $e) {
                throw new RuntimeException("parsing UK CSL $filename: " . $e->getMessage(), 0, $e);
            }
            foreach ($parsed as $record) {
                $records[] = $record;
            }
        }

        return new ListResult($this->name(), CslUkMapper::convert($records), hash_final($ctx));
    }

    private static function findLocal(string $dir, string $name): ?string
    {
        if ($dir === '' || !is_dir($dir)) {
            return null;
        }
        foreach (scandir($dir) ?: [] as $f) {
            if (strcasecmp($f, $name) === 0 && is_file($dir . DIRECTORY_SEPARATOR . $f)) {
                return $dir . DIRECTORY_SEPARATOR . $f;
            }
        }

        return null;
    }

    private static function isZip(string $path): bool
    {
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $magic = (string) fread($fh, 4);
        fclose($fh);

        return $magic === "PK\x03\x04";
    }
}
