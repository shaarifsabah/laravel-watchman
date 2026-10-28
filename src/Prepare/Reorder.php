<?php

declare(strict_types=1);

namespace Watchman\Prepare;

use Watchman\Support\Go;

/** Port of internal/prepare/pipeline_reorder.go ("SURNAME, Given" → "Given SURNAME"). */
final class Reorder
{
    private const SURNAME_PRECEDES = "/,(?:[\\t\\n\\f\\r ]+)?([\\p{L}\\p{M}'\u{2019}\\-\\.\\t\\n\\f\\r ]+)\\z/u";

    /** @param list<string> $names @return list<string> */
    public static function sdnNames(array $names, string $sdnType): array
    {
        return array_values(array_map(static fn ($n) => self::sdnName($n, $sdnType), $names));
    }

    public static function sdnName(string $name, string $sdnType): string
    {
        if (!Go::equalFold($sdnType, 'individual')) {
            return $name;
        }
        if (!preg_match(self::SURNAME_PRECEDES, $name, $m)) {
            return $name;
        }
        $given = Go::trimSpace($m[1]);
        $surname = Go::trimSpace(Go::trimSuffix($name, $m[0]));

        return Go::trimSpace($given . ' ' . $surname);
    }
}
