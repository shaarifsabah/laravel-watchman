<?php

declare(strict_types=1);

namespace Watchman\Index;

/**
 * In-process index store. Useful for tests, CLI tools, Octane/long-running
 * workers, or small custom lists. Data is lost when the process exits.
 */
final class MemoryStore implements IndexStore
{
    /** @var array<string, MemoryCorpus> */
    private array $corpora = [];
    /** @var array<string, array> */
    private array $meta = [];

    public function corpora(): array
    {
        return $this->corpora;
    }

    public function corpus(string $name): ?Corpus
    {
        return $this->corpora[$name] ?? null;
    }

    public function writer(string $name, ?TfIdf $tfidf = null): CorpusWriter
    {
        $corpus = new MemoryCorpus($name, $tfidf);

        return new class ($this, $corpus) implements CorpusWriter {
            private array $lists = [];

            public function __construct(private MemoryStore $store, private MemoryCorpus $corpus)
            {
            }

            public function add(\Watchman\Search\Entity $entity): void
            {
                $this->corpus->add($entity);
            }

            public function finishList(string $source, int $count, string $hash): void
            {
                $this->lists[$source] = ['count' => $count, 'hash' => $hash];
            }

            public function commit(array $meta = []): void
            {
                $meta['lists'] = array_map(static fn ($l) => $l['count'], $this->lists) + ($meta['lists'] ?? []);
                $meta['listHashes'] = array_map(static fn ($l) => $l['hash'], $this->lists) + ($meta['listHashes'] ?? []);
                $this->store->publish($this->corpus, $meta);
            }

            public function abort(): void
            {
            }
        };
    }

    /** @internal */
    public function publish(MemoryCorpus $corpus, array $meta): void
    {
        $this->corpora[$corpus->name()] = $corpus;
        $this->meta[$corpus->name()] = $meta;
    }

    public function drop(string $name): void
    {
        unset($this->corpora[$name], $this->meta[$name]);
    }

    public function meta(): array
    {
        return $this->meta;
    }
}
