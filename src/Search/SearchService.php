<?php

declare(strict_types=1);

namespace Watchman\Search;

use Watchman\Index\CandidateSelector;
use Watchman\Index\Candidates;
use Watchman\Index\IndexStore;
use Watchman\Index\TfIdf;

/**
 * Port of internal/search/service.go performSearch: select candidates, score
 * them, keep the top-K above minMatch, optionally attach debug details.
 */
final class SearchService
{
    public function __construct(
        private IndexStore $store,
        private int $chunkSize = 500,
    ) {
    }

    /** @return list<SearchedEntity> */
    public function search(Entity $query, SearchOptions $opts): array
    {
        $candidates = $this->selectCandidates($query);
        $tfidf = null;
        foreach ($candidates as $c) {
            $tfidf ??= $c->corpus->tfidf();
        }
        if ($tfidf !== null && $tfidf->enabled() && $query->preparedFields->nameFields !== []) {
            $query = clone $query;
            $query->preparedFields->nameWeights = $tfidf->weights($query->preparedFields->nameFields);
        }
        $simOpts = new SimilarityOpts($tfidf, $opts->algorithm);
        $top = new TopK($opts->limit, $opts->minMatch);

        foreach ($candidates as $cands) {
            foreach ($cands->chunks($this->chunkSize) as $chunk) {
                foreach ($cands->corpus->entities($chunk) as $entity) {
                    if ($tfidf !== null && $tfidf->enabled()) {
                        $entity = $this->withWeights($entity, $tfidf);
                    }
                    $top->add($entity, Similarity::score($query, $entity, $simOpts));
                }
            }
        }

        $out = [];
        foreach ($top->items() as [$entity, $weight]) {
            if ($entity->sourceId === '' || $weight <= 0.001) {
                continue;
            }
            $out[] = SearchedEntity::fromEntity($entity, $weight);
        }
        if ($opts->debug) {
            foreach ($out as $hit) {
                if ($hit->match < $opts->debugMinMatch) {
                    continue;
                }
                $log = new DebugLog();
                $hit->details = Similarity::detailed($query, $hit, $simOpts, $log);
                $hit->debug = base64_encode($log->contents());
            }
        }

        return $out;
    }

    /**
     * Mirrors index.Lists.SelectCandidates routing between the downloaded-lists
     * corpus and ingested-file corpora.
     *
     * @return list<Candidates>
     */
    public function selectCandidates(Entity $query): array
    {
        $corpora = $this->store->corpora();
        $lists = $corpora[IndexStore::LISTS] ?? null;
        $src = $query->source;

        if ($src === '') {
            $out = [];
            foreach ($corpora as $corpus) {
                $c = CandidateSelector::select($corpus, $query);
                if ($c->count > 0) {
                    $out[] = $c;
                }
            }

            return $out;
        }
        if (SourceList::isRequestType($src)) {
            return $lists !== null ? [CandidateSelector::select($lists, $query)] : [];
        }
        if ($lists !== null && array_key_exists($src, $lists->sources())) {
            return [CandidateSelector::select($lists, $query)];
        }
        foreach ($corpora as $name => $corpus) {
            if ($name !== IndexStore::LISTS && array_key_exists($src, $corpus->sources())) {
                return [CandidateSelector::select($corpus, $query)];
            }
        }

        return [];
    }

    private function withWeights(Entity $e, TfIdf $tfidf): Entity
    {
        if ($e->preparedFields->nameWeights !== [] || $e->preparedFields->nameFields === []) {
            return $e;
        }
        $e->preparedFields->nameWeights = $tfidf->weights($e->preparedFields->nameFields);
        $e->preparedFields->altNameWeights = array_map(static fn ($f) => $tfidf->weights($f), $e->preparedFields->altNameFields);
        $e->preparedFields->historicalNameWeights = array_map(static fn ($f) => $tfidf->weights($f), $e->preparedFields->historicalNameFields);

        return $e;
    }
}
