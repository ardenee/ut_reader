# UE5.8.3 — Production workflow reachability, verified subset (19 October 2026)

**Update 19 October 2026 — PRD migrations verified complete:** On Lelly-pc, UnrealDB official migration runner applied `202610150001` and `202610150002` as batch 2, then `migrate.php verify` succeeded. Independent read-only DEV-to-PRD MySQL inspection confirmed all three `ue_iostore_*` tables, seven package metadata columns, and both required indexes. The earlier migration-pending statements below record their pre-migration state and are superseded by this dated verification. No Satisfactory production import was initiated.

**Scope rule:** Evidence of a working Satisfactory file-format/IoStore path is not proof of all default Epic UE5.8.3 source branches or all 16 production workflows. Keep `RECOVERY.json.scope_complete=false` until actual production consumers and independent comparisons are mapped. No production Satisfactory import was run.

## Actual production entrypoint chains examined

| Recovery workflow | Observed UnrealDB production entrypoint | Epic/fixture coverage | Audit result |
| --- | --- | --- | --- |
| `read_decode` | `Uedb5IoStoreTocReader`, `CatalogIoStoreIndexedPackageReader`, `Uedb5IoStoreCodec` | Genuine experimental server TOC/UCAS: 27,240 packages, SHA256 retention; Oodle extraction fixture 5/5 | **Validated for tested Satisfactory Zen format**, other layouts remain |
| `extract_import_export` | `CatalogPakArchive.php`, `CatalogPakModernIndex.php`, `CatalogIoStoreContainerStorage` | Genuine PAK v11: 4,761 entries and 212,783,532 decoded bytes, 0 skipped; TOC/UCAS retention | **Validated for present v11/Zen inputs**, encrypted/unencoded alternate PAK indexes not certified |
| `stage_v5` / `persist_sql_projections` | `CatalogIoStoreMemberCatalogPublisher`, `Uedb5MetadataSnapshotWriter` | 27,240/27,240 published genuine Zen members and persisted `ue_uedb5_files` in isolated MySQL | **Validated for isolated real member dataset**, other production workflow callers unreviewed |
| `discover_dependencies` / `select_physical_providers` / `resolve_object_references` / `dependency_pass2` | `Uedb5GameDependencyPassService`, `PdoUedb5PhysicalProviderSelector`, `Uedb5DependencyRebuilder`, `PdoUedb5DependencyProjectionPublisher` | 27,240/27,240 successful Pass 2 records, zero worker failures, pinned policy + matching V5 hashes; genuine Zen D033 validator fix | **Validated for isolated real dataset**, cross-game/alternate-layout semantics not certified |
| `validation` | `Uedb5MigrationValidator` | `verify-ue5-583-satisfactory-full-isolated.php` exited 0: 27,240 checked, 27,240 ready, 0 failed | **Validated for isolated real dataset**, not all Unreal formats |
| `upload` | `CatalogIoStoreUploadStager` → `CatalogIoStoreImportJobHandler` → `CatalogIoStoreContainerStorage` → `CatalogIoStoreNameIndexJobHandler` → `CatalogIoStoreMemberPublishJobHandler` | `verify-iostore-durable-worker-standalone.php` exited 0: queue lifecycle on real `global.utoc`; `verify-iostore-worker-guards-standalone.php` exited 0 for invalid profile/TOC-hash alteration; `verify-iostore-missing-part-standalone.php` exited 0 | **Queue lifecycle + negative branches tested**; full 27,240-member browser/UI upload route not certified |
| `backup_restore` | `CatalogIoStoreBackupJobHandler`, `CatalogIoStoreContainerBackupService`; `GameBackupExportJobHandler` explicitly rejects silent omission of IoStore container from classic backup | Genuine 27,240-member TOC/UCAS export/restore+partition hash passed in isolated MySQL; separate durable backup route mapped | **Real container service passed**, complete site backup/restoration still open |
| `resync` / `repair` | `CatalogFileMaintenanceReimportService::reimport` dispatches `.zenpkg` to `CatalogIoStoreMemberReimportService::reimport` instead of treating it as classic `.uasset`; `CatalogNonBlockingImportJobHandler` invokes maintenance service | Genuine two-member isolated reimport preserved file identity and rebuilt V5 bytes; unavailable-partition recovery not broadly mapped | **Bounded reimport passes**, every route and retry unverified |
| `metadata_query_search` | Database projection/query consumers have not yet been exhaustively traced for Zen member identity across normal/search/repair views | 27,240 SQL metadata rows created; no full end-user search-vs-Epic fixture acceptance | **Open** |
| `identify` / `archive_import` | PAK policy and `CatalogPakImportJobHandler`; Zen upload uses distinct IoStore job handler | Correct PAK11 recognition and full extraction, but no isolated end-to-end Upload Bucket placement for entire fixture | **Open broader caller proof** |

## Independently rerun executable tests (19 October 2026)

- `catalog/bin/verify-iostore-durable-worker-standalone.php` — exit 0; durable IoStore import enqueued index and publish jobs.
- `catalog/bin/verify-iostore-worker-guards-standalone.php` — exit 0; invalid UE5 profile and mutated queued TOC hash both dead-letter correctly.
- `catalog/bin/verify-iostore-missing-part-standalone.php` — exit 0; absent UCAS partition rejected with SQL rows unchanged.
- `catalog/bin/verify-ue5-583-satisfactory-full-isolated.php` — 27,240/27,240 ready, 0 failed, exit 0; script checks isolated MySQL port and datadir.
- `catalog/bin/verify-source-compliance.php --strict` with `UNREALDB_EPIC_SOURCE_ROOT=L:/Source/Engine/UE5/UE 5.8.3` — 18/18 registered operations, 0 issues.

## Release blockers / remaining uncertainty

1. PRD `ue_iostore_packages` **still lacks all seven** columns introduced by existing migration `202610150002_iostore_package_names.php`, verified read-only. Apply the migration through the normal authenticated production migration process before user-initiated Satisfactory import; do not bypass with PHP side effects.
2. Exhaustive route tracing of package identification, upload, full archive import, repair/resync, and metadata search/queries, with failure/retry and returned UI behavior, remains open. Source format branches not exercised by these fixtures also remain open.
3. The 16 workflow `coverage` fields are deliberately **not** bulk-changed. This document is evidence for already demonstrated subsets, not a fabricated completeness certification.
