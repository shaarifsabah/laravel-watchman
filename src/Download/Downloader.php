<?php

declare(strict_types=1);

namespace Watchman\Download;

use RuntimeException;

/**
 * Port of pkg/download: fetch named files, preferring copies already present in
 * an "initial data directory", supporting file:// URLs and retrying HTTP up to 3 times.
 *
 * Downloads are written to temporary files (lists can be hundreds of MB).
 */
class Downloader
{
    /** @var array<string,string> */
    private array $headers = [];

    /** @var list<string> temp files created by this downloader */
    private array $temp = [];

    /** @var callable(string,string):void|null */
    private $logger;

    public function __construct(
        private int $timeoutSeconds = 60,
        private string $userAgent = 'laravel-watchman',
        private ?string $tempDir = null,
        ?callable $logger = null,
    ) {
        $this->logger = $logger;
    }

    public function withHeaders(array $headers): static
    {
        $clone = clone $this;
        $clone->headers = array_merge($this->headers, $headers);

        return $clone;
    }

    /**
     * @param array<string,string> $namesAndSources filename => URL
     * @return array<string,string> filename => local path (missing entries failed to download)
     */
    public function getFiles(string $dir, array $namesAndSources): array
    {
        $local = [];
        if ($dir !== '' && is_dir($dir)) {
            foreach (scandir($dir) ?: [] as $f) {
                if ($f !== '.' && $f !== '..' && is_file($dir . DIRECTORY_SEPARATOR . $f)) {
                    $local[] = $f;
                }
            }
        }
        $out = [];
        foreach ($namesAndSources as $name => $source) {
            foreach ($local as $f) {
                if (strcasecmp(basename($f), (string) $name) === 0) {
                    $out[$name] = $dir . DIRECTORY_SEPARATOR . $f;
                    $this->log('info', "found $f in initial data directory");
                    continue 2;
                }
            }
            try {
                $out[$name] = $this->download($source);
                $this->log('info', "downloaded $name");
            } catch (\Throwable $e) {
                $this->log('error', "FAILURE to download $name: " . $e->getMessage());
            }
        }

        return $out;
    }

    public function download(string $url): string
    {
        if (str_starts_with($url, 'file://')) {
            $path = substr($url, 7);
            if (!is_file($path)) {
                throw new RuntimeException("file not found: $path");
            }

            return $path;
        }
        $last = null;
        for ($i = 0; $i < 3; $i++) {
            $tmp = $this->tempFile();
            $fh = fopen($tmp, 'wb');
            $ch = curl_init($url);
            $headers = array_merge([
                'User-Agent' => $this->userAgent,
                'Accept-Language' => 'en-US,en;q=0.9',
            ], $this->headers);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fh,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => min(30, $this->timeoutSeconds),
                CURLOPT_HTTPHEADER => array_map(static fn ($k, $v) => "$k: $v", array_keys($headers), $headers),
                CURLOPT_ENCODING => '',
                CURLOPT_FAILONERROR => false,
            ]);
            $ok = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $err = curl_error($ch);
            curl_close($ch);
            fclose($fh);
            if ($ok !== false && $status >= 200 && $status < 300) {
                return $tmp;
            }
            $last = $ok === false ? $err : "HTTP $status";
            @unlink($tmp);
            usleep(100_000);
        }

        throw new RuntimeException("error max retries reached while trying to obtain file ($last)");
    }

    public function tempFile(): string
    {
        $dir = $this->tempDir ?? sys_get_temp_dir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $tmp = tempnam($dir, 'watchman-');
        if ($tmp === false) {
            throw new RuntimeException("unable to create temp file in $dir");
        }

        return $this->temp[] = $tmp;
    }

    /** Remove temporary downloads. */
    public function cleanup(): void
    {
        foreach ($this->temp as $f) {
            @unlink($f);
        }
        $this->temp = [];
    }

    public function __destruct()
    {
        $this->cleanup();
    }

    private function log(string $level, string $message): void
    {
        if ($this->logger !== null) {
            ($this->logger)($level, $message);
        }
    }
}
