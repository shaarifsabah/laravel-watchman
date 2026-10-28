<?php
require __DIR__ . '/lib.php';
use Watchman\Search\{Entity, Similarity};
$pairs = json_decode(file_get_contents(PARITY_OUT . '/pairs.json'), true);
$go = json_decode(file_get_contents(PARITY_OUT . '/go_sim.json'), true);
$bad = 0; $pieceBad = []; $maxd = 0; $ex = [];
foreach ($pairs as $i => $p) {
    $q = Entity::fromArray($p['query'])->normalize();
    $x = Entity::fromArray($p['index'])->normalize();
    $s = Similarity::score($q, $x);
    $d = Similarity::detailed($q, $x);
    $g = $go[$i]['similarity'];
    $diff = abs($s - $g);
    $maxd = max($maxd, $diff);
    if ($diff > 1e-9 || abs($d->finalScore - ($go[$i]['detailed']['finalScore'] ?? 0)) > 1e-9) {
        $bad++;
        foreach ($d->pieces as $k => $piece) {
            $gp = $go[$i]['detailed']['pieces'][$k] ?? null;
            if ($gp && abs(($gp['score'] ?? 0) - $piece->score) > 1e-9) { $pieceBad[$piece->pieceType] = ($pieceBad[$piece->pieceType] ?? 0) + 1; }
        }
        if (count($ex) < 10) $ex[] = sprintf("#%d go=%.6f php=%.6f q=%s idx=%s(%s)", $i, $g, $s, json_encode($p['query']['name']), $p['index']['name'], $p['index']['sourceID']);
    }
}
echo "pairs=" . count($pairs) . " mismatching=$bad maxdiff=$maxd\n"; print_r($pieceBad); echo implode("\n", $ex), "\n";
