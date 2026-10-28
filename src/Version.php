<?php

declare(strict_types=1);

namespace Watchman;

final class Version
{
    public const PACKAGE = '1.0.0';

    /** Upstream moov-io/watchman commit this port follows. */
    public const UPSTREAM = '7f44b4b6';

    public static function string(): string
    {
        return 'laravel-watchman/' . self::PACKAGE . ' (moov-io/watchman@' . self::UPSTREAM . ')';
    }
}
