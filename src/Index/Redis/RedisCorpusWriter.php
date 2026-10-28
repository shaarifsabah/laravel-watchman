<?php

declare(strict_types=1);

namespace Watchman\Index\Redis;

use Watchman\Index\Codec;
use Watchman\Index\CorpusWriter;
use Watchman\Index\IndexRecord;
use Watchman\Search\Entity;

final class RedisCorpusWriter implements CorpusWriter
{
    private int $next = 0;
    /** @var list<list<string>> */
    private array $buffer = [];
    private array $lists = [];
    private array $sources = [];
    private int $documents = 0;

    public function __construct(
        private RedisStore $store,
        private RedisClient $redis,
        private string $prefix,
        private string $name,
        private string $gen,
        private bool $tfidf = false,
        private int $batch = 2000,
    ) {
    }

    private function key(string $suffix): string
    {
        return $this->prefix . $this->gen . ':' . $suffix;
    }

    public function add(Entity $entity): void
    {
        $i = (string) $this->next++;
        $src = $entity->source;
        $type = $entity->type;
        $this->sources[$src] = ($this->sources[$src] ?? 0) + 1;

        $b = &$this->buffer;
        $b[] = ['HSET', $this->key('e'), $i, Codec::encode($entity)];
        $parts = [['', '']];
        if ($src !== '') {
            $parts[] = [$src, ''];
        }
        if ($type !== '') {
            $parts[] = ['', $type];
        }
        if ($src !== '' && $type !== '') {
            $parts[] = [$src, $type];
        }
        foreach ($parts as [$s, $t]) {
            $b[] = ['SADD', $this->key(RedisCorpus::partitionSuffix($s, $t)), $i];
        }
        $r = IndexRecord::fromEntity($entity);
        if ($r->name !== '') {
            $b[] = ['SADD', $this->key('k:name:' . $r->name), $i];
        }
        foreach (['tok' => $r->tokens, 'crypto' => $r->crypto, 'block' => $r->block, 'domain' => $r->domains] as $kind => $keys) {
            foreach ($keys as $k) {
                $b[] = ['SADD', $this->key("k:$kind:$k"), $i];
            }
        }
        foreach ($r->ids as $kind => $vals) {
            foreach ($vals as $v) {
                $b[] = ['ZADD', $this->key("i:$kind"), '0', $v . "\x00" . $i];
            }
        }
        if ($this->tfidf) {
            $pf = $entity->preparedFields;
            foreach (array_merge([$pf->nameFields], $pf->altNameFields) as $doc) {
                if ($doc === []) {
                    continue;
                }
                $this->documents++;
                foreach (array_unique($doc) as $term) {
                    if ($term !== '') {
                        $b[] = ['HINCRBY', $this->key('df'), (string) $term, '1'];
                    }
                }
            }
        }
        if (count($b) >= $this->batch) {
            $this->flush();
        }
    }

    private function flush(): void
    {
        if ($this->buffer !== []) {
            $this->redis->pipeline($this->buffer);
            $this->buffer = [];
        }
    }

    public function finishList(string $source, int $count, string $hash): void
    {
        $this->lists[$source] = ['count' => $count, 'hash' => $hash];
    }

    public function commit(array $meta = []): void
    {
        $this->flush();
        $meta['lists'] = array_map(static fn ($l) => $l['count'], $this->lists) + ($meta['lists'] ?? []);
        $meta['listHashes'] = array_map(static fn ($l) => $l['hash'], $this->lists) + ($meta['listHashes'] ?? []);
        $meta['sources'] = $this->sources;
        $meta['entities'] = $this->next;
        $meta['tfidf'] = ['enabled' => $this->tfidf, 'documents' => $this->documents];
        $this->store->activate($this->name, $this->gen, $meta);
    }

    public function abort(): void
    {
        $this->buffer = [];
        $this->store->deleteGeneration($this->gen);
    }
}
