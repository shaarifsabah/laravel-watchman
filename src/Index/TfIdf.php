<?php

declare(strict_types=1);

namespace Watchman\Index;

/**
 * Port of internal/tfidf: optional TF-IDF weighting of name tokens.
 * Disabled by default (TFIDF_ENABLED in Watchman).
 *
 * Document frequencies come from a lookup callback so they can live in Redis
 * or the database instead of process memory.
 */
final class TfIdf
{
    /** @var array<string,int> */
    private array $df = [];

    /** @var callable(list<string>):array<string,int>|null */
    private $lookup;

    public function __construct(
        private bool $enabled = false,
        private int $totalDocuments = 0,
        private float $smoothing = 1.0,
        private float $minIdf = 0.1,
        private float $maxIdf = 10.0,
        ?callable $lookup = null,
    ) {
        $this->lookup = $lookup;
    }

    public static function fromConfig(array $config, int $totalDocuments = 0, ?callable $lookup = null): self
    {
        return new self(
            (bool) ($config['enabled'] ?? false),
            $totalDocuments,
            (float) ($config['smoothing'] ?? 1.0),
            (float) ($config['min_idf'] ?? 0.1),
            (float) ($config['max_idf'] ?? 10.0),
            $lookup,
        );
    }

    /**
     * Build document frequencies in memory from token documents.
     *
     * @param iterable<list<string>> $documents
     */
    public function build(iterable $documents): void
    {
        $this->df = [];
        $this->totalDocuments = 0;
        foreach ($documents as $doc) {
            $this->totalDocuments++;
            $seen = [];
            foreach ($doc as $term) {
                if ($term === '' || isset($seen[$term])) {
                    continue;
                }
                $seen[$term] = true;
                $this->df[$term] = ($this->df[$term] ?? 0) + 1;
            }
        }
        $this->lookup = null;
    }

    public function enabled(): bool
    {
        return $this->enabled;
    }

    public function totalDocuments(): int
    {
        return $this->totalDocuments;
    }

    /** @return array<string,int> */
    public function documentFrequencies(): array
    {
        return $this->df;
    }

    private function idf(int $docFreq): float
    {
        $idf = log(($this->totalDocuments + $this->smoothing) / ($docFreq + $this->smoothing));

        return min($this->maxIdf, max($this->minIdf, $idf));
    }

    /**
     * @param list<string> $terms
     * @return list<float>
     */
    public function weights(array $terms): array
    {
        if ($terms === []) {
            return [];
        }
        if ($this->lookup !== null) {
            $missing = array_values(array_filter(array_unique($terms), fn ($t) => !array_key_exists($t, $this->df)));
            if ($missing !== []) {
                $found = ($this->lookup)($missing);
                foreach ($missing as $t) {
                    $this->df[$t] = (int) ($found[$t] ?? 0);
                }
            }
        }
        $tf = array_count_values($terms);
        $out = [];
        foreach ($terms as $term) {
            $termFreq = 1.0 + log((float) $tf[$term]);
            $df = $this->df[$term] ?? 0;
            $idf = ($df > 0 && $this->totalDocuments > 0) ? $this->idf($df) : $this->maxIdf;
            $out[] = $termFreq * $idf;
        }

        return $out;
    }
}
