# UnrealDB current project status

**Verified checkpoint: 15 October 2026.** This document is the navigation entry point for **current** production state. Dated migration and format design reports remain historical source/provenance records, even where their original text uses present tense.

## UE5.8.3 audit update — 19 October 2026

This section supersedes the historical UE5/IoStore limitation statement below, but does not change the seven-game production checkpoint. The strict pinned Epic verifier passed 18/18 registered operations (41 source hashes, 91 source symbols, 312 contract and 120 reachability assertions, zero issues). Isolated DEV Satisfactory Zen V5 publication, dependency Pass 2 and validation passed 27,240/27,240, and PAK v11 extraction passed 4,761/4,761. A new independently executed Epic UE5.8.3 UnrealPak differential also matched 4,761/4,761 per-file entries and decoded SHA-256 results (212,783,532 bytes). The independent Epic Zen retained-TOC comparison also passes **27,240/27,240 reconstructed package hashes and lengths (1,398,535,898 bytes)**. All 16 UE5-subset workflows are now mapped in the recovery register but remain unaccepted pending complete reachability and integration evidence. Production DEV/PRD IoStore schema migrations have both been applied. No Satisfactory production import has been started. **UE5.8.3 compliance certification remains open** pending exhaustive production workflow, branch inventory and independent Epic differential. See [UE5 certification status](../catalog/compliance/UE583-CERTIFICATION-STATUS.md) and [recovery register](../catalog/compliance/RECOVERY.json).

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
