<?php

declare(strict_types=1);

namespace Watchman\Sources\CslUs;

use RuntimeException;
use Watchman\Download\Downloader;
use Watchman\Download\ListResult;
use Watchman\Download\ListSource;
use Watchman\Search\SourceList;
use Watchman\Support\Csv;
use Watchman\Support\Go;

/**
 * US Consolidated Screening List (us_csl). Port of pkg/sources/csl_us.
 */
final class CslUsSource implements ListSource
{
    public const DEFAULT_TEMPLATE = 'https://data.trade.gov/downloadable_consolidated_screening_list/v1/%s';

    private const FIELDS = [
        'ID', 'Source', 'EntityNumber', 'Type', 'Programs', 'Name', 'Title', 'Addresses', 'FederalRegisterNotice',
        'StartDate', 'EndDate', 'StandardOrder', 'LicenseRequirement', 'LicensePolicy', 'CallSign', 'VesselType',
        'GrossTonnage', 'GrossRegisteredTonnage', 'VesselFlag', 'VesselOwner', 'Remarks', 'SourceListURL', 'AltNames',
        'Citizenships', 'DatesOfBirth', 'Nationalities', 'PlacesOfBirth', 'SourceInformationURL', 'IDs',
    ];

    public function __construct(private ?string $urlTemplate = null)
    {
    }

    public function name(): string
    {
        return SourceList::US_CSL;
    }

    public function load(Downloader $downloader, string $initialDir): ListResult
    {
        $template = $this->urlTemplate ?: Go::strxOr((string) getenv('US_CSL_DOWNLOAD_TEMPLATE'), (string) getenv('US_CSL_DOWNLOAD_URL'), self::DEFAULT_TEMPLATE);
        $files = $downloader->getFiles($initialDir, ['consolidated.csv' => sprintf($template, 'consolidated.csv')]);
        if (!isset($files['consolidated.csv'])) {
            throw new RuntimeException('unexpected 0 US CSL files found');
        }
        $path = $files['consolidated.csv'];

        return new ListResult($this->name(), self::entities($path), hash_file('sha256', $path));
    }

    /** @return \Generator<int, \Watchman\Search\Entity> */
    public static function entities(string $path): \Generator
    {
        foreach (self::read($path) as $entry) {
            yield CslUsMapper::toEntity($entry);
        }
    }

    /** @return \Generator<int, array<string,string>> */
    public static function read(string $path): \Generator
    {
        $first = true;
        foreach (Csv::rows($path) as $r) {
            if ($first) {
                $first = false; // header

                continue;
            }
            if (count($r) !== 29) {
                throw new RuntimeException('failed to parse CSV: wrong number of fields');
            }
            if (str_contains(Go::lower($r[1]), 'specially designated nationals')) {
                continue;
            }
            yield array_combine(self::FIELDS, $r);
        }
    }
}
