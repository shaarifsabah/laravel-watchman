# Laravel Watchman

Sanctions and watchlist screening for Laravel: a native PHP port of [moov-io/watchman](https://github.com/moov-io/watchman) (Go), based on upstream commit `7f44b4b6`.

The package downloads government sanctions lists, normalizes every name exactly the way Watchman does, and indexes them in **Redis** (or your SQL database). It then screens people, businesses, vessels and aircraft with Watchman's multi-field matcher. Every hit gets a score from **0 to 1**, and you can ask why it scored that way.

You do **not** need the Go server. If you already run one, set `WATCHMAN_DRIVER=http` and the same Laravel API talks to it instead.

> This is an independent community port. It is not an official Moov product and is not affiliated with or endorsed by Moov Financial. Screening results support, and do not replace, your own compliance review.

---

## Contents

1. [What you get](#1-what-you-get)
2. [Requirements](#2-requirements)
3. [Installation](#3-installation)
4. [Choosing a store (Redis / database / memory)](#4-choosing-a-store)
5. [Configuration reference](#5-configuration-reference)
6. [Loading the lists (refresh)](#6-loading-the-lists-refresh)
7. [Searching from PHP (facade)](#7-searching-from-php)
8. [Search parameters reference](#8-search-parameters-reference)
9. [Reading results](#9-reading-results)
10. [Understanding scores and `minMatch`](#10-understanding-scores-and-minmatch)
11. [Debugging a score](#11-debugging-a-score)
12. [HTTP API](#12-http-api)
13. [Artisan commands](#13-artisan-commands)
14. [Custom lists: file ingestion](#14-custom-lists-file-ingestion)
15. [Adding OpenSanctions / Senzing lists](#15-adding-opensanctions--senzing-lists)
16. [Offline / cached list files](#16-offline--cached-list-files)
17. [Adding your own list source in code](#17-adding-your-own-list-source-in-code)
18. [Using the Go server instead (http driver)](#18-using-the-go-server-instead-http-driver)
19. [Using the engine without Laravel](#19-using-the-engine-without-laravel)
20. [Tuning the scorer](#20-tuning-the-scorer)
21. [Real-world recipes](#21-real-world-recipes)
22. [Performance and sizing](#22-performance-and-sizing)
23. [Troubleshooting](#23-troubleshooting)
24. [How faithful is the port?](#24-how-faithful-is-the-port)
25. [Testing and development](#25-testing-and-development)
26. [License](#26-license)

---

## 1. What you get

| Watchman (Go) | This package |
|---|---|
| `GET /v2/search`, `GET /v2/listinfo`, `GET/POST /v2/data/refresh`, `POST /v2/ingest/{fileType}` | Same routes under `/watchman/...` (prefix configurable) |
| In-memory index rebuilt every 12h | Redis, database or in-memory index, rebuilt by a scheduled queued job; the new index is switched in atomically |
| `pkg/search` scoring | `Watchman\Search\Similarity` (line-by-line port, verified against Go) |
| OFAC SDN, OFAC Non-SDN, US CSL, FinCEN 311, EU, UK, UN, US TEL, OpenSanctions/Senzing | Same readers and mappers |
| CSV / Senzing file ingestion | `Watchman::ingest()` and `POST /v2/ingest/{fileType}` |
| YAML config + env vars | `config/watchman.php` + the same env var names |

Built-in list names:

| Name | List |
|---|---|
| `us_ofac` | OFAC Specially Designated Nationals (SDN) |
| `us_non_sdn` | OFAC Consolidated (Non-SDN) lists |
| `us_csl` | US Consolidated Screening List (Commerce, State, Treasury) |
| `us_fincen_311` | FinCEN Section 311 / 9714 special measures |
| `eu_csl` | EU Consolidated Financial Sanctions List |
| `uk_csl` | UK Sanctions List (FCDO) |
| `un_csl` | UN Security Council Consolidated List |
| `us_tel` | US State Department Terrorist Exclusion List |
| *any name* | Any Senzing-format dataset (OpenSanctions or your own) |

---

## 2. Requirements

- **PHP:** 8.2 or newer. You need the `intl`, `mbstring`, `dom`, `simplexml` and `curl` extensions.
  - `igbinary` is recommended, because it makes the index smaller and faster.
  - `zip` is needed only to read the UK list from an `.ods` file.
- **Laravel:** 12 or 13. (Laravel 10 and 11 no longer receive security fixes, and Composer refuses to install them.)
- **For the default store:** Redis, with either phpredis (`REDIS_CLIENT=phpredis`, the Laravel default) or predis.
- **Without Redis:** any database Laravel supports (MySQL, PostgreSQL, SQLite or SQL Server), used through the `database` store.

---

## 3. Installation

```bash
composer require shaarifsabah/laravel-watchman
```

To work on the package itself from a local checkout instead, use a path repository:

```bash
# in your Laravel app
composer config repositories.watchman '{"type":"path","url":"../laravel-watchman","options":{"symlink":true}}'
composer require "shaarifsabah/laravel-watchman:dev-main"
```

### Then

```bash
php artisan vendor:publish --tag=watchman-config   # creates config/watchman.php
php artisan migrate                                 # watchman_* tables (ingested files; index tables used by the database store)
php artisan watchman:refresh                        # first download + index build
php artisan watchman:status                         # check what is loaded
```

The service provider (`Watchman\Laravel\WatchmanServiceProvider`) and the `Watchman` facade are auto-discovered.

If you also want the migration file in your own `database/migrations`, publish it:

```bash
php artisan vendor:publish --tag=watchman-migrations
```

---

## 4. Choosing a store

Set the store with `WATCHMAN_STORE`:

| Store | When to use it | Notes |
|---|---|---|
| `redis` (default) | Production. A single index shared by every PHP-FPM, queue or Octane worker; long-running workers pick up each refresh automatically. | Fastest. Needs a Redis that never evicts keys. |
| `database` | No Redis available. | Uses the `watchman_*` tables. Searches become indexed SQL lookups. |
| `memory` | Tests, one-off scripts, long-running workers. | Lives only inside the current PHP process. |

### Redis setup

Give Watchman its **own** connection, so that `cache:clear` can never wipe the index and LRU eviction can never drop part of it.

```php
// config/database.php → 'redis'
'watchman' => [
    'url' => env('REDIS_URL'),
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'username' => env('REDIS_USERNAME'),
    'password' => env('REDIS_PASSWORD'),
    'port' => env('REDIS_PORT', '6379'),
    'database' => env('WATCHMAN_REDIS_DB', '3'),
],
```

```dotenv
WATCHMAN_STORE=redis
WATCHMAN_REDIS_CONNECTION=watchman
WATCHMAN_REDIS_PREFIX=watchman:
```

On that Redis server, set:

```
maxmemory-policy noeviction
```

`php artisan watchman:status` prints a warning if the policy allows eviction.

Redis keeps everything in memory. If it restarts without RDB/AOF persistence, the index is gone: run `php artisan watchman:refresh` again. Ingested files are kept in the database and are re-indexed automatically during every refresh.

### Database setup

```dotenv
WATCHMAN_STORE=database
WATCHMAN_DB_CONNECTION=mysql   # optional, defaults to your default connection
```

Run `php artisan migrate` before the first refresh.

### Memory store

```dotenv
WATCHMAN_STORE=memory
```

Each PHP process must call `Watchman::refresh()` itself. This suits tests and Octane, not PHP-FPM.

---

## 5. Configuration reference

Every key in `config/watchman.php`, with its environment variable:

### Driver and stores

| Key | Env | Default | Meaning |
|---|---|---|---|
| `driver` | `WATCHMAN_DRIVER` | `native` | `native` runs the PHP engine; `http` calls a Go Watchman server |
| `http.base_url` | `WATCHMAN_URL` | `http://localhost:8084` | Go server URL (http driver only) |
| `http.timeout` | `WATCHMAN_HTTP_TIMEOUT` | `10` | Seconds |
| `http.headers` | – | `[]` | Extra headers, e.g. for an auth proxy |
| `store` | `WATCHMAN_STORE` | `redis` | `redis`, `database` or `memory` |
| `redis.connection` | `WATCHMAN_REDIS_CONNECTION` | `default` | Name from `config/database.php` |
| `redis.prefix` | `WATCHMAN_REDIS_PREFIX` | `watchman:` | Key prefix |
| `database.connection` | `WATCHMAN_DB_CONNECTION` | default connection | |
| `database.table_prefix` | – | `watchman_` | |

### Downloads (Watchman's `Download` section)

| Key | Env | Default |
|---|---|---|
| `download.included_lists` | `WATCHMAN_INCLUDED_LISTS` or `INCLUDED_LISTS` (comma separated) | `us_ofac,us_non_sdn,us_csl,us_fincen_311` |
| `download.ignored_download_errors` | `WATCHMAN_IGNORED_DOWNLOAD_ERRORS` or `IGNORED_DOWNLOAD_ERRORS` | empty |
| `download.initial_data_directory` | `WATCHMAN_INITIAL_DATA_DIRECTORY` or `INITIAL_DATA_DIRECTORY` | empty |
| `download.error_on_empty_list` | `WATCHMAN_ERROR_ON_EMPTY_LIST` or `ERROR_ON_EMPTY_LIST` | `false` |
| `download.timeout` | `WATCHMAN_DOWNLOAD_TIMEOUT` | `120` seconds |
| `download.temp_directory` | `WATCHMAN_TEMP_DIRECTORY` | `storage/app/watchman` |
| `download.urls.us_ofac` | `OFAC_DOWNLOAD_TEMPLATE` | OFAC sanctions list service (`%s` = file name) |
| `download.urls.us_non_sdn` | `US_NON_SDN_DOWNLOAD_TEMPLATE` | same service |
| `download.urls.us_csl` | `US_CSL_DOWNLOAD_TEMPLATE` / `US_CSL_DOWNLOAD_URL` | data.trade.gov |
| `download.urls.us_fincen_311` | `FINCEN_311_DOWNLOAD_URL` | fincen.gov page |
| `download.urls.eu_csl` | `EU_CSL_DOWNLOAD_URL`, or `EU_CSL_TOKEN` to change only the token | EU webgate |
| `download.urls.uk_csl` | `UK_SANCTIONS_LIST_URL` | FCDO CSV |
| `download.urls.un_csl` | `UN_CONSOLIDATED_LIST_URL` | scsanctions.un.org XML |
| `download.urls.us_tel` | `US_TEL_URL` | OpenSanctions |
| `download.open_sanctions.api_key` | `OPENSANCTIONS_API_KEY` | empty (not needed for public bulk files) |
| `download.open_sanctions.lists` | – | none (5 US datasets are listed, commented out; see [§15](#15-adding-opensanctions--senzing-lists)) |
| `download.senzing` | – | `[]` (any other Senzing files) |

### Refresh, search, scoring and routes

| Key | Env | Default |
|---|---|---|
| `refresh.schedule` | `WATCHMAN_SCHEDULE_REFRESH` | `true` |
| `refresh.cron` | `WATCHMAN_REFRESH_CRON` | `17 */12 * * *` (every 12h) |
| `refresh.queue_connection` | `WATCHMAN_QUEUE_CONNECTION` | app default |
| `refresh.queue` | `WATCHMAN_QUEUE` | app default |
| `refresh.memory_limit` | `WATCHMAN_REFRESH_MEMORY` | `2G` |
| `refresh.lock_seconds` | – | `7200` |
| `refresh.cache_store` | `WATCHMAN_CACHE_STORE` | app default (used for the lock and status) |
| `search.default_limit` / `search.max_limit` | – | `10` / `100` (100 is the most Watchman returns) |
| `search.chunk_size` | – | `500` entities loaded per batch while scoring |
| `scoring.*` | same names as Watchman (see [§20](#20-tuning-the-scorer)) | Watchman defaults |
| `tfidf.enabled` | `TFIDF_ENABLED` | `false` |
| `routes.enabled` | `WATCHMAN_ROUTES` | `false` |
| `routes.prefix` | `WATCHMAN_ROUTE_PREFIX` | `watchman` |
| `routes.middleware` | – | `['api', 'throttle:60,1']`; add your auth middleware |
| `routes.admin_enabled` | `WATCHMAN_ADMIN_ROUTES` | `false` |
| `routes.admin_middleware` | – | `['api']`; admin routes register only once this holds an auth middleware |
| `ingest.max_body_bytes` | `INGEST_MAX_BODY_BYTES` | 32 MiB |
| `ingest.max_decompressed_bytes` | `INGEST_MAX_DECOMPRESSED_BYTES` | 256 MiB (largest size a `.gz` upload may expand to) |
| `index.serializer` | `WATCHMAN_SERIALIZER` | `auto` (igbinary when installed); `php` decodes with a class allow-list, for stores shared with less trusted clients. Run a refresh after changing it. |
| `index.retire_after_seconds` | `WATCHMAN_RETIRE_AFTER` | `300` (how long the previous index is kept after a refresh, for searches still running on it) |
| `ingest.files` | – | `[]` (see [§14](#14-custom-lists-file-ingestion)) |

A typical `.env`:

```dotenv
WATCHMAN_DRIVER=native
WATCHMAN_STORE=redis
WATCHMAN_REDIS_CONNECTION=watchman
WATCHMAN_INCLUDED_LISTS=us_ofac,us_non_sdn,us_csl,us_fincen_311
WATCHMAN_QUEUE=watchman
WATCHMAN_ADMIN_ROUTES=false
```

---

## 6. Loading the lists (refresh)

A refresh downloads every configured list, parses it, normalizes every name and writes a **new** index generation. When everything succeeds, the new index replaces the old one atomically and the old one is deleted. While a refresh runs, searches keep using the previous index.

```bash
php artisan watchman:refresh            # run now, in this process
php artisan watchman:refresh --queue    # dispatch the RefreshLists job to your queue
```

```php
use Watchman\Laravel\Facades\Watchman;

$stats = Watchman::refresh();     // blocking; returns lists, listHashes, startedAt, endedAt, version, warnings
Watchman::refreshStatus();        // ['state' => 'running'|'succeeded'|'failed'|'queued'|'idle', 'startedAt' => …, 'lastError' => …]
```

```php
use Watchman\Laravel\Jobs\RefreshLists;

RefreshLists::dispatch('manual')->onQueue('watchman');
```

### Automatic refresh every 12 hours

The package registers a scheduled job (`watchman:refresh`, cron `17 */12 * * *`). Two things must be running:

```bash
* * * * * cd /path/to/app && php artisan schedule:run >> /dev/null 2>&1   # cron
php artisan queue:work --queue=watchman,default --timeout=7200            # worker
```

Notes:
- **Long-running job:** a refresh can take minutes when large OpenSanctions lists are enabled, so give the queue worker a long `--timeout`.
- **Database queues:** if you use the database or Redis queue driver, also raise `retry_after` above the timeout in `config/queue.php`.
- **One refresh at a time:** the cache lock `watchman:refresh:lock` makes sure only one refresh runs. Use a cache store that supports locks (redis, database, file or memcached).
- **Turning it off:** set `WATCHMAN_SCHEDULE_REFRESH=false` to disable the schedule and trigger refreshes yourself.

### What happens when a list fails

- If a list is named in `ignored_download_errors`, a download or parse failure only adds a warning. The other lists are still loaded.
- Any other failure aborts the refresh, the previous index stays active, and `refreshStatus()` reports `failed` with the error.
- An unknown name in `included_lists` throws `unknown lists: …`, as in Watchman.

---

## 7. Searching from PHP

```php
use Watchman\Laravel\Facades\Watchman;

$result = Watchman::search([
    'type'      => 'person',
    'name'      => 'Dmitry Khoroshev',
    'birthDate' => '1993-04-17',
    'minMatch'  => 0.80,
    'limit'     => 5,
]);

if ($result->isEmpty()) {
    // no hit at or above 0.80
}

$best = $result->first();          // ?SearchedEntity
$best->match;                      // 0.867
$best->source;                     // 'us_ofac'
$best->sourceId;                   // '48603'
$best->name;                       // 'Dmitry Yuryevich KHOROSHEV'
```

Shortcut methods:

```php
Watchman::searchPerson('Nicolas Maduro', ['birthDate' => '1962-11-23', 'minMatch' => 0.8]);
Watchman::searchBusiness('Banco Nacional de Cuba', ['address' => 'Havana, Cuba']);
Watchman::searchVessel('Pioneer', ['imoNumber' => '9187629']);

// true when the best hit is >= the cutoff (default 0.80)
Watchman::isMatch(['type' => 'person', 'name' => 'Dmitry Khoroshev', 'birthDate' => '1993-04-17']);
Watchman::isMatch(['type' => 'business', 'name' => 'Aerocaribbean Airlines'], 0.75);
```

Dependency injection works too:

```php
use Watchman\Laravel\WatchmanManager;   // or Watchman\Contracts\WatchmanClient

public function __construct(private WatchmanManager $watchman) {}

$this->watchman->search(['name' => 'Jane Doe', 'type' => 'person']);
```

### Advanced: build the query yourself

```php
use Watchman\Laravel\Clients\NativeClient;
use Watchman\Search\{Entity, Person, GovernmentID, SearchOptions};

$query = new Entity('Dmitry Khoroshev', 'person', 'api-request');
$query->person = new Person('Dmitry Khoroshev', birthDate: new DateTimeImmutable('1993-04-17', new DateTimeZone('UTC')));
$query->person->governmentIDs = [new GovernmentID('passport', 'Russia', '2018278055')];

$response = app(NativeClient::class)->searchEntity(
    $query->normalize(),                         // always normalize a query before searching
    new SearchOptions(limit: 5, minMatch: 0.8, debug: true),
);
```

---

## 8. Search parameters reference

These are the same names as Watchman's `GET /v2/search`. Pass them as an array to `Watchman::search()`, or as query string parameters over HTTP. Repeatable fields accept an array in PHP, or the same key repeated in the URL (`?altNames=A&altNames=B`, or `altNames[]=A`).

### General

| Parameter | Example | Notes |
|---|---|---|
| `name` | `Dmitry Khoroshev` | Name to screen (any order, any case, accents OK) |
| `type` | `person`, `business`, `organization`, `vessel`, `aircraft` | **Always send it** when you know it. Person, business and organization can still match each other; vessels and aircraft only match their own type. |
| `source` | `us_ofac` | Search one list only. Leave empty to search all downloaded lists. |
| `sourceID` | `48603` | Returns that exact list record with match 1.0 |
| `altNames` | `['Dima Khoroshev']` | Other names of the query subject (repeatable) |

### Person

| Parameter | Example |
|---|---|
| `gender` | `male`, `female`, `m`, `f` |
| `birthDate` | `1993-04-17`, `1993-04` or `1993` |
| `deathDate` | `2020-01-31` |
| `titles` | `['CEO']` (repeatable) |

### Business and organization

| Parameter | Example |
|---|---|
| `created` | `2001-05-01` |
| `dissolved` | `2015` |

### Government IDs (person, business, organization)

Use `gov_<type>=<country>:<identifier>`, for example:

```php
'gov_passport'              => 'Russia:2018278055',
'gov_tax-id'                => 'Russia:366110340670',
'gov_national-id'           => 'IR:0012345678',
'gov_business-registration' => 'CN:91110000100000000X',
```

The supported types are `passport`, `drivers-license`, `national-id`, `tax-id`, `ssn`, `cedula`, `curp`, `cuit`, `electoral`, `business-registration`, `commercial-registry`, `birth-certificate`, `refugee-id`, `diplomatic-passport`, `personal-id`, `citizenship` and `nationality`.

Use the same country spelling the list uses. OFAC stores full names such as "Russia", so `Russia:…` matches exactly. A different spelling (`RU:…`) counts as a matching number with a country mismatch, which gives a lower score.

### Vessel

| Parameter | Example |
|---|---|
| `imoNumber` | `9187629` |
| `mmsi` | `273212870` |
| `callSign` | `UBGT3` |
| `flag` | `Russia` |
| `vesselType` | `cargo` |
| `built` | `2001` |
| `model` | |
| `owner` | |
| `tonnage` | `4500` |
| `grossRegisteredTonnage` | `12000` |

### Aircraft

| Parameter | Example |
|---|---|
| `serialNumber` | `1003` |
| `icaoCode` | `B744` |
| `model` | `Boeing 747` |
| `flag` | |
| `built` | |
| `aircraftType` | `cargo` |

### Contact, address and crypto (any type)

| Parameter | Example |
|---|---|
| `email` (also `emailAddress`, `emailAddresses`) | `info@tidewaterco.com` |
| `phone` (also `phoneNumber`, `phoneNumbers`) | `+98 21 8855 3321` |
| `fax` (also `faxNumber`, `faxNumbers`) | |
| `website` (also `websites`) | `www.tidewaterco.com` |
| `domain` (also `domains`) | `tidewaterco.com` |
| `address` (also `addresses`) | `123 Main St, Las Vegas NV 89101` (free text) |
| `cryptoAddress` (also `cryptoAddresses`) | `XBT:bc1qvhnfknw852ephxyc5hm4q520zmvf9maphetc9z` |

### Search options

| Parameter | Default | Notes |
|---|---|---|
| `limit` | `10` | Max 100 |
| `minMatch` | `0` | Only return hits with a score at least this high (0.80 is the usual screening cutoff) |
| `debug` | off | `true` attaches score details to every hit; a number such as `0.80` attaches them only to hits scoring at least that much |
| `algorithm` | `jaro-winkler` | Also `soundex`, `double-metaphone`, `beider-morse`, `editex`, `nsim`, `nsim-3`, `soft-bidist`, `soft-bisim` |
| `requestID` | | Free text, for your logs |
| `format` | `watchman` | HTTP only: `senzing` (JSON array) or `senzing/jsonl` |

Unknown parameter names are rejected, exactly like Watchman. Over HTTP you get a 400; from PHP, `Watchman::search()` throws `InvalidArgumentException` (`extra/unused query parameters in request: birthdate`). A misspelled field therefore never silently weakens a screening.

---

## 9. Reading results

`Watchman::search()` returns a `Watchman\Search\SearchResponse`:

```php
$response->query;      // the normalized query Entity
$response->entities;   // list<SearchedEntity>, best first
$response->first();    // ?SearchedEntity
$response->isEmpty();
$response->toArray();  // Watchman JSON: ['query' => …, 'entities' => […]]
```

Each hit is a `Watchman\Search\SearchedEntity`, which extends `Entity`:

| Property | Type | Meaning |
|---|---|---|
| `match` | float | Score 0–1 |
| `name` | string | Primary name on the list |
| `type` | string | `person`, `business`, `organization`, `vessel` or `aircraft` |
| `source` | string | List name (`us_ofac`, `us_csl`, …) |
| `sourceId` | string | ID in that list (OFAC ent_num, CSL id, …) |
| `person` | ?Person | `name`, `altNames`, `gender`, `birthDate`, `placeOfBirth`, `deathDate`, `titles`, `governmentIDs` |
| `business` / `organization` | ?Business | `name`, `altNames`, `created`, `dissolved`, `governmentIDs` |
| `vessel` | ?Vessel | `imoNumber`, `mmsi`, `callSign`, `flag`, `type`, `built`, `tonnage`, `owner`, … |
| `aircraft` | ?Aircraft | `serialNumber`, `icaoCode`, `model`, `flag`, `built`, … |
| `contact` | ContactInfo | `emailAddresses`, `phoneNumbers`, `faxNumbers`, `websites` |
| `addresses` | list<Address> | `line1`, `line2`, `city`, `postalCode`, `state`, `country` |
| `cryptoAddresses` | list | `currency`, `address` |
| `affiliations` | list | `entityName`, `type`, `details` |
| `sanctionsInfo` | ?SanctionsInfo | `programs`, `secondary`, `description` |
| `historicalInfo` | list | `type` (e.g. "Former Name"), `value` |
| `sourceData` | array | The original list record (OFAC row, CSL row, Senzing record…) |
| `debug` | string | Base64 text explanation (only with `debug`) |
| `details` | ?SimilarityScore | The 9 score pieces (only with `debug`) |

Dates are `DateTimeImmutable` in UTC. `toArray()` / `json_encode()` produce the same JSON field names as Watchman (`entityType`, `sourceList`, `sourceID`, …), so existing Watchman clients can read the output.

Example: save a screening record for your audit trail. Watchman never stores searches, so you must.

```php
$res = Watchman::searchPerson($customer->full_name, ['birthDate' => $customer->dob, 'minMatch' => 0.80]);
$info = Watchman::listInfo();

ScreeningLog::create([
    'customer_id' => $customer->id,
    'cutoff'      => 0.80,
    'list_hashes' => $info['listHashes'],      // which list versions were checked
    'hits'        => array_map(fn ($h) => [
        'list' => $h->source, 'id' => $h->sourceId, 'name' => $h->name, 'match' => $h->match,
    ], $res->entities),
]);
```

---

## 10. Understanding scores and `minMatch`

- **The scale:** every hit is scored 0–1. Watchman's documentation uses **0.80** as a production screening cutoff (precision ≈ 0.99 on a public labeled set), and about **0.59** for high-recall review queues.
- **Name alone is capped:** a name-only match is limited by design. For example, "Dmitry Khoroshev" against the OFAC record scores **0.767**, below 0.80. Adding fields your KYC process already collects raises a true match: with `birthDate=1993-04-17` it scores **0.867**.
- **Unique IDs score 1.0:** a matching passport, IMO number, MMSI, aircraft serial or crypto address (with matching country/currency) gives **1.0** immediately.
- **Conflicting IDs lower the score:** if both sides have an ID of the same type and the numbers differ, the score is multiplied by 0.70.
- **Numerals in names:** names containing different numbers ("Star 1" vs "Star 2") are also multiplied by 0.70.
- **What goes into the score:**
  - Exact identifiers, crypto addresses, government IDs and contact details (weight 50).
  - Name and titles (35).
  - Addresses (25).
  - Dates and supporting info (15).
  - Coverage penalties are then applied.

The [debug output](#11-debugging-a-score) shows each of these steps.

---

## 11. Debugging a score

```php
$res = Watchman::search(['type' => 'person', 'name' => 'Dmitry Khoroshev', 'debug' => true, 'limit' => 1]);
$hit = $res->first();

echo base64_decode($hit->debug);            // human-readable explanation
foreach ($hit->details->pieces as $piece) { // 9 ScorePiece objects
    echo "{$piece->pieceType}: score={$piece->score} weight={$piece->weight} compared={$piece->fieldsCompared}\n";
}
```

```bash
php artisan watchman:search "Dmitry Khoroshev" --type=person --debug
```

Or compare one record directly:

```php
use Watchman\Search\{Entity, Similarity, DebugLog};

$log = new DebugLog();
$details = Similarity::detailed($queryEntity->normalize(), $listEntity, null, $log);
echo $log->contents();
```

---

## 12. HTTP API

The routes are registered under the prefix `watchman` (set `WATCHMAN_ROUTE_PREFIX`; use an empty value to get Watchman's exact paths).

The routes are **off by default**. Enable them with `WATCHMAN_ROUTES=true` once you have added authentication middleware (see the security note below).

| Method | Path | Needs | Purpose |
|---|---|---|---|
| GET | `/watchman/v2/search` | `WATCHMAN_ROUTES=true` | Search ([§8](#8-search-parameters-reference)) |
| GET | `/watchman/v2/listinfo` | `WATCHMAN_ROUTES=true` | Loaded lists, counts, hashes, last refresh |
| GET | `/watchman/v2/data/refresh` | `WATCHMAN_ROUTES=true` | Refresh status |
| GET | `/watchman/ping` | `WATCHMAN_ROUTES=true` | Health check (`PONG`) |
| POST | `/watchman/v2/data/refresh` | `WATCHMAN_ADMIN_ROUTES=true` + auth in `admin_middleware` | Queue a refresh (202, or 409 if already running) |
| POST | `/watchman/v2/ingest/{fileType}` | `WATCHMAN_ADMIN_ROUTES=true` + auth in `admin_middleware` | Upload a custom list file |

> **Security:** like Watchman, these endpoints have no authentication of their own. Before enabling them, set `routes.middleware` (e.g. `['api', 'auth:sanctum', 'throttle:60,1']`) and, for the admin routes, `routes.admin_middleware` (e.g. `['api', 'auth:sanctum', 'can:manage-watchman']`). Admin routes are registered only when `admin_middleware` includes an auth middleware, recognised by name: `auth…` (`auth`, `auth:sanctum`, `auth.basic`), `can:…`, or a class whose name contains `Auth` (such as `Authenticate`). Server-side failures return a generic 500 and are logged; refresh errors are not shown over HTTP.

Examples:

```bash
curl "http://localhost/watchman/v2/search?type=person&name=Dmitry+Khoroshev&birthDate=1993-04-17&minMatch=0.80&limit=1"
```

```json
{
  "query": { "name": "Dmitry Khoroshev", "entityType": "person", "sourceList": "api-request", "...": "..." },
  "entities": [
    {
      "name": "Dmitry Yuryevich KHOROSHEV",
      "entityType": "person",
      "sourceList": "us_ofac",
      "sourceID": "48603",
      "person": { "name": "Dmitry Yuryevich KHOROSHEV", "birthDate": "1993-04-17T00:00:00Z", "...": "..." },
      "sourceData": { "entityID": "48603", "sdnName": "KHOROSHEV, Dmitry Yuryevich", "...": "..." },
      "match": 0.867
    }
  ]
}
```

```bash
# repeated parameters
curl "http://localhost/watchman/v2/search?type=person&name=Nicolas+Maduro&altNames=Nicolas+Maduro+Moros&altNames=Maduro"

# score details
curl "http://localhost/watchman/v2/search?type=person&name=Dmitry+Khoroshev&debug=true&limit=1"

# Senzing output (one JSON object per line)
curl "http://localhost/watchman/v2/search?type=person&name=Dmitry+Khoroshev&format=senzing/jsonl"
curl -H "Accept: senzing" "http://localhost/watchman/v2/search?name=..."

# lists and refresh
curl http://localhost/watchman/v2/listinfo
curl http://localhost/watchman/v2/data/refresh
curl -X POST http://localhost/watchman/v2/data/refresh          # admin routes only

# ingest a CSV (admin routes only)
curl -X POST --data-binary @fincen-person.csv -H "Content-Type: text/csv" \
     http://localhost/watchman/v2/ingest/fincen-person
```

Errors are JSON with status 400, e.g. `{"error":"unknown algorithm (supported: …)"}`. An oversized ingest body returns 413.

---

## 13. Artisan commands

| Command | What it does |
|---|---|
| `watchman:refresh` | Download all lists and rebuild the index now. Prints a table of lists, counts and hashes. |
| `watchman:refresh --queue` | Dispatch the refresh job instead of running it. |
| `watchman:refresh --trigger=deploy` | Name recorded in the refresh status. |
| `watchman:search "NAME"` | Screen a name from the terminal. |
| `watchman:status` | Driver, store, loaded lists, last refresh, refresh status, Redis eviction warning. |
| `watchman:gc` | Delete index generations left behind by crashed or killed refreshes. |

`watchman:search` options:

```bash
php artisan watchman:search "Dmitry Khoroshev" \
    --type=person \
    --limit=5 \
    --min-match=0.5 \
    --param=birthDate=1993-04-17 \
    --param=gov_passport=Russia:2018278055 \
    --param=altNames=Dima \
    --debug          # print the score explanation
    # --json         # print the raw JSON response instead of a table
```

---

## 14. Custom lists: file ingestion

Use this for your own internal blocklists, FinCEN 314(a) files, or any CSV. Each *file type* gets a column mapping, which uses the same structure as Watchman's YAML.

```php
// config/watchman.php
'ingest' => [
    'max_body_bytes' => 32 * 1024 * 1024,
    'files' => [
        'fincen-person' => [
            'format' => 'csv',   // csv | senzing | senzing-json | senzing-jsonl
            'mapping' => [
                'name'     => ['merge' => ['first_name', 'suffix', 'middle_name', 'last_name']],
                'sourceID' => ['column' => 'tracking_number'],
                'type'     => ['default' => 'person'],
                'person'   => [
                    'altNames'      => ['merge' => ['alias_first_name', 'alias_suffix', 'alias_middle_name', 'alias_last_name']],
                    'birthDate'     => ['column' => 'dob'],                 // 2006-01-02, 1/2/2006 or 01/02/2006
                    'governmentIDs' => ['identifier' => ['column' => 'number']],
                ],
                'contact'   => ['phoneNumbers' => ['columns' => 'phone']],
                'addresses' => [
                    'line1' => ['columns' => 'street'], 'city' => ['columns' => 'city'],
                    'state' => ['columns' => 'state'], 'postalCode' => ['columns' => 'zip'],
                    'country' => ['columns' => 'country'],
                ],
            ],
        ],
        'fincen-business' => [
            'format' => 'csv',
            'mapping' => [
                'name'     => ['column' => 'business_name'],
                'sourceID' => ['column' => 'tracking_number'],
                'type'     => ['default' => 'business'],
                'business' => [
                    'altNames'      => ['columns' => 'dba_name'],
                    'created'       => ['column' => 'incorporated'],
                    'governmentIDs' => ['identifier' => ['column' => 'number']],
                ],
            ],
        ],
        'internal-blocklist' => ['format' => 'senzing'],   // Senzing JSON or JSON Lines
    ],
],
```

Mapping rules:
- `column` takes one column's value.
- `merge` joins several columns with spaces, skipping empty ones.
- For list fields, `columns` takes one column and `merge` adds one combined value.
- Rows with the same `sourceID` are merged into one entity, as in Watchman.

Upload and search (`.gz` files are accepted too):

```php
Watchman::ingest('fincen-person', file_get_contents(storage_path('fincen-person.csv')));

// Ingested lists are searched when you name them as the source, as in Watchman:
Watchman::search(['type' => 'person', 'name' => 'John Doe', 'source' => 'fincen-person']);
```

Uploading again replaces that file type's data. Rows are stored in `watchman_ingested_entities` and re-indexed after every refresh, so they survive Redis restarts.

---

## 15. Adding OpenSanctions / Senzing lists

Any dataset published in Senzing format can be added. Its `source_list` name is added to the loaded lists automatically, and hits are tagged with that name.

None are enabled by default. The published config lists five US datasets, commented out. Before enabling OpenSanctions data, check [its license terms](https://www.opensanctions.org/licensing/): it is free for non-commercial use, and commercial use needs a license from OpenSanctions. Large datasets such as `us_sam_exclusions` (100k+ records) also make refreshes and searches noticeably slower.

```php
'open_sanctions' => [
    'api_key' => env('OPENSANCTIONS_API_KEY'),   // only needed for non-public endpoints
    'lists' => [
        ['source_list' => 'us_sam_exclusions', 'location' => 'https://data.opensanctions.org/datasets/latest/us_sam_exclusions/senzing.json'],
        ['source_list' => 'gb_hmt_sanctions',  'location' => 'https://data.opensanctions.org/datasets/latest/gb_hmt_sanctions/senzing.json'],
    ],
],

// your own Senzing files (local file:// paths or URLs)
'senzing' => [
    ['source_list' => 'internal_pep', 'location' => 'file:///var/data/internal_pep.jsonl'],
],
```

Add the names to `ignored_download_errors` if a failed download should only produce a warning:

```dotenv
WATCHMAN_IGNORED_DOWNLOAD_ERRORS=us_sam_exclusions,gb_hmt_sanctions
```

Search a single dataset with `'source' => 'us_sam_exclusions'`.

---

## 16. Offline / cached list files

Point `WATCHMAN_INITIAL_DATA_DIRECTORY` at a folder. Any file found there (name compared case-insensitively) is used **instead of downloading**. This is how you run air-gapped, or pin list versions:

| List | File name(s) |
|---|---|
| `us_ofac` | `sdn.csv`, `add.csv`, `alt.csv`, `sdn_comments.csv` |
| `us_non_sdn` | `CONS_PRIM.CSV`, `CONS_ADD.CSV`, `CONS_ALT.CSV`, `CONS_COMMENTS.CSV` |
| `us_csl` | `consolidated.csv` |
| `us_fincen_311` | `fincen_311.html` |
| `eu_csl` | `eu_csl.csv` |
| `uk_csl` | `UK_Sanctions_List.csv` (or `UK_Sanctions_List.ods`) |
| `un_csl` | `un_consolidated.xml` |
| `us_tel` | `us_tel.json` |
| Senzing / OpenSanctions | a file named exactly like its `source_list`, e.g. `us_sam_exclusions` |

You can also send every download through a mirror or cache (such as moov-io/watchman-cache) with the URL variables in [§5](#5-configuration-reference), e.g. `OFAC_DOWNLOAD_TEMPLATE=https://cache.internal/ofac/%s`. `file:///path` locations work as well.

---

## 17. Adding your own list source in code

Implement `Watchman\Download\ListSource` and register it in a service provider:

```php
use Watchman\Download\{Downloader, ListRefresher, ListResult, ListSource};
use Watchman\Search\{Entity, Business};

final class MyVendorList implements ListSource
{
    public function name(): string { return 'vendor_blocklist'; }

    public function load(Downloader $downloader, string $initialDir): ListResult
    {
        $files = $downloader->getFiles($initialDir, ['vendors.json' => 'https://example.com/vendors.json']);
        $path = $files['vendors.json'] ?? throw new RuntimeException('vendors.json not available');

        $entities = (function () use ($path) {
            foreach (json_decode(file_get_contents($path), true) as $row) {
                $e = new Entity($row['name'], 'business', 'vendor_blocklist', (string) $row['id']);
                $e->business = new Business($row['name'], $row['aliases'] ?? []);
                yield $e->normalize();                       // always normalize
            }
        })();

        return new ListResult('vendor_blocklist', $entities, hash_file('sha256', $path));
    }
}

// AppServiceProvider::register()
$this->app->extend(ListRefresher::class, function (ListRefresher $r) {
    $r->extend('vendor_blocklist', fn () => new MyVendorList());
    return $r;
});
```

Then add `vendor_blocklist` to `WATCHMAN_INCLUDED_LISTS`.

---

## 18. Using the Go server instead (http driver)

If you already run Watchman (`docker run -p 8084:8084 moov/watchman`):

```dotenv
WATCHMAN_DRIVER=http
WATCHMAN_URL=http://watchman.internal:8084
```

`Watchman::search()`, `listInfo()`, `refresh()`, `refreshStatus()` and `ingest()` are then sent to the Go server and return the same PHP objects. The package routes forward to it as well. Nothing is indexed locally, and the scheduled refresh is skipped because the Go server refreshes itself.

You can also use both drivers side by side:

```php
Watchman::driver('native')->search([...]);
Watchman::driver('http')->search([...]);
```

---

## 19. Using the engine without Laravel

Everything under `Watchman\` except `Watchman\Laravel` is plain PHP:

```php
require 'vendor/autoload.php';

use Watchman\Download\{Downloader, ListRefresher};
use Watchman\Index\MemoryStore;
use Watchman\Search\{QueryParser, SearchService};

$store = new MemoryStore();               // or Watchman\Index\Redis\RedisStore / Database\DatabaseStore
$refresher = new ListRefresher($store, new Downloader(), [
    'included_lists' => ['us_ofac'],
    'initial_data_directory' => '/data/lists',
]);
$refresher->refresh();

$parser = QueryParser::fromQueryString('type=person&name=Dmitry+Khoroshev&minMatch=0.5');
$hits = (new SearchService($store))->search($parser->entity(), $parser->options());

foreach ($hits as $hit) {
    printf("%.3f %s %s\n", $hit->match, $hit->sourceId, $hit->name);
}
```

The individual building blocks can also be used on their own:

```php
use Watchman\Search\{Entity, Similarity};
use Watchman\Prepare\{Normalize, Stopwords};
use Watchman\StringScore\JaroWinkler;
use Watchman\Norm\{Country, Phone, Domain};
use Watchman\Address\UsAddress;

Similarity::score($query->normalize(), $candidate->normalize());   // 0..1
Normalize::lowerAndRemovePunctuation('Nicolás MADURO');           // "nicolas maduro"
Stopwords::remove('the bank of new york');                         // "bank new york"
JaroWinkler::bestPairCombination(['john', 'smith'], ['jon', 'smyth']);
Country::normalize('IR');                                          // "Iran"
Phone::normalize('+1 (702) 555-1234');                             // "17025551234"
Domain::keys('https://mail.example.co.uk/x');                      // ["mail.example.co.uk", "example.co.uk"]
UsAddress::parse('123 Main St Apt 4B, Springfield, IL 62704');     // Address object
```

---

## 20. Tuning the scorer

The defaults are Watchman's. Change them only if you have measured why. Each value can be set as an environment variable or in `config('watchman.scoring')`:

| Variable | Default | Effect |
|---|---|---|
| `JARO_WINKLER_BOOST_THRESHOLD` | 0.7 | Jaro score above which the prefix boost applies |
| `JARO_WINKLER_PREFIX_SIZE` | 4 | Prefix length for the boost |
| `LENGTH_DIFFERENCE_CUTOFF_FACTOR` | 0.9 | Token length ratio below which a penalty applies |
| `LENGTH_DIFFERENCE_PENALTY_WEIGHT` | 0.3 | Strength of that penalty |
| `DIFFERENT_LETTER_PENALTY_WEIGHT` | 0.9 | Multiplier when tokens start with different sounds |
| `UNMATCHED_INDEX_TOKEN_WEIGHT` | 0.15 | Penalty for list-name words the query didn't match |
| `EXACT_MATCH_FAVORITISM` | 0 | Bonus for exact words (legacy scorer) |
| `ADJACENT_SIMILARITY_POSITIONS` | 3 | Legacy scorer window |
| `DISABLE_PHONETIC_FILTERING` | false | Compare all token pairs, not only those with similar first sounds |
| `USE_SOUNDEX_MATCHING` / `SOUNDEX_BOOST_WEIGHT` | false / 0 | Soundex boost for every search |
| `FINAL_SCORE_LOW_COVERAGE_MULTIPLIER` | 0.95 | |
| `FINAL_SCORE_MIN_REQUIRED_FIELDS_MULTIPLIER` | 0.90 | |
| `FINAL_SCORE_NAME_ONLY_MULTIPLIER` | 0.95 | Name-only penalty |
| `ID_CONFLICT_PENALTY_MULTIPLIER` | 0.70 | Same ID type, different number |
| `NAME_NUMERAL_CONFLICT_PENALTY_MULTIPLIER` | 0.70 | "Star 1" vs "Star 2" |
| `KEEP_STOPWORDS` | false | Keep words like "the", "of", "ltd" |
| `TFIDF_ENABLED` (+ `TFIDF_SMOOTHING`, `TFIDF_MIN_IDF`, `TFIDF_MAX_IDF`) | false | Weight rare name words higher. **Requires a refresh** after changing it. |

To try a different name algorithm for one search, use `'algorithm' => 'double-metaphone'` (or `soundex`, `beider-morse`, `editex`, `nsim`, `nsim-3`, `soft-bidist`, `soft-bisim`).

---

## 21. Real-world recipes

**Onboarding check in a controller**

```php
public function store(Request $request)
{
    $data = $request->validate(['name' => 'required', 'dob' => 'nullable|date', 'country' => 'nullable']);

    $result = Watchman::searchPerson($data['name'], array_filter([
        'birthDate' => $data['dob'] ?? null,
        'address'   => $data['country'] ?? null,
        'minMatch'  => 0.80,
        'limit'     => 5,
    ]));

    if (! $result->isEmpty()) {
        // hold the account for manual compliance review
    }
}
```

**Screening a payment counterparty (business + IBAN country + website)**

```php
Watchman::search([
    'type'    => 'business',
    'name'    => $payment->beneficiary_name,
    'address' => $payment->beneficiary_address,
    'website' => $payment->beneficiary_website,
    'minMatch' => 0.80,
]);
```

**Crypto withdrawal check (exact address match scores 1.0)**

```php
Watchman::isMatch(['cryptoAddress' => 'XBT:' . $withdrawal->address], 0.99);
```

**Re-screen all customers after each refresh**

```php
// e.g. in a scheduled job that runs after the refresh
Customer::chunkById(500, function ($customers) {
    foreach ($customers as $c) {
        $hit = Watchman::searchPerson($c->name, ['birthDate' => $c->dob, 'minMatch' => 0.80, 'limit' => 1])->first();
        if ($hit) {
            ScreeningAlert::firstOrCreate(['customer_id' => $c->id, 'list' => $hit->source, 'list_id' => $hit->sourceId], ['score' => $hit->match]);
        }
    }
});
```

**Only re-screen when the lists actually changed**

```php
$hashes = Watchman::listInfo()['listHashes'];
if ($hashes != cache('watchman:last_hashes')) {
    // lists changed → run the re-screen job
    cache(['watchman:last_hashes' => $hashes]);
}
```

**Vessel / shipping check**

```php
Watchman::searchVessel('Ocean Pioneer (ex-Cape Diamond)', ['imoNumber' => '9187629', 'flag' => 'Panama']);
```

---

## 22. Performance and sizing

These figures were measured on a small cloud VM with the Redis store:

| Data | Refresh time | Redis memory |
|---|---|---|
| OFAC SDN + US CSL (22.5k entities, full files) | 15–22 s | ~70 MB |
| OFAC, Non-SDN, CSL, EU, UK, UN, TEL (21.6k entities, Watchman test files) | ~25 s | ~76 MB |

Large OpenSanctions datasets (SAM exclusions has 100k+ records) add minutes and hundreds of MB.

Search times:
- **Typical searches** take 1–40 ms. The index narrows the scoring down to records that share name words, IDs, crypto addresses or address keys.
- **Typo-only searches** take longer. When no name word exists in the index (for example "Xzqv Wplk"), Watchman scores the whole partition for that type. That took about 0.9 s for ~13k people. Always pass `type` to keep the partition small. Enabling OPcache JIT (`opcache.jit=tracing`) makes scoring about 30% faster.

Refresh memory peaks at about 100–135 MB for the lists above. Set `WATCHMAN_REFRESH_MEMORY` higher for big OpenSanctions files.

---

## 23. Troubleshooting

| Symptom | Fix |
|---|---|
| `no sanctions lists are loaded; run php artisan watchman:refresh` (HTTP 500) | The index is empty: Redis restarted without persistence, was flushed, or no refresh has run yet. Run `php artisan watchman:refresh`, then check `watchman:status`. Searches refuse to answer rather than report "no matches". |
| `unknown lists: xyz` | A name in `included_lists` has no built-in source, OpenSanctions/Senzing entry or registered custom source. |
| `unknown list "…" in ignored_download_errors` | Only names of real sources can be ignored. |
| `a data refresh is already running` | Another refresh holds the lock. Wait, or clear `watchman:refresh:lock` from the cache if a worker was killed. |
| Refresh killed by the queue worker | Raise `--timeout`, `retry_after` and `WATCHMAN_REFRESH_MEMORY`. |
| Searches work, then suddenly return nothing | Redis evicted or flushed the index. Use a dedicated Redis with `noeviction`, then refresh. |
| `igbinary extension required to read this index` | The index was built on a machine with igbinary. Install it, or run a refresh. |
| `extra/unused query parameters in request: …` | Misspelled parameter (names are case-sensitive: `birthDate`, `minMatch`, `sourceID`). |
| `unknown algorithm` | Use one of the names in [§8](#8-search-parameters-reference). |
| Ingested rows not found | Pass `'source' => '<fileType>'` (Watchman behaviour). |
| Passport search doesn't score 1.0 | Use the list's country spelling (`Russia:…`, not `RU:…`). Check with `debug`. |
| Stale index generations in Redis/DB after crashes | `php artisan watchman:gc` |
| `/watchman/...` routes return 404 | Routes are off by default: set `WATCHMAN_ROUTES=true` and run `php artisan route:clear`. POST routes also need `WATCHMAN_ADMIN_ROUTES=true` and an auth middleware in `routes.admin_middleware`; otherwise a warning is logged and they are not registered. |
| HTTP 500 `internal error while processing the request` | A server-side failure (Redis or database down, corrupt index). The details are in the Laravel log. Bad input always returns 400 with the reason. |

Logs are written to your default Laravel log channel, prefixed `watchman:`.

---

## 24. How faithful is the port?

These parts are ported from the Go source:
- Name normalization and stopword removal, including whatlanggo's language detection and bbalet/stopwords' lists.
- Phone, country and domain normalization, and the US address parser.
- Jaro-Winkler and the alternative name algorithms, and every score piece.
- Candidate selection, following `internal/index`: partitions, name-word postings with document-frequency pruning, ID and QWERTY-typo lookups, and hashed government-ID and address blocking keys.

The data tables are generated from the exact dependency versions Watchman pins (`bin/generate-data.php`).

Parity was checked against a harness compiled from the Go source (`tools/parity`):
- 16,989 OFAC, 5,512 CSL, 1,322 Senzing and 3 FinCEN entities map identically, including every prepared field.
- 6,000 randomized similarity comparisons match (all except one random language-detection tie).
- 25,000 name preparations, 15,000 Jaro-Winkler calls, 6,285 normalizations and 3,006 address parses match.
- The EU, UK, UN and US TEL readers pass the Go unit-test expectations.
- The only mismatches are a few short names where Go's own language detection is a random tie. Go itself returns different results between runs for those names.

Not ported (all optional in Watchman):
- AI-embedding cross-script search (OpenRouter).
- Geocoding.
- The libpostal / deepparse address parsers (the `usaddress` fallback is used).
- The web UI.
- MCP.

---

## 25. Testing and development

```bash
composer install
vendor/bin/phpunit                                  # unit + feature tests (memory store)
WATCHMAN_TEST_STORE=redis vendor/bin/phpunit        # against Redis (REDIS_HOST/REDIS_PORT)
WATCHMAN_TEST_STORE=database vendor/bin/phpunit     # against SQLite
```

To regenerate the language, stopword, phone, ISO-3166 and public-suffix tables after upgrading upstream dependencies:

```bash
php bin/generate-data.php <go-dump.json> <bbalet-stopwords-dir> <watchman/internal/prepare/union_stopwords.go> <public_suffix_list.dat>
```

(`bin/datagen/main.go` produces `go-dump.json`.) Parity tools and instructions are in `tools/parity/README.md`.

### Code map

```
src/
  Search/         entity models, Similarity (scoring), QueryParser, SearchService, Merge
  StringScore/    Jaro-Winkler, Soundex, phonetic filter, alternative algorithms
  Prepare/        name normalization, stopwords, language detection, legal forms, ex-names…
  Norm/           country, phone, website, domain (PSL)
  Address/        US address parser
  Sources/        Ofac, CslUs, FinCen311, CslEu, CslUk, CslUn, UsTel, Senzing
  Download/       Downloader, ListRefresher, ListSource
  Index/          candidate selection + Redis / Database / Memory stores
  Ingest/         CSV / Senzing file ingestion
  Laravel/        service provider, facade, clients, controllers, commands, job
config/watchman.php   routes/watchman.php   database/migrations/   resources/data/
```

---

### Contributing

Issues and pull requests are welcome. Every change is reviewed and approved by the maintainer before it is merged. See [CONTRIBUTING.md](CONTRIBUTING.md), and report security problems privately as described in [SECURITY.md](SECURITY.md).

---

## 26. License

Apache-2.0, like moov-io/watchman (see `LICENSE` and `NOTICE`). The generated data in `resources/data` comes from:
- bbalet/stopwords (BSD)
- abadojack/whatlanggo (MIT)
- dongri/phonenumber (MIT)
- moov-io/iso3166 (Apache-2.0)
- the Public Suffix List (MPL-2.0)

Their license texts are in the `licenses/` folder.
