# UE1 — source-to-production implementation register

New process, started 2026-10-21. This is a bounded difference register, not a claim of whole-engine compliance.

## Source branches examined

1. `L:\Source\Games\UT99\Unreal Tournament [v1.400] [1999-11-30]`: `Core\Inc\UnObjVer.h:20-26`; `Core\Src\UnLinker.h:144-204,327-376`.
2. `L:\Source\Games\Unreal\Unreal [v1.227]`: `Core\Inc\UnObjVer.h:23-46`; `Core\Inc\UnLinker.h:97-180`. This tree contains explicit non-Epic licensee changes and needs provenance classification.
3. `L:\Source\Engine\UE1`: README indicates a version-200-derived community project; treat as secondary until its differences are classified.

## UE1-D001 — cross-engine package tag admitted to UE1 (INCORRECT, corrected; O01 still open)

**Epic source:** UT99 v400 `PACKAGE_FILE_TAG = 0x9E2A83C1`, checked for exact equality inside `ULinkerLoad::ULinkerLoad` after summary deserialization (`UnLinker.h:362-369`). The available Unreal v227 branch defines the same standard tag (`UnObjVer.h:23`).

**UnrealDB before correction:** `catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php` used `CatalogUnrealPackageTag::isSupportedLittleEndianValue` for both UE1 and UE2. That shared predicate intentionally admits `0x9E2A83C2` for UE2/Killing Floor. Consequently, when instantiated as `CatalogUE1PackageReader`, the legacy parser admitted the UE2-only tag.

**Evidence before patch:** `catalog/bin/verify-ue1-package-tag-source.php` returned false for both `ut99_v400_rejects_ue2_killing_floor_tag` and `unreal_v227_rejects_ue2_killing_floor_tag`, exit 1.

**Production fix:** In the canonical shared parser, UE1 requires `CatalogUnrealPackageTag::STANDARD`; the existing generic admission predicate remains operative for UE2. No shared global tag policy was changed.

**Evidence after patch:** The new 6-check source-anchored test passes, including retained UE2/Killing Floor admission. Existing tag, UE1 legacy, v227 and UE1/UE2/UE3 regression checks pass. See `UE1-ACCEPTANCE.md`.

**Residual O01 scope:** independently verify every production admission caller, game-profile/version dispatch, file-byte identification, graceful rejection and V5 publication. This defect is corrected but O01 is **not ACCEPTED**.

## UE1-D002 — legacy minimum version decision requires review (UNRESOLVED)

**Epic source:** UT99 v400 `UnLinker.h:371-375`: when `Summary.FileVersion < PACKAGE_MIN_VERSION` (60), loader asks the user whether to continue. This is conditional, not an unconditional rejection. Unreal v227 separately defines minimum 60.

**UnrealDB:** `CatalogLegacyPackageReader.php:273-375` reads version and proceeds into tables without that prompt; product-level profile gates may impose a different supported scope. No proven end-to-end discrepancy is claimed yet.

**Next:** establish supported file generations, available input policy, profile admission and correct noninteractive failure semantics before deciding implementation. Do not invent a blanket version rejection.

## Production integration traced so far

- `catalog/src/Infrastructure/Readers/CatalogReaderResolver.php`: UE1/UE2 reader class mapping.
- `catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php`: shared parser with UE1/UE2 wrappers.
- `catalog/src/Infrastructure/Metadata/Uedb5SourceSnapshotFactory.php`: source keys `ut99`, `unrealgold`, profile decision, special v227 constructor, V5 snapshot dispatch.
- `catalog/src/Infrastructure/Metadata/Uedb5Ut99SnapshotBuilder.php` and `Uedb5UnrealSnapshotBuilder.php`: distinct game snapshots.
- Other callers (header inspector, scanner, staging, browser, persistence) still require full O01 walkthrough.
