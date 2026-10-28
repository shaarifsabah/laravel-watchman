<?php

declare(strict_types=1);

namespace Watchman\Ingest;

use InvalidArgumentException;
use RuntimeException;
use Watchman\Search\Address;
use Watchman\Search\Business;
use Watchman\Search\Entity;
use Watchman\Search\GovernmentID;
use Watchman\Search\Merge;
use Watchman\Search\Person;
use Watchman\Sources\Senzing\SenzingReader;
use Watchman\Support\Go;
use Watchman\Support\GoTime;

/**
 * Port of internal/ingest/service.go: parse uploaded CSV / Senzing files using
 * a per-fileType mapping (config/watchman.php "ingest.files").
 *
 * Mapping keys mirror Watchman's YAML (camelCase):
 *   format: csv|senzing|senzing-json|senzing-jsonl
 *   mapping: name{column|merge}, sourceID{…}, type{default},
 *            person{name, altNames{columns|merge}, birthDate, governmentIDs{type, identifier}},
 *            business{name, altNames, created, governmentIDs},
 *            contact{phoneNumbers{columns|merge}},
 *            addresses{line1, line2, city, state, postalCode, country}
 */
final class IngestService
{
    private const TIME_FORMATS = ['2006-01-02', '1/2/2006', '01/02/2006'];

    /** @param array<string, array> $files fileType => schema */
    /**
     * @param int $maxDecompressedBytes refuse gzip uploads that expand beyond this size
     */
    public function __construct(private array $files, private int $maxDecompressedBytes = 268435456)
    {
    }

    /** @return array<string, array> */
    public function fileTypes(): array
    {
        return $this->files;
    }

    /**
     * @return array{fileType: string, entities: list<Entity>}
     */
    public function read(string $name, string $contents): array
    {
        try {
            return $this->parse($name, $contents);
        } catch (InvalidArgumentException $e) {
            throw $e;
        } catch (RuntimeException|\JsonException|\ValueError $e) {
            // Anything the readers reject is a problem with the uploaded file, not the server.
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array{fileType: string, entities: list<Entity>}
     */
    private function parse(string $name, string $contents): array
    {
        $contents = $this->maybeDecompress($contents);
        $out = null;
        foreach ($this->files as $fileType => $schema) {
            if (!Go::equalFold($name, (string) $fileType)) {
                continue;
            }
            $format = strtolower((string) ($schema['format'] ?? 'csv'));
            $out = match ($format) {
                'csv' => ['fileType' => (string) $fileType, 'entities' => $this->readCsv((string) $fileType, (array) ($schema['mapping'] ?? []), $contents)],
                'senzing', 'senzing-json', 'senzing-jsonl' => ['fileType' => (string) $fileType, 'entities' => SenzingReader::readString($contents, (string) $fileType)],
                default => throw new InvalidArgumentException("unknown format $format"),
            };
        }
        if ($out === null || $out['entities'] === []) {
            throw new InvalidArgumentException("schema $name not found");
        }
        $out['entities'] = Merge::entities($out['entities']);
        if ($out['entities'] === []) {
            throw new InvalidArgumentException("schema $name not found");
        }

        return $out;
    }

    private function maybeDecompress(string $s): string
    {
        if (strlen($s) >= 2 && $s[0] === "\x1f" && $s[1] === "\x8b") {
            $d = @gzdecode($s, $this->maxDecompressedBytes);
            if ($d === false) {
                throw new InvalidArgumentException('could not decompress the gzip body, or it expands beyond ' . $this->maxDecompressedBytes . ' bytes');
            }

            return $d;
        }

        return $s;
    }

    /** @return list<Entity> */
    private function readCsv(string $name, array $m, string $contents): array
    {
        $fh = fopen('php://temp', 'r+b');
        fwrite($fh, $contents);
        rewind($fh);
        $headers = fgetcsv($fh, null, ',', '"', '');
        if ($headers === false || $headers === [null]) {
            throw new RuntimeException('problem reading headers');
        }
        $headers = array_map(static fn ($h) => str_replace("\u{FFFD}", '', (string) $h), $headers);
        $out = [];
        while (($row = fgetcsv($fh, null, ',', '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }
            $row = array_map(static fn ($v) => str_replace("\u{FFFD}", '', (string) $v), $row);
            $e = new Entity();
            $e->name = self::col($headers, $m['name'] ?? [], $row);
            $e->type = (string) ($m['type']['default'] ?? '');
            $e->source = $name;
            $e->sourceId = self::col($headers, $m['sourceID'] ?? [], $row);

            if (isset($m['person'])) {
                $p = $m['person'];
                $e->person = new Person($e->name, self::cols($headers, $p['altNames'] ?? [], $row), governmentIDs: self::ids($headers, $p['governmentIDs'] ?? [], $row));
                $e->person->birthDate = self::time(self::col($headers, $p['birthDate'] ?? [], $row));
            }
            if (isset($m['business'])) {
                $b = $m['business'];
                $e->business = new Business($e->name, self::cols($headers, $b['altNames'] ?? [], $row), governmentIDs: self::ids($headers, $b['governmentIDs'] ?? [], $row));
                $e->business->created = self::time(self::col($headers, $b['created'] ?? [], $row));
            }
            $e->contact->phoneNumbers = self::cols($headers, $m['contact']['phoneNumbers'] ?? [], $row);
            $e->addresses = self::addresses($headers, (array) ($m['addresses'] ?? []), $row);
            $out[] = $e->normalize();
        }
        fclose($fh);

        return $out;
    }

    private static function value(array $headers, string $col, array $row): string
    {
        $idx = array_search($col, $headers, true);

        return ($idx !== false && $idx < count($row)) ? Go::trimSpace((string) $row[$idx]) : '';
    }

    /** ColumnDef: {column, merge[]} → joined with spaces */
    private static function col(array $headers, array $def, array $row): string
    {
        $fields = [];
        if (($c = (string) ($def['column'] ?? '')) !== '' && ($v = self::value($headers, $c, $row)) !== '') {
            $fields[] = $v;
        }
        foreach ((array) ($def['merge'] ?? []) as $c) {
            if (($v = self::value($headers, (string) $c, $row)) !== '') {
                $fields[] = $v;
            }
        }

        return implode(' ', $fields);
    }

    /** ColumnArrayDef: {columns, merge[]} → list */
    private static function cols(array $headers, array $def, array $row): array
    {
        $fields = [];
        if (($c = (string) ($def['columns'] ?? '')) !== '' && ($v = self::value($headers, $c, $row)) !== '') {
            $fields[] = $v;
        }
        $name = '';
        foreach ((array) ($def['merge'] ?? []) as $c) {
            if (($v = self::value($headers, (string) $c, $row)) !== '') {
                $name .= ' ' . $v;
            }
        }
        if (($name = Go::trimSpace($name)) !== '') {
            $fields[] = $name;
        }

        return $fields;
    }

    private static function time(string $v): ?\DateTimeImmutable
    {
        if ($v === '') {
            return null;
        }
        $t = GoTime::parseAny(self::TIME_FORMATS, $v);
        if ($t === null) {
            throw new InvalidArgumentException("unable to parse date \"$v\"");
        }

        return $t;
    }

    /** @return list<GovernmentID> */
    private static function ids(array $headers, array $def, array $row): array
    {
        $id = self::col($headers, (array) ($def['identifier'] ?? []), $row);

        return $id === '' ? [] : [new GovernmentID('', '', $id)];
    }

    /** @return list<Address> */
    private static function addresses(array $headers, array $def, array $row): array
    {
        $line1 = self::cols($headers, (array) ($def['line1'] ?? []), $row);
        $line2 = self::cols($headers, (array) ($def['line2'] ?? []), $row);
        $city = self::cols($headers, (array) ($def['city'] ?? []), $row);
        $state = self::cols($headers, (array) ($def['state'] ?? []), $row);
        $postal = self::cols($headers, (array) ($def['postalCode'] ?? []), $row);
        $country = self::cols($headers, (array) ($def['country'] ?? []), $row);
        $out = [];
        foreach ($line1 as $i => $l1) {
            $a = new Address($l1, $line2[$i] ?? '', $city[$i] ?? '', $postal[$i] ?? '', $state[$i] ?? '', $country[$i] ?? '');
            if ($a->line1 !== '') {
                $out[] = $a;
            }
        }

        return $out;
    }
}
