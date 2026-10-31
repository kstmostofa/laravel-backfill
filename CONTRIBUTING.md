# Contributing

Thanks for taking the time. Bug reports, failing tests, docs fixes and feature ideas are all welcome.

## Before you open a pull request

For anything bigger than a small fix, open an issue or a discussion first. This package is built around one invariant: a backfill can be killed at any instant, restarted, and must arrive at the same end state. A change that weakens that will not be merged, however useful it is, so it is worth agreeing on the approach before you write the code.

## Setting up

```bash
git clone https://github.com/kstmostofa/laravel-backfill.git
cd laravel-backfill
composer install
```

## Checks

Every pull request runs these in CI. Run them locally first:

```bash
composer test       # Pest, on SQLite
composer analyse    # PHPStan via Larastan
composer lint       # Pint, check only
composer format     # Pint, fix in place
```

The suite also runs against MySQL and PostgreSQL in CI. To run it locally against either:

```bash
BACKFILL_DRIVER=mysql composer test
BACKFILL_DRIVER=pgsql composer test
```

Connection details come from `BACKFILL_MYSQL_*` and `BACKFILL_PGSQL_*` environment variables. The defaults match a stock local server with a `laravel_backfill_test` database.

The chaos tests fork the process and send a real `SIGKILL`. They need the `pcntl` and `posix` extensions and skip themselves without them.

## What a good pull request has

- **A test that fails without the change.** For anything touching the runner, a test on the engine where it matters. Per-row savepoints, for example, only prove anything on PostgreSQL.
- **Docs updated in the same pull request.** The docs live in `docs/` and build with VitePress. See `docs/README.md`.
- **A line in `CHANGELOG.md`** under the unreleased version.

## Reporting a bug

Use the bug report template. The most useful things to include are the database engine and version, whether the run was on the queue, and the output of `php artisan backfill:status <name>`.

## Security issues

Please do not open a public issue. See [SECURITY.md](SECURITY.md).
