<?php

declare(strict_types=1);

namespace Watchman\Search;

/**
 * A search hit: the list entity plus its match score (pkg/search.SearchedEntity).
 */
final class SearchedEntity extends Entity
{
    public float $match = 0.0;
    /** base64 human-readable debug output (only when debug was requested) */
    public string $debug = '';
    public ?SimilarityScore $details = null;

    public static function fromEntity(Entity $e, float $match): self
    {
        $out = new self();
        foreach (get_object_vars($e) as $k => $v) {
            $out->{$k} = $v;
        }
        $out->match = $match;

        return $out;
    }

    public function toArray(): array
    {
        $out = parent::toArray();
        $out['match'] = $this->match;
        if ($this->debug !== '') {
            $out['debug'] = $this->debug;
        }
        if ($this->details !== null && $this->details->toArray() !== []) {
            $out['details'] = $this->details->toArray();
        }

        return $out;
    }

    public static function fromArray(array $a): static
    {
        $e = parent::fromArray($a);
        $e->match = (float) ($a['match'] ?? 0);
        $e->debug = (string) ($a['debug'] ?? '');
        $e->details = isset($a['details']) && is_array($a['details']) ? SimilarityScore::fromArray($a['details']) : null;

        return $e;
    }
}
