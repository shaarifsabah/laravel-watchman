<?php

declare(strict_types=1);

namespace Watchman\Prepare;

use Watchman\Support\Go;

final class Gender
{
    public static function normalize(string $input): string
    {
        return match (Go::lower(Go::trimSpace($input))) {
            'm', 'male', 'man', 'guy' => 'male',
            'f', 'female', 'woman', 'gal', 'girl' => 'female',
            default => 'unknown',
        };
    }
}
