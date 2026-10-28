<?php

declare(strict_types=1);

namespace Watchman\Index\Redis;

use Watchman\Index\CandidateSelector;
use Watchman\Index\Codec;
use Watchman\Index\Corpus;
use Watchman\Index\TfIdf;

final class RedisCorpus implements Corpus
{
    private ?TfIdf $tfidf = null;

    public function __construct(
        private RedisClient $redis,
        private string $prefix,
        private string $name,
        private string $gen,
        private array $meta,
        array $tfidfConfig = [],
    ) {
        if (!empty($meta['tfidf']['enabled'])) {
            $this->tfidf = TfIdf::fromConfig($tfidfConfig + ['enabled' => true], (int) ($meta['tfidf']['documents'] ?? 0), function (array $terms): array {
                $vals = $this->redis->command('HMGET', $this->key('df'), ...$terms);
                $out = [];
                foreach ($terms as $i => $t) {
                    $out[$t] = (int) ($vals[$i] ?? 0);
                }

                return $out;
            });
        }
    }

    public function meta(): array
    {
        return $this->meta;
    }

    public function generation(): string
    {
        return $this->gen;
    }

    private function key(string $suffix): string
    {
        return $this->prefix . $this->gen . ':' . $suffix;
    }

    public static function partitionSuffix(string $src, string $type): string
    {
        return 'p:' . ($src === '' ? '*' : $src) . ':' . ($type === '' ? '*' : $type);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function sources(): array
    {
        return array_map('intval', (array) ($this->meta['sources'] ?? []));
    }

    public function partitionCount(string $src, string $type): int
    {
        return (int) $this->redis->command('SCARD', $this->key(self::partitionSuffix($src, $type)));
    }

    public function partitionIds(string $src, string $type): array
    {
        $ids = array_map('intval', (array) $this->redis->command('SMEMBERS', $this->key(self::partitionSuffix($src, $type))));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    public function postings(string $kind, array $keys, string $src, string $type): array
    {
        if ($keys === []) {
            return [];
        }
        $part = $this->key(self::partitionSuffix($src, $type));
        $all = $src === '' && $type === '';
        $cmds = [];
        foreach ($keys as $k) {
            $cmds[] = $all ? ['SMEMBERS', $this->key("k:$kind:$k")] : ['SINTER', $this->key("k:$kind:$k"), $part];
        }
        $res = $this->redis->pipeline($cmds);
        $out = [];
        foreach (array_values($keys) as $i => $k) {
            $ids = array_map('intval', is_array($res[$i] ?? null) ? $res[$i] : []);
            sort($ids, SORT_NUMERIC);
            $out[$k] = $ids;
        }

        return $out;
    }

    public function idLookup(string $kind, array $exact, array $prefixes, string $src, string $type): array
    {
        $key = $this->key("i:$kind");
        $cmds = [];
        foreach ($exact as $v) {
            $cmds[] = ['ZRANGEBYLEX', $key, '[' . $v . "\x00", '(' . $v . "\x01"];
        }
        foreach ($prefixes as $p) {
            $cmds[] = ['ZRANGEBYLEX', $key, '[' . $p, '(' . $p . "\xff"];
        }
        $ids = [];
        foreach ($this->redis->pipeline($cmds) as $members) {
            foreach ((array) $members as $m) {
                $pos = strrpos((string) $m, "\x00");
                if ($pos !== false) {
                    $ids[(int) substr((string) $m, $pos + 1)] = true;
                }
            }
        }
        $ids = array_keys($ids);
        sort($ids, SORT_NUMERIC);
        if ($ids === [] || ($src === '' && $type === '')) {
            return $ids;
        }
        $member = $this->redis->command('SMISMEMBER', $this->key(self::partitionSuffix($src, $type)), ...array_map('strval', $ids));
        $out = [];
        foreach ($ids as $i => $id) {
            if ((int) ($member[$i] ?? 0) === 1) {
                $out[] = $id;
            }
        }

        return $out;
    }

    public function entities(array $idxs): array
    {
        if ($idxs === []) {
            return [];
        }
        $vals = $this->redis->command('HMGET', $this->key('e'), ...array_map('strval', $idxs));
        $vals = is_array($vals) ? array_values($vals) : [];
        $out = [];
        foreach (array_values($idxs) as $i => $idx) {
            if (is_string($vals[$i] ?? null) && $vals[$i] !== '') {
                $out[$idx] = Codec::decode($vals[$i]);
            }
        }

        return $out;
    }

    public function tfidf(): ?TfIdf
    {
        return $this->tfidf;
    }

    /** @internal for tests */
    public function intersect(array $a, array $b): array
    {
        return CandidateSelector::intersect($a, $b);
    }
}
