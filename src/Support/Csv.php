<?php

declare(strict_types=1);

namespace Watchman\Support;

use RuntimeException;

final class Csv
{
    /**
     * Iterate CSV records (RFC 4180, no backslash escaping — like Go's encoding/csv).
     *
     * @return \Generator<int, list<string>>
     */
    public static function rows(string $path): \Generator
    {
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            throw new RuntimeException("unable to open $path");
        }
        try {
            while (($row = fgetcsv($fh, null, ',', '"', '')) !== false) {
                if ($row === [null]) {
                    continue; // blank line (Go skips empty lines)
                }
                yield array_map(static fn ($v) => (string) $v, $row);
            }
        } finally {
            fclose($fh);
        }
    }
}
