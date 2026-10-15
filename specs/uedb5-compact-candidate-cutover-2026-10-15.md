# UEDB5 compact candidate production cutover — 2026-10-15

> **Current production status (15 October 2026):** UnrealDB uses **UEDB5-only** metadata registration and runtime. All **235,239** verified packages have validated format-5 registration; the bounded production verifier and **17/17** current regressions passed. The canonical live metadata tree contains **235,239 `.uedb5` files and no V1-V4 containers**. Actual upload, stable-ID reimport, backup restore, and non-deferred deletion/reconciliation were successfully exercised across all seven games against isolated MySQL; the results do not imply a new exhaustive production-package parse. Historical V4 staging/candidate statements below are **not current operational instructions**. See [the maintained V5 lifecycle evidence](../catalog/docs/UEDB5-V5-LIFECYCLE-INTEGRATION.md).


## Status: completed

The SQL-only compact candidate backfill and production cutover have completed in the existing `hwmvlite_ut_reader_catalog` MySQL database on PRD (`Lelly-pc`).

### Durable backfill
- All seven games completed: **235,239 files** at their final checkpoint positions.
- No re-read or reprocessing of package files was needed for the bulk migration. The SQL backfill reconstructed compact candidates from existing validated V5 SQL identities.
- Each file was checked against source SQL candidates with transactional commit and checkpoint update; missing or ambiguous dictionary identities were rejected.
- Original and replacement candidate lookups matched in the targeted pre-cutover checks.

### Production runtime
- `catalog/src` has zero direct references to `ue_uedb5_name_candidates` or `ue_uedb5_object_candidates`.
- Metadata search, misnamed-file provider discovery and V5 parity search query compact candidate tables through `ue_uedb5_compact_name_dictionary`.
- The base projection publisher, migration validator, name synchronizer and object synchronizer use the compact candidate schema.
- The canonical `catalog/install.sql` and `Uedb5SqlProjectionContract` define the compact schema for fresh installations.
- The compact publisher and individual synchronizers passed transaction/rollback testing; targeted SQLite tests for projection repair, parity search, schema and source-backed validation passed.

### Legacy retirement
1. Both old candidate tables were atomically renamed to `ue_uedb5_legacy_retired_name_candidates` and `ue_uedb5_legacy_retired_object_candidates`.
2. Eight verification suites passed after renaming, proving the tested runtime no longer depended on their original names.
3. A live UT4 compact search for `soundconcurrencysettings` returned eight matching file IDs; V5 validation of file `114136` returned ready with 25 names and one object.
4. The retired tables were then **dropped** after checks for 235,239 completed files, three compact tables and no foreign-key dependents. The database views, routines and triggers scan found no references.
5. Eight suites and the same live search/validator smoke checks passed after the drop.

### Storage
- Before dropping retired tables, Windows reported `D:` free space of 250,647,887,872 bytes.
- After dropping them, Windows reported 276,416,860,160 bytes free: **25,768,972,288 bytes / 24.0 GiB** of additional physical free space.
- The former two candidate tables accounted for approximately 138 GiB of InnoDB logical data-plus-index allocation estimates; this must **not** be confused with the measured physical free-space gain.
- The old `.ibd` files are absent from the database directory; compact `.ibd` files remain.

### Boundaries and retired utilities
- Backfill/legacy table comparison commands and historical benchmarks that reference the former table names are **retired** and cannot be used against the new production schema. Do not rerun the migration.
- Do not restore old candidate tables or reintroduce old-name dual writes/fallbacks. The compact name dictionary uses a surrogate name_id; exact SHA-256 fingerprints and source-backed V5 package metadata remain the semantic authority.
- No complete external HTTP/browser-site regression run is asserted here. The recorded passing checks are targeted live SQL-backed search, validator, transaction/rollback, and contract tests.
