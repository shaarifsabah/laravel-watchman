<?php
require __DIR__ . '/lib.php';
$go = json_decode(file_get_contents(PARITY_OUT . '/go_ofac.json'), true);
$d = WATCHMAN_GO_SRC . '/pkg/sources/ofac/testdata';
$res = Watchman\Sources\Ofac\OfacReader::read(['add.csv'=>"$d/add.csv",'alt.csv'=>"$d/alt.csv",'sdn.csv'=>"$d/sdn.csv",'sdn_comments.csv'=>"$d/sdn_comments.csv"]);
compareEntities($go, Watchman\Sources\Ofac\OfacMapper::groupIntoEntities($res), 'OFAC');
