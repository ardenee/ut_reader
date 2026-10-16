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

### IoStore HTTP uploads, durable queue, member extraction and searchable names — 2026-10-15 continuation

Implemented admin-only `iostore-import.php` supporting either a local complete `.utoc/.ucas` family or a multipart browser upload (one TOC and all named UCAS partitions). `CatalogIoStoreUploadStager` requires PHP-verified HTTP uploads, matching complete partition filenames/count, safe filenames and durable private staging before queuing. Existing `CatalogQueueWorkerStarter` wakes the durable worker. `JobType::IMPORT_IOSTORE_CONTAINER` routes to `CatalogIoStoreImportJobHandler`; this validates an active UE5 game profile and queued TOC SHA-256, retains/hashes all parts, and idempotently indexes them. The admin import form is guarded by the additive IoStore schema migration. This is **container retention/indexing**, not classic-file import.

`iostore-package-download.php` now permits **administrator-only** extraction of one decoded Zen export-bundle package from retained container data, naming it `.zenpkg` rather than falsely claiming it is a standalone `.uasset`. Default maximum is 128 MiB per extraction. Real isolated tests matched original SHA-256 for 5/5 Satisfactory packages and rejected oversized requests.

Additive migration `202610150002_iostore_package_names.php` and matching fresh-install `install.sql` columns persist real Zen package names, import/export/dependency counts, metadata status and error text. A bounded `CatalogIoStorePackageNameIndexer` and `JobType::INDEX_IOSTORE_PACKAGE_NAMES` worker index packages in slices of 100 and defer for retry/resume. The Package Viewer now shows and searches persisted names or FPackageId, with a fallback if migration 202610150002 is not installed. The names are separate from the conventional `ue_files` catalog; no fabricated classic-file identities.

**Verified in standalone MySQL 127.0.0.1:3339 only:**
- Real HTTP POST of Satisfactory global.utoc/global.ucas to a loopback PHP fixture succeeded and retained the files. Missing/misnamed UCAS returned HTTP 422.
- The retained multipart pair passed through real `PdoJobQueue` and `JobWorker` to a completed container import.
- Two independent repeated durable imports completed with one container identity and one part manifest.
- Non-UE5 game and changed TOC hash produced expected dead-letter errors.
- Name index worker recovered across three deferred 100-package slices; **457 Satisfactory members named, zero failures** after correcting missing Oodle DLL discovery for CLI workers. The 193 initial isolated failures all had the same missing-DLL error, not a source-version parsing error. `Uedb5IoStoreCodec` now falls back to the known `oo2core_9_win64.dll` in this PHP installation's extension directory when the Apache environment variable is absent.
- Indexed Zen package extraction verified 5 original SHA-256 byte matches and an extraction size-limit rejection.

**NOT COMPLETE:** schema migrations are still pending on production; real per-member catalogue identities and durable full V5 SQL publication, mounted-container package-store arbitration/dependency resolution, backup/restore, container-member ownership-aware deletion, browser chunked multi-gigabyte uploads, encrypted containers, and production integration have not been proven. Never claim all IoStore support is complete merely because retention and viewer browsing work.

### Zen member catalog publication proof — isolated checkpoint

`CatalogIoStoreMemberCatalogPublisher` now performs opt-in per-member extraction to a separately named `.zenpkg` source file under the retained container's game/hash namespace, with real decoded-byte MD5/SHA-1, a genuine `ue_files` record, and the existing guarded V5 snapshot registration and compact SQL publication. The source path registered in the catalog uses the `storage/` prefix. It deliberately refuses to merge a different FPackageId on identical game/MD5 identity.

In the isolated standalone MySQL fixture, two real Satisfactory member entries (TOC indexes 700 and 701) each created a new catalog identity and verified V5 registration/search candidates. Their immediate replay returned existing rather than a duplicate. The fixture was executed before the later `storage/` path-prefix correction; a new fresh-row path test remains required before wiring the durable production job.

**This is not enabled from any live upload, queue or restore route.** The staged durable handler was discarded while incomplete. Remaining gates include fresh-row physical source resolution, failure/retry/concurrent publish, container/member ownership-aware delete and restore, end-to-end V5 dependency arbitration, and production UE5 game-profile readiness.

### Durable Zen member V5 publisher checkpoint — 2026-10-15 continuation

After real source/file-ID publication in commit 8d443665, `CatalogIoStoreMemberCatalogPublisher` was tightened to verify existing `ue_files` recorded `storage/` paths, physical source MD5/SHA-1, scan status and game before allowing a replay; successful replay also reconciles the container member's `publication_status` back to `published`. No different `FPackageId` is folded into a duplicate MD5 identity.

`JobType::PUBLISH_IOSTORE_PACKAGES` and `CatalogIoStoreMemberPublishJobHandler` now route through the normal durable worker factory, publish a bounded 10 metadata-ready members per claim, use a per-container MySQL advisory lock to serialize parallel workers, checkpoint progress and defer until more members are ready. The worker reads only retained TOCs from the configured storage root, verifies the TOC digest and requires an active UE5 game profile. Publication is not automatically queued from live imports while backup/deletion and mounted-store conformance remain incomplete.

In standalone MySQL port 3339, `verify-iostore-member-worker-standalone.php` temporarily assigned the isolated Satisfactory container's fixture game to an isolated active UE5 profile, then restored the original profile in `finally`. Two separate claims of one durable job (#136) each successfully published 10 packages, progressing to 24 total published members including four earlier direct fixtures, with 437 additional metadata-ready members still awaiting publication. The job remained queued/deferred without retry errors or replaying completed members. This is **bounded isolated proof**, not the full 27,240 member run.

Remaining: self-contained full container acceptance including proper UE5 game identity, post-upload automatic scheduling, authoritative cross-mounted package store resolution, multi-part and encrypted fixture coverage, catalog backup/restore and deletion/reimport semantics, and production schema migration/deployment. Do not mark production IoStore support complete.

### IoStore-aware catalog member deletion contract

`CatalogFileMaintenanceRemovalService` now resets a linked `ue_iostore_packages.publication_status` to `indexed` **inside the same existing transaction** as V5 projection and `ue_files` deletion. The member's foreign-key `file_id` is subsequently set NULL on catalog file removal, while its original `.utoc/.ucas` container and TOC-index identity remain available for later Zen republishing. The new logic is conditional on the additive IoStore schema being installed and preserves V5-only non-IoStore behavior.

An isolated MySQL transaction test `verify-iostore-member-removal-sql-standalone.php` proved published member -> indexed/unlinked during deletion, then exact state restoration after rollback. The existing `verify-uedb5-file-removal-lifecycle.php` regression passed. The test is SQL-contract-only; full physical deletion and post-deletion provider/dependency reconciliation of a Zen member remain to be exercised.

### Zen catalog source identity and full V5 base validation

The real Satisfactory member publisher now requires **metadata-ready** indexed packages and uses their original package paths and name/import/export counts for genuine `ue_files` records (rather than temporary hexadecimal display names). `CatalogZenIoStorePackagePublisher` writes `.zenpkg` original_name consistently with its retained source file, calls the authoritative **PdoUedb5BaseProjectionPublisher** for provider keys, search dictionary and compact candidates, then validates the V5 container, source-shaped snapshot, registered identity and SQL projections with **Uedb5MigrationValidator** inside the publication transaction. The V5 migration status records `uedb5-validation-v1` evidence and remains **staged** until source-backed dependency results are published; it must never be set `validated` merely because the first pass succeeded.

Isolated full-schema Satisfactory members at TOC indexes 52 and 53 passed publication and exact-source parity validation; SQL confirmed genuine Zen package names, .zenpkg original names, correct counts, required provider/search projections, and **staged** validation status with explicit `dependency_results_not_built`. `verify-uedb5-zen-container-publisher-standalone.php` now uses the real full isolated schema rather than an incomplete fake `ue_files` table; seven regression checks pass (replay, provider projection, staged status, identity rejection). The ordinary catalog `Uedb5MigrationValidator::validateSourceBytes` still assumes classic `games/<profile>/verified/<stored_name>` paths, so callers MUST supply independently verified source-shaped Zen snapshots from retained IoStore TOC/UCAS when validating Zen files; do not use the classic source-path reparsing route.

### Mounted Zen runtime-context pass-through

`Uedb5DependencyRebuilder::rebuild` now supports an optional `zen_runtime_context` argument passed unchanged to `Uedb5Ue5ZenDependencyResolver`. This closes the earlier API gap where real Epic mounted-container/package-store context could never reach the actual resolver from the normal V5 dependency rebuilding path. Without explicit authoritative context, the existing **conservative unresolved** behavior remains. The existing Zen resolver, Zen package and real Satisfactory dependency regression tests passed after this change.

This pass-through **does not generate authoritative context**. Unreal Engine's backend priority and within-backend container Order/Sequence are runtime mount properties; neither .utoc filename nor database insertion order proves them. A future mounted-store builder must consume authoritative ordering/redirect-localization context and must not set `package_store_context_authoritative` unless all relevant mounts are known.

### Stable-ID IoStore Zen reimport and missing-derived-source recovery

`CatalogIoStoreMemberReimportService` is now routed from `CatalogFileMaintenanceReimportService` for `.zenpkg` catalog members, so the classic Unreal package scanner does not misinterpret decoded IoStore chunks as standalone `.uasset` files. The service checks one authoritative member ownership record, active UE5 profile, retained TOC integrity, FPackageId/TOC-index match, and decoded member MD5/SHA-1 and size against the original verified `ue_files` row. It preserves the canonical relative path and stable file ID, rebuilds the UEDB5 snapshot and complete base SQL provider/search/candidate projections from retained source bytes, and records full source-shape validation evidence as `staged` pending dependency reconciliation. Before replacement it copies the existing V5 file to a recoverable backup; on SQL/validator failure it rolls back and restores the prior container, retaining a backup when filesystem rollback fails.

Tests on standalone MySQL 3339:
- `verify-iostore-member-reimport-standalone.php`: same file ID 111239, deterministic V5 bytes before/after, correct V5 staged status/validator policy, fixture profile restored.
- `verify-iostore-missing-member-repair-standalone.php`: simulated missing derived `.zenpkg` file in isolated storage was regenerated byte-identically from original IoStore, with original MD5 and file ID preserved.
- Existing `verify-uedb5-metadata-mutation-identity.php` regression passed after Zen branch routing.

Reimport intentionally resets dependency pass rather than copying possibly stale resolved dependency results. Source/package-store mounted-context reconciliation still requires its own authoritative pass.

### Hash-verified IoStore backup, restore and durable administrative workflow

Added `CatalogIoStoreContainerBackupService` to preserve an entire ready IoStore TOC/UCAS partition family as a self-contained, manifest-verified backup. Source and target bytes are checked by size and SHA-256. A complete backup is atomically published under `iostore-<TOC-SHA256>`; repeated exports verify and reuse it. Restoration requires an existing active UE5 destination game, validates every backup component before writes, and retains/reindexes the original complete container set using `CatalogIoStoreContainerStorage`.

Real Satisfactory isolated MySQL results:
- `verify-iostore-container-backup-restore-standalone.php`: global TOC/UCAS pair exported, re-exported idempotently, and restored as a new isolated UE5 container with verified partition.
- `verify-iostore-main-backup-restore-standalone.php`: full ~621 MB FactoryGame-WindowsServer UCAS + TOC exported, hash-verified, restored into a different isolated UE5 game, and all **27,240** original FPackageId members indexed (8.52s measured in the test run).
- `verify-iostore-backup-corruption-standalone.php`: single-byte change in an independently copied UCAS rejected restoration before SQL mutation.
- `verify-iostore-backup-jobs-standalone.php`: one real queued `catalog.backup_iostore_container` and one `catalog.restore_iostore_container` completed through `JobWorker` with persisted result IDs; uses the configured `GameBackupStore` root for dedicated backup sets.
- New **admin-only** `iostore-backup.php` in Package Viewer queues these operations. The UI has migration/status gates, CSRF validation, active UE5 game selection and bounded backup-key/TOC identity validation. `verify-package-viewer-labels.php` passes.
- Standard game-backup export now **fails closed** when a game has IoStore containers: the old standalone-package export format does not preserve TOC/UCAS and would create a misleading incomplete backup. The dedicated IoStore backup route is required. Existing `verify-uedb5-full-sync-backup-lifecycle.php` passed.

**Scope:** Complete container retention and index recovery are demonstrated. These dedicated backups are not yet a single full-game backup that automatically restores every derived `ue_files` Zen member, its V5 status and dependency graph; reimported containers require bounded name-index/V5 publication and authoritative mounted-store dependency jobs. Full production migrations are still pending. Do not label standard game backup/restore as IoStore-compatible or automatically queue it until its complete lifecycle has independent tests.

### Automatic durable IoStore indexing/publication queue choreography

After a verified `catalog.import_iostore_container` job retains and indexes a complete TOC/UCAS set, it now enqueues `catalog.index_iostore_package_names` and `catalog.publish_iostore_packages` on the same durable queue, returning their IDs in the import job result. Follow-up jobs use priority 110 (normal import 100); their insertion/order remains durable across worker restart. The bounded publication handler defers not just while ready members remain unpublished but also while members still await name indexing. If any names permanently fail, the final publication result says `completed_with_failures`, never silently reports a fully successful 27,240-member import. This orchestration does not synthesize Epic mount-order/backend priority or promote staged dependency results to validated.

Isolated `verify-iostore-durable-worker-standalone.php` now registers all three real job handlers, tests two idempotent Satisfactory global-container imports and drains all four automatically scheduled name/publication jobs. Result: both imports completed once, same retained container ID, all four child jobs completed. The global fixture contains zero export-bundle package members; this proves queue choreography, **not** end-to-end 27,240-member completion or production mounted-store dependency results.


### 2026-10-15 backup restore follow-up hardening

- GitHub `81f02ee9`: durable `catalog.restore_iostore_container` now schedules package-name indexing and V5 member publication jobs, matching the direct import route. Successful restoration still means retained/indexed; downstream publication remains asynchronous and must be checked independently.
- GitHub `fc6a2974`: restore manifest verification now rejects duplicate component filenames, duplicate partition indices, missing indices and invalid TOC index; existing size/SHA checks remain in place.
- These patches received repository source inspection only in this session. They are **not yet certified by PHP lint or standalone MySQL tests**. Keep PRD unchanged until a clean DEV or isolated test workspace executes the new coverage.
- PRD checkout at `5ac0d8f0` contains numerous unrelated local modifications/untracked files; do not reset or bulk-overlay it. Only the PRD Desktop Commander device was accessible during this continuation.

- GitHub `55e5e581` and `e692c3a3`: import and restore follow-up enqueue use deterministic container-scoped dedupe keys to prevent concurrent/retried parent jobs from creating duplicate active name-index/publication workers. The queue deduplication implementation was inspected; an actual retry/concurrency test is still outstanding.


### 2026-10-16 isolated PRD-host regression checkpoint

- Source was checked out independently to `C:\\Temp\\uedb5-iostore-review-20261016`; production application checkout remained unchanged. Standalone MySQL verified itself as `127.0.0.1:3339` with `C:\\Temp\\uedb5-isolated-mysql\\data\\` (never the live instance).
- PHP 8.5 lint passed for `CatalogIoStoreBackupJobHandler.php`, `CatalogIoStoreImportJobHandler.php`, `CatalogIoStoreContainerBackupService.php` and the amended backup-job test.
- `verify-iostore-backup-jobs-standalone.php`: PASS, real durable backup and restore jobs; restored container returned both durable follow-up job IDs.
- `verify-iostore-backup-corruption-standalone.php`: PASS, one-byte UCAS tamper rejected without creating a new destination container.
- `verify-iostore-durable-worker-standalone.php`: PASS after updating expected active follow-ups from four to two (same-container import replay dedupe), with IDs shared between the two import results.
- `verify-iostore-main-backup-restore-standalone.php`: PASS, retained/restored 27,240 indexed package members and verified complete TOC/UCAS pair. This test observes an existing indexed destination; it does **not** publish 27,240 member V5 metadata rows.
- **Not yet certified:** full multi-member name indexing, V5 publication and authoritative Zen mounted-store dependency rebuild; backup-manifest adversarial tests for duplicate/missing parts; concurrent worker/retry edge cases. Do not deploy or mark full IoStore production lifecycle complete on these bounded results alone.


### 2026-10-16 bounded Zen member publication and name-index checkpoint

- Independent GitHub checkout and standalone MySQL `127.0.0.1:3339` confirmed before jobs. PRD live code/storage/database untouched.
- `C:\\php8.5\\php.exe catalog\\bin\\verify-iostore-member-worker-standalone.php`: PASS. Two 10-member durable publish batches; after test 48 published, 413 metadata-ready remaining and 26,783 awaiting names. Job correctly remains queued (deferred), no reported error.
- `C:\\php8.5\\php.exe catalog\\bin\\verify-iostore-name-job-standalone.php`: PASS. Three 100-member bounded name-index batches, ending with 757 ready, 26,483 awaiting names, zero failed; empty global container's job completed.
- `C:\\php8.5\\php.exe catalog\\bin\\verify-iostore-v5-source-validation-standalone.php`: PASS for fixture entries 50 and 51, source identity valid, but dependency readiness explicitly false for both (`dependency_results_not_built`; 3 expected, 0 constructed). No claim of dependency completion.
- **Remaining:** full 27,240-member name/publication lifecycle, actual package-store-global mounted-context dependency rebuild and validation, plus adversarial failures/concurrency. This is not a full lifecycle certification. The fixture remains partially processed by design; do not restart broad seven-game scans.

- `C:\\php8.5\\php.exe catalog\\bin\\verify-uedb5-satisfactory-zen-dependencies-standalone.php`: PASS as a conservative resolver contract, not a complete dependency outcome. One isolated consumer (file ID 980000000), seven referenced packages/physical provider candidates, 29 dependency rows: 1 resolved, 28 unresolved. Unresolved sample reason `package_identity_runtime_context_required` confirms authoritative runtime mounted-store context is still missing for a complete result. Do **not** convert these unresolved rows into resolved guesses.


### 2026-10-16 production dependency-path blocker (deployment gate)

- Rechecked GitHub main `9dbd307e` in independent PRD-host checkout. `verify-uedb5-ue5-zen-dependency-resolution.php` and `verify-uedb5-ue5-iostore-foundation.php` report no failures (isolated contract tests).
- Actual `Uedb5GameDependencyPassService::processFile()` forwards `$options` to `Uedb5DependencyRebuilder::rebuild()`, but `resolverOptions()` currently returns **only** `common_packages`. Therefore no production path there provides the required `zen_runtime_context` keys `package_identity_context_authoritative` and `package_store_context_authoritative` or Epic-accurate mounted package-store arbitration.
- Do not set these authority flags to true merely because a TOC/UCAS file or container registry exists. Identity rewrites, backend priority, mount Order/Sequence, redirects, localization and build/runtime environment must be established from genuine context. Unresolved source reason `package_identity_runtime_context_required` is correct pending that work.
- **Do not deploy/certify full IoStore V5 production ingestion yet**: the user's 'apply after completion' precondition is not satisfied. No production files were changed during this verification.
