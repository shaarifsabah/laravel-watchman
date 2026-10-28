<?php

declare(strict_types=1);

namespace Watchman\Search;

/**
 * Port of internal/largest: keeps the highest-weighted items above minMatch.
 * Ties keep insertion order (new items go after equal weights).
 */
final class TopK
{
    private const MAX_CAPACITY = 100;

    /** @var list<array{0:mixed,1:float}> value, weight (descending) */
    private array $items = [];
    private int $capacity;
    private float $minMatch;

    public function __construct(int $capacity, float $minMatch)
    {
        if ($minMatch <= 0.001) {
            $minMatch = 0.01;
        }
        if ($capacity < 0 || $capacity > self::MAX_CAPACITY) {
            $capacity = self::MAX_CAPACITY;
        }
        $this->capacity = $capacity;
        $this->minMatch = $minMatch;
    }

    public function add(mixed $value, float $weight): void
    {
        if ($weight < $this->minMatch || $this->capacity === 0) {
            return;
        }
        $n = count($this->items);
        if ($n >= $this->capacity) {
            if ($weight <= $this->items[$n - 1][1]) {
                return;
            }
            array_pop($this->items);
            $n--;
        }
        // first index whose weight < $weight
        $lo = 0;
        $hi = $n;
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi, 2);
            if ($this->items[$mid][1] < $weight) {
                $hi = $mid;
            } else {
                $lo = $mid + 1;
            }
        }
        array_splice($this->items, $lo, 0, [[$value, $weight]]);
    }

    /** @return list<array{0:mixed,1:float}> */
    public function items(): array
    {
        return $this->items;
    }
}
