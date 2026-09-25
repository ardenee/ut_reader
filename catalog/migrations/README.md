# Database migrations

For a complete fresh-install sequence, PHP/Apache/MySQL prerequisites and first-run setup, see the [installation guide](../../docs/installation.md).

`catalog/install.sql` is the consolidated base schema for a new UnrealDB installation. Schema changes newer than that baseline are delivered as ordered, immutable migration files in this directory.

The current base schema represents baseline `202609240002`.

## Current post-baseline migrations

- `202609250001_ue3_export_identity_projection.php` adds the exact class-package, class-name, object-flags and outer-index fields required to reproduce UE3 `VerifyImportInner()` matching from the existing v4 export-path projection.

Versions through `202609240002` remain consolidated into `catalog/install.sql` and retired from this directory.

A fresh/current deployment loads `catalog/install.sql`; the migration runner is then used only for schema changes newer than `202609240002`.

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

For the current codebase, an existing installation should already be migrated through baseline `202609240002`; future migration files newer than that baseline must be applied before matching application/worker code is started.

## Consolidated current prerequisites

The schema requirements formerly introduced by migrations `202608110001` through `202609240002` are now part of the fresh-install baseline, including resumable job workflow identity, metadata publication state, dependency refresh indexes, GeoIP/access telemetry, public upload controls, invalid-file identities, package coverage caches, and metadata-format-4 identity projections.

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


## Metadata format 4 cutover

Production metadata is now format 4 (`.uedb4`) only. The runtime reader/writer does not support `.uedb2` or `.uedb3`.

The v3 -> v4 bridge is deliberately isolated under `catalog/bin/v4-migration/` and is used only by:

```text
php catalog/bin/migrate-uedb3-to-uedb4.php
```

After all verified metadata registrations are format 4, obsolete files are removed with:

```text
php catalog/bin/cleanup-obsolete-metadata-files.php --apply
```

For a future v5+, do not add compatibility branches to production metadata classes. Add an offline prior-format reader/loader under the matching migration directory, publish the new extension/magic/version atomically, then remove the previous-format files after database coverage verifies cleanly.
