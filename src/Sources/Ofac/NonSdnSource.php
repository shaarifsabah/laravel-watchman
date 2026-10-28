<?php

declare(strict_types=1);

namespace Watchman\Sources\Ofac;

use RuntimeException;
use Watchman\Download\Downloader;
use Watchman\Download\ListResult;
use Watchman\Search\SourceList;

/**
 * OFAC Consolidated (Non-SDN) lists (us_non_sdn): same CSV layout as the SDN list.
 */
final class NonSdnSource extends OfacSource
{
    protected const FILES = ['CONS_PRIM.CSV', 'CONS_ADD.CSV', 'CONS_ALT.CSV', 'CONS_COMMENTS.CSV'];

    public function name(): string
    {
        return SourceList::US_NON_SDN;
    }

    public function load(Downloader $downloader, string $initialDir): ListResult
    {
        $template = $this->urlTemplate ?: (getenv('US_NON_SDN_DOWNLOAD_TEMPLATE') ?: self::DEFAULT_TEMPLATE);
        $addrs = [];
        foreach (self::FILES as $f) {
            $addrs[$f] = sprintf($template, $f);
        }
        $files = $downloader->getFiles($initialDir, $addrs);
        if ($files === []) {
            throw new RuntimeException('unexpected 0 US Non-SDN files found');
        }
        $res = OfacReader::read($files);

        return new ListResult($this->name(), OfacMapper::groupIntoEntities($res, $this->name()), $res['hash']);
    }
}
