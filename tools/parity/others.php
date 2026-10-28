<?php
require __DIR__ . '/lib.php';
compareEntities(json_decode(file_get_contents(PARITY_OUT . '/go_csl.json'), true), Watchman\Sources\CslUs\CslUsSource::entities(WATCHMAN_GO_SRC . '/pkg/sources/csl_us/testdata/consolidated.csv'), 'CSL');
compareEntities(json_decode(file_get_contents(PARITY_OUT . '/go_sz.json'), true), Watchman\Sources\Senzing\SenzingReader::readFile(WATCHMAN_GO_SRC . '/test/testdata/opensanctions/us_congress_senzing.json', 'us_congress'), 'Senzing us_congress');
compareEntities(json_decode(file_get_contents(PARITY_OUT . '/go_peps.json'), true), Watchman\Sources\Senzing\SenzingReader::readFile(WATCHMAN_GO_SRC . '/test/testdata/opensanctions/peps_senzing.json', 'peps'), 'Senzing peps');
$m = Watchman\Sources\FinCen311\FinCen311Source::parse(file_get_contents((getenv('FINCEN_311_HTML') ?: PARITY_OUT . '/fincen_311.html')));
compareEntities(json_decode(file_get_contents(PARITY_OUT . '/go_fincen.json'), true), array_map([Watchman\Sources\FinCen311\FinCen311Source::class, 'toEntity'], $m), 'FinCEN');
