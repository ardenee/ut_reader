# UnrealDB database migrations

## Supported workflow

`catalog/install.sql` remains the baseline for a new empty database. Every deployment must then run the ordered migration command before starting or replacing web and worker processes.

```text
php catalog/bin/migrate.php status
php catalog/bin/migrate.php migrate --dry-run
php catalog/bin/migrate.php migrate --lock-timeout=60
php catalog/bin/migrate.php verify
```

Do not import `catalog/install.sql` over a populated database.

## Migration files

Migration files live in `catalog/migrations/` and use an immutable numeric version prefix:

```text
202607180001_remember_login.php
202607180002_package_aliases.php
202607180003_dependency_metadata.php
202607180004_unverified_staging.php
202607180005_job_reliability.php
202607180006_job_resource_limits.php
```

Migration 005 adds durable background-job progress, heartbeat, cancellation, lease-recovery and dead-letter metadata. Existing queued and running jobs retain their payload and attempt data while the status enum is widened.

Migration 006 adds persisted resource classes, per-class limits and target concurrency keys. Existing dependency rebuilds are assigned to the exclusive `dependency-heavy` class; upload-progress pruning is assigned to `housekeeping`. Other existing jobs retain the bounded `default` class.

Each migration is idempotent because MySQL and MariaDB DDL can commit implicitly. A failed migration is not recorded in `ue_schema_migrations`; rerunning it must safely continue from any structure already created before the failure.

Never edit an applied migration. Add a new migration. The runner records a SHA-256 checksum and refuses to continue when an applied file changes or disappears.

## Locking and concurrency

The runner obtains a database-scoped MySQL advisory lock before changing schema. Only one deployment or operator can apply migrations at a time. The default wait is 30 seconds and may be changed with `--lock-timeout` or `UNREALDB_MIGRATION_LOCK_TIMEOUT`.

The status and dry-run commands do not create the migration table or change schema.

## Existing deployment upgrade

1. Stop or pause maintenance operations that write large batches.
2. Allow active workers to finish or request cancellation at a safe checkpoint.
3. Back up MySQL and package storage.
4. Deploy code that supports both the current and upgraded schema.
5. Run `migrate --dry-run` and review the pending versions.
6. Run `migrate --lock-timeout=60` from a trusted CLI.
7. Run `verify`.
8. Start or roll out web and worker processes.
9. Test login, search, upload, dependency display, one queued job, cancellation, dead-letter retry and resource-class admission.

The current migrations are additive or widen existing columns. Destructive changes require a separate expand-and-contract release sequence.

## Fresh installation

1. Create the empty database.
2. Import `catalog/install.sql`.
3. Configure `catalog/config.php` or `UNREALDB_CATALOG_CONFIG`.
4. Run `php catalog/bin/migrate.php migrate`.
5. Run `php catalog/bin/migrate.php verify`.
6. Create the first administrator with `catalog/bin/create-admin.php`.
7. Start the application.

Docker Compose performs step 4 through its one-shot `migrate` service and blocks web and worker startup if migration fails.

The Kubernetes production workflow runs a one-shot Job using the immutable release image before applying the web and worker Deployments. A migration failure stops the rollout.

## Rollback policy

Application rollback is allowed only while the previous image remains compatible with the migrated schema. Migrations 005 and 006 are additive apart from widening the job-status enum. Older code ignores the resource columns, but do not roll back to an image that writes terminal failures without understanding existing `dead_letter` rows.

Do not automatically run down migrations. Data-removing changes require a reviewed forward-fix or a separately tested restore procedure.

## Legacy SQL upgrade files

The older `catalog/upgrade-*.sql` files are retained temporarily for historical reference. New deployments and upgrades must use `catalog/bin/migrate.php`; the SQL files are no longer the release-order source of truth.
