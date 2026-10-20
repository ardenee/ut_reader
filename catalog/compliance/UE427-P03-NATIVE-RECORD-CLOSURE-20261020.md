# UE4.27.2 P03 — Epic-native import/export record and extent closure

**Disposition: U427-P03 VERIFIED**, with fixed denominator **3/10** primary operations. This is source-defined import/export **wire-format and reader** acceptance, not UE4.27.2 application-wide certification. P04–P10, including import-provider verification and V5 durability, remain open.

## Authority and actual differences

Pinned Epic checkout: `L:/Source/Engine/UE4/UE 4.27.2` at `d94b38ae3446da52224bedd2568c078f828b4039`.
- `CoreUObject/Private/UObject/ObjectResource.cpp::operator<<(FStructuredArchive::FSlot,FObjectImport&)`: four required fields with FName (int32 name-table index and int32 instance number), signed FPackageIndex, and the fifth `PackageName` FName only for `WITH_EDITORONLY_DATA`, UE4 version >=520 and `!Ar.IsFilterEditorOnly()`.
- `ObjectResource.cpp::operator<<(FStructuredArchive::FSlot,FObjectExport&)`: source-gated `TemplateIndex`, 32/64-bit `SerialSize/SerialOffset`, booleans, GUID, PackageFlags and five preload dependency fields. On load, `E.ObjectFlags = EObjectFlags(Save & RF_Load)`, rather than the unmasked serialized u32.
- `CoreUObject/Private/UObject/LinkerLoad.cpp::{SerializeImportMap,SerializeExportMap}`: seeks to declared offsets and deserializes each source record into the linker.
- `ObjectMacros.h`: source-computed `RF_Load=0x02D4003B`. The separate UT4 clean-master `ObjectResource.cpp` applies the same mask, checked directly before updating its fixture expectations.

**U427-P03-D001 (corrected):** `UE4/UnrealPackageReader.php` previously exposed the serialized u32 verbatim as effective `objectFlags`; Epic instead masks all non-`RF_Load` bits during native export loading. An isolated mutation of the first export of genuine UE4 v520 `EditorBlueprintResources/ActorMacros.uasset` ORs the excluded `RF_MarkAsNative=0x4` into a previously valid serialized value 9, producing wire 13. Epic returns effective **9**, UnrealDB originally returned **13** (one proven native mismatch). Fixed source reader exposes `objectFlags=9` and retains `serializedObjectFlags=13` as byte-level evidence. After the fix, the mutated asset passes **1,135/1,135** Epic C++ import/export comparisons. The UT4 v510 snapshot fixture originally assumed raw 0x4 would remain loaded; corrected it, after reading pinned UT4 `ObjectResource.cpp`, to expect effective 0 and serialized 4. UT4 metadata persistence suite now passes.

**Documentation correction:** the older UE4 specification attributed future optional import fields and a special filtered `PackageName` identity hack to stock 4.27.2 without matching this pinned `ObjectResource.cpp`. Its source-defined branches have now been corrected in `specs/ue4-4.27.2-package-format.md`.

## Independent Epic-native differential

`catalog/compliance/ue427-native-oracle/Source/UE427Oracle/Private/UE427OracleCommandlet.cpp` now uses a small `FArchiveProxy` to provide the same FName name-map resolution as `FLinkerLoad`. Crucially, the **entire import/export record is read through Epic's compiled `FObjectImport` and `FObjectExport` serialization operators**; layout and branch decisions are not duplicated in PHP. All row offsets, effective object flags, signed package indices, name-index/instance pairs, serialized-size widths and optional fields are logged with per-file/row ordinals. The archive proxy's copied `ArIsFilterEditorOnly` state is explicitly initialized because `FArchiveProxy::SetFilterEditorOnly` forwards to its inner archive without setting the proxy state.

- Full corpus: **1,749/1,749 genuine installed UE4.27 packages**; 20,207 imports, 37,552 exports; **1,074,766 field comparisons**, zero mismatches or native errors. Corpus versions 216–522. Export sizes observed: 64 bytes × 652, 68 × 2,721, 72 × 23,999, 96 × 956, 104 × 9,224. One genuine asset has no name entries or object records; the oracle explicitly records that zero-count state, so there are 1,749/1,749 count rows.
- `PackageName` version >=520 (unfiltered) appears in **61 genuine assets** with imports; all agree byte-for-byte in record layout and indexes.
- A separate Epic `FPackageFileSummary`/`FMemoryWriter` generated 4,096-byte filtered summary has `PKG_FilterEditorOnly`, version 522, a source-shaped one-name table and one import. Epic's actual import operator reads a **28-byte record** omitting PackageName, exactly matching UnrealDB (**14/14** compared fields, no mismatches). This fixture is a controlled synthetic wire case, not a shipped real package.
- Six negative mutations of a copy of genuine `Animation/DefaultAnimBoneCompressionSettings.uasset` (zero/out-of-file import offset, zero/out-of-file export offset, negative import/export count) were already rejected by the **pre-existing** summary validation in UnrealDB. Redundant extra validation briefly introduced in the reader was reverted before commit; no invented P03 difference is claimed for these cases.
- Production shared UE4 reader and UT4 v511 UEDB5 snapshot/writer roundtrip regression pass. Canonical Epic source and original installed genuine assets unchanged; temporary native oracle binaries/logs and fixtures remain on DEV. No PRD database writes.

## Reproduce

Epic native BuildPlugin via installed `H:/Epic Games/UE_4.27/Engine/Build/BatchFiles/RunUAT.bat`, resulting commandlet on disposable `C:/Temp/UE427Oracle/UE427Oracle.uproject` with `-run=UE427Oracle -Names -Records -List=...`.

- Full native log `C:/Temp/ue427-p03-full-native-final.log`, corpus manifest `C:/Temp/ue427-all-assets.txt`: `php catalog/bin/verify-ue427-native-records.php C:/Temp/ue427-p03-full-native-final.log C:/Temp/ue427-all-assets.txt`.
- Filtered positive: `C:/Temp/ue427-p03-filtered-native-final.log`, `C:/Temp/ue427-p03-filtered-list.txt`, generated through the native commandlet's `-FixturesDir=C:/Temp/ue427-native-fixtures`.
- Flag mask: `php catalog/bin/generate-ue427-export-flag-fixture.php`; native output `C:/Temp/ue427-p03-flag-native-before.log`, manifest `C:/Temp/ue427-p03-exportflags/list.txt`; the same `verify-ue427-native-records.php` now passes 1,135 comparisons for the mutation.
- Negative table extents: `php catalog/bin/verify-ue427-record-offset-negatives.php` (six validations).
- Compatibility: `php catalog/bin/verify-ue427-real-reader-table-corpus.php`; `php catalog/bin/verify-uedb5-ut4-persistence.php`.

## Explicit nonclaims

P03 does **not** certify actual object payload decoding or UEXP/UBULK storage (P05), DependsMap/soft references (P04), loader `VerifyImportInner` and redirect resolution (P07), active ingress/profile dispatch (P08), V5 persistence/backup/repair (P09), or full malformed-source/negative coverage (P10). Engine-supplied runtime `ThisIndex` and filter decisions remain distinct from the raw record bytes.
