# UEDB5 V5-only lifecycle integration checkpoint

Updated 2026-10-15. Branch: `main`, baseline `b5e4e8cc`.

## Verified with actual code

| Path | Test | Evidence / limits |
| --- | --- | --- |
| Fresh source parse → new V5 metadata → compact SQL | `catalog/bin/verify-uedb5-source-sql-publisher-isolated.php` | Seven real verified game packages passed source parse, V5 registration inspection, compact publish and rollback on 2026-10-15. Source read-only; disposable V5 files; SQL session-temporary. |
| V5 dependency resolution and provider arbitration | `catalog/bin/diagnose-uedb5-full-provider-parity-isolated.php` | Seven bounded game samples matched stored import outcomes/reasons. Only Unreal II sample included selected external physical providers. |
| Dependency SQL projection | `catalog/bin/verify-uedb5-dependency-sql-isolated.php` | Actual publisher exercised against temporary SQL projection tables. |
| Worker claim and terminal transitions | `catalog/bin/verify-uedb5-worker-claim-lifecycle-isolated.php` | Actual `JobWorker` and dependency handler; isolated in-memory queue, NOT durable MySQL. |
| Repair workflow affinity | `catalog/bin/verify-uedb5-repair-workflow-isolated.php` | Real worker releases affinity on failed/cancelled/dead-letter child and retains it for queued child. |
| Reimport: missing source, stale ID, verified relational rollback | `catalog/bin/verify-uedb5-reimport-safety-isolated.php` and `catalog/bin/verify-uedb5-reimport-rollback-isolated.php` | Actual service/support code; SQLite only. Does NOT execute successful scanner replacement. |
| Removal: success, precommit rollback, postcommit failure, transaction ownership | `catalog/bin/verify-uedb5-removal-isolated.php` | Actual removal service on disposable local files and isolated SQLite fixtures. Does NOT exercise non-deferred successful dependency reconciliation. |
| Bounded integrity | `catalog/bin/verify-uedb5-post-cutover-bounded.php` | Production inspection, no package rescan. |

## End-to-end gaps (do not mark complete)

1. **Complete successful scanner-driven reimport**: fresh parser → stable `ue_files` ID → compact metadata → provider and dependency SQL projections → downstream affected-file refresh.
2. **Upload and backup restore**: normal, duplicate, fault and rollback paths against complete disposable storage and database.
3. **Durable real-MySQL job lifecycle**: `PdoJobQueue` enqueue → claim with admission/resource/root locks → heartbeat → handler → dependency publication → persisted completion/retry.
4. **Non-deferred deletion**: successful affected dependency refresh/reconciliation and post-commit failures with durable retry.
5. **Other-game external-provider parity**: the six non-Unreal-II fixtures exercise no selected external provider.
6. **Final cutover cleanup**: identify remaining historical V4-only schema/code and live readers/writers before dropping any old tables; reconcile PRD/DEV/GitHub and update specifications.

## Hard isolation blocker

The PRD PHP database account connects to `hwmvlite_ut_reader_catalog` (MySQL 8.4.11), has no visible test/sandbox database, and no database-creation grant. It has privileges on the **live** schema only.

MySQL session-temporary tables can support source/SQL publisher tests but **cannot** support the production `PdoJobClaimer` because its self-joins produce `Can't reopen table`. Do not replace this with persistent test tables in production.

**Required**: provision a completely independent disposable MySQL database/instance with equivalent schema and ordinary tables, plus disposable package/metadata storage and queue workers pointing exclusively to it. Verify its connection identity and paths before any destructive tests. A containerized local MySQL instance is acceptable if it is independently provisioned and isolated.

## Operating rules

- Do not reparse/rescan 235,239 packages; use bounded known-good samples.
- No production queue jobs, persistent SQL writes, storage rename/unlink or backup-restore changes for tests.
- Preserve Epic-source parity and never enable V4 fallbacks or silent unsupported-version gates.
- Report **which** layer passed: source parser, compact publisher, worker with fake queue, actual durable queue, or full user lifecycle.
- When finishing an integration gap, update this checklist with fixture identity, exact command, outcome and any limits. Avoid rerunning unchanged broad audits.

## Candidate-table cutover verification (2026-10-15)

Command: `C:\php8.5\php.exe catalog\bin\verify-uedb5-candidate-cutover-readonly.php`

Result: **passed**. The live catalog database exposes exactly two tables with `candidate` in their names: `ue_uedb5_compact_name_candidates` and `ue_uedb5_compact_object_candidates`. No other candidate-named tables remain, and the retired-table runtime scanner reports 0 source files / 0 references for its retired vocabulary.

**Do not drop either compact table:** both are active production V5 search and SQL projection dependencies. This bounded evidence supersedes older notes claiming separate *candidate-named* tables await deletion; it does **not** prove all obsolete V1-V4 tables or files have been removed. The runtime diagnostics include V4-era reason labels whose semantics must be audited separately before removal.


## All four requested lifecycle milestones — 2026-10-15 standalone MySQL integration

**Status: four successful, bounded end-to-end paths verified.** This section supersedes the earlier “End-to-end gaps” section for these *successful-path examples only*. Failure and concurrency coverage beyond the named examples remains incomplete.

### Isolation

- Separate MySQL 8.4 instance on **127.0.0.1:3339**, physical data directory **C:\\Temp\\uedb5-isolated-mysql\\data**, schema `uedb5_isolated_integration`; independent of production MySQL service and production database.
- Ordinary tables created from the live catalog schema **structure only**; no wholesale production table data imported.
- Four explicitly selected UE2 verified package rows, associated V5 registration/status/provider keys and copied V5 containers were used as fixtures.
- Storage: `catalog/storage/uedb5-isolated-reimport-20261015` (junction-backed path), physically inside the catalog's configured storage mount.
- Test scripts verify the standalone server port and physical data path before using writable PDO connections. Do not run against the live MySQL port or live package storage.
- Important: these integration scripts use **mutable, non-idempotent fixtures**, including deletes. Prepare a fresh standalone database/storage snapshot before replaying the destructive cases. Do not run a successful delete test twice against the same rows.

### Verified actual workflows

| Milestone | Isolated evidence | Result |
| --- | --- | --- |
| **1. Scanner-driven reimport** | `verify-uedb5-reimport-standalone.php`: file 111139 (`343T.utx`), real `sync_reimport`; 298 names, 31 imports, 236 exports; stable file ID and MD5, V5 registration/container verified | PASS |
| **2a. Normal upload** | `verify-uedb5-upload-standalone.php`: `CreditsT.utx` created verified file 111195; 20 names, 3 imports, 2 exports, four V5 blocks | PASS |
| **2b. Game backup restore** | `verify-uedb5-backup-entry-standalone.php`: `DetailTextures.utx`, imported file 111196; durable entry job 8 completed with V5 publication | PASS |
| **2c. Full game backup coordinator** | `verify-uedb5-backup-workflow-standalone.php`: `Glands.utx`; parent job 9 completed, child import 10 completed, game dependency job 11 completed; imported=1 | PASS |
| **3. Durable MySQL queue + V5 dependency publication** | `verify-uedb5-durable-dependency-standalone.php`: consumer file 111139, three physically copied external providers; job 5 completed, 31 dependency edges and 7 summary rows, V5 dependency policy `uedb5-dependency-pass-v13`; independent invalid job dead-letter persistence tested | PASS |
| **4. Non-deferred deletion and full reconciliation** | `verify-uedb5-nondeferred-delete-standalone.php`: provider file 111194 (`WeaponFXT.utx`) physically/catalog-deleted; six prior resolved provider edges became zero; reconciliation parent 19 and both file children completed; two affected consumer files, 76 targeted imports, two summaries and one game statistics refresh; zero failures | PASS |

### Runtime fixes proven by the integrations

1. `LocalFilesystemPackageStorage`: recorded `relative_path` now derives from the **canonical configured storage mount**, including Windows junctions and nested isolated storage subtrees. Production mount produces `storage/games/...`; nested mount produces `storage/<subdir>/games/...`. Paths outside the catalog storage mount are rejected. Verified by `verify-uedb5-storage-junction-isolated.php` (3 checks).
2. `GameBackupImportJobHandler`: import the real `Infrastructure\\Storage\\GameBackupStore` class, pass the correct config array to its constructor, call `resolveBackupFile()` instead of absent `entryPath()`, load scanner path helpers and import `VerifiedFileCompactMetadataFinalizer`.

### Regression result

On 2026-10-15, 11 relevant suites exited 0:
`verify-uedb5-storage-junction-isolated.php`, `verify-uedb5-reimport-safety-isolated.php`,
`verify-uedb5-reimport-rollback-isolated.php`, `verify-uedb5-removal-isolated.php`,
`verify-uedb5-repair-workflow-isolated.php`, `verify-uedb5-dependency-sql-isolated.php`,
`verify-import-dependency-consistency-contract.php`, `verify-upload-worker-contracts.php`,
`verify-game-lifecycle-boundaries.php`, `verify-uedb5-post-cutover-bounded.php`,
`verify-uedb5-candidate-cutover-readonly.php`.

### Remaining coverage, not blockers to the four success paths

- These are **bounded UE2 workflows**, not a claim that all seven engine profiles passed every lifecycle operation.
- Concurrent workers, lease expiry/retry, alias variations, complete backup export, and fault-injected restores/deletions require further isolated test cases.
- The standalone MySQL fixture is **not production traffic or production queue certification**. PRD cutover remains subject to production reconciliation and V4 artifact cleanup.
- Do not assume that hard-coded one-shot fixture scripts are safe to rerun after deletion or backup import; **preserve this checkpoint** and use a freshly reset standalone database for additional full lifecycle runs.
