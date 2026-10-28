<?php

declare(strict_types=1);

namespace Watchman\Search;

/** Partial scoring result from one comparison function (pkg/search.ScorePiece). */
final class ScorePiece
{
    public function __construct(
        public float $score = 0.0,
        public float $weight = 0.0,
        public bool $matched = false,
        public bool $required = false,
        public bool $exact = false,
        public int $fieldsCompared = 0,
        public string $pieceType = '',
        public bool $uniqueIdentity = false,
    ) {
    }

    public function toArray(): array
    {
        $out = [
            'score' => $this->score,
            'weight' => $this->weight,
            'matched' => $this->matched,
            'required' => $this->required,
            'exact' => $this->exact,
            'fieldsCompared' => $this->fieldsCompared,
            'pieceType' => $this->pieceType,
        ];
        if ($this->uniqueIdentity) {
            $out['uniqueIdentity'] = true;
        }

        return $out;
    }

    public static function fromArray(array $a): self
    {
        return new self(
            (float) ($a['score'] ?? 0), (float) ($a['weight'] ?? 0), (bool) ($a['matched'] ?? false),
            (bool) ($a['required'] ?? false), (bool) ($a['exact'] ?? false), (int) ($a['fieldsCompared'] ?? 0),
            (string) ($a['pieceType'] ?? ''), (bool) ($a['uniqueIdentity'] ?? false),
        );
    }
}
