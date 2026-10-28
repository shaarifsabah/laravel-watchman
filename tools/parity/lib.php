<?php
require getenv('WATCHMAN_AUTOLOAD') ?: __DIR__ . '/../../vendor/autoload.php';

// Where the Go harness wrote its JSON (PARITY_OUT) and the moov-io/watchman checkout (WATCHMAN_GO_SRC).
define('PARITY_OUT', rtrim(getenv('PARITY_OUT') ?: sys_get_temp_dir(), '/'));
define('WATCHMAN_GO_SRC', rtrim(getenv('WATCHMAN_GO_SRC') ?: __DIR__ . '/../../../watchman', '/'));

function canon($v) {
    if ($v === null) return null;
    if (is_array($v)) {
        if ($v === []) return null;
        $out = [];
        foreach ($v as $k => $x) { $c = canon($x); if ($c !== null && $c !== '' && $c !== false && $c !== 0 && $c !== 0.0) $out[$k] = $c; }
        return $out === [] ? null : $out;
    }
    if (is_float($v)) return round($v, 9);
    return $v;
}
function diff($a, $b, $path = '') {
    $a = canon($a); $b = canon($b);
    if (is_array($a) && is_array($b)) {
        $out = [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
            $out = array_merge($out, diff($a[$k] ?? null, $b[$k] ?? null, "$path/$k"));
        }
        return $out;
    }
    if (is_numeric($a) && is_numeric($b) && abs($a - $b) < 1e-9) return [];
    return $a === $b ? [] : ["$path: go=" . json_encode($a, JSON_UNESCAPED_UNICODE) . " php=" . json_encode($b, JSON_UNESCAPED_UNICODE)];
}
function prepGo(array $p): array {
    return ['name' => $p['Name'] ?? '', 'altNames' => $p['AltNames'] ?? [], 'nameFields' => $p['NameFields'] ?? [],
        'altNameFields' => $p['AltNameFields'] ?? [], 'historicalNames' => $p['HistoricalNames'] ?? [],
        'historicalNameFields' => $p['HistoricalNameFields'] ?? [],
        'phones' => $p['Contact']['phoneNumbers'] ?? [], 'fax' => $p['Contact']['faxNumbers'] ?? [], 'websites' => $p['Contact']['websites'] ?? [],
        'addresses' => $p['Addresses'] ?? [], 'domains' => $p['Domains'] ?? []];
}
function prepPhp(Watchman\Search\Entity $e): array {
    $p = $e->preparedFields->toArray();
    return ['name' => $p['name'], 'altNames' => $p['altNames'], 'nameFields' => $p['nameFields'], 'altNameFields' => $p['altNameFields'],
        'historicalNames' => $p['historicalNames'], 'historicalNameFields' => $p['historicalNameFields'],
        'phones' => $p['contact']['phoneNumbers'], 'fax' => $p['contact']['faxNumbers'], 'websites' => $p['contact']['websites'],
        'addresses' => $p['addresses'], 'domains' => $p['domains']];
}
function compareEntities(array $go, iterable $php, string $label): void {
    // Some lists repeat a sourceID (e.g. CSL), so match records by ID in file order.
    $byId = [];
    foreach ($go as $g) { $byId[$g['entity']['sourceID']][] = $g; }
    $n = 0; $bad = 0; $fields = []; $examples = [];
    foreach ($php as $e) {
        $n++;
        $g = isset($byId[$e->sourceId]) ? array_shift($byId[$e->sourceId]) : null;
        if ($g === null) { $bad++; $examples[] = "missing in go: {$e->sourceId}"; continue; }
        if ($byId[$e->sourceId] === []) { unset($byId[$e->sourceId]); }
        $d = array_merge(diff($g['entity'], json_decode(json_encode($e->toArray()), true)), diff(prepGo($g['prepared']), prepPhp($e), '/prepared'));
        if ($d) {
            $bad++;
            foreach ($d as $x) { $f = preg_replace('#/\d+#', '/#', explode(':', $x)[0]); $fields[$f] = ($fields[$f] ?? 0) + 1; }
            if (count($examples) < 12) $examples[] = $e->sourceId . ' ' . $d[0];
        }
    }
    echo "$label: php=$n go=" . count($go) . " mismatching=$bad missing_in_php=" . count($byId) . "\n";
    arsort($fields); print_r(array_slice($fields, 0, 15, true)); foreach ($examples as $x) echo "  $x\n";
}
