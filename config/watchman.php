<?php

/*
|--------------------------------------------------------------------------
| Laravel Watchman
|--------------------------------------------------------------------------
|
| Sanctions screening (OFAC, CSL, FinCEN 311, EU, UK, UN, OpenSanctions)
| ported from moov-io/watchman. Option names mirror Watchman's YAML config
| and environment variables where possible.
|
*/

$csv = static fn (?string $v, array $default = []) => $v === null || trim($v) === ''
    ? $default
    : array_values(array_filter(array_map('trim', explode(',', $v)), static fn ($x) => $x !== ''));

return [

    /*
    | "native" runs the PHP port inside your app (no Go server needed).
    | "http" proxies every call to a running Watchman Go server.
    */
    'driver' => env('WATCHMAN_DRIVER', 'native'),

    'http' => [
        'base_url' => env('WATCHMAN_URL', 'http://localhost:8084'),
        'timeout' => (int) env('WATCHMAN_HTTP_TIMEOUT', 10),
        'headers' => [],
    ],

    /*
    | Where the native driver keeps its search index:
    |   redis    – shared in-memory index for every worker (recommended)
    |   database – SQL tables created by the package migration
    |   memory   – per-process only (tests, Octane, CLI experiments)
    */
    'store' => env('WATCHMAN_STORE', 'redis'),

    'redis' => [
        // Use a dedicated connection/database with maxmemory-policy "noeviction".
        'connection' => env('WATCHMAN_REDIS_CONNECTION', 'default'),
        'prefix' => env('WATCHMAN_REDIS_PREFIX', 'watchman:'),
    ],

    'database' => [
        'connection' => env('WATCHMAN_DB_CONNECTION'),
        'table_prefix' => 'watchman_',
    ],

    /*
    | List downloads (Watchman "Download" config).
    */
    'download' => [
        // us_ofac, us_non_sdn, us_csl, us_fincen_311, eu_csl, uk_csl, un_csl, us_tel
        'included_lists' => $csv(env('WATCHMAN_INCLUDED_LISTS', env('INCLUDED_LISTS')), ['us_ofac', 'us_non_sdn', 'us_csl', 'us_fincen_311']),

        // A failing list named here logs a warning instead of failing the refresh.
        'ignored_download_errors' => $csv(env('WATCHMAN_IGNORED_DOWNLOAD_ERRORS', env('IGNORED_DOWNLOAD_ERRORS'))),

        // Files found here (by name, case-insensitive) are used instead of downloading.
        'initial_data_directory' => env('WATCHMAN_INITIAL_DATA_DIRECTORY', env('INITIAL_DATA_DIRECTORY', '')),

        'error_on_empty_list' => (bool) env('WATCHMAN_ERROR_ON_EMPTY_LIST', env('ERROR_ON_EMPTY_LIST', false)),

        'timeout' => (int) env('WATCHMAN_DOWNLOAD_TIMEOUT', 120),

        'temp_directory' => env('WATCHMAN_TEMP_DIRECTORY'),

        // Optional URL / template overrides (sprintf templates take the file name).
        'urls' => [
            'us_ofac' => env('OFAC_DOWNLOAD_TEMPLATE'),
            'us_non_sdn' => env('US_NON_SDN_DOWNLOAD_TEMPLATE'),
            'us_csl' => env('US_CSL_DOWNLOAD_TEMPLATE', env('US_CSL_DOWNLOAD_URL')),
            'us_fincen_311' => env('FINCEN_311_DOWNLOAD_URL'),
            // EU_CSL_TOKEN only changes the token in the EU's public download URL.
            'eu_csl' => env('EU_CSL_DOWNLOAD_URL') ?: (env('EU_CSL_TOKEN')
                ? sprintf(\Watchman\Sources\CslEu\CslEuSource::PUBLIC_URL_FORMAT, env('EU_CSL_TOKEN'))
                : null),
            'uk_csl' => env('UK_SANCTIONS_LIST_URL'),
            'un_csl' => env('UN_CONSOLIDATED_LIST_URL'),
            'us_tel' => env('US_TEL_URL'),
        ],

        // Senzing-formatted OpenSanctions datasets. Each source_list is added to the
        // included lists automatically and is what hits are tagged with.
        // OpenSanctions data has its own license terms (free for non-commercial use; commercial
        // use needs a license from OpenSanctions), so none are enabled by default.
        'open_sanctions' => [
            'api_key' => env('OPENSANCTIONS_API_KEY'),
            'lists' => [
                // ['source_list' => 'us_sam_exclusions', 'location' => 'https://data.opensanctions.org/datasets/latest/us_sam_exclusions/senzing.json'],
                // ['source_list' => 'us_fbi_most_wanted', 'location' => 'https://data.opensanctions.org/datasets/latest/us_fbi_most_wanted/senzing.json'],
                // ['source_list' => 'us_dea_fugitives', 'location' => 'https://data.opensanctions.org/datasets/latest/us_dea_fugitives/senzing.json'],
                // ['source_list' => 'us_occ_enfact', 'location' => 'https://data.opensanctions.org/datasets/latest/us_occ_enfact/senzing.json'],
                // ['source_list' => 'us_dhs_uflpa', 'location' => 'https://data.opensanctions.org/datasets/latest/us_dhs_uflpa/senzing.json'],
            ],
        ],

        // Any other Senzing files (local paths with file:// or URLs).
        'senzing' => [
            // ['source_list' => 'my_list', 'location' => 'file:///path/to/list.jsonl'],
        ],
    ],

    /*
    | Periodic refresh (Watchman RefreshInterval, default 12h).
    */
    'refresh' => [
        'schedule' => (bool) env('WATCHMAN_SCHEDULE_REFRESH', true),
        'cron' => env('WATCHMAN_REFRESH_CRON', '17 */12 * * *'),
        'queue_connection' => env('WATCHMAN_QUEUE_CONNECTION'),
        'queue' => env('WATCHMAN_QUEUE'),
        'memory_limit' => env('WATCHMAN_REFRESH_MEMORY', '2G'),
        'lock_seconds' => 7200,
        'cache_store' => env('WATCHMAN_CACHE_STORE'),
    ],

    /*
    | Index generations. After a refresh the previous index is kept this long, so searches
    | already running in other workers can finish; `watchman:gc` and later refreshes delete it.
    */
    'index' => [
        'retire_after_seconds' => (int) env('WATCHMAN_RETIRE_AFTER', 300),
        // "auto" uses igbinary when installed (smaller, faster). "php" uses serialize() with a class
        // allow-list: choose it if other, less trusted clients can write to the same Redis or database.
        'serializer' => env('WATCHMAN_SERIALIZER', 'auto'),
    ],

    'search' => [
        'default_limit' => 10,
        'max_limit' => 100, // cannot exceed 100 (Watchman's maximum)
        // Candidates are loaded and scored in chunks of this size.
        'chunk_size' => 500,
    ],

    /*
    | Scoring parameters. null = Watchman default (or the matching env var).
    */
    'scoring' => [
        'JARO_WINKLER_BOOST_THRESHOLD' => env('JARO_WINKLER_BOOST_THRESHOLD'),
        'JARO_WINKLER_PREFIX_SIZE' => env('JARO_WINKLER_PREFIX_SIZE'),
        'LENGTH_DIFFERENCE_CUTOFF_FACTOR' => env('LENGTH_DIFFERENCE_CUTOFF_FACTOR'),
        'LENGTH_DIFFERENCE_PENALTY_WEIGHT' => env('LENGTH_DIFFERENCE_PENALTY_WEIGHT'),
        'DIFFERENT_LETTER_PENALTY_WEIGHT' => env('DIFFERENT_LETTER_PENALTY_WEIGHT'),
        'EXACT_MATCH_FAVORITISM' => env('EXACT_MATCH_FAVORITISM'),
        'UNMATCHED_INDEX_TOKEN_WEIGHT' => env('UNMATCHED_INDEX_TOKEN_WEIGHT'),
        'ADJACENT_SIMILARITY_POSITIONS' => env('ADJACENT_SIMILARITY_POSITIONS'),
        'DISABLE_PHONETIC_FILTERING' => env('DISABLE_PHONETIC_FILTERING'),
        'USE_SOUNDEX_MATCHING' => env('USE_SOUNDEX_MATCHING'),
        'SOUNDEX_BOOST_WEIGHT' => env('SOUNDEX_BOOST_WEIGHT'),
        'FINAL_SCORE_LOW_COVERAGE_MULTIPLIER' => env('FINAL_SCORE_LOW_COVERAGE_MULTIPLIER'),
        'FINAL_SCORE_MIN_REQUIRED_FIELDS_MULTIPLIER' => env('FINAL_SCORE_MIN_REQUIRED_FIELDS_MULTIPLIER'),
        'FINAL_SCORE_NAME_ONLY_MULTIPLIER' => env('FINAL_SCORE_NAME_ONLY_MULTIPLIER'),
        'ID_CONFLICT_PENALTY_MULTIPLIER' => env('ID_CONFLICT_PENALTY_MULTIPLIER'),
        'NAME_NUMERAL_CONFLICT_PENALTY_MULTIPLIER' => env('NAME_NUMERAL_CONFLICT_PENALTY_MULTIPLIER'),
        'KEEP_STOPWORDS' => env('KEEP_STOPWORDS'),
    ],

    // Optional TF-IDF weighting of name tokens (off by default, as in Watchman).
    'tfidf' => [
        'enabled' => (bool) env('TFIDF_ENABLED', false),
        'smoothing' => (float) env('TFIDF_SMOOTHING', 1.0),
        'min_idf' => (float) env('TFIDF_MIN_IDF', 0.1),
        'max_idf' => (float) env('TFIDF_MAX_IDF', 10.0),
    ],

    /*
    | HTTP API mirroring Watchman's /v2 endpoints. Off by default.
    | These routes have no authentication of their own: add your auth middleware
    | (e.g. 'auth:sanctum') before enabling them on a reachable server.
    */
    'routes' => [
        'enabled' => (bool) env('WATCHMAN_ROUTES', false),
        'prefix' => env('WATCHMAN_ROUTE_PREFIX', 'watchman'),
        'middleware' => ['api', 'throttle:60,1'],
        // POST /v2/data/refresh and POST /v2/ingest/{fileType} change data. They are only
        // registered when admin_middleware includes an auth middleware ("auth…", "can:…" or a class
        // whose name contains "Auth"), e.g. ['api', 'auth:sanctum', 'can:manage-watchman'].
        'admin_enabled' => (bool) env('WATCHMAN_ADMIN_ROUTES', false),
        'admin_middleware' => ['api'],
    ],

    /*
    | Custom file ingestion (POST /v2/ingest/{fileType}), same mapping as Watchman's YAML.
    */
    'ingest' => [
        'max_body_bytes' => (int) env('INGEST_MAX_BODY_BYTES', 32 * 1024 * 1024),
        // A gzip upload may expand to at most this many bytes.
        'max_decompressed_bytes' => (int) env('INGEST_MAX_DECOMPRESSED_BYTES', 256 * 1024 * 1024),
        'files' => [
            // 'fincen-person' => [
            //     'format' => 'csv',
            //     'mapping' => [
            //         'name' => ['merge' => ['first_name', 'suffix', 'middle_name', 'last_name']],
            //         'sourceID' => ['column' => 'tracking_number'],
            //         'type' => ['default' => 'person'],
            //         'person' => [
            //             'name' => ['merge' => ['first_name', 'middle_name', 'last_name']],
            //             'altNames' => ['merge' => ['alias_first_name', 'alias_suffix', 'alias_middle_name', 'alias_last_name']],
            //             'birthDate' => ['column' => 'dob'],
            //             'governmentIDs' => ['type' => ['column' => 'number_type'], 'identifier' => ['column' => 'number']],
            //         ],
            //         'contact' => ['phoneNumbers' => ['columns' => 'phone']],
            //         'addresses' => [
            //             'line1' => ['columns' => 'street'], 'city' => ['columns' => 'city'], 'state' => ['columns' => 'state'],
            //             'postalCode' => ['columns' => 'zip'], 'country' => ['columns' => 'country'],
            //         ],
            //     ],
            // ],
        ],
    ],
];
