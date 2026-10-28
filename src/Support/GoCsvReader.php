<?php

declare(strict_types=1);

namespace Watchman\Support;

use RuntimeException;

/**
 * Byte-for-byte port of Go's encoding/csv Reader.readRecord (Go 1.24) for the
 * options Watchman uses: Comma, LazyQuotes and FieldsPerRecord.
 *
 * PHP's fgetcsv() differs from Go in several ways that matter for the EU/UK
 * lists (LazyQuotes handling of stray quotes, ErrFieldCount on rows whose
 * field count differs from the first record, CRLF normalisation, …), so the
 * list readers use this class instead.
 *
 * read() returns [record|null, error|null] where error is one of the ERR_*
 * constants, mirroring Go's (record, err) pair: on ERR_FIELD_COUNT / ERR_QUOTE /
 * ERR_BARE_QUOTE the (partial) record is still returned.
 *
 * (Used by both the EU and UK readers; a candidate for Watchman\Support.)
 */
final class GoCsvReader
{
    public const ERR_EOF = 'EOF';
    public const ERR_FIELD_COUNT = 'wrong number of fields';
    public const ERR_QUOTE = 'extraneous or missing " in quoted-field';
    public const ERR_BARE_QUOTE = 'bare " in non-quoted-field';

    /** @var resource */
    private $fh;

    private bool $ownsHandle = false;

    /**
     * @param resource|string $source an open stream or a file path
     */
    public function __construct(
        mixed $source,
        public string $comma = ',',
        public bool $lazyQuotes = false,
        public int $fieldsPerRecord = 0,
    ) {
        if (is_string($source)) {
            $fh = @fopen($source, 'rb');
            if ($fh === false) {
                throw new RuntimeException("unable to open $source");
            }
            $this->fh = $fh;
            $this->ownsHandle = true;
        } elseif (is_resource($source)) {
            $this->fh = $source;
        } else {
            throw new RuntimeException('GoCsvReader expects a path or stream');
        }
        if ($this->comma === '' || $this->comma === '"' || $this->comma === "\r" || $this->comma === "\n") {
            throw new RuntimeException('csv: invalid field or comment delimiter');
        }
    }

    public static function fromString(string $data, string $comma = ',', bool $lazyQuotes = false, int $fieldsPerRecord = 0): self
    {
        $fh = fopen('php://temp', 'w+b');
        fwrite($fh, $data);
        rewind($fh);
        $r = new self($fh, $comma, $lazyQuotes, $fieldsPerRecord);
        $r->ownsHandle = true;

        return $r;
    }

    public function __destruct()
    {
        if ($this->ownsHandle && is_resource($this->fh)) {
            fclose($this->fh);
        }
    }

    /** bufio.Reader.ReadSlice('\n') + csv readLine normalisation. @return array{0:string,1:bool} [line, eof] */
    private function readLine(): array
    {
        $line = fgets($this->fh);
        if ($line === false || $line === '') {
            return ['', true];
        }
        $n = strlen($line);
        if ($line[$n - 1] !== "\n") {
            // partial last line: err becomes nil, drop trailing \r before EOF
            if ($line[$n - 1] === "\r") {
                $line = substr($line, 0, -1);
            }

            return [$line, false];
        }
        if ($n >= 2 && $line[$n - 2] === "\r") {
            $line = substr($line, 0, $n - 2) . "\n";
        }

        return [$line, false];
    }

    private static function lengthNL(string $b): int
    {
        return ($b !== '' && $b[strlen($b) - 1] === "\n") ? 1 : 0;
    }

    /**
     * @return array{0: list<string>|null, 1: string|null}
     */
    public function read(): array
    {
        $line = '';
        $eof = false;
        while (!$eof) {
            [$line, $eof] = $this->readLine();
            if (!$eof && strlen($line) === self::lengthNL($line)) {
                $line = '';

                continue; // skip empty lines
            }
            break;
        }
        if ($eof) {
            return [null, self::ERR_EOF];
        }

        $err = null;
        $comma = $this->comma;
        $commaLen = strlen($comma);
        $buf = '';
        $fields = [];
        while (true) {
            if ($line === '' || $line[0] !== '"') {
                // Non-quoted field
                $i = strpos($line, $comma);
                if ($i !== false) {
                    $field = substr($line, 0, $i);
                } else {
                    $field = substr($line, 0, strlen($line) - self::lengthNL($line));
                }
                if (!$this->lazyQuotes && str_contains($field, '"')) {
                    $err = self::ERR_BARE_QUOTE;
                    break;
                }
                $buf .= $field;
                $fields[] = strlen($buf);
                if ($i !== false) {
                    $line = (string) substr($line, $i + $commaLen);

                    continue;
                }
                break;
            }

            // Quoted field
            $line = (string) substr($line, 1);
            while (true) {
                $i = strpos($line, '"');
                if ($i !== false) {
                    $buf .= substr($line, 0, $i);
                    $line = (string) substr($line, $i + 1);
                    if ($line !== '' && $line[0] === '"') {
                        $buf .= '"'; // "" escape
                        $line = (string) substr($line, 1);
                    } elseif ($line !== '' && strncmp($line, $comma, $commaLen) === 0) {
                        $line = (string) substr($line, $commaLen);
                        $fields[] = strlen($buf);

                        continue 2; // next field
                    } elseif (self::lengthNL($line) === strlen($line)) {
                        $fields[] = strlen($buf);
                        break 2; // end of record
                    } elseif ($this->lazyQuotes) {
                        $buf .= '"'; // bare quote
                    } else {
                        $err = self::ERR_QUOTE;
                        break 2;
                    }
                } elseif ($line !== '') {
                    // hit end of line inside quotes: keep reading
                    $buf .= $line;
                    // Go clears io.EOF here; an empty line then hits the "abrupt EOF" branch below.
                    [$line] = $this->readLine();
                } else {
                    // abrupt end of file
                    if (!$this->lazyQuotes) {
                        $err = self::ERR_QUOTE;
                        break 2;
                    }
                    $fields[] = strlen($buf);
                    break 2;
                }
            }
        }

        $record = [];
        $prev = 0;
        foreach ($fields as $idx) {
            $record[] = (string) substr($buf, $prev, $idx - $prev);
            $prev = $idx;
        }

        if ($this->fieldsPerRecord > 0) {
            if (count($record) !== $this->fieldsPerRecord && $err === null) {
                $err = self::ERR_FIELD_COUNT;
            }
        } elseif ($this->fieldsPerRecord === 0) {
            $this->fieldsPerRecord = count($record);
        }

        return [$record, $err];
    }
}
