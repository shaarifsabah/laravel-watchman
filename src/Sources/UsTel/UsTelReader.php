<?php

declare(strict_types=1);

namespace Watchman\Sources\UsTel;

use JsonException;
use RuntimeException;
use stdClass;
use Watchman\Support\Go;

/**
 * Port of pkg/sources/us_tel/reader.go (+ encoding/json decoding into TELRecord).
 *
 * Accepts either a JSON array of records or JSON Lines (a stream of objects), chosen by the
 * first non-whitespace byte, like the Go reader. Decoding follows encoding/json: keys match
 * json tags exactly or case-insensitively, unknown keys are ignored, null leaves a field
 * unchanged (or sets a slice to nil), and a type mismatch is an error. Data after the closing
 * ']' of an array is ignored (Go stops after reading that token).
 *
 * Records are arrays keyed by the TELRecord json tags, with nil slices as null.
 */
final class UsTelReader
{
    private const STRING_FIELDS = ['id', 'caption', 'schema', 'first_seen', 'last_seen', 'last_change'];
    private const SLICE_FIELDS = ['referents', 'datasets', 'origin'];
    private const PROPERTY_SLICES = ['program', 'topics', 'name'];
    private const FIELD_ORDER = ['id', 'caption', 'schema', 'referents', 'datasets', 'origin', 'first_seen', 'last_seen', 'last_change', 'properties', 'target'];

    /** @return array<string,mixed> zero-value TELRecord */
    public static function emptyRecord(): array
    {
        return [
            'id' => '', 'caption' => '', 'schema' => '', 'referents' => null, 'datasets' => null, 'origin' => null,
            'first_seen' => '', 'last_seen' => '', 'last_change' => '',
            'properties' => ['program' => null, 'topics' => null, 'name' => null],
            'target' => false,
        ];
    }

    /**
     * @param callable(array<string,mixed>):void $onRecord
     */
    public static function read(string $data, callable $onRecord): void
    {
        $len = strlen($data);
        $pos = 0;
        while ($pos < $len && ($data[$pos] === ' ' || $data[$pos] === "\t" || $data[$pos] === "\r" || $data[$pos] === "\n")) {
            $pos++;
        }
        if ($pos >= $len) {
            return; // empty input: no records, no error
        }
        $first = $data[$pos];

        if ($first === '[') {
            $pos++;
            $n = 0;
            while (true) {
                $pos = self::skipWs($data, $pos);
                if ($pos >= $len) {
                    throw new RuntimeException('EOF'); // decoder.Token() after More() == false
                }
                $c = $data[$pos];
                if ($c === ']') {
                    return; // trailing data is never read
                }
                if ($c === '}') {
                    throw new RuntimeException("invalid character '}' after array element");
                }
                if ($n > 0) {
                    if ($c !== ',') {
                        throw new RuntimeException('failed to decode array record: invalid character ' . json_encode($c) . ' after array element');
                    }
                    $pos = self::skipWs($data, $pos + 1);
                }
                [$value, $pos] = self::decodeValue($data, $pos, 'failed to decode array record');
                $onRecord(self::toRecord($value, 'failed to decode array record'));
                $n++;
            }
        }

        if ($first === '{') {
            while (true) {
                $pos = self::skipWs($data, $pos);
                if ($pos >= $len) {
                    return;
                }
                [$value, $pos] = self::decodeValue($data, $pos, 'failed to decode JSON lines record');
                $onRecord(self::toRecord($value, 'failed to decode JSON lines record'));
            }
        }

        throw new RuntimeException(sprintf("expected JSON array starting with '[' or JSON Lines starting with '{', got %s", self::goQuoteByte($first)));
    }

    private static function skipWs(string $s, int $pos): int
    {
        $len = strlen($s);
        while ($pos < $len && ($s[$pos] === ' ' || $s[$pos] === "\t" || $s[$pos] === "\r" || $s[$pos] === "\n")) {
            $pos++;
        }

        return $pos;
    }

    /**
     * Find the extent of one JSON value starting at $pos and decode it.
     *
     * @return array{0:mixed,1:int}
     */
    private static function decodeValue(string $s, int $pos, string $ctx): array
    {
        $len = strlen($s);
        $start = $pos;
        $c = $s[$pos] ?? '';
        if ($c === '{' || $c === '[') {
            $depth = 0;
            for (; $pos < $len; $pos++) {
                $ch = $s[$pos];
                if ($ch === '"') {
                    $pos = self::skipString($s, $pos);
                    if ($pos < 0) {
                        throw new RuntimeException("$ctx: unexpected EOF");
                    }
                } elseif ($ch === '{' || $ch === '[') {
                    $depth++;
                } elseif ($ch === '}' || $ch === ']') {
                    $depth--;
                    if ($depth === 0) {
                        $pos++;
                        break;
                    }
                }
            }
            if ($depth !== 0) {
                throw new RuntimeException("$ctx: unexpected EOF");
            }
        } elseif ($c === '"') {
            $pos = self::skipString($s, $pos);
            if ($pos < 0) {
                throw new RuntimeException("$ctx: unexpected EOF");
            }
            $pos++;
        } else {
            while ($pos < $len && strpbrk($s[$pos], " \t\r\n,]}{[\"") === false) {
                $pos++;
            }
            if ($pos === $start) {
                throw new RuntimeException("$ctx: invalid character " . json_encode($c) . ' looking for beginning of value');
            }
        }
        try {
            $value = json_decode(substr($s, $start, $pos - $start), false, 10000, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (JsonException $e) {
            throw new RuntimeException("$ctx: " . $e->getMessage(), 0, $e);
        }

        return [$value, $pos];
    }

    /** @return int index of the closing quote, or -1 */
    private static function skipString(string $s, int $pos): int
    {
        $len = strlen($s);
        for ($i = $pos + 1; $i < $len; $i++) {
            if ($s[$i] === '\\') {
                $i++;
            } elseif ($s[$i] === '"') {
                return $i;
            }
        }

        return -1;
    }

    /** @return array<string,mixed> */
    private static function toRecord(mixed $value, string $ctx): array
    {
        $r = self::emptyRecord();
        if ($value === null) {
            return $r; // null into a struct is a no-op
        }
        if (!$value instanceof stdClass) {
            throw new RuntimeException("$ctx: json: cannot unmarshal " . self::jsonKind($value) . ' into Go value of type us_tel.TELRecord');
        }
        foreach (get_object_vars($value) as $key => $v) {
            $field = self::matchField((string) $key, self::FIELD_ORDER);
            if ($field === null) {
                continue;
            }
            if (in_array($field, self::STRING_FIELDS, true)) {
                $r[$field] = self::str($v, $r[$field], "TELRecord.$field", $ctx);
            } elseif (in_array($field, self::SLICE_FIELDS, true)) {
                $r[$field] = self::strSlice($v, "TELRecord.$field", $ctx);
            } elseif ($field === 'target') {
                if ($v !== null) {
                    if (!is_bool($v)) {
                        throw new RuntimeException("$ctx: json: cannot unmarshal " . self::jsonKind($v) . ' into Go struct field TELRecord.target of type bool');
                    }
                    $r['target'] = $v;
                }
            } elseif ($field === 'properties') {
                if ($v === null) {
                    continue;
                }
                if (!$v instanceof stdClass) {
                    throw new RuntimeException("$ctx: json: cannot unmarshal " . self::jsonKind($v) . ' into Go struct field TELRecord.properties of type us_tel.TELProperties');
                }
                foreach (get_object_vars($v) as $pk => $pv) {
                    $pf = self::matchField((string) $pk, self::PROPERTY_SLICES);
                    if ($pf !== null) {
                        $r['properties'][$pf] = self::strSlice($pv, "TELRecord.properties.$pf", $ctx);
                    }
                }
            }
        }

        return $r;
    }

    /** Exact json tag match first, then case-insensitive (encoding/json field matching). */
    private static function matchField(string $key, array $fields): ?string
    {
        if (in_array($key, $fields, true)) {
            return $key;
        }
        foreach ($fields as $f) {
            if (Go::equalFold($key, $f)) {
                return $f;
            }
        }

        return null;
    }

    private static function str(mixed $v, string $current, string $field, string $ctx): string
    {
        if ($v === null) {
            return $current;
        }
        if (!is_string($v)) {
            throw new RuntimeException("$ctx: json: cannot unmarshal " . self::jsonKind($v) . " into Go struct field $field of type string");
        }

        return $v;
    }

    /** @return list<string>|null */
    private static function strSlice(mixed $v, string $field, string $ctx): ?array
    {
        if ($v === null) {
            return null;
        }
        if (!is_array($v)) {
            throw new RuntimeException("$ctx: json: cannot unmarshal " . self::jsonKind($v) . " into Go struct field $field of type []string");
        }
        $out = [];
        foreach ($v as $item) {
            $out[] = self::str($item, '', $field, $ctx);
        }

        return $out;
    }

    private static function jsonKind(mixed $v): string
    {
        return match (true) {
            is_string($v) => 'string',
            is_bool($v) => 'bool',
            is_int($v), is_float($v) => 'number',
            is_array($v) => 'array',
            $v instanceof stdClass => 'object',
            default => 'value',
        };
    }

    /** Go's %q for a byte (as a rune). */
    private static function goQuoteByte(string $b): string
    {
        $o = ord($b);
        if ($o >= 0x20 && $o < 0x7f) {
            return "'" . ($b === "'" ? "\\'" : ($b === '\\' ? '\\\\' : $b)) . "'";
        }
        if ($o >= 0xa1 && $o !== 0xad) {
            return "'" . mb_chr($o, 'UTF-8') . "'"; // %q of a byte prints it as the rune U+00XX
        }

        return sprintf("'\\x%02x'", $o);
    }
}
