# Database migrations

> **Current status:** [Current project status](../../docs/current-project-status.md) records the authoritative 15 October 2026 V5 production checkpoint.

For a complete fresh-install sequence, PHP/Apache/MySQL prerequisites and first-run setup, see the [installation guide](../../docs/installation.md).

`catalog/install.sql` is the consolidated base schema for a new UnrealDB installation. Schema changes newer than that baseline are delivered as ordered, immutable migration files in this directory.

The current base schema represents baseline `202609240002`.

## Current post-baseline migrations

- `202609250001_ue3_export_identity_projection.php` adds the exact class-package, class-name, object-flags and outer-index fields required to reproduce UE3 `VerifyImportInner()` matching from the existing v4 export-path projection.
- `202609260001_class_remaps.php` adds game-scoped administrator ClassRemap configuration used as an explicit UE1/UE2 dependency-resolution fallback.

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




## Historical V4 compact-term ID handling (retired)

The old `ue_terms` and associated V4 lookup tables were retired. The former `compact-ue-term-ids.php` compaction/rekey tool is **not shipped and must not be executed**. V5 uses a separate compact name dictionary and candidate schema; consult the current V5 SQL publication contract and current project status.

## UEDB5 production metadata cutover

Production verified-file metadata uses UEDB5 (.uedb5) exclusively.
Active runtime readers and verified-file publication must not read or publish UEDB4.
Legacy V1-V4 verified-metadata tables have been retired from the checked production schema; do not reintroduce them. Both compact V5 candidate tables remain required.
V5 source-only runtime check: `php catalog/bin/verify-uedb5-cutover-ready.php`. Bounded production check: `php catalog/bin/verify-uedb5-post-cutover-bounded.php`.
Historical V3-to-V4 migration and obsolete file cleanup tools are retired; do not use them against V5.
