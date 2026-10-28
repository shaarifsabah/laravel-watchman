# Contributing

Thanks for helping. Anyone can suggest changes, and every change is reviewed and approved by the maintainer ([@shaarifsabah](https://github.com/shaarifsabah)) before it is merged.

## How to propose a change

1. **Open an issue first** for anything bigger than a small fix, so we can agree on the approach before you spend time on it.
2. **Fork** the repository and create a branch from `main`.
3. Make your change, with tests.
4. Run the checks locally:
   ```bash
   composer install
   vendor/bin/phpunit                                   # memory store
   WATCHMAN_TEST_STORE=redis vendor/bin/phpunit         # needs a local Redis
   WATCHMAN_TEST_STORE=database vendor/bin/phpunit      # SQLite in memory
   ```
5. Open a **pull request** against `main` and fill in the template.

You cannot push to this repository directly. Pull requests are merged only after the automated tests pass and the maintainer approves them.

## Rules for changes

- **Scoring must stay identical to Go Watchman.** This package is a faithful port of [moov-io/watchman](https://github.com/moov-io/watchman). Changes to scoring, name preparation, normalization or list readers must not change results compared with the Go version. If you fix something here, check it against Go first (see `tools/parity/README.md`) and include the comparison in your pull request. If the bug is in Go too, please report it upstream as well.
- **No new runtime dependencies** without discussing it in an issue first.
- Keep public method names and config keys stable. Breaking changes need a major version.
- Update `README.md` and `CHANGELOG.md` when you change behaviour or configuration.

## Licensing of contributions

By submitting a pull request you agree that your contribution is licensed under the [Apache License 2.0](LICENSE), the same as the rest of the project.

## Security problems

Please do not open a public issue. Follow [SECURITY.md](SECURITY.md).
