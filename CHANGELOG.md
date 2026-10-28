# Changelog

All notable changes to this project are documented here. This project follows [Semantic Versioning](https://semver.org/).

## Unreleased

## 1.0.0

First public release.

- Native PHP port of moov-io/watchman @ 7f44b4b6: entity model, name preparation pipeline,
  Jaro-Winkler scoring (plus soundex, double-metaphone, beider-morse, editex, n-sim, soft-bigram/bisim),
  similarity pieces and final-score penalties, candidate selection.
- List sources: OFAC SDN, OFAC Non-SDN, US CSL, FinCEN 311, EU CSL, UK sanctions list, UN consolidated list,
  US TEL, any Senzing/OpenSanctions dataset.
- Index stores: Redis (default), database, in-memory; atomic generation swap on refresh.
- Laravel integration: config, facade, /v2 routes, artisan commands, queued scheduled refresh, file ingestion.
- `http` driver for an existing Watchman Go server.
- `Watchman::search()` rejects unknown parameter names with `InvalidArgumentException`, like the HTTP API.
- Safe defaults: HTTP routes are off until enabled, admin routes register only behind an auth middleware,
  no OpenSanctions datasets are downloaded unless configured.
- Searches fail with an error instead of returning no matches when no lists are loaded.
- Long-running workers (Octane, queue workers) follow refreshes made by other processes; a replaced index is
  kept for `index.retire_after_seconds` (300 s) so searches already running can finish.
