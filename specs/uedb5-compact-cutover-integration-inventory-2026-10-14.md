# UEDB5 compact candidate production cutover inventory — 2026-10-14

## Validation checkpoint

The compact backfill rehearsal passed exact full-row candidate parity across **60 files in seven games** (30 UT4 and five files each for six other games). Results: 5,924 name candidates and 4,492 object candidates, no differences reported. The session-only backfill executor is `catalog/bin/rehearse-uedb5-compact-backfill-batch.php`.

The durable progress SQL is proposed in `catalog/sql/uedb5-compact-backfill-progress.sql`; session-only regression `catalog/bin/verify-uedb5-compact-progress-contract.php` passed 4/4 checks (commit, replay, rollback, monotonic cursor). None of these statements has been applied as permanent DDL.

## Exact runtime dependencies from current PRD audit

Writers:
- `Infrastructure/Metadata/PdoUedb5BaseProjectionPublisher.php`: initial/replacement name and object publishing.
- `Infrastructure/Metadata/PdoUedb5NameCandidateSynchronizer.php`: targeted name repairs.
- `Infrastructure/Metadata/PdoUedb5ObjectCandidateSynchronizer.php`: targeted object repairs.
- `Infrastructure/Games/PdoCatalogGameTableMaintenance.php`: candidate game-table maintenance and deletion.
- Additional callers of base publishing: `Uedb5GameSourceMigrationService`, `Uedb5MissingObjectProjectionRepair`, `CompactFileMaintenanceSnapshot`, `CatalogFileMaintenanceSupport`, and `Uedb5VerifiedFilePublisher`.

Readers and validators:
- `Infrastructure/Search/Uedb5CatalogMetadataSearch.php`: exact and broader name/object search.
- `Infrastructure/Maintenance/CatalogMisnamedFileDetector.php`.
- `Infrastructure/Metadata/Uedb5MigrationValidator.php`.
- `Infrastructure/Metadata/Uedb5ParityV5ReadService.php`.
- `Infrastructure/Metadata/Uedb5MissingObjectProjectionRepair.php`.
- `Infrastructure/Metadata/Uedb5CutoverReadinessVerifier.php`.
- `Infrastructure/Metadata/Uedb5SqlProjectionContract.php` and `Infrastructure/Persistence/PdoDependencySchemaManager.php` supply DDL/verification contracts.

## Mandatory ordering before production backfill

1. Validate persistent compact schema including foreign keys and expected indexes; no separate database is needed, but production schema changes require a deliberate maintenance plan.
2. Integrate **all** candidate writers, including targeted repairs and deletes, into a coordinated transactional dual-write strategy or enforce an exclusive maintenance period in which conflicting writes are disabled.
3. Ensure file-id locking, compact publishing, full-row parity and checkpoint advance share the correct transactional boundary. A cursor alone cannot guard against edits to earlier files.
4. Implement real shared-table multi-connection concurrency tests (session-only temporary tables cannot test cross-session contention), retryable failures and restart semantics.
5. Backfill in bounded cursor batches with counts and fingerprint/object/public-export parity. Report mismatches before advancing cursor. Test against live modified files and late updates.
6. Replace all search, maintenance, parity and validator references with compact equivalents; test the **full** dependency matrix.
7. Only after durable complete parity and operational rollback checks, retire legacy candidate tables and measure reclaimed disk.

**Safety constraint:** The opt-in compact publisher/lookup are not active production paths. The permanent backfill must not be run until the writer-coordination prerequisite is met. No permanent schema or data changed in this checkpoint.
