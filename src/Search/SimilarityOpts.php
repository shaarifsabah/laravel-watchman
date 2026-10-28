<?php

declare(strict_types=1);

namespace Watchman\Search;

use Watchman\Index\TfIdf;

final class SimilarityOpts
{
    public function __construct(
        public ?TfIdf $tfidf = null,
        public string $algorithm = '',
    ) {
    }
}
