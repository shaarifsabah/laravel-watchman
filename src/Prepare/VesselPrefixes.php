<?php

declare(strict_types=1);

namespace Watchman\Prepare;

use Watchman\Support\Go;

/** Port of internal/prepare/pipeline_vessel_prefixes.go */
final class VesselPrefixes
{
    private const PREFIXES = ['msv ', 'm v ', 'm t ', 'n m ', 't b ', 'mv ', 'mt ', 'ss ', 'ms ', 'tb ', 'nm '];

    public static function strip(string $s): string
    {
        $s = Go::trimSpace($s);
        if ($s === '') {
            return $s;
        }
        $changed = true;
        while ($changed) {
            $changed = false;
            foreach (self::PREFIXES as $prefix) {
                if (Go::hasPrefix($s, $prefix)) {
                    $s = Go::trimSpace((string) substr($s, strlen($prefix)));
                    $changed = true;
                    break;
                }
            }
        }

        return $s;
    }
}
