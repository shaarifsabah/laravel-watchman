# Security policy

## Reporting a vulnerability

Please report security problems **privately**, using GitHub's "Report a vulnerability" button on the
[Security tab](https://github.com/shaarifsabah/laravel-watchman/security/advisories/new) of this repository.
Do not open a public issue.

Include what is affected, how to reproduce it, and the impact you expect. You will get a reply within 7 days.

## Supported versions

Only the latest released minor version receives security fixes.

## Deployment reminders

- The package's HTTP routes have **no authentication of their own**, the same as Go Watchman. Before you expose them, add your own middleware in `config/watchman.php` (`routes.middleware` and `routes.admin_middleware`).
- The admin routes (`POST /v2/data/refresh` and `POST /v2/ingest/{fileType}`) are off by default. Enable them only behind authentication.
- Anyone who can write to the Redis database or SQL tables that hold the index can change screening results. Keep that store private to the application. If less trusted clients share it, set `WATCHMAN_SERIALIZER=php` (then run `watchman:refresh`), so the index is decoded with a class allow-list instead of igbinary.
- Screening results are only as current as the last list refresh. Monitor `php artisan watchman:status` or `GET /v2/data/refresh`.
