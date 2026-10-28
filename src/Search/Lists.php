<?php

declare(strict_types=1);

namespace Watchman\Search;

/**
 * @internal helpers for (de)serializing model lists the way Go's encoding/json does.
 */
final class Lists
{
    /** @return list<string> */
    public static function strings(mixed $v): array
    {
        if (!is_array($v)) {
            return [];
        }

        return array_values(array_map(static fn ($x) => (string) $x, $v));
    }

    /** @return list<GovernmentID> */
    public static function govIds(mixed $v): array
    {
        return is_array($v) ? array_values(array_map(static fn ($x) => $x instanceof GovernmentID ? $x : GovernmentID::fromArray((array) $x), $v)) : [];
    }

    /** Go encodes nil slices as null. */
    public static function nullable(array $v): ?array
    {
        return $v === [] ? null : array_values($v);
    }

    /** @param list<object> $v */
    public static function arrays(array $v): ?array
    {
        return $v === [] ? null : array_values(array_map(static fn ($x) => $x->toArray(), $v));
    }
}
