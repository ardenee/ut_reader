# UE5-D033 — Zen dependency source counter omitted header packages and bundle edges

**Update 19 October 2026 — PRD migrations verified complete:** On Lelly-pc, UnrealDB official migration runner applied `202610150001` and `202610150002` as batch 2, then `migrate.php verify` succeeded. Independent read-only DEV-to-PRD MySQL inspection confirmed all three `ue_iostore_*` tables, seven package metadata columns, and both required indexes. The earlier migration-pending statements below record their pre-migration state and are superseded by this dated verification. No Satisfactory production import was initiated.

**Status:** source-backed defect corrected and reproduced against an actual Satisfactory Dedicated Server UE5 cooked container, in isolated MySQL only. **Not a claim that full UE5 reachability is complete.**

## Epic reference and affected consumer

Pinned Epic UE5.8.3: `Engine/Source/Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp`, `FAsyncPackage2::ImportPackagesRecursiveInner` and `FZenPackageHeader::ImportedPackageIds`; Zen dependency-bundle import/export edges are serialized with `kind`. UnrealDB's production resolver `Uedb5Ue5ZenDependencyResolver::resolve` correctly records independent `imported_package_ids` and the `kind`-qualified dependency bundle entries. However `Uedb5MigrationValidator::zenHeaderDependencySourceCount` omitted the former and read a nonexistent `target_type` property for the latter. Consequently it falsely rejected correct persisted Pass-2 output.

## Real negative-before / positive-after reproduction

The **source** fixture is the unmodified Satisfactory Dedicated Server experimental install at `//192.168.0.55/c$/Program Files/Epic Games/SatisfactoryDSExperiment/FactoryGame/Content/Paks/FactoryGame-WindowsServer.utoc` and its sibling `.ucas`. DEV retained the verified bytes in `C:/Temp/uedb5-iostore-retention-test`, isolated MySQL `127.0.0.1:3339/uedb5_isolated_integration`. This **does not populate PRD Satisfactory files**.

- The real `.utoc` indexed **27,240** package members, then the complete TOC/UCAS family was retained, hashed and marked `ready` by the normal storage service (container ID 4).
- An Oodle decoder already present in the UE5.8.3 probe (`.../BuildGraph/net10.0/runtimes/win-x64/native/oo2core.dll`) successfully decoded **5/5** compared packages with matching SHA-256 and enforced the size-limit failure case. This is a test environment dependency, not a code fix or a bundled library.
- Bounded name-index tests processed **50** real members with **zero failures** after applying `202610150002_iostore_package_names.php` to isolated MySQL only and using the actual DLL path. Repeated package-member publication for TOC entries 52 and 53 returned `existing` with stable file IDs and V5 projections.
- Targeted Pass 2 produced 3 results for isolated file ID 1 and **98** for file ID 2. Real file ID 2's serialized source contains **8 imported-package IDs**, **36 object imports** (28 dependency-relevant), and **62 bundle entries** (48 `Export`, 14 `Import`), yielding **8 + 28 + 62 = 98**.
- **Before:** `Uedb5MigrationValidator::validate(2, $persistedV5)` threw `UEDB5 dependency result count does not match the source dependency count` because it expected only 28. The other tested file validated.
- **After:** both genuine persisted snapshots validate `ready=true`: file 1 has 3 results/3 edges; file 2 has **98 results/98 edges/8 package projections**.
- Bounded member reimport passed with a stable file ID and identical V5 SHA-256; it correctly returns the file to `staged` and requires dependency Pass 2 again. The isolated container backup/replay/restore passed for the full TOC+UCAS set and preserved 27,240 indexed members and its partition hash.
- Regression script `catalog/bin/verify-ue5-583-zen-dependency-count.php` checks source-shaped `imported_package_ids`, object imports and `kind` edges, including rejection of invented `target_type`.

## Production readiness conditions not bypassed

PRD MySQL `hwmvlite_ut_reader_catalog` was queried **read-only**. Its `ue_iostore_packages` DDL lacks columns added by the existing migration `202610150002_iostore_package_names.php`; that migration was applied only to the isolated DB for test continuation. The PRD migration must be deployed through its controlled migration path **before the user imports Satisfactory**. The Satisfactory game currently has no registered imported files or assigned game profile in PRD; this is intentional. Do not use a UE2 game/profile for production Satisfactory — only the disposable isolated fixture did so with temporary UE5 profile assignment.

The **18 registered UE5 source operations** remain distinct from unverified broader workflow/source reachability; no production import or full-UE5 certification was performed.

## B009/B010 consumer hash reattestation

After changing the validator, the original B009 Zen-versioning and B010 Zen-conditional-layout manifests correctly invalidated their pinned consumer SHA-256 evidence. The shared independent consumer test `catalog/bin/verify-uedb5-zen-version-staging-contract.php` passed all checks, then both manifests were updated to the actual validator hash `ec5374803e5e611b831dd2bcf63f22ea9b523ea2b1512b7da25d764b458432c3`. With `UNREALDB_EPIC_SOURCE_ROOT=L:/Source/Engine/UE5/UE 5.8.3`, `verify-source-compliance.php --strict` completed: 18/18 original operations verified, 0 issues. This reattestation does not close UE5 end-to-end scope gaps.
