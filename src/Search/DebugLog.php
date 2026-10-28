<?php

declare(strict_types=1);

namespace Watchman\Search;

/** Collects the human-readable scoring explanation (Go's io.Writer debug output). */
final class DebugLog
{
    private string $buffer = '';

    public function write(string $s): void
    {
        $this->buffer .= $s;
    }

    public function contents(): string
    {
        return $this->buffer;
    }
}
