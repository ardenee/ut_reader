# UE4.27.2 P01 — Epic-native custom versions, byte order and editor policy (20 October 2026)

**Disposition: P01 ACTIVE, not fully certified.** This batch closes the independent-native summary swap and unversioned-policy fixtures, repairs a real enum-to-GUID identity difference, and adds an independent full-corpus custom-version differential. The remaining P01 branch is nonempty deprecated GUID-format (-3 to -5) custom-version serialization under Epic's reader; the installed corpus has no such entries.

## Pinned Epic source and actual differences

- Engine checkout: `L:/Source/Engine/UE4/UE 4.27.2`, commit `d94b38ae3446da52224bedd2568c078f828b4039`.
- `CoreUObject/Private/UObject/PackageFileSummary.cpp`: accepts normal/swapped package tags, toggles archive byte swapping, `CurrentLegacyFileVersion=-7`, enforces editor unversioned behavior, checks file-version bounds; `CoreUObject/Private/UObject/LinkerLoad.cpp` separately checks stock licensee version.
- `CoreUObject/Private/UObject/UObjectGlobals.cpp`: `GAllowUnversionedContentInEditor=0` by default.
- **U427-P01-D001 (corrected):** `Core/Private/Serialization/CustomVersion.cpp::FEnumCustomVersion_DEPRECATED::ToCustomVersion` converts a serialized uint32 enum tag into `FGuid(0,0,0,Tag)` with friendly name `EnumTag<Tag>`. UnrealDB previously retained only the raw signed integer tag and version, omitting Epic's canonical GUID identity. It now retains the original unsigned tag and supplies the Epic-derived GUID and friendly name. Before: 7 entries had missing canonical GUIDs across 7 assets; after: **2,271/2,271** native GUID/version identity matches.
- **U427-P01-D002 (corrected):** UE4 reader previously continued reading an unversioned summary and reported a generic issue. Stock UE4 Editor returns at the version preamble instead. The reader now rejects by default at that boundary; a deliberate `allow_unversioned_content_in_editor` option models Epic's explicitly permitted branch. It keeps serialized zeros distinct from effective parser versions and flags that runtime registered custom-version identities cannot be derived from the file. No production profile was opted in.

## Independent tests and reproduction

Native source: `catalog/compliance/ue427-native-oracle/Source/UE427Oracle/Private/UE427OracleCommandlet.cpp` built with the installed UE4.27 `RunUAT.bat BuildPlugin` (Win64). The new `-FixturesDir=...` mode uses Epic `FPackageFileSummary` and `FMemoryWriter` to generate 4,096-byte summary-only fixtures (`native-little.uasset`, `native-swapped.uasset`, `native-unversioned.uasset`). The first experimental build produced zero tags because the synthetic summary's tag was unset; the generator was corrected to explicitly set `PACKAGE_FILE_TAG`; only the corrected fixtures are acceptance evidence.

- Full Epic C++ summary differential: **1,749/1,749** installed assets; **29,733/29,733** summary fields; **0 mismatches**, reproducible via `php catalog/bin/verify-ue427-native-summary-differential.php C:/Temp/ue427-all-native-custom-stdout.log C:/Temp/ue427-all-assets.txt`.
- Full Epic C++ custom-version differential: **1,749/1,749** assets; **2,271/2,271** GUID/version entries; **0 mismatches** via `php catalog/bin/verify-ue427-native-custom-versions.php C:/Temp/ue427-all-native-custom-stdout.log C:/Temp/ue427-all-assets.txt`. Corpus markers: -7×478, -6×578, -5×93, -4×18, -3×504, -2×78. Entries: optimized 2,264; enum-normalized 7; deprecated GUID nonempty 0.
- Native fixtures: `php catalog/bin/verify-ue427-native-policy-fixtures.php` **45/45** assertions, three summary-only fixtures, zero failures. Epic Editor default rejects unversioned at byte 24; opt-in accepts it and reads a 200-byte summary with effective UE4 version 522. Native and UnrealDB both read the swapped summary with byte swapping enabled and version 522. The verifier uses `CatalogReaderResolver` to exercise production UE4 class selection.
- Full genuine reader corpus: `php catalog/bin/verify-ue427-real-reader-table-corpus.php` passed **1,749/1,749**, 108,582 names, 20,207 imports, 37,552 exports, 91,218 DependsMap references and 112 soft references.
- Existing licensee, corrupt-tag, file-version and truncated-summary negative tests still pass. The initial UE4 Editor-policy change exposed a UT4 v511 unversioned-regression in `verify-uedb5-ut4-persistence.php` (snapshot saw effective version 0). The production reader now explicitly preserves the separately pinned `ut4-alpha`/511 informational-unversioned branch; the UT4 persistence suite reruns successfully with zero failures. No production MySQL writes or Epic source edits.

DEV native outputs (not committed binaries): `C:/Temp/ue427-native-fixtures-*.log`, `C:/Temp/ue427-all-native-custom-stdout.log`, `C:/Temp/ue427-all-assets.txt`. Rebuildable oracle source and PHP verifiers are committed.

## Remaining P01 gate / exclusions

1. Generate a **nonempty** deprecated GUID-format summary (`LegacyFileVersion=-3`, `-4` or `-5`) from source-shaped wire fields, read it through the compiled Epic `FPackageFileSummary`, and differentially verify every GUID, version and friendly-name identity against UnrealDB. Real packages with those markers in this corpus have zero entries. Do not silently count empty arrays as proof.
2. No full-table swapped-endian package is claimed; P01's swapped acceptance is the native **summary** only. Name/import/export byte-order parity is part of P02–P03. No claim is made for editor runtime custom-version registration parity in optional unversioned mode; that state is not serialized.
3. P02–P10 and end-to-end production ingress/V5 workflows remain open in the fixed ten-item denominator. The Epic summary/custom comparison is not a UE4.27.2 certification certificate.
