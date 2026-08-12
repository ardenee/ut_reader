# UnrealDB database schema and migrations

## Consolidated baseline

`catalog/install.sql` is the canonical **squashed baseline** for a new empty MySQL 8+ or compatible MariaDB database. The current consolidated baseline contains schema and seed-data changes through version `202608090002`.

Historical migrations represented by that baseline have been retired. Existing installations may still contain their rows in `ue_schema_migrations`; the migration runner reports those rows as `archived` rather than treating the removed files as drift.

The baseline intentionally omits retired row-per-object verified metadata tables. Current verified metadata is format-2 compact metadata plus maintained lookup/summary projections.

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

This is intentional: active post-baseline migrations remain immutable upgrade boundaries until the next deliberate schema squash.

## Current incremental migrations

The current code requires these migrations newer than baseline `202608090002`:

### `202608110001_unverified_game_match_cache.php`

Creates `ue_unverified_game_match_cache`, the cached exact dependency/object-path game-match projection used by background refresh and the Unverified page.

### `202608120001_job_workflow_recovery_logging.php`

Extends `ue_background_jobs` with:

- `parent_job_id`
- `workflow_unit_key`
- parent/status index
- unique `(parent_job_id, workflow_unit_key)` identity
- self foreign key with `ON DELETE CASCADE`

It also creates `ue_job_logging_settings` with errors-first defaults.

These columns are required by the current resumable parent/child workflow architecture. Apply this migration before starting workers running the current code.

## Deployment sequence

For an upgraded installation:

```text
# stop/restart detached workers around the code/schema deployment
php catalog/bin/migrate.php status
php catalog/bin/migrate.php migrate --dry-run
php catalog/bin/migrate.php migrate --lock-timeout=60
php catalog/bin/migrate.php verify
php catalog/bin/verify-resumable-job-workflows.php --database
```

Restart workers after migration so all worker processes load the current job-type/handler graph.

## Applied migrations are immutable

Migration files are checksum-protected. Once a migration has been applied to any installation, do not edit that file for comments, formatting, whitespace or implementation fixes.

Follow-up schema/data changes require a new versioned migration.

The migration runner rejects changed checksums and missing migration files above the consolidated baseline.

## Future schema changes

New migration files belong in `catalog/migrations/` and must:

- use a version greater than the consolidated baseline/current migration set;
- follow the immutable `<version>_<name>.php` format;
- be idempotent because MySQL/MariaDB DDL may commit implicitly;
- remain incremental until a deliberate reviewed schema squash updates `catalog/install.sql` and the migration README together.

## Runtime database access

New runtime SQL belongs in intent-specific Infrastructure query/repository objects under `catalog/src/Infrastructure`, not in pages/API entry points.

Verified package metadata reads are compact-only. Object-level dependency reads use the current compact dependency source; aggregate dependency views use maintained package summaries. Runtime SQL-shape emulation of retired metadata tables has been removed. `php catalog/bin/audit-legacy-runtime-references.php` enforces this boundary.

## Durable job database model

The durable job subsystem uses:

- `PdoJobQueue` as the application-facing queue implementation;
- `PdoJobEnqueuer` for inserts/idempotent child creation;
- `PdoJobClaimer` for parallel `SKIP LOCKED` claims plus resource/concurrency admission;
- `PdoJobLeaseStore` for heartbeat/completion/failure/cancellation/defer;
- `PdoJobRecovery` for expired-lease recovery;
- `parent_job_id` + `workflow_unit_key` for independently restartable workflow units;
- preserved `progress_json` for live progress and exact-cursor/phase recovery;
- generated/indexed `display_status` for live admin reads.

Routine event logging is separate from durable queue progress/results and can be reduced to errors-only without affecting recovery.
