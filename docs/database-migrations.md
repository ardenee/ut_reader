# UnrealDB database schema and migrations

## Consolidated baseline

`catalog/install.sql` is the canonical **squashed baseline** for a new empty MySQL 8+ or compatible MariaDB database. The current baseline contains schema and seed-data changes through version `202608030001`.

Historical migrations represented by that baseline have been retired. Existing installations may still contain their rows in `ue_schema_migrations`; the migration runner reports those rows as `archived` rather than treating the removed files as drift.

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

Migrations newer than the consolidated `202608030001` baseline currently include worker/import concurrency and queue read-model changes. They remain ordinary immutable migrations until the next deliberate schema squash.

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

Verified package metadata reads use the compact metadata/lookup model. Transitional reads of retained Names/Imports/Exports/dependency compatibility sources must pass through the approved compact compatibility boundary. `php catalog/bin/audit-legacy-runtime-references.php` enforces this rule and rejects any return of the removed `ue_search_documents` projection.

The durable job subsystem uses:

- `PdoJobQueue` as the single application-facing queue implementation;
- `PdoJobEnqueuer` for inserts/deduplication;
- `PdoJobClaimer` for parallel `SKIP LOCKED` claims plus resource/concurrency admission;
- `PdoJobLeaseStore` for heartbeat/completion/failure/cancellation;
- `PdoJobRecovery` for expired-lease recovery;
- generated/indexed `display_status` for live admin status reads.
