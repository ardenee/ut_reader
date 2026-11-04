# UnrealDB current project status

## Verified checkpoint: UE1 published, statistics current (4 November 2026)

**UE1 fully closed within the seven defined UnrealDB operations: 7/7 PASS, 0 BLOCKED.** Current fixed contract register: **29 PASS / 3 BLOCKED** across five engines; only UE2, UE3, UE4 website dependency source epochs are pending.

**PRD-local UE1 cutover completed:** the user supplied the Lelly-pc production log C:\Temp\UnrealDB\UE1\epic-policy-cutover\cutover-20261104-132552.log. Both resumable workers exited zero. Independent read-only PRD SQL and source-policy verifier confirmed **UT99 74,303/74,303** and **Unreal Gold 10,788/10,788** verified files current under UE1-mount-v14, 0 stale, 0 old v13 rows. UE2/UE3/UE4 epochs remain separate; UE5 stays current. Accepted native UE1 reader/serialization evidence was reused, not rerun.

**games.php Unavailable fixed:** the cutover advanced last UE1 dependency publication to November 4, but cached game stats were from October 5. Game-list freshness correctly hid outdated totals. Rebuilt existing scoped game statistics for IDs 3 and 12 against PRD; both rows were rebuilt November 4 at 15:41:47. They now satisfy the source policy, payload SHA and aggregate-after-publication gates. Public Missing and Base-game Missing badges are numeric (both 0 *confirmed missing*); this does **not** imply all UE1 imports are resolved, as source-unknown mounted providers remain unresolved.

**Future cutovers protected:** the PRD-only UE1 orchestrator now refreshes both game statistics after dependency republication and **before** final cutover verification. The full verifier includes game_stats_current for each game; stale cache rejects a false success. Seven isolated SQLite negative/positive freshness cases and a PowerShell AST/order check passed. DEV PHP HTTP against real PRD read-only MySQL returned HTTP 200 on games.php, both dependency JSON endpoints, Uses/Used By, File Info and both game file lists. Source data, not old v13 records, drives the results; no game runtime was invented. Authenticated PRD browser was not independently exercised.

**Source scope:** the seven implemented UE1 library/website paths are closed, not every possible historical UE1 binary or mounted game session. Three remaining blockages are UE2, UE3 and UE4 website dependency publication, not UE1. Evidence: [UE1 production and games.php closure](../tests/evidence/ue1/ue1-prd-v14-published-stats-website-closure-20261104.json) and [operation register](../tests/contracts.json).

---

## Historical UE1 pre-cutover checkpoint (superseded 4 November 2026)

**UE1 website dependency remains BLOCKED, not PASS.** Genuine production read-only preflight on both UE1 games shows **85,091/85,091** verified files with staged current-format UEDB5 and primary provider keys: UT99 game #3 has 74,303 and Unreal Gold game #12 has 10,788. Both have zero invalid identities and complete staging, but **0/85,091 currently published under uedb5-dependency-pass-ue1-mount-v14**. Original source/native parser evidence was reused, not rerun.

The current PHP website examiner and File Info rendered HTTP 200 on DEV using read-only PRD MySQL for original file IDs #113256 and #1246569, with stale-data notices; JSON dependency endpoints return HTTP 409 and hide old v13 links. Admin-only Missing pages cannot be verified unauthenticated. Existing production canary, migration and policy verifier scripts are SHA-identical across DEV and PRD; no original UE1 file or production database was modified.

**Operational blocker:** PRD Lelly-pc is not connected to Desktop Commander. WinRM, WMI and remote Task Scheduler from DEV are unavailable. The scoped migration script enforces local PRD execution; the separate production rollout policy additionally requires a verified full database/metadata backup and restore point before writes. The script does not itself verify a full database backup, and no restore point has been independently verified. Do not bypass the guard by migrating 85,091 files from DEV over UNC storage and remote MySQL.

**PRD-local execution after a verified backup/restore point:** run the already-staged PowerShell script C:\Apache24\htdocs\unrealdb\catalog\bin\apply-ue1-epic-policy-cutover.ps1 with -Apply -CanaryOnly; after both canaries pass, run it with -Apply -Workers 2 -Limit 100, then C:\php8.5\php.exe C:\Apache24\htdocs\unrealdb\catalog\bin\verify-ue1-epic-policy-cutover.php. The jobs are resumable, scoped to UE1 games 3 and 12, and preserve UE2-UE5 independent epochs.

Evidence: [genuine UE1 preflight and website read-path status](../tests/evidence/ue1/ue1-prd-v14-readiness-and-local-execution-gate-20261104.json). The [single contract register](../tests/contracts.json) retains ue1-website-dependency=BLOCKED until actual source-correct production regeneration and website outcomes are verified.

---

## Verified checkpoint: 4 November 2026 - seven UE5 operations closed

**Current fixed operation-path register: 28 PASS / 4 BLOCKED across five Unreal engines. UE5 is 7/7 PASS in the implemented file/header/table/IoStore/dependency/website scope.** The four remaining blocked cases are UE1-UE4 website dependency views; their historical v13 database projections have not been republished under per-engine v14.

**PRD Satisfactory game 14:** FactoryGame-WindowsServer.utoc retained and indexed 27,240 original Zen packages. Durable source-backed dependency pass job #9525707 completed all 27,240/27,240, leaving 0 stale UE5 v14 policy publications. Current missing-dependency SQL projections contain 0 explicitly source-proven missing objects; source-unknown mounted references remain unresolved rather than inferred missing. Historical Zen SQL-examination backfill job #9525708 completed separately; new package publication writes examination rows atomically.

**Upload lifecycle fixed and deployed:** commit [9ba48789](https://github.com/ardenee/ut_reader/commit/9ba48789) schedules name indexing, member publication and a durable dependency follow-up from initial IoStore upload/backup restore. The dependency job defers until publication completes, reports failed name indexing and reuses active dedupe keys on retry. 13 named handoff checks passed. Metadata-only global.utoc is not misclassified as package-bearing.

**UE5 web and dependency closure:** commit [89e776e8](https://github.com/ardenee/ut_reader/commit/89e776e8) corrects File Info, Uses, Used By and the JSON endpoint for verified Zen packages. Real exact FPackageId references from current UEDB5 summary records produce 1 consumer for Ramp #1363210, 212 for BC_Foundations #1379013, and 202 for ACLAnimBoneCompressionSettings #1386377. Pagination, negative provider and duplicate physical provider cases are checked without claiming native runtime mount resolution. Local PHP HTTP views ran against production MySQL read-only with unavailable .uedb5 storage to verify SQL-only browsing. Ten changed worker/web source files were deployed DEV to GitHub to PRD, with SHA-256 comparisons.

**Closure boundary:** these results verify seven implemented UnrealDB UE5 operations, reusing accepted Epic 5.8.3 native comparisons and current Satisfactory 5.6.1 selected-source evidence, not every historical UE5 cooked format. UE5.0.0 first-release 44-byte Zen summary has not been independently validated from genuine 5.0 cooked bytes. Direct unauthenticated PRD HTTPS requests from DEV returned 403; authenticated production browser UI is not independently proven, although DEV HTTP plus PRD read-only MySQL and PRD code hashes are. No Epic source, original PRD package bytes or PRD SQL changed in this closure audit.

Accepted evidence: [UE5 auto dependency and website F17/F22 closeout](../tests/evidence/ue5/ue5-upload-dependency-and-website-final-closure-20261104.json) and [single operation register](../tests/contracts.json).

---

## Historical checkpoint: 3 November 2026 - superseded by UE5 live publication

The 3 November source-parity summary described 32 implemented code paths, but the live end-to-end contract register still had five website blockers then. This does not represent 32 end-to-end passes. Original UE5.0.0
FZenPackageSummary is **44 bytes** (GraphDataOffset), versus 5.8.3
**52 bytes** (dependency bundles/imported names). The source-pinned 5.8.3
Zen reader must not be advertised as native-verified for UE5.0.0 cooked bytes;
no genuine 5.0.0 cooked fixture was tested in this batch.

**PRD 235,239 verified/staged source files remain intact; their dependency
projections still carry the historical v13 marker.** All five engine
resolvers now use distinct source-correct v14 publication epochs. Old v13
missing/resolved outcomes are withheld from user-facing missing/Requires/
Required By and cross-game dependency reads until actual source-correct
per-engine Pass 2 regeneration; no policy is mass relabeled. UE1 85,091
pending, UE2 79,179, UE3 6,722, UE4 64,247; UE5 Satisfactory
game14 has 0 imported/staged IoStore packages in PRD. Read-only checks
against original UT4 file #1353254 retain 527 original SQL edges but
reject stale dependency interpretation. Duplicate verified import with
intact V5 and stale-only dependency status remains a duplicate, **not**
a forced source reimport or metadata repair.

**No production SQL, original source, IoStore imports or UEDB5 metadata
writes** occurred in this verification phase. Epic source and DEV-native
fixture inventories remain untouched. See
[UE5 actual first/latest source and engine-epoch evidence](../tests/evidence/ue5/ue5-500-vs-583-zen-struct-and-all-engine-policy-epoch-20261103.json)
and the [per-engine production rollout gate](../tests/ENGINE-DEPENDENCY-EPOCH-CUTOVER-20261103.md).
The earlier October checkpoints below are historical descriptions.

**Verified checkpoint: 15 October 2026.** This document is the navigation entry point for **current** production state. Dated migration and format design reports remain historical source/provenance records, even where their original text uses present tense.

## UE5.8.3 audit update — 19 October 2026

This section supersedes the historical UE5/IoStore limitation statement below, but does not change the seven-game production checkpoint. The strict pinned Epic verifier passed 18/18 registered operations (41 source hashes, 91 source symbols, 312 contract and 120 reachability assertions, zero issues). Isolated DEV Satisfactory Zen V5 publication, dependency Pass 2 and validation passed 27,240/27,240, and PAK v11 extraction passed 4,761/4,761. A new independently executed Epic UE5.8.3 UnrealPak differential also matched 4,761/4,761 per-file entries and decoded SHA-256 results (212,783,532 bytes). A pinned engine-content real-file sample also accepted 475 genuine UE5 classic .uasset readers across ten UE5 version values; 119 genuine UE5 classic assets also passed the V5 staging writer/reader roundtrip (25 older pre-UE5-summary assets recognized as legacy). The independent Epic Zen retained-TOC comparison also passes **27,240/27,240 reconstructed package hashes and lengths (1,398,535,898 bytes)**. All 16 UE5-subset workflows are now mapped in the recovery register but remain unaccepted pending complete reachability and integration evidence. Production DEV/PRD IoStore schema migrations have both been applied. No Satisfactory production import has been started. **UE5.8.3 compliance certification remains open** pending exhaustive production workflow, branch inventory and independent Epic differential. See [UE5 certification status](../catalog/compliance/UE583-CERTIFICATION-STATUS.md) and [recovery register](../catalog/compliance/RECOVERY.json).

## Current production baseline

- Production is **UEDB5-only**. All **235,239** verified files across Unreal Gold, UT99, Unreal II, UT2003, UT2004, UT3, and UT4 have format-5 registration and `validated` migration status.
- The bounded post-cutover verifier passed. It checks current registration/status, missing/incorrect registrations, retired table absence, runtime fallback and **14** representative V5 container hashes. It is **not** an exhaustive fresh reparse of 235,239 source packages.
- The live `metadata` tree contains exactly **235,239 canonical `.uedb5` containers**, no V1-V4 containers or other artifacts. **48** obsolete/stale files (21,344,662 bytes) were moved to a separately retained SHA-256-journaled quarantine: `C:\Temp\uedb5-legacy-quarantine-20261015`.
- The checked historical V1-V4 metadata SQL tables are absent; the retired-table runtime reference scanner returns **0** references. **Do not drop** `ue_uedb5_compact_name_candidates`, `ue_uedb5_compact_object_candidates` or their V5 dictionary: they are active search/publication data.
- The production schema inventory estimates **88,854,872,064 bytes** for 72 base tables, with both live compact candidate tables together estimated at **74,387,734,528 bytes**. These are information-schema estimates, not a measured disk-reclamation promise.
- Existing production MySQL 8.4, Apache 2.4 and PHP 8.5 remain the target runtime. Production MySQL stays on its own service; isolated integration MySQL is independently hosted on local loopback port 3339 and is shut down after testing.

## Lifecycle and operational validation

- **Seven-game:** real scanner upload/duplicate handling, stable-ID reimport, game-backup restore with durable parent/child jobs, and non-deferred physical/catalog deletion with completed dependency reconciliation each passed bounded isolated MySQL tests on all seven games.
- **Workers:** two concurrent real PHP processes completed 40/40 persisted jobs exactly once; explicit dead-letter retry, corrupted-backup rejection and interrupted two-file backup restoration were tested. The recovered backup parent 115, entries 116/117 and dependency job 118 completed without replaying an already successful entry.
- **UT3 correction:** `PdoPackageSupersetAnalyzer` now uses the worker-provided storage configuration, avoiding accidental global-production-storage reads during isolated reconciliation; job 71 recovered successfully.
- **Regressions:** the final current post-cleanup regression set passed **17/17**. Seven representative source packages also passed current parser/V5 SQL publication/rollback fixtures.
- No full 235,239-file rescan was run for this checkpoint. All seven-game lifecycle fixtures were isolated from production SQL and stored packages.

## Known coverage limits

- **UE5 Zen/IoStore:** source-backed readers/resolvers and fixtures exist, but the seven current production games do not establish production `.utoc/.ucas` ingestion or live Zen package-store coverage.
- The tested package samples and 14 bounded production hash checks are not exhaustive engine-policy parity or source-data validation over all records. Source-specific conformance exceptions remain documented in the relevant specs.
- Candidate SQL size is a performance concern, **not** evidence that candidate tables are obsolete. Any redesign needs query-plan, search/provider parity and staged rollback evidence.
- Fixture setup and destructive integration scripts are **not idempotent**; recreate isolated database/storage fixtures before replaying imports or deletions. Never point them at the production MySQL service.

## Current commands (bounded/read-only on production)

```powershell
C:\php8.5\php.exe catalog/bin/verify-uedb5-post-cutover-bounded.php
C:\php8.5\php.exe catalog/bin/verify-uedb5-candidate-cutover-readonly.php
C:\php8.5\php.exe catalog/bin/audit-legacy-runtime-references.php
C:\php8.5\php.exe catalog/bin/audit-uedb5-obsolete-schema-readonly.php
C:\php8.5\php.exe catalog/bin/migrate.php status
```

**Do not rerun** historical V3/V4 migration/retirement scripts, destructive quarantine actions or all-file migration commands as a routine health check.

## Authoritative documentation and evidence

- [V5 lifecycle test results, job IDs, quarantine and isolated restart instructions](../catalog/docs/UEDB5-V5-LIFECYCLE-INTEGRATION.md)
- [V5 format specification](../specs/uedb5-format.md) (normative format sections; dated migration discussion is historical)
- [Epic-source conformance ledger](../specs/epic-source-conformance.md)
- [Game-profile version admission](../specs/game-profile-version-admission-2026-10-10.md) (no arbitrary version/licensee range rejection)
- [Production deployment](production-deployment.md), [installation](installation.md), [database migration guide](database-migrations.md)
- [Earlier production closeout (historical checkpoint)](../specs/uedb5-production-closeout-2026-10-12.md)
