<?php

declare(strict_types=1);

namespace Watchman\Index;

use RuntimeException;
use Watchman\Search\Entity;

/**
 * Compact entity serialization for index stores (igbinary when available).
 */
final class Codec
{
    /** Bump when the stored entity shape changes; old generations must be refreshed. */
    public const SCHEMA = 1;

    /**
     * Use igbinary when the extension is loaded. igbinary is smaller and faster but cannot restrict
     * which classes it creates; turn it off (config watchman.index.serializer = "php") if the index
     * store is shared with clients you do not fully trust, so decoding is limited to allowedClasses().
     */
    private static bool $igbinary = true;

    public static function useIgbinary(bool $use): void
    {
        self::$igbinary = $use;
    }

    public static function encode(Entity $e): string
    {
        if (self::$igbinary && function_exists('igbinary_serialize')) {
            return 'i' . igbinary_serialize($e);
        }

        return 's' . serialize($e);
    }

    public static function decode(string $data): Entity
    {
        $kind = $data[0] ?? '';
        $body = substr($data, 1);
        $e = match ($kind) {
            'i' => self::$igbinary && function_exists('igbinary_unserialize')
                ? igbinary_unserialize($body)
                : throw new RuntimeException('this index was written with igbinary, which is unavailable or disabled; run watchman:refresh'),
            's' => unserialize($body, ['allowed_classes' => self::allowedClasses()]),
            default => throw new RuntimeException('unknown entity encoding'),
        };
        if (!$e instanceof Entity) {
            throw new RuntimeException('corrupt entity in index; run watchman:refresh');
        }

        return $e;
    }

    /** @var list<string>|null */
    private static ?array $allowed = null;

    /**
     * Classes an encoded entity may contain: the entity model in Watchman\Search plus DateTimeImmutable.
     *
     * @return list<string>
     */
    public static function allowedClasses(): array
    {
        if (self::$allowed !== null) {
            return self::$allowed;
        }
        $classes = [\DateTimeImmutable::class, \DateTime::class, \DateTimeZone::class];
        foreach (glob(dirname(__DIR__) . '/Search/*.php') ?: [] as $file) {
            $classes[] = 'Watchman\\Search\\' . basename($file, '.php');
        }

        return self::$allowed = $classes;
    }
}
