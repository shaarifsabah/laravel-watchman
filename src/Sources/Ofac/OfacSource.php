<?php

declare(strict_types=1);

namespace Watchman\Sources\Ofac;

use RuntimeException;
use Watchman\Download\Downloader;
use Watchman\Download\ListResult;
use Watchman\Download\ListSource;
use Watchman\Search\SourceList;

/**
 * OFAC Specially Designated Nationals (us_ofac).
 */
class OfacSource implements ListSource
{
    public const DEFAULT_TEMPLATE = 'https://sanctionslistservice.ofac.treas.gov/api/PublicationPreview/exports/%s';
    protected const FILES = ['add.csv', 'alt.csv', 'sdn.csv', 'sdn_comments.csv'];

    public function __construct(protected ?string $urlTemplate = null)
    {
    }

    public function name(): string
    {
        return SourceList::US_OFAC;
    }

    public function load(Downloader $downloader, string $initialDir): ListResult
    {
        $template = $this->urlTemplate ?: (getenv('OFAC_DOWNLOAD_TEMPLATE') ?: self::DEFAULT_TEMPLATE);
        $addrs = [];
        foreach (static::FILES as $f) {
            $addrs[$f] = sprintf($template, $f);
        }
        $files = $downloader->getFiles($initialDir, $addrs);
        if ($files === []) {
            throw new RuntimeException('unexpected 0 OFAC files found');
        }
        $res = OfacReader::read($files);

        return new ListResult($this->name(), OfacMapper::groupIntoEntities($res, $this->name()), $res['hash']);
    }
}
