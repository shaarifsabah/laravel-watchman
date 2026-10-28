<?php
require __DIR__ . '/lib.php';
use Watchman\Norm\{Country, Phone, Website, Domain}; use Watchman\Support\Go; use Watchman\StringScore\JaroWinkler; use Watchman\Search\Entity;
function report($label, $bad, $n, $ex) { echo "$label: $n cases, mismatching=$bad\n"; foreach (array_slice($ex,0,8) as $e) echo "   $e\n"; }
// norm
$go = json_decode(file_get_contents(PARITY_OUT . '/go_norm.json'), true); $bad=0; $ex=[];
foreach ($go as $g) { $in=$g['input'];
  $php = ['country'=>Country::normalize($in['country']), 'phoneNumber'=>Phone::normalize($in['phone']), 'website'=>Website::normalize($in['website']), 'domainKeys'=>Domain::keys($in['website']), 'domainKeysFromEmail'=>Domain::keysFromEmail($in['email'])];
  foreach ($php as $k=>$v) { $gv=$g[$k] ?? null; if (canon($gv)!==canon($v)) { $bad++; $ex[]="$k ".json_encode($in[$k==='country'?'country':($k==='phoneNumber'?'phone':($k==='domainKeysFromEmail'?'email':'website'))], JSON_UNESCAPED_UNICODE)." go=".json_encode($gv, JSON_UNESCAPED_UNICODE)." php=".json_encode($v, JSON_UNESCAPED_UNICODE); } } }
report('norm', $bad, count($go)*5, $ex);
// address
$go = json_decode(file_get_contents(PARITY_OUT . '/go_addr.json'), true); $bad=0; $ex=[];
foreach ($go as $g) { $p = Watchman\Address\UsAddress::parse($g['input'])->toArray(); if ($d = diff($g['parsed'], $p)) { $bad++; $ex[] = json_encode($g['input'])." ".$d[0]; } }
report('address', $bad, count($go), $ex);
// prepare
$go = json_decode(file_get_contents(PARITY_OUT . '/go_prep.json'), true); $bad=0; $ex=[];
foreach ($go as $g) { $in=$g['input']; $e=(new Entity($in['name'], $in['type']))->normalize(); $pf=$e->preparedFields;
  $d = diff(['n'=>$g['prepared']['Name'],'f'=>$g['prepared']['NameFields'],'h'=>$g['prepared']['HistoricalNameFields'],'l'=>$g['lowerAndRemovePunctuation'],'s'=>$g['removeStopwords']],
            ['n'=>$pf->name,'f'=>$pf->nameFields,'h'=>$pf->historicalNameFields,'l'=>Watchman\Prepare\Normalize::lowerAndRemovePunctuation($in['name']),'s'=>Watchman\Prepare\Stopwords::remove(Watchman\Prepare\Normalize::lowerAndRemovePunctuation($in['name']))]);
  if ($d) { $bad++; $ex[] = json_encode($in['name'], JSON_UNESCAPED_UNICODE)." ".$d[0]; } }
report('prepare', $bad, count($go), $ex);
// jw
$go = json_decode(file_get_contents(PARITY_OUT . '/go_jw.json'), true); $bad=0; $ex=[];
foreach ($go as $g) { [$a,$b]=[$g['input']['a'],$g['input']['b']];
  $php=[JaroWinkler::smetrics($a,$b,0.7,4), JaroWinkler::bestPairCombination(Go::fields($a),Go::fields($b)), JaroWinkler::jaroWinkler($a,$b)];
  $gv=[$g['smetricsJaroWinkler'],$g['bestPairCombinationJaroWinkler'],$g['stringscoreJaroWinkler']];
  foreach ($php as $k=>$v) if (abs($v-$gv[$k])>1e-12) { $bad++; $ex[]="$k $a | $b go={$gv[$k]} php=$v"; } }
report('jaro-winkler', $bad, count($go)*3, $ex);
