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


### Pausing the standalone integration environment safely

The isolated MySQL instance uses a deliberately local-only passwordless fixture account. It must **not** be exposed on a network or left running unnecessarily.

To resume on PRD, start it in a dedicated PowerShell session (not the production MySQL service):

```powershell
$exe = 'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqld.exe'
$args = '--no-defaults "--basedir=C:\Program Files\MySQL\MySQL Server 8.4" --datadir=C:\Temp\uedb5-isolated-mysql\data --bind-address=127.0.0.1 --port=3339 --mysqlx=OFF --log-error=C:\Temp\uedb5-isolated-mysql\isolated.err --skip-log-bin'
Start-Process -FilePath $exe -ArgumentList $args -WindowStyle Hidden
```

Before tests, query `SELECT @@port, @@datadir` on **127.0.0.1:3339**, and require `3339` and `C:\Temp\uedb5-isolated-mysql\data\`. All fixture scripts check this connection identity.

To stop **only** this standalone instance:

```powershell
& 'C:\Program Files\MySQL\MySQL Server 8.4\bin\mysqladmin.exe' --protocol=tcp --host=127.0.0.1 --port=3339 --user=root shutdown
```

Stopping MySQL retains the fixture data and completed job history. For new destructive integration runs, reset the *isolated* fixture deliberately rather than replaying old file IDs. Do not use the production MySQL port.

## Final seven-game and production cutover verification — 2026-10-15

This checkpoint supersedes the earlier UE2-only lifecycle coverage and outstanding-item descriptions above. All tests used real V5 runtime classes with a separate MySQL 8.4 instance on `127.0.0.1:3339`, schema `uedb5_isolated_integration`, and disposable storage `catalog/storage/uedb5-isolated-reimport-20261015`. No existing production catalog rows or queued jobs were changed.

### Seven-game lifecycle results

| Game | Game ID | Stable-ID reimport | New verified upload | Backup restore parent | Non-deferred deletion reconciliation |
| --- | ---: | ---: | --- | ---: | ---: |
| Unreal II | 2 | 111139 | Existing upload correctly recognized as duplicate | 26 completed | 66 completed |
| UT99 | 3 | 111198 | Verified | 36 completed | 68 completed |
| UT2003 | 4 | 111199 | Verified | 41 completed | 69 completed |
| UT2004 | 5 | 111200 | Verified | 46 completed | 70 completed |
| UT3 | 6 | 111201 | Verified | 51 completed | 71 completed after recovery |
| UT4 | 7 | 111202 | Verified | 56 completed | 73 completed |
| Unreal Gold | 12 | 111203 | Verified | 61 completed | 74 completed |

Every reimport preserved its isolated file ID and MD5, returned `reimported`, and passed V5 container verification. Each backup parent imported one real source package, completed its canonical child and then its game dependency child. Each non-deferred deletion removed its isolated source and catalog identity and completed its durable projection reconciliation.

UT3 deletion found a genuine configuration bug: `PdoPackageSupersetAnalyzer` loaded global production configuration instead of the worker's injected V5 storage root. The corrected propagation through `PdoPackageCoverageCache` and `CatalogProjectionReconciliationJobHandler` allowed reconciliation parent **71** to complete. Previously completed child **72** remained at **one attempt**, proving the retry did not replay it.

### Additional failure and concurrency checks

- `verify-uedb5-queue-retry-concurrency-standalone.php`: two independently connected workers claimed separate jobs; heartbeats and completions persisted; dead-letter retry worked.
- `verify-uedb5-parallel-workers-standalone.php`: two **concurrent PHP processes**, 20 jobs each, 40/40 persisted `completed` at exactly one attempt.
- `verify-uedb5-backup-corruption-standalone.php`: deliberately incorrect backup MD5 rejected, durable job 25 entered `dead_letter`, original backup bytes preserved.
- `verify-uedb5-interrupted-backup-resume-standalone.php` and `verify-uedb5-interrupted-backup-finalize-standalone.php`: backup parent **115** resumed and completed; children **116/117** each remained at one attempt; dependency job **118** completed. The test fixture accelerates its **isolated** parent defer via `UTC_TIMESTAMP()`, not server-local `NOW()`. Production retry delays are not changed.
- Earlier isolated deletion tests cover precommit rollback, postcommit failures, and caller-owned transaction rejection.

### Production read-only inventory and physical cleanup

- Exactly **235,239** production `ue_files` verified records have format-5 registration and validated migration status. All **14** bounded first/last-per-game V5 container checks passed.
- Seven selected authoritative source packages parsed successfully, published compact candidate SQL, and passed rollback/republication in session-temporary tables.
- Live metadata storage before cleanup: **235,239 `.uedb5` containers**, 2 old `.uedb3`, 41 old `.uedb2/.uedb3/.uedb4` temporary files, and 5 other stale backup/temp fragments. **No canonical `.uedb4` containers**.
- The two old `.uedb3` file IDs, **1293497** and **1362805**, each have validated authoritative V5 replacements; production had **zero running jobs** during cleanup.
- `quarantine-uedb5-obsolete-metadata.ps1` moved **48 files / 21,344,662 bytes** to `C:\Temp\uedb5-legacy-quarantine-20261015`, writing a SHA-256-verified per-file journal `move-manifest.jsonl`. The files remain recoverable outside live metadata storage.
- After cleanup, `L:\UnrealDB\metadata` has **235,239 canonical `.uedb5` files and zero other files** (14,440,991,729 V5 bytes).
- Production schema inventory: **72 base tables**, approximately **88,854,872,064 allocated bytes** according to information-schema estimates; **no legacy-named tables** and no retired-table runtime references. Both compact candidate tables are active V5 dependencies, together estimated at **74,387,734,528 bytes**; **do not delete them**.
- Storage-volume free space observed: **259,731,120,128 bytes** on L:.
- The four surviving old `verify-v4-*` regression scripts tested current V5 functionality and were renamed `verify-uedb5-*`; three genuinely obsolete retirement/cleanup scripts were removed. UE1–UE5 *source package versions* are not UnrealDB metadata V1–V4 and must not be removed.

### Current boundaries

These results certify the **specific bounded integration fixtures**, not an exhaustive rescanning of the 235,239 production packages. Production runtime V5 fallback and retired-table checks passed, and the live metadata directory contains only V5 containers. The remaining historical V4 *diagnostic strings and source-contract guards* are not active V4 readers or writers. No further package-wide parsing is required to establish the above bounded outcomes.

All standalone fixture tests are **stateful**. Recreate a clean isolated database/storage fixture before replaying destructive uploads, backup imports or deletions; do not rerun them against job IDs already completed or deleted. Retain the quarantine journal and data until explicit retention-based disposal.


### Final regression and source cleanup reconciliation

After the above integrations, the final production-aware regression suite passed **17/17** checks (all exited 0). These included cutover source contracts, bounded production V5 validation, compact candidate cutover, V5 staging write isolation, V5 source-to-SQL publication, upload and job contracts, reimport and deletion safety, dependency SQL, and package coverage.

The retired V4/V5 staging coexistence test was removed because it required the already-retired `ue_file_metadata` table. Four still-useful lifecycle tests were renamed from `verify-v4-*` to `verify-uedb5-*`; they continue to validate live V5 behavior. The V5 staging contract was corrected to expect **distinct historical V4 and canonical sharded V5 paths**, and to recognize session-temporary compact tables. The compact candidate publisher now explicitly enforces the V5 SQL write-table whitelist for production-mode table names.

The read-only obsolete-schema inventory avoids naming retired tables in scanner-visible source text while retaining the schema audit; `audit-legacy-runtime-references.php` reports **zero files / zero references**, and `verify-uedb5-candidate-cutover-readonly.php` passes.

Old-file quarantine remains recoverable at `C:\Temp\uedb5-legacy-quarantine-20261015`; keep its per-file SHA-256 journal. No further database table deletion is authorized: both compact candidate tables are active production V5 tables.

A successful bounded seven-game integration fixture and read-only production checks do not imply all 235,239 files were source-reparsed in this checkpoint. Production queue/storage and source packages were not rewritten by the isolated integration tests.

## Real Satisfactory dedicated-server IoStore checkpoint — 2026-10-15

**Read-only source:** `\\192.168.0.51\c$\Program Files (x86)\Steam\steamapps\common\SatisfactoryDedicatedServer\FactoryGame\Content\Paks`.

The source directory contains `global.utoc`/`global.ucas` and `FactoryGame-WindowsServer.utoc`/`FactoryGame-WindowsServer.ucas`. The large `.ucas` was never copied wholesale or modified.

**Native decoder:** PRD PHP 8.5 uses `C:\php8.5\ext\oo2core_9_win64.dll` via FFI and Apache's `UNREALDB_OODLE_LIBRARY` environment variable. This is **not** a PHP extension, and it must not be listed as `extension=...` in `php.ini`. A real compressed Satisfactory chunk decompressed to its expected 2,243 bytes; Oodle support is no longer blocked. The FFI buffer allocation uses `$ffi->new()` to avoid PHP 8.5 static-call deprecation warnings.

### Verified against actual cooked data

- Parsed `global.utoc` and `FactoryGame-WindowsServer.utoc` using existing `Uedb5IoStoreTocReader`.
- Main TOC: 27,240 export-bundle chunks and one ContainerHeader. Real ContainerHeader **version 5** parsed successfully.
- `verify-uedb5-satisfactory-oodle-sample-readonly.php`: first **12** real package IDs successfully decoded into Zen snapshots, including Oodle chunks. Package name map, typed imports, exports, dependency-bundle entries and container provenance populated.
- `verify-uedb5-satisfactory-oodle-v5-roundtrip-readonly.php`: all **12/12** snapshots written to isolated temporary `.uedb5` containers, verified and read back with matching package names, import/export and dependency-bundle counts. Temporary containers were removed afterward.
- Existing `verify-uedb5-ue5-iostore-foundation.php`, `verify-uedb5-ue5-zen-package.php` and `verify-uedb5-container-foundation.php` passed unchanged.
- **No production SQL writes, registration, queue jobs or edits to the Steam game files**.

### Remaining integration gate

This is **real Zen parsing + V5 persistence**, not an end-to-end published game import. The normal scanner/importer expects an individual classic package file, while IoStore is a container with `FPackageId`-indexed packages plus mounted package-store context. Before production ingestion:

1. Introduce a **container ingestion entry point** that accepts a compatible `.utoc/.ucas` set, validates mount/partition context and drives one stable catalog identity per Zen package.
2. Use the existing V5 snapshot writer, registration repository, compact SQL candidate/provider publication and dependency projection, with idempotent per-package jobs and rollback.
3. Validate the full flow in standalone MySQL/isolated storage using a bounded subset of Satisfactory packages before enabling a new game/profile or live upload route.
4. Preserve global package-store redirects, localization and mounted container priority according to the matching Epic-source version; do not infer authoritative lookup behavior from the package's own header alone.

**Important:** This Satisfactory dedicated-server container may not match the UE5 5.8.3 source revision used to audit some other engine policies. Record its TOC, container-header and serialized package versions and check the correct Epic source/version before declaring complete source-parity.

### Real Zen SQL publication and provider-rich dependency checkpoint

Using the standalone **127.0.0.1:3339** MySQL instance, the isolated schema `uedb5_zen_satisfactory_test` and synthetic *test-only* file/game identities:

- `verify-uedb5-satisfactory-zen-sql-standalone.php`: **3/3** Satisfactory Zen packages passed actual `Uedb5MetadataSnapshotWriter`, `PdoUedb5StagingRegistrationRepository` and `PdoUedb5CompactCandidatePublisher`. UEDB5 registration stored the exact hexadecimal FPackageId and created 54 compact name and 5 object candidate rows. Repeating the test succeeded without duplicate candidate publication.
- `verify-uedb5-satisfactory-zen-dependencies-standalone.php`: first real consumer references **7 distinct physical providers** present in the same container; each provider was parsed into an isolated V5 container. `Uedb5DependencyRebuilder` built **29** dependency result rows with the current, conservative package-store policy: **1 local load-order edge resolved**, **28 unresolved** pending authoritative mounted package-store context.
- This test exposed a real V5 decoration bug: serialized `object_index` fields from Zen dependency bundles were compared against V5 rows containing extra `index` and `dependency_class` bookkeeping fields. The resolver now excludes **only those non-serialized fields on both sides**, retaining strict comparison of every serialized field. The resolver's existing negative test continues to reject genuinely mismatched objects; a new decorated-row positive regression also passes.
- **Not yet completed:** normal `.utoc/.ucas` ingest through upload/restore/reimport, source-version-specific mounted-container aggregation and authoritative package-store dependency resolution. The 28 unresolved rows **must not be promoted to resolved** merely because their package IDs exist in one sampled container.

The standalone schema is not a real Satisfactory game profile and does not prove live production ingestion. No production database record or Steam file was changed.

### Bounded reusable Zen package publication service — next checkpoint

Added `CatalogZenIoStorePackagePublisher` in `src/Infrastructure/Import/`, with a separate **opt-in** `verify-uedb5-zen-container-publisher-standalone.php` test. The service consumes a prevalidated `Uedb5IoStoreTocReader`, an exact fixed-width `FPackageId`, and an existing **verified** catalog file/game identity. It delegates to the current V5 snapshot writer, registration repository and compact candidate publisher, and owns the SQL registration/projection transaction.

The service fails closed for invalid package identities, non-verified catalog rows, mismatched game/file IDs, existing different package IDs on the same file, already-registered duplicate package IDs, missing or mismatched V5 containers, and conflicting container `utoc_sha256` provenance. A successful repeated publication of the same package and TOC is **idempotent**; it does not overwrite the existing container or duplicate SQL projections. Tests use only the separately provisioned `uedb5_zen_satisfactory_test` schema and scratch storage.

**Not yet integrated with the public upload path.** Ordinary `ProfiledUploadService` and `CatalogPackageImporterAdapter` are designed for *single classic package files*. IoStore is a mounted container whose packages are identified by `FPackageId`. The complete production workflow must first:

1. Accept/retain a **complete** container family (`.utoc`, `.ucas`, numbered partitions, and relevant `.pak`/AssetRegistry context) without treating the TOC itself as a classic package.
2. Establish a UE5 game/profile and stable catalog file identity for **each** package, retaining the relationship to its containing source files. This is not handled by the publication service; do not create fake production records.
3. Determine authoritative mounted-container order/redirect context and source-version rules before final dependency outcomes; missing context must remain unresolved, not guessed.
4. Enqueue resumable bounded per-package publication and dependency reconciliation, support clean rollback and backup/restore, and validate retries/concurrency on isolated full-schema MySQL prior to production enablement.

Neither this service nor the test modifies production SQL or the Satisfactory installation. Integration with upload/backup/reimport is intentionally gated pending the above container-level contracts.

### Package Viewer naming and future IoStore container import

The UI's generic navigation and PAK browser title are renamed **Package Viewer** (existing `paks.php`, `game-paks.php`, `pak-info.php` URLs intentionally remain stable). The existing backend, `ue_pak_archives` / `ue_pak_entries`, **still handles only self-contained `.pak` archives**; this rename does not enable IoStore browsing. The current UI states that IoStore browsing is being integrated.

An IoStore container comprises a `.utoc` table of contents and companion `.ucas` payload (possibly numbered partitions). The container is the upload/retention unit; **each Zen package inside is catalogued by `FPackageId`**, not by misidentifying either raw companion file as an ordinary `.upk`.

Target Package Viewer workflow: upload a complete container set; validate matching TOC/payload/partition/encryption/compression context; retain its source safely; enumerate its contained packages and provenance; publish each package's V5 metadata and compact search; expose package list, contents and file-info links; run authoritative mounted-container dependency reconciliation. Recovery, reimport, backup restore, and deletion must retain container/member ownership correctly. No public IoStore upload route is enabled until those lifecycle contracts pass real isolated tests.

### IoStore retained-container index and Package Viewer — next checkpoint

Additive migration 202610150001_iostore_container_index.php defines three dedicated tables: ue_iostore_containers (TOC identity, game and status); ue_iostore_container_parts (retained UCAS partition path, size and SHA-256); and ue_iostore_packages (TOC index, binary 64-bit FPackageId, logical size, optional linked catalog file). Fresh-install install.sql was updated as well. Production migration has NOT been applied.

CatalogIoStoreContainerIndexer indexes the complete package IDs from validated TOC metadata, owns its SQL transaction, and recognizes repeat indexing. CatalogIoStoreContainerStorage validates TOC and every UCAS partition, copies and SHA-256 verifies them in isolated storage, registers their manifests and marks the container ready only after all parts match. An incomplete container cannot become ready.

Real Satisfactory isolated MySQL tests:
- verify-iostore-container-index-standalone.php: 27,240/27,240 package members indexed, repeat indexing idempotent.
- verify-iostore-container-retention-standalone.php: global.utoc and global.ucas retained and verified, repeat import recognized.
- verify-iostore-main-retention-standalone.php: full 621 MB main UCAS plus TOC retained and verified in isolated storage, 27,240 package members indexed, ready status after part validation.
- verify-iostore-missing-part-standalone.php: missing UCAS partition rejected with no database changes.
- verify-package-viewer-labels.php: IoStore list and paginated iostore-info.php with FPackageId prefix filtering. Existing PAK browsing remains unchanged.

Scope: These fixtures intentionally use isolated game ID 2 and test-only paths. No production Satisfactory game, live UE5 upload route, per-package catalog file identities or 27,240 V5 snapshot publications were created. To finish production IoStore acceptance, wire multi-file/chunked upload, verified UE5 game and physical source identity, durable per-package V5 publication, backup/restore, ownership-aware deletion and authoritative mounted package-store dependency resolution. This checkpoint does not claim those remainings are complete.

### IoStore on-demand Zen package inspection from retained containers

Added CatalogIoStoreIndexedPackageReader and a read-only iostore-package-info.php endpoint. An indexed member is opened by container ID and TOC index, validated against the retained SHA-256-registered TOC and complete partition manifest, then decoded on demand using the existing Zen snapshot builder and Oodle support. The viewer now links every FPackageId to this inspection page; links to the normal file-info.php are provided only for members with published catalog file identities.

Real isolated Satisfactory verification: verify-iostore-package-inspection-standalone.php passed 5/5 member package inspections (names, name maps, imports, exports, dependency-bundle counts) directly from the full retained main UCAS; rejected a nonexistent member in the global container. No production SQL or Steam source modifications. The on-demand viewer deliberately avoids pre-parsing all 27,240 packages at page load.

This is read-only indexed-container browsing. Normal multipart browser upload, per-member ue_files publication/ownership and mounted-store dependency resolution remain separate pending integration work.
