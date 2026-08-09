# UnrealDB database schema and migrations

## Consolidated baseline

`catalog/install.sql` is the canonical **squashed baseline** for a new empty MySQL 8+ or compatible MariaDB database. The current baseline contains schema and seed-data changes through version `202608030001`.

Historical migrations represented by that baseline have been retired. Existing installations may still contain their rows in `ue_schema_migrations`; the migration runner reports those rows as `archived` rather than treating the removed files as drift.

The fresh-install baseline intentionally omits retired row-per-object metadata tables. Current verified metadata is stored in format-2 compact containers and current lookup/summary projections. Post-baseline retirement migrations are idempotent and therefore remain safe for fresh installations where the retired tables were never created.

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

This keeps a fresh installation identical to an upgraded production database even when new migrations have been added since the last schema squash.

## Current incremental migrations

Migrations newer than the consolidated `202608030001` baseline include staged-import concurrency, job resource/display read models, base-game policy schema, dedicated unverified compact metadata staging, and physical retirement of the former row-per-object metadata tables. They remain ordinary immutable migrations until the next deliberate schema squash.

In particular, `202608080001_background_job_display_status.php` adds the stored/indexed `ue_background_jobs.display_status` read model used by the Background Jobs APIs. Runtime code assumes that migration has been applied.

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

Verified package metadata reads are compact-only. Object-level dependency reads use the current compact dependency source; aggregate dependency views use maintained package summaries. Runtime SQL-shape emulation of retired metadata tables has been removed. `php catalog/bin/audit-legacy-runtime-references.php` enforces this boundary; explicit historical migration, staging conversion and retirement tooling are the only permitted exceptions.

The durable job subsystem uses:

- `PdoJobQueue` as the single application-facing queue implementation;
- `PdoJobEnqueuer` for inserts/deduplication;
- `PdoJobClaimer` for parallel `SKIP LOCKED` claims plus resource/concurrency admission;
- `PdoJobLeaseStore` for heartbeat/completion/failure/cancellation;
- `PdoJobRecovery` for expired-lease recovery;
- generated/indexed `display_status` for live admin status reads.
