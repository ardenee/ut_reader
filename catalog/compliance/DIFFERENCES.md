# Epic ↔ UnrealDB difference and acceptance ledger

This is the **engineering audit tracker**, distinct from method classification and manifest source-symbol checks. Source inventory: [README](README.md). Classification counts do not imply changes or compliance.

## Demonstrated differences / limitations

| ID | Scope | Epic rule / expected behavior | UnrealDB finding | Correction / evidence | State |
|---|---|---|---|---|---|
| UE5-D001 | Zen dependency read facade | Runtime filtering yields distinct `export_filtered_for_runtime_build` versus `export_filter_runtime_state_required` reasons (AsyncLoading2.cpp, pinned SHA256 `43ce5d78…`) | `CatalogCompactDependencyReadService::compactRows` discarded `reason_code` although other read facades retained it | B048 added returned field; PHP syntax passed. `verify-uedb5-zen-reason-flow-contract.php` checks the current source flow; not yet database-backed facade fixture | **Code changed; integration unverified** |
| UE5-D002 | Compact SQL projection | Distinct unresolved reasons must remain distinguishable *if* consumer requires them | `ue_uedb5_dependency_edges` stores canonical outcome but no `reason_code`; two different unresolved reasons collapse to same outcome | B050/B056 SQLite fixture proves this, B057 proves republish removes stale edges. Preserve full V5 staged metadata for detailed diagnosis until a documented schema/consumer decision is made | **Open design/consumer requirement** |
| UE5-D003 | UE5 source-definition audit harness | Newer checkpoint classifications must be counted | `B0??` glob silently omitted B100+ inventory | B101 fixed `B[0-9][0-9][0-9]`; B102 requires explicit classification and negative-test failure when withheld | **Fixed; synthetic negative/positive regression passed** |
| UE5-D004 | Cross-environment consistency | Same tested implementation needed on release target | DEV/PRD runtime checkouts at `5ac0d8f0`, missing current-main classes; dirty tracked changes on both targets | B051–B055. Do not overwrite changes. Validate clean runtime alignment and migrate through controlled backup/merge/deploy | **Open deployment blocker** |

## Supported behavioral checks — NOT identified defects

| Area | Epic reference and behavior | UnrealDB evidence | Still missing |
|---|---|---|---|
| UE5 export filter rules | `AsyncLoading2_ShouldSkipLoadingExport`, `EExportFilterFlags`: context-dependent client/server/editor exclusions | B042–B046: synthetic byte, context, provider-resolution tests pass | Epic-cooked fixture differential and all consumers |
| Zen export maps/dependency bundles | `FZenPackageHeader::MakeView`, `FAsyncPackage2::ProcessExportDependencies` | B034–B041, B056–B057: malformed/reference, four command partitions, SQLite republishing tests pass | authentic cooked differential; full ordering semantics |
| Package store arbitration | `PackageStore.cpp` package entry: None/Missing fall through; Pending/NotInstalled/Ok stop. First redirect wins; backend priority matters | B107 backend regression passes, including redirect fallback | mount/priority tests on complete container sets and override contexts |
| Verse/cell imports | `ConditionalCreateCellImport`, `GetPublicCellExportIndexAsLocalIndex`, `GenerateImportHashFromVersePath` | B067–B080 source-body classifications | independent byte-vector/fixture parity |
| Source inventory | Pinned `AsyncLoading2.cpp`, `PackageStore.cpp`, `ZenPackageHeader.cpp` | B097, B101–B106: 88/88 + 11/11 *selected* method definitions classified | all other Epic source files, dispatch/call graph, UE1–UE4 source-differentials |

## Satisfactory isolated container publication / previous six-task workflow

The user provided an authoritative **isolated integration DB** check for `container_id=1`: **27,240 total / 27,240 published / 0 awaiting names / 0 awaiting publication / 0 failed** (100% published). This **closes the completion count for that isolated run**; it does not alone establish correct dependency semantics or production deployment.

| Original task | Current evidence | Remaining acceptance |
|---|---|---|
| 1 Mounted-container order / package-store precedence / redirects | Pinned Epic rules recorded; backend arbitration synthetic test passes | End-to-end mounted complete-container family and priority/redirect differential |
| 2 Connect to V5 dependency resolver | Runtime resolver and backend helper exist; targeted synthetic tests pass | Demonstrate live ingestion → metadata → resolver → stored edges with real fixture |
| 3 Optional game-specific/runtime overrides | Context-aware filter tests pass | Verify defaults work without overrides, and override precedences with real fixtures |
| 4 Name indexing + publish 27,240 | **Completed for isolated `container_id=1`** per observed SQL counts | Only independent content correctness checks, not repeat full scan |
| 5 Dependencies / failures / retries / backup/restore / workers | Several targeted standalone suites exist; 12-suite bounded baseline green | Consolidated end-to-end lifecycle acceptance on isolated test DB and retained container |
| 6 Sync GitHub / DEV / PRD and deploy | Live DEV/PRD at old head; both dirty, so not aligned with GitHub | Preserve changes, review diffs, merge/backup, deploy and smoke test |

## Current actionable backlog (do not create another batch just to rename it)

1. **Reconcile DEV and PRD working changes** against GitHub with backups and an explicit conflicts report. Do not pull/reset live trees blindly.
2. **Turn the isolated 27,240-package result into reproducible integration acceptance**, including dependency sample comparisons, retries/failures, restore and concurrent worker tests. Reuse retained packages; no full scan just to remeasure counts.
3. **Close D001/D002** with a database-backed compact/full metadata round-trip test and documented consumer contract.
4. **Cross-reference the formal 18 operation IDs and 33 open gates** with real tests/evidence; only set verified after all acceptance conditions pass.
5. **Expand independent source-derived inventories for UE1–UE5**, prioritizing reachable package parsing/dependency semantics rather than runtime-only callback names.

**Audit honesty rule:** a classified source symbol is not a difference, a fix, or certified parity. Every actual difference must have an explicit expected/actual comparison and closure evidence.


## 2026-10-16 synchronization checkpoint (non-destructive)

- GitHub main: `4a533498` when staging completed.
- Live DEV: `D:\Apache24\htdocs\unrealdb` at `5ac0d8f0`, **112 locally modified tracked files**, all 112 paths also changed upstream.
- Live PRD: `C:\Apache24\htdocs\unrealdb` at `5ac0d8f0`, **330 locally modified tracked files**, all 330 paths also changed upstream.
- Saved *tracked* local Git diffs / status manifests: `C:\Temp\unrealdb-prd-20261016-audit-sync.patch`, `C:\Temp\unrealdb-prd-20261016-audit-sync-status.txt`, `D:\Temp\unrealdb-dev-20261016-audit-sync.patch` and status manifest on DEV. These are **not full backups of untracked files or databases**.
- Clean, detached current-main worktrees: PRD `C:\Temp\unrealdb-prd-main-20261016`, DEV `D:\Temp\unrealdb-dev-main-20261016`. Both match `4a533498`, zero modified tracked files, and pass the bounded **12/12** UE5 audit tests.
- **Live applications are NOT upgraded or version-aligned yet.** Full cutover must merge/reconcile the local overlapping modifications and separately protect untracked files, deployment config and databases before activating code. Staging new worktrees is not equivalent to deploying them.
