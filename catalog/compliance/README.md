# UnrealDB ↔ Epic source compliance — master audit register

**Purpose:** identify file-format/runtime-resolution differences between Epic source and UnrealDB, implement corrections, and retain reproducible evidence so an area is not audited again without a source change.

**Important: B001–B108 are checkpoint identifiers, NOT 108 fixes or a finite denominator.** Many B019–B106 entries are source-method classifications, not demonstrated defects. Do not use the latest batch number as a percent complete.

## Layout and authoritative evidence

- `batches/`: 15 registered *source-to-implementation obligations* (18 operations) for the compliance verifier. These are the formal gates. Preserve their stable operation IDs and hashes.
- `inventory/`: append-only review evidence, individual source-method classifications, regression test observations and environment notes. **An inventory entry does not certify or close an obligation.**
- `DIFFERENCES.md`: human-readable difference → fix → tests → outstanding work ledger. **Use this first** to understand actual engineering work.
- `README.md`: process and navigation.

This is **not** a complete Unreal Engine file-format spec or a complete inventory of all Epic source. The current UE5 definition checks cover four selected classes in `AsyncLoading2.cpp` (88/88 classified) plus selected `PackageStore.cpp` and `ZenPackageHeader.cpp` methods (11/11 classified); the other C++ classes/sources and UE1–UE4 need their own reconciled inventories.

## Audit status and acceptance

As of B108, `verify-source-compliance.php --all` reported **18 operations, 0 verified, 33 open gates**. The source-symbol/hash checks passed on pinned sources; those are *presence checks*, not behavioral proof. The combined isolated UE5 test runner passed 12/12 bounded tests. Neither outcome proves Epic compliance.

For each actual divergence create a ledger entry containing: stable difference ID, affected engine/game, Epic source path/hash/lines and precise behavior, UnrealDB code reference, observed mismatch, correction commit, test fixture, passing/failing evidence and acceptance gate. Only mark **fixed+verified** when a negative-before/positive-after test and a source-compatible fixture prove the change. If not demonstrably mismatched use **unverified**, not **fixed**.

## Commands

Set `UNREALDB_EPIC_SOURCE_ROOT` to the pinned UE5.8.3 source root, then run:

```powershell
& C:\php8.5\php.exe catalog\bin\audit-ue5-source-definitions.php
& C:\php8.5\php.exe catalog\bin\audit-ue5-store-definitions.php
& C:\php8.5\php.exe catalog\bin\verify-uedb5-ue5-source-batches.php
& C:\php8.5\php.exe catalog\bin\verify-source-compliance.php --all --strict
```

A strict failure while gates remain open is expected; it must not be reported as certification.

## Deployment rule

GitHub main, DEV, and PRD runtime code must be reconciled from their own worktrees before deployment. Do not equate copied audit JSONs with runtime sync. Preserve uncommitted changes and production data, and validate deployment against the application being used. The isolated MySQL `uedb5_isolated_integration` is not proof of a production-schema cutover.
