# Epic ↔ UnrealDB difference and acceptance ledger

This is the **engineering audit tracker**, distinct from method classification and manifest source-symbol checks. Source inventory: [README](README.md). Classification counts do not imply changes or compliance.

## Current engineering status — 2026-10-16

- **Source-code deployment:** GitHub `main`, live DEV (`D:\\Apache24\\htdocs\\unrealdb`) and live PRD (`C:\\Apache24\\htdocs\\unrealdb`) were independently verified at `9d968aa5` before this audit. The focused UE5 fix `470d7b1d02426e66062f4e11307040400292a5d9` was pushed to `main` and fast-forwarded to DEV; PRD is the commit's originating checkout. Untracked files, settings, databases and old stashes were not altered.
- **Formal verifier:** 15 manifests / 18 operations; 18 pinned source hashes and 36 source symbol checks pass; **0 operations fully verified, 33 verification gates open, 0 validator issues**. These are evidence gates, not 33 discovered defects.
- **Bounded tests:** PRD and DEV each passed the **13/13** combined UE5 source suite (including a disposable DB-backed D001 metadata/read-facade regression). Effective-store-context and physical-provider-selector tests passed on PRD; PRD HTTP root and catalog returned 200. No complete-container fixture differential or production database acceptance is claimed.
- **Historical notes below are chronology, not the active backlog.** Do not revive old DEV/PRD stash reconciliation as an audit obligation.

## Demonstrated differences / limitations

| ID | Scope | Epic rule / expected behavior | UnrealDB finding | Correction / evidence | State |
|---|---|---|---|---|---|
| UE5-D001 | Zen dependency read facade | Runtime filtering yields distinct `export_filtered_for_runtime_build` versus `export_filter_runtime_state_required` reasons (AsyncLoading2.cpp, pinned SHA256 `43ce5d78…`) | Historically `CatalogCompactDependencyReadService::compactRows` discarded `reason_code` although other read facades retained it | B048 added returned field. Commit [`3b8d5092`](https://github.com/ardenee/ut_reader/commit/3b8d50927179e4dac71b342b8fe6f6c0493f737d) added `verify-uedb5-zen-compact-reason-db.php`, which constructs a real temporary UEDB5 metadata container and disposable SQLite schema: the parity reader, compact facade and `rows()` alias each retained both distinct unresolved reason codes (5/5 checks; PRD combined suite 13/13). No production MySQL touched. | **Reader fix verified through isolated DB-backed round-trip; Epic-cooked fixture parity remains open** |
| UE5-D002 | Compact SQL projection | Distinct unresolved reasons must remain distinguishable *if* consumer requires them | `ue_uedb5_dependency_edges` stores canonical outcome but no `reason_code`; two different unresolved reasons collapse to same outcome | B050/B056 SQLite fixture proves this, B057 proves republish removes stale edges. Preserve full V5 staged metadata for detailed diagnosis until a documented schema/consumer decision is made | **Open design/consumer requirement** |
| UE5-D003 | UE5 source-definition audit harness | Newer checkpoint classifications must be counted | `B0??` glob silently omitted B100+ inventory | B101 fixed `B[0-9][0-9][0-9]`; B102 requires explicit classification and negative-test failure when withheld | **Fixed; synthetic negative/positive regression passed** |
| UE5-D004 | Cross-environment consistency | Same tested implementation needed on release target | Historically DEV/PRD were behind GitHub, with dirty tracked changes | **Resolved:** the live code revisions matched `9d968aa5`; focused fix `470d7b1d` was pushed to GitHub and fast-forwarded to DEV from PRD. Earlier stashes remain preserved, not applied. | **Resolved for code revision consistency; full HTTP/DB acceptance separate** |
| UE5-D005 | UE5 `FPackageStore` backend FPackageId textual key normalization | Epic looks up numeric `FPackageId` values in each prioritized backend (`PackageStore.cpp`, pinned SHA256 `fb5c1930…`, lines 565–593). Hex letter casing cannot change package identity. | `Uedb5ZenPackageStoreBackendArbitrator::entry()` / `redirect()` uppercased the requested ID but not descriptor map keys. A lowercase equivalent could falsely return Missing/no redirect. | Fix commit [`470d7b1d`](https://github.com/ardenee/ut_reader/commit/470d7b1d02426e66062f4e11307040400292a5d9): canonicalize entry and redirect keys, reject malformed/colliding IDs; add negative-before / positive-after regression to `verify-uedb5-zen-backend-arbitration.php` (exit 2 → 0). 12/12 bounded suite, effective-store context, physical-provider selector green. Formal B005 implementation fingerprint refreshed. | **Fixed in code; synthetic integration checks pass; real mounted-store differential still open** |

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
| 5 Dependencies / failures / retries / backup/restore / workers | Several targeted standalone suites exist; current 13-suite bounded UE5 regression is green | Consolidated end-to-end lifecycle acceptance on isolated test DB and retained container |
| 6 Sync GitHub / DEV / PRD and deploy | Code aligned at `470d7b1d` after prior live alignment; prior HTTP 200 checks on PRD | Recheck live HTTP/DB acceptance after substantive runtime updates; do not reopen old stash comparisons |

## Current actionable backlog (do not create another batch just to rename it)

1. **Close backend FPackageId D005 fixture gate** with an authentic mounted-container/backend result differential; code fix and bounded tests have passed. Keep the existing DEV/PRD backups untouched.
2. **Turn the isolated 27,240-package result into reproducible integration acceptance**, including dependency sample comparisons, retries/failures, restore and concurrent worker tests. Reuse retained packages; no full scan just to remeasure counts.
3. **Complete D002 consumer decision and authentic D001 differential**: D001's isolated DB-backed compact/full metadata read round-trip now passes; D002 still needs a documented requirement for persisting detailed reasons in compact SQL.
4. **Cross-reference the formal 18 operation IDs and 33 open gates** with real tests/evidence; only set verified after all acceptance conditions pass.
5. **Expand independent source-derived inventories for UE1–UE5**, prioritizing reachable package parsing/dependency semantics rather than runtime-only callback names.

**Audit honesty rule:** a classified source symbol is not a difference, a fix, or certified parity. Every actual difference must have an explicit expected/actual comparison and closure evidence.

## Open obligation and evidence summary

| Work | Confirmed evidence | Not yet verified |
|---|---|---|
| D005 FPackageId casing | Pinned numeric Epic lookup; failing-before/passing-after PHP lookup regression; 12/12 suite | Real cooked/mounted family backend input differential |
| D001 compact reader reason field | Returned field, source-flow regression and real UEDB5 file → disposable SQLite → compact reader round-trip (5/5 assertions) | Independent Epic-cooked fixture data; production MySQL not tested |
| D002 compact SQL reason persistence | Two reason codes collapse in isolated SQLite SQL projection | Determine whether any required consumer needs detailed reasons in compact storage |
| UE5 mounted-store precedence and redirects | Synthetic priority/redirect tests and Epic method anchors | Complete multi-container authentic runtime fixture; provider/redirect round-trip |
| UE5 end-to-end dependency correctness | Isolated 27,240 publication complete (not repeated) | Sample package dependency outcomes, retry/recovery, concurrency and restore acceptance |
| Formal source obligations (UE5) | 18 source hash and 36 symbol checks pass | 33 open verification gates across 18 operations; no operation certified |
| UE1–UE4 and remaining UE5 call graph | Historical bounded source inventory | Authoritative cross-implementation behavioral evidence for reachable paths |


## 2026-10-16 synchronization checkpoint (non-destructive)

- GitHub main: `4a533498` when staging completed.
- Live DEV: `D:\Apache24\htdocs\unrealdb` at `5ac0d8f0`, **112 locally modified tracked files**, all 112 paths also changed upstream.
- Live PRD: `C:\Apache24\htdocs\unrealdb` at `5ac0d8f0`, **330 locally modified tracked files**, all 330 paths also changed upstream.
- Saved *tracked* local Git diffs / status manifests: `C:\Temp\unrealdb-prd-20261016-audit-sync.patch`, `C:\Temp\unrealdb-prd-20261016-audit-sync-status.txt`, `D:\Temp\unrealdb-dev-20261016-audit-sync.patch` and status manifest on DEV. These are **not full backups of untracked files or databases**.
- Clean, detached current-main worktrees: PRD `C:\Temp\unrealdb-prd-main-20261016`, DEV `D:\Temp\unrealdb-dev-main-20261016`. Both match `4a533498`, zero modified tracked files, and pass the bounded **12/12** UE5 audit tests.
- **Live applications are NOT upgraded or version-aligned yet.** Full cutover must merge/reconcile the local overlapping modifications and separately protect untracked files, deployment config and databases before activating code. Staging new worktrees is not equivalent to deploying them.


## 2026-10-16 live code alignment (completed)

- **Live PRD and DEV were fast-forwarded to GitHub `df87f0a2`**, with zero modified tracked files after upgrade and the bounded 12/12 UE5 source regression suite passing on both live application paths.
- Prior tracked changes are saved as labeled Git stashes (`pre-main-sync-PRD-20261016`, `pre-main-sync-DEV-20261016`) and tracked patches. Stashes were not reapplied over current code; any unmerged local changes require explicit review rather than silent reintroduction.
- To prevent overwrites of older untracked files newly tracked in main, **776 PRD files** were moved to `C:\Temp\unrealdb-prd-untracked-collisions-20261016`, and **180 DEV files** to `D:\Temp\unrealdb-dev-untracked-collisions-20261016`. Other untracked and ignored files were not deliberately removed.
- Both `catalog/config.php` paths remain present. PHP lint passed for `catalog/index.php`, bootstrap autoloader and Zen package reader. This is a code/test validation, **not a live HTTP or production MySQL acceptance test**.
- The six-task workflow's source-code synchronization stage is fulfilled at the commit level. Deployment-wide acceptance still needs HTTP/DB verification and independent integration checks. Next doc-only commit should be fast-forwarded to both live trees.


## Live PRD acceptance and preserved-change triage (2026-10-16)

- **PRD HTTP:** `http://127.0.0.1/` and `http://127.0.0.1/catalog/` both HTTP 200. URLs beginning `/unrealdb/` returned 404 because this Apache instance serves UnrealDB at the document root.
- **Isolated MySQL verified again after deployment:** direct read-only query on port 3339, `uedb5_isolated_integration.ue_iostore_packages`, `container_id=1`: **27,240 total, 27,240 published, 0 failed**. No full rescan.
- **Two targeted IoStore negative-path integration regressions on live PRD PHP against isolated MySQL** passed: `verify-iostore-missing-part-standalone.php` (reject incomplete container, SQL row count unchanged) and `verify-iostore-worker-guards-standalone.php` (reject inactive/non-UE5 profile and changed queued TOC hash via dead-letter jobs). Production MySQL remains outside this test path.
- **PRD old stash:** 330 formerly modified tracked paths; **308 byte-identical to current GitHub main**, **22 differ**, including V4 legacy compressed metadata files, older migrations and compact metadata utilities. Do not blindly restore those 22; investigate whether each is obsolete or a legitimate unmerged fix.
- **DEV old stash:** 112 formerly modified tracked paths; **46 byte-identical to current GitHub main**, **66 differ**, focused on job/upload APIs, workers, UI, archive processing, persistence and compact writers. **Source work not automatically recovered**: assess 66 potentially divergent files individually, with tests. Existing stashes and collision backups remain intact.
- Open checks: HTTP behavior with real authenticated requests, nonisolated production database smoke test, end-to-end retained full-family container dependency fixture, retry/recovery/backup-restore, concurrent workers. Do not claim that passing only synthetic/source inventories certifies Epic parity.


## DEV preserved-change reconciliation — first confirmed decisions (2026-10-16)

- **JobWorkerFactory:** preserved DEV version attempts to synchronize queued resource policies and run historical profile/outcome/system-error/parent-lifecycle repairs on *every worker startup*. GitHub main intentionally uses side-effect-free worker construction and explicit maintenance commands. **Decision: retain main; do not reintroduce startup writes.** Current PRD `verify-worker-startup-no-invalid-ue-backfill.php` passed.
- **Job action API:** preserved DEV `catalog/api/v1/job-action.php` lacks the newer `retry_children` and `revalidate` handling present in main (saved-version diff: 2 insertions / 167 deletions). **Decision: retain main.**
- **Targeted regression evidence:** PRD `verify-upload-worker-contracts.php` and `verify-resumable-job-workflows.php` both passed (exit 0); these are bounded scripts, not full authenticated end-to-end acceptance.
- **CompactSearchProjectionWriter:** preserved DEV version lacks the newer writer implementation (saved-version diff: 511 deletions compared with main). **Do not restore older file.** Separate source-to-writer behavior review remains open.
- No older stash files have been restored. Further 60+ DEV and 22 PRD divergent files remain to triage; exclude old intentionally deprecated V4 files from restoration, but retain recovery stashes.
