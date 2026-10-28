<?php

declare(strict_types=1);

namespace Watchman\Index;

use Watchman\Search\Entity;

/**
 * A read-only searchable generation of entities (Go's internal/index corpus).
 *
 * Entity ids ("idx") are dense integers assigned in insertion order.
 * All returned id lists are sorted ascending and unique.
 * $src = '' means all sources, $type = '' means all types.
 */
interface Corpus
{
    public function name(): string;

    /** @return array<string,int> source list => entity count */
    public function sources(): array;

    public function partitionCount(string $src, string $type): int;

    /** @return list<int> */
    public function partitionIds(string $src, string $type): array;

    /**
     * Posting lists restricted to the partition.
     * Kinds: tok (name token), name (exact prepared name), crypto, block (linksim prefix), domain.
     *
     * @param list<string> $keys
     * @return array<string, list<int>> key => ids
     */
    public function postings(string $kind, array $keys, string $src, string $type): array;

    /**
     * Identifier lookups (kinds imo, mmsi, air, email, phone, website): ids whose value equals
     * one of $exact or starts with one of $prefixes, restricted to the partition.
     *
     * @param list<string> $exact
     * @param list<string> $prefixes
     * @return list<int>
     */
    public function idLookup(string $kind, array $exact, array $prefixes, string $src, string $type): array;

    /**
     * @param list<int> $idxs
     * @return array<int, Entity> idx => normalized entity
     */
    public function entities(array $idxs): array;

    public function tfidf(): ?TfIdf;
}
