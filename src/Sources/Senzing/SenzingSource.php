<?php

declare(strict_types=1);

namespace Watchman\Sources\Senzing;

use RuntimeException;
use Watchman\Download\Downloader;
use Watchman\Download\ListResult;
use Watchman\Download\ListSource;

/**
 * Any Senzing-formatted list (OpenSanctions datasets or your own files).
 */
final class SenzingSource implements ListSource
{
    public function __construct(
        private string $sourceList,
        private string $location,
        private ?string $apiKey = null,
    ) {
        $this->sourceList = strtolower(trim($sourceList));
    }

    public function name(): string
    {
        return $this->sourceList;
    }

    public function load(Downloader $downloader, string $initialDir): ListResult
    {
        if ($this->apiKey !== null && $this->apiKey !== '') {
            $downloader = $downloader->withHeaders(['Authorization' => 'Token ' . $this->apiKey]);
        }
        $files = $downloader->getFiles($initialDir, [$this->sourceList => $this->location]);
        if (!isset($files[$this->sourceList])) {
            throw new RuntimeException("download failed for senzing list {$this->sourceList}: file not available");
        }
        $path = $files[$this->sourceList];

        return new ListResult($this->sourceList, SenzingReader::readFile($path, $this->sourceList), hash_file('sha256', $path));
    }
}
