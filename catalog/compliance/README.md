# UnrealDB ↔ Epic source compliance — master audit register

**Purpose:** identify file-format/runtime-resolution differences between Epic source and UnrealDB, implement corrections, and retain reproducible evidence so an area is not audited again without a source change.

**Important: B001–B108 are checkpoint identifiers, NOT 108 fixes or a finite denominator.** Many B019–B106 entries are source-method classifications, not demonstrated defects. Do not use the latest batch number as a percent complete.

## Compliance recovery baseline (start here)

[RECOVERY.md](RECOVERY.md) describes the executable recovery and original-operation
closure order. [RECOVERY.json](RECOVERY.json) is the **machine-checked**
reconciliation of seven supported games, all 18 existing source obligations,
D001-D023 differences, lifecycle routes and known gaps.

Run `catalog/bin/verify-compliance-recovery.php` and its negative regression
before source acceptance. `verify-source-compliance.php --all` now checks
recovery consistency as well as pinned source hashes. A passing consistency
check is explicitly **not** a compliance certificate: source scope is still
incomplete, **B004, B005, B006, B007, B008, B009, B010, B011 and B012 are verified and 9 original operations remain partial**;
**15 evidence gates remain
open** after fully verifying B005 and closing B004's source encoding inventory
and B009/B010's scoped header caller inventories. No overall completion percentage is currently defensible.

## Layout and authoritative evidence

- `batches/`: 15 registered *source-to-implementation obligations* (18 operations) for the compliance verifier. These are the formal gates. Preserve their stable operation IDs and hashes.
- `inventory/`: append-only review evidence, individual source-method classifications, regression test observations and environment notes. **An inventory entry does not certify or close an obligation.**
- `DIFFERENCES.md`: human-readable difference → fix → tests → outstanding work ledger. **Use this first** to understand actual engineering work.
- `README.md`: process and navigation.

This is **not** a complete Unreal Engine file-format spec or a complete inventory of all Epic source. The current UE5 definition checks cover four selected classes in `AsyncLoading2.cpp` (88/88 classified) plus selected `PackageStore.cpp` and `ZenPackageHeader.cpp` methods (11/11 classified); the other C++ classes/sources and UE1–UE4 need their own reconciled inventories.

## Audit status and acceptance

The historical B108 baseline had 18 operations, 0 verified and 33 open evidence gates. **Current formal result: 11/18 source-defined operations verified, 12 open evidence gates, zero verifier errors**; the bounded PRD UE5 test suite passes **50/50**. All verified operations require pinned source hashes, executable branch/caller checks and operation-level acceptance. A passing operation is not a global UE1–UE5 compliance certificate. The source-symbol/hash checks passed on pinned sources; those are *presence checks*, not behavioral proof. The historical B108 combined isolated UE5 test runner passed 12/12 bounded tests. The 50-script bounded runner includes full operation-level acceptance for B004/B005/B006/B009/B010/B011/B012, independent source oracles and V5 consumer regressions. **B005 is fully verified for its documented backend-status/priority operation**, not for global UE5 engine or application compliance. It enumerates all PHP under catalog/src, monitors the exact direct Zen header and builder caller set, and includes negative unregistered-caller controls. The extra controls verify the source-backed B005 operation, not global engine compliance. All 50 scripts and the formal source-compliance verifier are rerun before each B006 closure deployment. Runtime source-verification checks are distinct from actually executing Epic's loader or performing a full engine differential. The D008 read-only real Satisfactory test cross-checks two retained package IDs against the header and V5 SQL; it does not assert runtime authority. Neither outcome proves Epic compliance. D006 additionally demonstrates a real provider-summary conflict bug (two red checks before, 23 green after), corrected at `450d3542` and verified using the real MySQL connection with temporary shadow tables across seven existing V5 snapshots plus a three-provider conflict fixture; no production catalog rows were altered. D002 is now explicitly documented as a compact SQL accelerator design decision, not a reason-code data-loss defect in the source-shaped UEDB5 read path. The code revision synchronization blocker was closed separately; see `DIFFERENCES.md` for the current active backlog and evidence.

For each actual divergence create a ledger entry containing: stable difference ID, affected engine/game, Epic source path/hash/lines and precise behavior, UnrealDB code reference, observed mismatch, correction commit, test fixture, passing/failing evidence and acceptance gate. Only mark **fixed+verified** when a negative-before/positive-after test and a source-compatible fixture prove the change. If not demonstrably mismatched use **unverified**, not **fixed**.

## Commands

Set `UNREALDB_EPIC_SOURCE_ROOT` to the pinned UE5.8.3 source root, then run:

```powershell
& C:\php8.5\php.exe catalog\bin\audit-ue5-source-definitions.php
& C:\php8.5\php.exe catalog\bin\audit-ue5-store-definitions.php
& C:\php8.5\php.exe catalog\bin\verify-uedb5-ue5-source-batches.php
& C:\php8.5\php.exe catalog\bin\verify-source-compliance.php --all --strict
# MySQL session-temporary SQL publisher acceptance (no persistent SQL writes):
& C:\php8.5\php.exe catalog\bin\verify-uedb5-dependency-sql-isolated.php
# Isolated Satisfactory real container-header read-only fixture (MySQL port 3339):
& C:\php8.5\php.exe catalog\bin\verify-uedb5-satisfactory-header-readonly-isolated.php
# Indexed, read-only live-data check (explicit file IDs are mandatory):
& C:\php8.5\php.exe catalog\bin\diagnose-uedb5-provider-consensus-readonly.php --file-ids=111139,113253
```

A strict failure while gates remain open is expected; it must not be reported as certification.

## Deployment rule

GitHub main, DEV, and PRD runtime code must be reconciled from their own worktrees before deployment. Do not equate copied audit JSONs with runtime sync. Preserve uncommitted changes and production data, and validate deployment against the application being used. The isolated MySQL `uedb5_isolated_integration` is not proof of a production-schema cutover.
