# UnrealDB database schema and migrations

## Consolidated baseline

`catalog/install.sql` is the canonical **squashed baseline** for a new empty MySQL 8+ or compatible MariaDB database. The current baseline contains schema and seed-data changes through version `202608090002`.

Historical migrations represented by that baseline have been retired. Existing installations may still contain their rows in `ue_schema_migrations`; the migration runner reports those rows as `archived` rather than treating the removed files as drift.

The fresh-install baseline intentionally omits retired row-per-object metadata tables. Current verified metadata is stored in format-2 compact containers and current lookup/summary projections. Dedicated compressed unverified staging, administrator-controlled background-job resource limits, generated job display status/indexes, and base-game/federation policy schema are all part of the consolidated baseline.

Do not import `catalog/install.sql` over a populated database.

## Fresh installation

A fresh database must apply the consolidated baseline **and every migration newer than the baseline**:

1. Create an empty database.
2. Import `catalog/install.sql`.
3. Configure `catalog/config.php`.
4. Run `php catalog/bin/migrate.php status`.
5. Run `php catalog/bin/migrate.php migrate --lock-timeout=60`.
6. Run `php catalog/bin/migrate.php verify`.
7. Create the first administrator with `catalog/bin/create-admin.php`.

This keeps a fresh installation identical to an upgraded production database when new migrations are added after the latest schema squash.

## Current incremental migrations

There are no required incremental migrations newer than the `202608090002` consolidated baseline at the time of this squash. Future schema changes must start with a version greater than `202608090002`.

## Future schema changes

New migration files belong in `catalog/migrations/` and must:

- use a version greater than the consolidated baseline;
- follow the immutable `<version>_<name>.php` format;
- be idempotent because MySQL/MariaDB DDL may commit implicitly;
- remain incremental until a deliberate reviewed schema-squash updates `catalog/install.sql`.

Normal deployment sequence:

```text
php catalog/bin/migrate.php status
php catalog/bin/migrate.php migrate --dry-run
php catalog/bin/migrate.php migrate --lock-timeout=60
php catalog/bin/migrate.php verify
```

The migration runner rejects changed checksums and missing migration files above the consolidated baseline.

## Runtime database access

New runtime SQL belongs in intent-specific Infrastructure query/repository objects under `catalog/src/Infrastructure`, not in pages or API entry points.

Verified package metadata reads are compact-only. Object-level dependency reads use the current compact dependency source; aggregate dependency views use maintained package summaries. Runtime SQL-shape emulation of retired metadata tables has been removed. `php catalog/bin/audit-legacy-runtime-references.php` enforces this boundary.

The durable job subsystem uses:

- `PdoJobQueue` as the single application-facing queue implementation;
- `PdoJobEnqueuer` for inserts/deduplication;
- `PdoJobClaimer` for parallel `SKIP LOCKED` claims plus resource/concurrency admission;
- `PdoJobLeaseStore` for heartbeat/completion/failure/cancellation;
- `PdoJobRecovery` for expired-lease recovery;
- generated/indexed `display_status` for live admin status reads.
