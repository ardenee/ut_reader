# UnrealDB ↔ Epic source compliance — master audit register

**Purpose:** identify file-format/runtime-resolution differences between Epic source and UnrealDB, implement corrections, and retain reproducible evidence so an area is not audited again without a source change.

**Important: B001–B108 are checkpoint identifiers, NOT 108 fixes or a finite denominator.** Many B019–B106 entries are source-method classifications, not demonstrated defects. Do not use the latest batch number as a percent complete.

## Compliance recovery baseline (start here)

[RECOVERY.md](RECOVERY.md) describes the executable recovery and original-operation
closure order. [RECOVERY.json](RECOVERY.json) is the **machine-checked**
reconciliation of seven supported games, all 18 existing source obligations,
D001-D031 differences, lifecycle routes and known gaps.

Run `catalog/bin/verify-compliance-recovery.php` and its negative regression
before source acceptance. `verify-source-compliance.php --all` now checks
recovery consistency as well as pinned source hashes. A passing consistency
check is explicitly **not** a global UE1-UE5 compliance certificate. For the
registered **UE5.8.3** operation set, **17 of 18 original operations are verified**
and only `UE5-583-B015-1` remains partial. Its source inventory is reconciled; the
remaining acceptance requires Epic Verse/VNI runtime material that is absent from the
supplied source and Launcher installation. After reconciling stale B002/B003/B015
batch-wrapper inventory flags, the formal verifier reports **one open operation gate**
and zero issues. Broader UE1-UE4/game-profile/workflow scope is still open, so no
overall cross-engine completion percentage is defensible.

## Layout and authoritative evidence

- `batches/`: 15 registered *source-to-implementation obligations* (18 operations) for the compliance verifier. These are the formal gates. Preserve their stable operation IDs and hashes.
- `inventory/`: append-only review evidence, individual source-method classifications, regression test observations and environment notes. **An inventory entry does not certify or close an obligation.**
- `DIFFERENCES.md`: human-readable difference → fix → tests → outstanding work ledger. **Use this first** to understand actual engineering work.
- `README.md`: process and navigation.

This is **not** a complete Unreal Engine file-format spec or a complete inventory of all Epic source. The current UE5 definition checks cover four selected classes in `AsyncLoading2.cpp` (88/88 classified) plus selected `PackageStore.cpp` and `ZenPackageHeader.cpp` methods (11/11 classified); the other C++ classes/sources and UE1–UE4 need their own reconciled inventories.

## Audit status and acceptance

The historical B108 baseline had 18 operations, 0 verified and 33 open evidence gates. **Current UE5.8.3 formal result: 17/18 operations verified, one open operation gate, zero verifier issues.** The full source-compliance run executes pinned-source checks and the declared operation drivers; at the 18 October 2026 closure point it reports 41 source-hash checks, 91 source-symbol checks, 307 source-contract assertions, 120 reachability assertions and 17 consumer assertions. The sole partial operation is `UE5-583-B015-1`: genuine Verse VNI/UHT code-generation provenance and a real `WITH_VERSE_VM=1` `VPackage`/`VCell` ScriptCells runtime require Epic components not present in the supplied source/Launcher distribution. This is retained as an external evidence prerequisite rather than silently accepted or expanded into new audit work. A passing UE5 operation is still not a global UE1-UE5 compliance certificate; see `DIFFERENCES.md` and `RECOVERY.md` for the cross-engine backlog and exact evidence boundaries.

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


**Actual UE5 script runtime evidence preparation:** [UE5-SCRIPT-RUNTIME-TRACE-PREPARATION.md](UE5-SCRIPT-RUNTIME-TRACE-PREPARATION.md) documents an opt-in reversible trace patch proposal for the four existing partial operations. The generator pins Epic UE5.8.3 \`AsyncLoading2.cpp\`, adds 15 source logging sites **to a disposable output file only**, and its 16-test verification confirms relative Git patch application, source integrity, deterministic output and refusal to overwrite Epic source. No UE5 runtime events have been captured; this does not change any original acceptance/disposition.

**Live-trace quality gate and installed-engine incident:** [UE5-SCRIPT-RUNTIME-TRACE-INSPECTION.md](UE5-SCRIPT-RUNTIME-TRACE-INSPECTION.md) records the byte-identical UE5.8.3 Launcher source, asset-resave incident, and pending official installation verification. The new 25-check read-only trace inspector validates typed path/hash identities and rejects fake/partial/synthetic evidence; no live UObject registry evidence has been accepted, so all four partial operations remain open.

**D007 header accountability closure:** [B006-CONTAINER-HEADER-SOURCE.md](B006-CONTAINER-HEADER-SOURCE.md) separately registers the existing source-backed IoContainerHeader load fix as a prerequisite to the existing B006 mounted file-store operation. Original operations remain 18, B006 remains verified after a distinct 25-check source/caller oracle plus 33 byte-fixture checks; D007 is no longer unregistered. Only the bookkeeping gap is closed; future cooked v6, cross-container live runtime and overall UE1–UE5 compliance remain unverified.

D030 (existing B012): B012-OPTIONAL-MEMBER-PUBLICATION-SOURCE.md records the main/optional IoStore file identity correction c555f1c4, all consumer routes, historical negatives, 27 accepted checks and the missing authentic optional cooked fixture. Original operation denominator unchanged.

D031 B006 durable IoStore retry: B006-CONTAINER-RETRY-INTEGRITY.md records the count-only corruption defect, correction 1bb60bd9 and 21 real-TOC/isolated-SQLite checks. MySQL concurrency unverified.
