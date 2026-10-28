<?php

declare(strict_types=1);

namespace Watchman\Norm;

use Watchman\Support\Data;
use Watchman\Support\Go;

/** Port of github.com/moov-io/iso3166 v0.4.0 */
final class Iso3166
{
    /** @var array<string,string>|null lowercase name => alpha2 */
    private static ?array $byName = null;

    public static function getName(string $code): string
    {
        $d = Data::get('iso3166');
        $up = Go::upper($code);
        if (!empty($d['alpha2'][$up])) {
            return $d['alpha2'][$up][0];
        }
        if (!empty($d['alpha3'][$up])) {
            return $d['alpha3'][$up][0];
        }

        return '';
    }

    public static function lookupCode(string $input): string
    {
        $input = str_replace(['.', ','], '', $input);
        $input = str_replace('  ', ' ', $input);
        if (self::$byName === null) {
            self::$byName = [];
            foreach (Data::get('iso3166')['alpha2'] as $code => $names) {
                foreach ($names as $name) {
                    $k = mb_convert_case($name, MB_CASE_FOLD_SIMPLE, 'UTF-8');
                    self::$byName[$k] ??= $code;
                }
            }
        }

        return self::$byName[mb_convert_case($input, MB_CASE_FOLD_SIMPLE, 'UTF-8')] ?? '';
    }

    public static function validAlpha2(string $code): bool
    {
        return isset(Data::get('iso3166')['alpha2'][Go::upper($code)]);
    }
}
