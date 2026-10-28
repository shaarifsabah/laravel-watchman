<?php

declare(strict_types=1);

namespace Watchman\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Watchman\Laravel\Facades\Watchman;

final class WatchmanApiTest extends TestCase
{
    use RefreshDatabase;

    private function refreshLists(): array
    {
        return Watchman::refresh();
    }

    public function test_refresh_indexes_configured_lists(): void
    {
        $stats = $this->refreshLists();

        $this->assertSame(257, $stats['lists']['us_ofac']);
        $this->assertSame(99, $stats['lists']['us_csl']); // one SDN-sourced row is skipped, as in Go
        $this->assertSame(50, $stats['lists']['us_congress']);
        $this->assertSame(64, strlen($stats['listHashes']['us_ofac']));

        $info = Watchman::listInfo();
        $this->assertSame(257, ((array) $info['lists'])['us_ofac']);
        $this->assertSame('succeeded', Watchman::refreshStatus()['state']);
    }

    public function test_search_scores_match_watchman_readme(): void
    {
        $this->refreshLists();

        // README: name only scores 0.767 and is below the 0.80 cutoff
        $res = Watchman::search(['type' => 'person', 'name' => 'Dmitry Khoroshev', 'limit' => 1]);
        $this->assertSame('48603', $res->first()->sourceId);
        $this->assertEqualsWithDelta(0.767, $res->first()->match, 0.001);
        $this->assertTrue(Watchman::search(['type' => 'person', 'name' => 'Dmitry Khoroshev', 'minMatch' => 0.80])->isEmpty());

        // README: adding the date of birth clears 0.80 (0.867)
        $res = Watchman::searchPerson('Dmitry Khoroshev', ['birthDate' => '1993-04-17', 'minMatch' => 0.80]);
        $this->assertEqualsWithDelta(0.867, $res->first()->match, 0.001);
        $this->assertTrue(Watchman::isMatch(['type' => 'person', 'name' => 'Dmitry Khoroshev', 'birthDate' => '1993-04-17']));
    }

    public function test_search_fails_loudly_when_no_lists_are_loaded(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no sanctions lists are loaded');

        Watchman::search(['type' => 'person', 'name' => 'Nicolas Maduro']);
    }

    public function test_facade_rejects_unknown_parameters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('extra/unused query parameters in request: birthdate');

        Watchman::search(['type' => 'person', 'name' => 'Dmitry Khoroshev', 'birthdate' => '1993-04-17', 'format' => 'senzing']);
    }

    public function test_http_search_endpoint(): void
    {
        $this->refreshLists();

        $this->getJson('/watchman/v2/search?type=person&name=Nicolas+Maduro&limit=2')
            ->assertOk()
            ->assertJsonPath('entities.0.sourceID', '22790')
            ->assertJsonPath('entities.0.sourceList', 'us_ofac')
            ->assertJsonPath('query.person.name', 'Nicolas Maduro');

        $this->getJson('/watchman/v2/search?name=x&bogus=1')
            ->assertStatus(400)
            ->assertJsonPath('error', 'extra/unused query parameters in request: bogus');

        $this->getJson('/watchman/v2/search?name=x&algorithm=nope')->assertStatus(400);

        $debug = $this->getJson('/watchman/v2/search?type=person&name=Dmitry+Khoroshev&limit=1&debug=true')->assertOk()->json('entities.0');
        $this->assertNotEmpty($debug['debug']);
        $this->assertCount(9, $debug['details']['pieces']);

        $this->get('/watchman/v2/search?type=person&name=Dmitry+Khoroshev&limit=1&format=senzing/jsonl')
            ->assertOk()
            ->assertSee('"RECORD_ID":"48603"', false);

        $this->getJson('/watchman/v2/listinfo')->assertOk()->assertJsonPath('lists.us_ofac', 257);
        $this->get('/watchman/ping')->assertOk()->assertSee('PONG');
    }

    public function test_source_filter_and_senzing_list(): void
    {
        $this->refreshLists();

        $res = Watchman::search(['type' => 'person', 'name' => 'Jennifer McClellan', 'source' => 'us_congress', 'limit' => 1]);
        $this->assertSame('us_congress', $res->first()->source);
        $this->assertTrue(Watchman::search(['name' => 'Jennifer McClellan', 'source' => 'unknown_list'])->isEmpty());
    }

    public function test_ingest_csv_file(): void
    {
        $this->refreshLists();
        $csv = file_get_contents(dirname(__DIR__) . '/fixtures/fincen-person.csv');

        $this->call('POST', '/watchman/v2/ingest/fincen-person', [], [], [], ['CONTENT_TYPE' => 'text/csv'], $csv)
            ->assertOk()
            ->assertJsonPath('fileType', 'fincen-person');

        $res = Watchman::search(['type' => 'person', 'name' => 'John K Doe1', 'source' => 'fincen-person', 'limit' => 1]);
        $this->assertSame('fincen-person', $res->first()?->source);

        $this->call('POST', '/watchman/v2/ingest/unknown', [], [], [], [], 'a,b')->assertStatus(400);
    }

    public function test_refresh_endpoint_runs_job(): void
    {
        $this->postJson('/watchman/v2/data/refresh')->assertStatus(202);
        $this->getJson('/watchman/v2/data/refresh')->assertOk()->assertJsonPath('state', 'succeeded');
    }
}
