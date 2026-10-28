<?php

declare(strict_types=1);

namespace Watchman\Norm;

use Watchman\Support\Go;

/** Port of internal/norm/website.go */
final class Website
{
    public static function normalize(string $input): string
    {
        $s = Go::trimSpace(Go::lower($input));
        if ($s === '') {
            return '';
        }
        if (($i = strpos($s, '://')) !== false) {
            $s = (string) substr($s, $i + 3);
        }
        if (($i = strrpos($s, '@')) !== false) {
            $s = (string) substr($s, $i + 1);
        }
        $i = strcspn($s, '/?#');
        if ($i < strlen($s)) {
            $s = substr($s, 0, $i);
        }
        $s = Go::trimSuffix($s, '.');
        $s = Go::trimSuffix($s, ':443');
        $s = Go::trimSuffix($s, ':80');
        $s = Go::trimPrefix($s, 'www.');

        return Go::trimSpace($s);
    }
}
