<?php

declare(strict_types=1);

namespace Watchman\Prepare;

use Watchman\Support\Go;

/** Port of internal/prepare/pipeline_vessel_place.go */
final class VesselPlace
{
    public static function stripTrailing(string $s): string
    {
        $s = Go::trimSpace($s);
        if ($s === '') {
            return $s;
        }
        while (true) {
            $t = self::stripTrailingParen($s);
            if ($t === null) {
                break;
            }
            $s = $t;
        }
        $i = strrpos($s, ',');
        if ($i !== false && $i > 0) {
            $head = Go::trimSpace(substr($s, 0, $i));
            if ($head !== '') {
                $s = $head;
            }
        }

        return $s;
    }

    private static function stripTrailingParen(string $s): ?string
    {
        $s = Go::trimSpace($s);
        if (!Go::hasSuffix($s, ')')) {
            return null;
        }
        $start = strrpos($s, '(');
        if ($start === false) {
            return null;
        }
        $inner = Go::trimSpace(substr($s, $start + 1, strlen($s) - 1 - ($start + 1)));
        if (ExNames::cutFormerInner($inner) !== null) {
            return null;
        }

        return Go::trimSpace(substr($s, 0, $start));
    }
}
