<?php

declare(strict_types=1);

namespace Watchman\Download;

interface ListSource
{
    /** The SourceList name entities are tagged with (us_ofac, us_csl, …). */
    public function name(): string;

    /**
     * Download (or read from $initialDir) and parse the list.
     */
    public function load(Downloader $downloader, string $initialDir): ListResult;
}
