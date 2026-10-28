<?php

declare(strict_types=1);

namespace Watchman\Prepare;

use Watchman\Support\Go;

/** Port of internal/prepare/pipeline_party_labels.go */
final class PartyLabels
{
    private const PREFIXES = ['ordering customer:', 'shipper:', 'beneficiary:', 'field 59:', 'messrs.', 'messrs '];

    public static function strip(string $s): string
    {
        $s = Go::trimSpace($s);
        if ($s === '') {
            return $s;
        }
        $lower = Go::lower($s);
        foreach (self::PREFIXES as $prefix) {
            if (Go::hasPrefix($lower, $prefix)) {
                return Go::trimSpace((string) substr($s, strlen($prefix)));
            }
        }

        return $s;
    }
}
