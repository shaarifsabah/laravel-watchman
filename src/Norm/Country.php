<?php

declare(strict_types=1);

namespace Watchman\Norm;

use Watchman\Support\Go;

/** Port of internal/norm/country.go */
final class Country
{
    private const OVERRIDES = [
        'CZ' => 'Czech Republic', 'GB' => 'United Kingdom', 'IR' => 'Iran', 'KP' => 'North Korea',
        'KR' => 'South Korea', 'MD' => 'Moldova', 'MF' => 'Saint Martin', 'RU' => 'Russia',
        'SX' => 'Saint Martin', 'SY' => 'Syria', 'TR' => 'Turkey', 'TW' => 'Taiwan',
        'UK' => 'United Kingdom', 'US' => 'United States', 'USA' => 'United States', 'VE' => 'Venezuela',
        'VG' => 'Virgin Islands', 'VI' => 'Virgin Islands', 'VN' => 'Vietnam',
    ];

    /** @var array<string,string> */
    private static array $memo = [];

    public static function normalize(string $input): string
    {
        if (isset(self::$memo[$input])) {
            return self::$memo[$input];
        }
        $name = Iso3166::getName($input);
        if ($name !== '') {
            $out = Go::or(self::OVERRIDES[Go::upper($input)] ?? '', $name);
        } else {
            $code = Iso3166::lookupCode($input);
            if ($code !== '') {
                $out = Go::or(self::OVERRIDES[$code] ?? '', Iso3166::getName($code));
            } else {
                $out = Go::or(self::OVERRIDES[Go::upper($input)] ?? '', $input);
            }
        }
        if (count(self::$memo) > 10000) {
            self::$memo = [];
        }

        return self::$memo[$input] = $out;
    }
}
