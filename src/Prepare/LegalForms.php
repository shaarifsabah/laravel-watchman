<?php

declare(strict_types=1);

namespace Watchman\Prepare;

use Watchman\Support\Go;

/** Port of internal/prepare/pipeline_legal_forms.go */
final class LegalForms
{
    private const PHRASES = [
        ['limited liability company', 'llc'],
        ['public limited company', 'plc'],
        ['incorporated', 'inc'],
        ['corporation', 'corp'],
        ['company', 'co'],
        ['limited', 'ltd'],
    ];
    private const SPACED = ['llc', 'plc'];

    public static function canonicalize(string $s): string
    {
        $s = Go::trimSpace($s);
        if ($s === '') {
            return $s;
        }
        $padded = ' ' . $s . ' ';
        foreach (self::PHRASES as [$from, $to]) {
            $padded = str_replace(' ' . $from . ' ', ' ' . $to . ' ', $padded);
        }
        foreach (self::SPACED as $short) {
            $spaced = ' ' . implode(' ', str_split($short)) . ' ';
            $padded = str_replace($spaced, ' ' . $short . ' ', $padded);
        }

        return Go::trimSpace($padded);
    }
}
