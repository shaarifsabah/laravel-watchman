<?php

declare(strict_types=1);

namespace Watchman\Index;

/**
 * Storage backend for corpora. The "lists" corpus holds downloaded lists;
 * "ingest:<fileType>" corpora hold uploaded files.
 */
interface IndexStore
{
    public const LISTS = 'lists';

    /** @return array<string, Corpus> active corpora keyed by name */
    public function corpora(): array;

    public function corpus(string $name): ?Corpus;

    public function writer(string $name, ?TfIdf $tfidf = null): CorpusWriter;

    public function drop(string $name): void;

    /**
     * Metadata saved on commit (lists, listHashes, startedAt, endedAt, …) per corpus.
     *
     * @return array<string, array>
     */
    public function meta(): array;
}
