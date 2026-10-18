# UE4.27.2 source audit — resumption baseline (18 October 2026)

## Authority and boundaries

- Epic UE4 source: `L:\Source\Engine\UE4\UE 4.27.2`, Git `d94b38ae3446da52224bedd2568c078f828b4039`. `Engine/Build/Build.version` identifies Major 4, Minor 27, Patch 2.
- The independent checkout `C:\Users\arden\source\repos\UE4` points to the same Git revision. The canonical source tree was not modified.
- Existing specifications: `specs/ue4-4.27.2-package-format.md`, `specs/ue4-4.27.2-dependency-resolution.md`, and `specs/ue4-4.27.2-verifyimport-conformance-audit.md`. The latter is a dated 29 September checkpoint, **not a complete present-day compliance certificate**.
- **Do not apply UE4.27.2 policy to UT4.** The project explicitly pins UT4 to its separate clean-master source at package version 511. UE4.27.2 is a later supplemental reader/format reference. Neither source overrides the other.
- UE5.8.3 remains a separate 17/18 verified audit, with B015-1 blocked on absent external Epic Verse prerequisites. Do not restart that work as part of UE4.
- This document is a recovery entry for UE4 audit, **not** a new B-number, not a UE4 completion count, and not a declaration of fresh Epic/UnrealDB differences.

## First source anchor

Epic `Engine/Source/Runtime/CoreUObject/Private/UObject/PackageFileSummary.cpp` SHA-256 `8FF60DD29D5A0E50EB1222DA0D9C5DF71E088A1F2641E8512E6F6CE05CBA4146` implements `CurrentLegacyFileVersion=-7`. Loading a negative legacy marker below -7 zeroes file/licensee versions and returns before consuming the remaining summary. This **format-layout** decision is not a profile file-version range gate. The already-fixed, read-only UnrealDB inspector records unsupported -8/-9, and the independent `verify-ue427-legacy-summary-inspector.php` returns exit 0 (3/3 synthetic tests). See `UE427-LEGACY-SUMMARY-DIAGNOSTIC.md`; do not mistake the synthetic test for full package parsing acceptance.

## Existing UE4 contracts — first DEV execution

| Driver | Result | Classification |
| --- | --- | --- |
| `verify-ue4-427-reader-contract.php` | exit 0 | Existing reader unit evidence; not full Epic coverage |
| `verify-ue4-package-name-boundary-contract.php` | exit 0, 7/7 | Existing version/editor-only package-name boundary evidence |
| `verify-ue4-rf-public-mask-contract.php` | exit 0 | Existing `RF_Public=0x1` behavior |
| `verify-ue427-legacy-summary-inspector.php` | exit 0, 3/3 | Existing explicitly bounded summary fix |
| `verify-ue4-dependency-matching-contract.php` | exit 1, 1/37 failed | Test expects missing `catalog/bin/audit-ue4-missing-verifyimport.php`; must determine current replacement/retire test |
| `verify-ue4-targeted-dependency-rebuild-contract.php` | exit 255 | References nonexistent `CompactDependencyRebuilder` class |
| `verify-ue4-v4-package-name-repair-contract.php` | exit 2, 8 failures | References missing retired `repair-ue4-v4-package-name-unresolved.php` |
| `verify-ue4-v4-metadata-unresolved-contract.php` | exit 1, 8/10 | Two current policy/behavior assertions fail: `ut4_v511_source_unavailable_reason_is_explicit` and `top_level_package_import_uses_selected_physical_provider`. **These are UT4 V4 compatibility assertions, not proven UE4.27.2 Epic source discrepancies.** |

The absent UEDB4 diagnostics were removed by Git commit `ac5e4a8d` (Retire UEDB4-only diagnostic tooling). Do not blindly restore obsolete tooling or weaken assertions. First map legacy tests to current V5 services or explicitly classify retired coverage; separately investigate the two genuinely failing V4 metadata assertions.

## Work contract for UE4.27.2

1. Reconcile the **existing** UE4 specs and the dated VerifyImport audit against the pinned UE4 source. Reuse canonical source symbols and hashes, retaining original paths and source-defined conditional branches.
2. Trace reader/load implementation end-to-end: summary and version layout, name/import/export maps, serialized offsets, bulk and optional payloads, package/asset registry dependencies, and `FLinkerLoad::VerifyImportInner` resolution semantics. Record untested branches as unknown rather than silently accepted.
3. Trace current UnrealDB readers and their active upload/import, restore, resync, repair, V5 projection and query consumers. Separate genuine UE4.27.2 behavior from older UT4 and from UE5 IoStore-specific semantics.
4. Assign a **stable difference ID only when source and implementation are demonstrably divergent**; link source location, impacted production caller, negative-before/positive-after fixture, code fix and deployment result. No new numbered batch sequence merely for investigation.
5. Preserve an explicit source-inventory completeness denominator and a distinct acceptance gate for each in-scope operation. Runtime-only state must remain unresolved if no authentic UE4 runtime capture or source-equivalent differential proves it.
6. Use DEV for code changes and tests; commit to GitHub main through the DEV shell and fast-forward PRD after successful tests. Never alter the canonical Epic checkout or use bulk SQL rewrites as an audit shortcut.

**Current UE4 disposition:** inventory and live production acceptance **not yet verified**. This is the start of the UE4 phase, not completion.
