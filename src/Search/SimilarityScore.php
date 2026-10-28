<?php

declare(strict_types=1);

namespace Watchman\Search;

/** Field-level scoring details (pkg/search.SimilarityScore). */
final class SimilarityScore
{
    /**
     * @param list<ScorePiece> $pieces
     */
    public function __construct(
        public array $pieces = [],
        public float $finalScore = 0.0,
    ) {
    }

    public function toArray(): array
    {
        $out = [];
        if ($this->pieces !== []) {
            $out['pieces'] = array_map(static fn (ScorePiece $p) => $p->toArray(), $this->pieces);
        }
        if ($this->finalScore != 0.0) {
            $out['finalScore'] = $this->finalScore;
        }

        return $out;
    }

    public static function fromArray(array $a): self
    {
        return new self(
            array_map(static fn ($p) => ScorePiece::fromArray((array) $p), (array) ($a['pieces'] ?? [])),
            (float) ($a['finalScore'] ?? 0),
        );
    }
}
