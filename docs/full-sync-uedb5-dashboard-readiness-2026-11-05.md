# Full Sync → UEDB5 → Games statistics readiness (5 November 2026)

## Decision

Game-scoped Full Sync **does** regenerate UEDB5 metadata from each original verified package, then republish dependencies and refresh the game counters used by `catalog/games.php`. The workflow was audited and the two missing *completion-safety* gates were corrected. No Full Sync was started during this review.

## Proven execution chain

1. `catalog/full-sync.php?game_id=2` displays current verified game scope and POSTs only on explicit administrator action.
2. `catalog/api/v1/full-sync-job.php` enqueues the durable `catalog.full_sync_game` workflow, then wakes the worker pool.
3. `CatalogFullSyncJobHandler` clears only game-scoped derived dependency/provider rows, plans one `catalog.full_sync_file` unit per verified file, and blocks advancing until all child jobs succeed.
4. `CatalogFullSyncUnitJobHandler` calls `CatalogFileMaintenanceActionService::execute('sync_reimport',...)`; `CatalogFileMaintenanceReimportService` reads the stored original and rescans it with the selected game's updated reader.
5. `VerifiedFileCompactMetadataFinalizer` calls `Uedb5VerifiedFilePublisher`, which calls `Uedb5SourceSnapshotFactory::buildForGameId()` (UE2 game-specific reader/policy), `Uedb5MetadataSnapshotWriter::write()` (fresh v5 container and verification), registration, base-table projection and first dependency pass.
6. `CatalogFullSyncJobHandler` checks provider registration, then enqueues bounded dependency batches through `CatalogFullSyncDependencyBatchService` and `PdoCatalogDependencyRebuilder`. Bad batch members are isolated for retry.
7. `CatalogFullSyncProjectionService::finalize()` rebuilds package coverage and `PdoGameCatalogStats::rebuildGame()`, updating `ue_game_catalog_stats`. The new completion guard calls `Uedb5DependencyPublicationScope::isGameStatsCurrent()` using the same gate as the `PdoGameCatalogListQuery` displayed by `catalog/games.php`.

**Reader rejection:** the old code returned a successful child with `retained_reader_incompatible` while still using old metadata. That was incorrect for a requested full regeneration. The new behaviour records a source-validation error, preserves originals/previous metadata, **fails** the child, and prevents the parent from completing until that child is fixed/retried. Normal successful file units still complete.

**Dashboard completion gate:** the cached stat rows must not only be rebuilt; the same freshness check used by Games must accept every verified file's exact current dependency policy/payload SHA and the aggregate update timestamp. Otherwise Full Sync finalization fails rather than claiming the page is current.

**Worker freshness:** `CatalogWorkerCodeVersion` now includes Full Sync projection/reimport services and UE2 game-source selection/verification helpers so detached workers detect changed code instead of retaining stale loaded implementations.

**No UEDB5 backups:** `Uedb5MetadataSnapshotWriter` constructs a random `.tmp` file, uses an atomic replacement, verifies the resulting `.uedb5`, and cleans up the temporary file. It does not persist a `.bak` UEDB5 copy. The maintenance code can read a *transient in-memory* previous snapshot to roll back failures; that is not a backup copy created on disk. Original packages are retained. The scanner temporarily copies the original for reimport and cleans it up.

## Read-only production baseline (game 2, captured on 5 November 2026)

- Unreal II: 1,217 verified packages, 1 duplicate; 1,217/1,217 dependency publication markers.
- Cached game statistics: 1,218 files, 1,217 verified; 110,970 dependencies, 0 resolved, 71,675 unresolved, 39,295 common, 0 confirmed missing.
- Stats last updated `2026-11-04 16:28:15`; both `isGameCurrent` and `isGameStatsCurrent` returned true.
- Unauthenticated HTTPS GET returned HTTP 403; this is an admin-only endpoint. Authenticated PRD UI was not exercised.
- No API POST, production write, background job start, original file change, or UEDB5 rescan was triggered.

## Focused change evidence

- `tests/tools/verify_full_sync_reimport_failure_gate.php`: in-memory SQLite and fake maintenance injection. A reader-incompatible file produces a blocking error and no completed child; a successful reimport marks the child complete; no production state changed.
- `tests/tools/verify_full_sync_dashboard_freshness_gate.php`: isolated SQLite package/aggregate publication scenarios. Stale SHA, missing aggregate, and outdated aggregate are rejected; current/rebuilt aggregate accepted.
- Changed PHP files passed `php -l`; change checked with `git diff --check`.
- Existing source and metadata profiles were not modified by this follow-up review.

## Expected behavior for the user's full resync

A completed parent job now means **all of its reimport and dependency child jobs succeeded and the Games counters passed the current-publication gate**. Failed or dead-letter children require repair and retry; the page will not show false refreshed dependency numbers while the job is partial. The game-scoped workflow deliberately excludes duplicates and other unverified files from the source pass.

The read-only pre-sync baseline above may be used for comparison, but any change in resolved/missing counts requires source/evidence evaluation rather than assuming a larger number is better.
