# Unreal Tournament 3 / early UE3 package format and pre-dependency preprocessing

## Scope

This specification is the package-serialization and pre-dependency authority for the active Unreal Tournament 3 catalogue profile.

Primary source:

- `L:\Source\Engine\UE3\Unreal Engine [v3.0] [01-00-2008]\epic.jan2008\UnrealEngine3` — v512 engine Core loader, plus `Development\Src\UTGame` (838 UnrealScript classes, 32 native C++ sources and 10 headers) and `Development\Src\UTGameContent\Classes` (276 game-content classes).
- Active UT3 package profile: Epic package version 512, licensee version 0.
- Installed retail package class-identity cross-check: `UTGame.u`, `UTGameContent.u`, `UT3Gold.u`, and `UT3GoldGame.u` against `L:\Source\Engine\UE3\Unreal Engine [v3.0] UDKUltimate [05-11-17]\UDKUltimate\Development\Src\UT3ScriptSource_1.5\Development\Src`; all 1,218 original exported class names match that 1.5 script-source inventory. This is class-name evidence, **not** proof of bytecode/source implementation equality or official provenance.
- Source-version inventory and exact installed-file evidence: [UT3 all-source provenance and class parity](../tests/evidence/ue3/ut3-all-source-revision-provenance-and-installed-class-parity-20261105.json).
- `VER_FULL_VERSION_OF_UT3_BUMP = 512`
- `GPackageFileMinVersion = 491`
- the checkout continues through `VER_LATEST_ENGINE = 530`

The wider 491-530 engine load range is not treated as permission to apply UT3 game-profile dependency semantics to versions other than 512. The active UnrealDB UT3 profile remains exactly v512/licensee 0.

The March-2008 UTGame UnrealScript tree matches the January-2008 tree byte-for-byte for all 838 class files. The July-2013 engine source is authoritative for its later UE3 revision, **not** automatically for original retail UT3 v512; its UTGame class inventory matches only 358 of 908 classes in the installed `UTGame.u`. The independently located `UT3ScriptSource_1.5` game scripts provide a stronger **game-class-name** inventory, but do not replace the January-2008 Core package serializer or authorize back-porting later loader rules.

## Source references

| Area | Jan-2008 source |
|---|---|
| package/version constants | `Development/Src/Core/Inc/UnObjVer.h`, `Src/UnObjVer.cpp` |
| object/load flags | `Development/Src/Core/Inc/UnObjBas.h` |
| summary/import/export serializers | `Development/Src/Core/Src/UnLinker.cpp` |
| FName entry serializer | `Development/Src/Core/Src/UnName.cpp` |
| FName representation | `Development/Src/Core/Inc/UnName.h` |
| table load/fixup order | `ULinkerLoad::Tick`, `SerializeNameMap`, `SerializeImportMap`, `SerializeExportMap`, `FixupImportMap` |
| FName references | `ULinkerLoad::operator<<(FName&)` |

## Package tag and byte order

The normal package tag is `0x9E2A83C1`; the swapped tag is `0xC1832A9E`.

The summary serializer accepts either. When the swapped tag is found it normalizes the in-memory tag and toggles archive byte swapping before reading the remaining summary.

A nonmatching tag does not authorize reading the remainder as a UE3 summary.

## Package summary for v512

For the active v512 profile, the summary serializes:

1. Tag
2. FileVersion
3. TotalHeaderSize
4. FolderName
5. PackageFlags
6. NameCount / NameOffset
7. ExportCount / ExportOffset
8. ImportCount / ImportOffset
9. DependsOffset
10. Guid
11. GenerationCount and generation rows
12. EngineVersion
13. CookedContentVersion
14. CompressionFlags
15. CompressedChunks
16. PackageSource

Each generation contains ExportCount, NameCount, and NetObjectCount.

`AdditionalPackagesToCook` is gated by `VER_ADDITIONAL_COOK_PACKAGE_SUMMARY = 516`, so it is absent from v512. Later cross-level GUID, thumbnail, texture-allocation and other summary fields likewise must not be invented for the v512 profile.

Compressed chunks contain four INT fields:

- UncompressedOffset
- UncompressedSize
- CompressedOffset
- CompressedSize

## Name entries

Jan-2008 UE3 defines:

- `NAME_SIZE = 128`
- `EObjectFlags` as a QWORD

On load, `FNameEntry::operator<<`:

1. reads an FString;
2. copies at most `NAME_SIZE - 1 = 127` characters into the runtime name buffer;
3. reads 64-bit NameEntry flags.

UnrealDB preserves the full serialized FString in raw metadata. The 127-character limit applies to the effective runtime-style FName used for catalogue identity, not to the stored source evidence.

## UE3 load-context bits

The Jan-2008 source defines:

- `RF_LoadForClient = 0x0001000000000000`
- `RF_LoadForServer = 0x0002000000000000`
- `RF_LoadForEdit   = 0x0004000000000000`

Therefore the all-context mask is:

`0x0007000000000000`

This is deliberately different from UE1/UE2's low-bit `0x00070000`.

The source Commandlet defaults enable IsClient, IsServer and IsEditor together. UnrealDB uses that explicit all-context source configuration for deterministic UT3 catalogue preprocessing.

## SerializeNameMap

`SerializeNameMap` runs before import/export verification.

For each serialized FNameEntry the Jan-2008 linker:

1. reads the entry;
2. truncates its runtime base name to 127 characters through FNameEntry loading;
3. tests its 64-bit flags against `_ContextFlags`;
4. inserts the base FName when relevant;
5. otherwise inserts `NAME_None`.

The loader explicitly avoids re-splitting numbered names at this point.

Raw name text and flags must therefore remain available separately from the effective NameMap.

## FName references and instance numbers

A serialized FName reference is:

1. NameIndex — INT
2. Number — INT

After reading NameIndex the linker validates it against NameMap.

If the effective NameMap entry is `NAME_None`, the Number is still consumed from the file but the resulting FName is `NAME_None`.

Otherwise the resulting FName combines the effective base name with the serialized internal Number. UE3 uses the internal-number convention where a nonzero internal number `N` renders with external suffix `_(N-1)`.

Consequences for UnrealDB:

- the source NameIndex must be retained;
- the source Number must be retained;
- filtering to NAME_None overrides the Number;
- base-name truncation occurs before number rendering.

## Import table

Each FObjectImport serializes:

1. ClassPackage — FName
2. ClassName — FName
3. OuterIndex — PACKAGE_INDEX / INT
4. ObjectName — FName

Runtime SourceLinker, SourceIndex and XObject are reset/derived state rather than serialized identity.

## Export table

The Jan-2008 FObjectExport serializer writes, in order:

1. ClassIndex
2. SuperIndex
3. OuterIndex
4. ObjectName
5. ArchetypeIndex
6. ObjectFlags — 64-bit EObjectFlags
7. SerialSize
8. SerialOffset
9. ComponentMap
10. ExportFlags
11. GenerationNetObjectCount
12. PackageGuid
13. PackageFlags

At v512 the ComponentMap is still serialized; the later removal gate is version 543.

UT3 `RF_Public` is a high 64-bit flag. Truncating ObjectFlags to 32 bits changes dependency visibility semantics and is invalid.

## Pre-dependency load order

The relevant Jan-2008 loader sequence is:

1. serialize package summary;
2. SerializeNameMap;
3. SerializeImportMap;
4. SerializeExportMap;
5. FixupImportMap;
6. continue loader setup / import verification.

For catalogue parity, transformations must occur in that order. FixupImportMap must consume effective FNames, not raw serialized strings.

## FixupImportMap

The Jan-2008 source performs these deterministic compatibility rewrites:

### SoundCueLocalized class object

When:

- Import.ObjectName == SoundCueLocalized
- Import.ClassName == Class
- Import.OuterIndex addresses an Import
- that outer Import.ObjectName == Engine

then:

- Import.ObjectName becomes SoundCue.

### SoundCueLocalized class references

When:

- Import.ClassName == SoundCueLocalized
- Import.ClassPackage == Engine

then:

- Import.ClassName becomes SoundCue.

### SequenceObjects package migration

When:

- Import.ObjectName == SequenceObjects
- Import.ClassName == Package

then:

- Import.ObjectName becomes Engine.

When:

- Import.ClassPackage == SequenceObjects

then:

- Import.ClassPackage becomes Engine.

These comparisons operate on FName identity after NameMap preprocessing. A source name filtered to `NAME_None` must not subsequently trigger a textual SequenceObjects or SoundCueLocalized remap.

## No later RemapClasses pass in the UT3 source

The Jan-2008 linker does not contain the later standalone `RemapClasses` step used by later UE3 revisions.

In particular, the later pre-`VER_FIXED_PREFAB_SEQUENCES` prefab Sequence class rewrite must not be applied to UT3 v512 merely because the package version is below 536.

The owning source/profile selects that behavior, not the version number alone.

## Supplemental comparison: UE3 build 10897

Local later authority:

`L:\Source\Engine\UE3\Unreal Engine 3 (10897)`

This is the CodeRedModding build-10897 checkout previously pinned to commit `601d6a1f50a0a4a67e3ee0c352333783408d1ba7`.

The later source proves material differences and therefore must not be substituted for UT3:

- `NAME_SIZE` is 1024 rather than 128;
- later NameMap loading explicitly disregards name context flags in final-release behavior;
- FixupImportMap is substantially expanded;
- the loader adds a separate `RemapClasses` step after FixupImportMap;
- later source includes the pre-`VER_FIXED_PREFAB_SEQUENCES` prefab compatibility rewrite.

These are later-UE3 rules, not missing pieces of the Jan-2008 UT3 contract.

## UnrealDB implementation contract

For the active UT3 v512/licensee-0 profile UnrealDB must:

1. preserve the raw package summary and byte-swapped-tag state;
2. preserve raw Name text and the full 64-bit Name flags;
3. preserve FName NameIndex and Number;
4. use the UE3 high-bit all-context mask `0x0007000000000000`;
5. cap the effective base name at 127 characters;
6. map context-irrelevant names to NAME_None before rendering FName numbers;
7. reconstruct numbered FNames using the serialized internal Number;
8. apply FixupImportMap only after effective NameMap construction;
9. preserve the full 64-bit export ObjectFlags;
10. retain v512 ComponentMap and the remaining source export fields;
11. avoid later RemapClasses/prefab rewrites;
12. keep package 513+, nonzero licensee versions and other UE3 game profiles outside the active UT3 VerifyImport policy.

## Migration impact

The corrected NameMap behavior is derivable entirely from staged UEDB5 metadata because the v5 snapshot already retains:

- raw Name text;
- 64-bit Name flags;
- FName indices;
- FName numbers;
- Import/Export tables.

No original UT3 package reread is required for this correction.

Use:

`D:\php8.5\php.exe catalog\bin\diagnose-ut3-name-map-impact.php --summary`

for a bounded count against the real staging DB, then rerun without `--summary` for exact file IDs.

Only active v512/licensee-0 staged rows are considered by this diagnostic.
