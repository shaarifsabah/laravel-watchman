<?php

declare(strict_types=1);

namespace Watchman\Search;

/**
 * Source list identifiers. Values match pkg/search.SourceList.
 */
final class SourceList
{
    public const API_REQUEST = 'api-request';
    public const MCP_REQUEST = 'mcp-request';
    public const US_TEL = 'us_tel';
    public const EU_CSL = 'eu_csl';
    public const UK_CSL = 'uk_csl';
    public const US_CSL = 'us_csl';
    public const US_OFAC = 'us_ofac';
    public const US_NON_SDN = 'us_non_sdn';
    public const US_FINCEN_311 = 'us_fincen_311';
    public const UN_CSL = 'un_csl';

    /** Lists that have a built-in downloader. */
    public const DOWNLOADABLE = [
        self::EU_CSL, self::UK_CSL, self::US_CSL, self::US_OFAC,
        self::US_NON_SDN, self::US_FINCEN_311, self::UN_CSL, self::US_TEL,
    ];

    public static function isRequestType(string $source): bool
    {
        return $source === self::API_REQUEST || $source === self::MCP_REQUEST;
    }
}
