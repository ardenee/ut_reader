# Database migrations

`catalog/install.sql` is the consolidated base schema for a new UnrealDB installation. Schema changes newer than that baseline are delivered as ordered, immutable migration files in this directory.

The current base schema represents baseline `202608090002`.

## Current post-baseline migrations

The current migration sequence is:

- `202608110001_unverified_game_match_cache.php` — adds the cached per-unverified-file exact game/dependency match projection used by background refresh and fast Unverified page reads.
- `202608120001_job_workflow_recovery_logging.php` — adds durable parent/child workflow identity (`parent_job_id`, `workflow_unit_key`) and job-event logging settings used by resumable/recoverable background workflows.
- `202608130001_program_upload_settings.php` — adds `ue_program_settings`, currently used for administrator-configurable program/upload ingress limits.
- `202608140001_verified_metadata_publication_state.php` — adds explicit compact-metadata publication state to `ue_files` (`metadata_status`, `metadata_error`, `metadata_updated_at`) so incomplete verified metadata publication can be detected and repaired rather than silently treated as healthy.

A fresh/current deployment loads `catalog/install.sql` and then runs the migration runner so every post-baseline migration is applied.

## Normal commands

Inspect migration state:

```text
php catalog/bin/migrate.php status
```

Preview pending changes without applying them:

```text
php catalog/bin/migrate.php migrate --dry-run
```

Apply pending migrations:

```text
php catalog/bin/migrate.php migrate
```

Verify migration history/checksums/schema state:

```text
php catalog/bin/migrate.php verify
```

For the current codebase, production should be migrated through the latest file in this directory before Apache/workers are expected to use the corresponding functionality.

## Important current prerequisites

### `202608120001`

This migration must be present before workers create resumable parent/child workflows. The recovery architecture relies on the parent/unit uniqueness constraint to make coordinator replay idempotent.

The workflow/schema prerequisites can be checked read-only with:

```text
php catalog/bin/verify-resumable-job-workflows.php --database
```

### `202608130001`

This migration provides the general program-settings table used by current upload ingress configuration. Without it, administrator-configurable upload/program limits cannot be persisted through the current settings model.

### `202608140001`

This migration makes verified compact-metadata state explicit. Existing verified files with a registered format-2 metadata container are marked ready; verified files without a current registration remain pending so maintenance can identify/repair them.

Current deployments should not assume that `scan_status = verified` by itself means compact metadata publication is complete; `metadata_status` is the explicit publication-state boundary introduced by this migration.

## Applied migrations are byte-immutable

Once a migration has been applied to any installation, **do not edit that migration file again**.

This includes:

- comments;
- audit headers;
- formatting;
- whitespace;
- line endings;
- descriptions;
- PHP/SQL behavior.

`MigrationRunner` stores a SHA-256 checksum of the complete migration file in `ue_schema_migrations`. If an already-applied migration file changes, verification/migration must fail rather than silently accepting schema-history drift.

Any follow-up schema or data change therefore requires a **new migration version**.

Documentation/audit tools must exclude applied migration files from cosmetic rewrites.

## Relationship to `install.sql`

Do not silently copy active post-baseline migration changes into `catalog/install.sql` while deployed databases still depend on those migrations as upgrade boundaries.

When the baseline is deliberately advanced, treat it as one coordinated schema-consolidation change:

1. confirm supported installations have migrated/verified successfully;
2. update `catalog/install.sql` to the new baseline;
3. archive the now-baseline migration boundary deliberately;
4. update the baseline version documented here;
5. verify both clean installation and upgrade-from-previous-baseline paths.

Existing installations retain their historical rows in `ue_schema_migrations`; the migration runner treats migrations consolidated into the current baseline as archived history.

## Production deployment rule

On the maintained single-host Windows deployment, schema changes should be applied deliberately before restarting code/workers that require them:

```text
backup if the release changes schema/storage
php catalog/bin/migrate.php status
php catalog/bin/migrate.php migrate --dry-run
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
restart/reconcile workers
run readiness/runtime verification
```

See [`../../docs/database-migrations.md`](../../docs/database-migrations.md) and [`../../docs/production-deployment.md`](../../docs/production-deployment.md) for the wider deployment policy.
