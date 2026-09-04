# Database migrations

`catalog/install.sql` is the consolidated base schema for a new UnrealDB installation. Schema changes newer than that baseline are delivered as ordered, immutable migration files in this directory.

The current base schema represents baseline `202608090002`.

## Current post-baseline migrations

The current migration sequence is:

- `202608110001_unverified_game_match_cache.php` — adds the cached per-unverified-file exact game/dependency match projection used by background refresh and fast Unverified page reads.
- `202608120001_job_workflow_recovery_logging.php` — adds durable parent/child workflow identity (`parent_job_id`, `workflow_unit_key`) and job-event logging settings used by resumable/recoverable background workflows.
- `202608130001_program_upload_settings.php` — adds `ue_program_settings`, currently used for administrator-configurable program/upload ingress limits.
- `202608140001_verified_metadata_publication_state.php` — adds explicit compact-metadata publication state to `ue_files` (`metadata_status`, `metadata_error`, `metadata_updated_at`) so incomplete verified metadata publication can be detected and repaired rather than silently treated as healthy.
- `202608170001_unverified_pak_members.php` — links retained neutral Upload Bucket PAK containers to their extracted package children and records ownership so assignment/deletion keeps the PAK and its contained packages together safely.
- `202608190001_dependency_refresh_performance.php` — adds generated/indexed package identity keys and targeted dependency-link indexes used by high-volume affected-dependency discovery and cached game-stat publication.

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

For the current codebase, production should be migrated through the latest file in this directory before the application and workers use the corresponding functionality.

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

### `202608170001`

This migration is required before a `.pak` can be processed through the neutral Upload Bucket. The original PAK is retained as a container row while each supported extracted package is indexed independently; `ue_unverified_pak_members` records which child belongs to the PAK and whether the PAK owns that child.

The ownership flag is the deletion/assignment safety boundary: an extracted child that was already present as a duplicate is linked but is never deleted merely because the PAK parent is removed.

### `202608190001`

This migration removes the remaining expression-heavy package identity comparisons from cached game-stat rebuilds by materializing normalized package/stem keys as STORED generated columns with supporting indexes. It also adds direct `(required_package_term_id,file_id)` and `(resolved_file_id,file_id)` indexes for affected-dependency discovery.

Apply this migration before restarting workers with the matching dependency-refresh code. `PdoGameCatalogStats` retains the previous expression-based query only as an upgrade-compatibility fallback while the migration is still pending.

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

Schema changes should be applied deliberately before restarting application code or workers that require them:

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




## Exhausted compact term IDs

Historical compact publication used duplicate-heavy `INSERT IGNORE` priming for `ue_terms`. InnoDB could consume AUTO_INCREMENT values for ignored duplicates, so an installation may reach the `INT UNSIGNED` ceiling while containing only a small fraction of 4.29 billion real terms.

Current writers resolve existing terms first and insert only genuinely missing terms. For an installation that already exhausted the live ID range, do **not** widen all projection tables to BIGINT merely to preserve sparse IDs. Use the resumable offline compaction utility instead:

```text
php catalog/bin/compact-ue-term-ids.php status
php catalog/bin/compact-ue-term-ids.php run --offline-confirmed
php catalog/bin/compact-ue-term-ids.php verify
php catalog/bin/compact-ue-term-ids.php cleanup --offline-confirmed
```

The run phase creates a dense old→new mapping and a compacted dictionary, then rekeys `ue_name_lookup`, `ue_dependency_links` and `ue_export_lookup` in bounded `file_id` ranges. Each range update and its resume cursor commit in the same transaction. The final dictionary swap occurs only after every reference table has been rekeyed. Apache/public writes and Background Jobs workers must remain stopped for the complete run + verify sequence because reference IDs and the active dictionary intentionally differ during the rekey.
