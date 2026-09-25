# UPK / Unreal Engine 3 Package Format

## Scope and revision

This specification documents the source-backed **UPK physical package format** in the supplied UE3 source line. UPK is not a separate archive layered around a UE3 package: the inspected UE3 linker reads it through the ordinary Unreal package structures and serialization documented here.

Primary repository:

- Repository: `ardenee/UE3src`
- Branch: `main`
- Source tree: `Unreal Engine [v3.0] UDKUltimate [05-11-17]/UDKUltimate`
- Core source: `Development/Src/Core`

This tree is used as the latest UE3 source of truth. Earlier UE3 revisions in the same repository were independently compared, including the January 2008, March 2008, and May 2009 trees, so later summary additions are not projected backwards.

The supplied `ardenee/UT3src-comunity` repository was also inspected. It is primarily community/exported UT3 script/content and does not contain the native Core linker implementation needed to supersede the UE3 source for binary UPK parsing. It is therefore contextual UT3 evidence, not the binary-format authority.

UE4/UT4 are not used to redefine UPK. Their package/PAK formats are documented separately.

The latest package version in this tree is `VER_LATEST_ENGINE = 820` (the enum resolves through `VER_AUTOMATIC_VERSION_PLUS_ONE - 1`). Licensee version is 0. `GPackageFileMinVersion = 491`.

## Authoritative references

| Area | Source |
|---|---|
| package tag and versions | `Core/Inc/UnObjVer.h`, `Core/Src/UnObjVer.cpp` |
| archive semantics | `Core/Inc/UnArchive.h`, `Core/Src/UnArchive.cpp` |
| package structures | `Core/Inc/UnLinker.h` |
| summary/import/export/chunk serialization | `Core/Src/UnLinker.cpp` |
| FString | `Core/Src/UnMisc.cpp` |
| name entries | `Core/Src/UnName.cpp` |
| package loader | `Core/Src/UnLinker.cpp: ULinkerLoad` |

## Package magic and byte order

UE3 defines:

- `PACKAGE_FILE_TAG = 0x9E2A83C1`
- `PACKAGE_FILE_TAG_SWAPPED = 0xC1832A9E`

The summary accepts either tag.

When the swapped tag is encountered, the serializer normalizes `Sum.Tag` to `PACKAGE_FILE_TAG` and toggles archive byte swapping. Therefore byte-swapped UE3 packages are explicitly source-supported and must not be rejected solely because the first 32-bit value is the swapped tag.

## Version encoding

`FPackageFileSummary::FileVersion` is a 32-bit integer:

- low 16 bits: Epic engine package version;
- high 16 bits: licensee version.

`SetFileVersions(Epic, Licensee)` always stores:

`(Licensee << 16) | Epic`

Unlike the earlier UE2-family source reviewed for this project, there is no LicenseeMode branch in this function.

Current source globals:

- `GPackageFileVersion = VER_LATEST_ENGINE = 820`
- `GPackageFileLicenseeVersion = 0`
- `GPackageFileMinVersion = 491`
- `GPackageFileCookedContentVersion = VER_LATEST_COOKED_PACKAGE | (VER_LATEST_COOKED_PACKAGE_LICENSEE << 16)`
- latest cooked package version = 129, licensee = 0.

## FString serialization

FString serializes a signed 32-bit `INT SaveNum`; it is not a UE1/UE2 compact index.

On save:

- Unicode is represented by a negative count;
- pure ANSI is represented by a positive count unless the archive forces Unicode.

On load:

- positive/nonnegative count -> ANSI bytes;
- negative count -> Unreal Unicode code units through `appSerializeUnicodeString`;
- count magnitude determines allocation;
- a one-element loaded string normalizes to empty.

`GetMaxSerializeSize()` is checked as a runtime/network allocation safety guard. It is not an unconditional package-format maximum and must not become an arbitrary UnrealDB package/string limit.

## Name table entries

`FNameEntry` loading reads a signed 32-bit StringLen.

- StringLen < 0: Unicode, read `-StringLen` Unicode code units.
- StringLen >= 0: ANSI, read StringLen bytes directly.

The entry then serializes name flags when `SUPPORT_NAME_FLAGS` is enabled; otherwise a dummy EObjectFlags value is still serialized.

On save, the source obtains the entry as FString and uses FString serialization.

The loader constructs NameMap entries without re-splitting numbered names.

## Serialized FName references

`ULinkerLoad::operator<<(FName&)` serializes:

1. `NAME_INDEX NameIndex`
2. `INT Number`

NameIndex is bounds checked against NameMap.

If the mapped name is NAME_None, the serialized Number is still consumed and the resulting name is NAME_None. Otherwise the runtime FName combines the mapped name index with the serialized instance number.

This is a major format change from the compact name-reference encoding in the earlier UE1/UE2 package readers.

## Object references and package indices

`ULinkerLoad::operator<<(UObject*&)` reads a fixed `INT Index`.

Normal resolution passes that index to `IndexToObject`.

UE3 therefore does not use the UE1/UE2 compact object-reference encoding here.

A special cross-level reference form may be recognized when a property has been marked as a potential cross-level reference. Its 32-bit value has high-byte magic `0xF0`, with the remaining fields interpreted as level and GUID indices. This behavior is context-sensitive and must not be applied to every ordinary package index.

## UPK identity and extension semantics

The source-backed binary identity is the UE3 package summary/tag, not the filename extension alone. Files commonly named `.upk` are parsed by the UE3 package linker; there is no separate UPK wrapper header established by the inspected Core source.

Consequently UnrealDB should classify a `.upk` as UE3 UPK only after validating the UE3 package structure. It must not treat `.upk` as an archive comparable to UMOD/UT2MOD/UT4MOD or UE4 PAK.

## Summary serialized layout

For a valid normal or swapped package tag, `FPackageFileSummary` serializes in this order:

1. Tag
2. FileVersion
3. TotalHeaderSize
4. FolderName
5. PackageFlags
6. NameCount
7. NameOffset
8. ExportCount
9. ExportOffset
10. ImportCount
11. ImportOffset
12. DependsOffset

If `GetFileVersion() >= VER_ADDED_CROSSLEVEL_REFERENCES`:

13. ImportExportGuidsOffset
14. ImportGuidsCount
15. ExportGuidsCount

Otherwise ImportExportGuidsOffset is set to INDEX_NONE.

If `GetFileVersion() >= VER_ASSET_THUMBNAILS_IN_PACKAGES`:

16. ThumbnailTableOffset

Then:

17. Guid
18. GenerationCount
19. Generation entries
20. EngineVersion
21. CookedContentVersion
22. CompressionFlags
23. CompressedChunks
24. PackageSource

If `GetFileVersion() >= VER_ADDITIONAL_COOK_PACKAGE_SUMMARY`:

25. AdditionalPackagesToCook

If `GetFileVersion() >= VER_TEXTURE_PREALLOCATION`:

26. TextureAllocations

No earlier UE1/UE2 summary layout should be substituted for this structure.

## Generation entries

Each `FGenerationInfo::Serialize` writes:

1. ExportCount
2. NameCount
3. NetObjectCount

All are serialized explicitly by this UE3 source.

## Import table

`FObjectImport` serialized order:

1. ClassPackage — FName
2. ClassName — FName
3. OuterIndex — package index
4. ObjectName — FName

On load runtime fields are reset:

- SourceLinker = NULL
- SourceIndex = INDEX_NONE
- XObject = NULL

UE3 calls this field `OuterIndex`; do not model it as the older UE2 `PackageIndex` merely because its signed-index role is related.

## Export table

`FObjectExport` serialized order in this source:

1. ClassIndex
2. SuperIndex
3. OuterIndex
4. ObjectName
5. ArchetypeIndex
6. ObjectFlags
7. SerialSize
8. SerialOffset
9. legacy ComponentMap only when `Ar.Ver() < VER_REMOVED_COMPONENT_MAP`
10. ExportFlags
11. GenerationNetObjectCount
12. PackageGuid
13. PackageFlags

These integer indices and serial offsets/sizes are ordinary UE3 archive integer serialization, not UE1/UE2 compact indices.

## Legacy component map

When package version is older than `VER_REMOVED_COMPONENT_MAP`, the export serializer consumes a legacy `TMap<FName,INT>`.

At and after that version the field is absent.

UnrealDB must gate it on the package version exactly as source does.

## Name/import/export loading

The async-capable linker initializes in discrete stages.

For the table stages:

- NameMap seeks to NameOffset; when TotalHeaderSize is available it precaches from NameOffset through the remaining header.
- ImportMap seeks to ImportOffset and reads ImportCount FObjectImport entries.
- ExportMap seeks to ExportOffset and reads ExportCount FObjectExport entries.

TotalHeaderSize is therefore part of the loader's header/precache contract, not an arbitrary validation limit.

## Source-defined import fixups before resolution

After deserializing imports, `FixupImportMap` performs explicit compatibility remapping.

The supplied latest UE3 source includes at least:

### SoundCueLocalized

- class object `SoundCueLocalized` under Engine is remapped to `SoundCue`;
- references whose ClassName is SoundCueLocalized and ClassPackage is Engine have ClassName changed to SoundCue.

### SequenceObjects

- package import ObjectName SequenceObjects / ClassName Package is changed to Engine;
- ClassPackage SequenceObjects is changed to Engine.

These are hardcoded source compatibility transformations and must be kept revision-specific.

They are not evidence for a generic configurable ClassRemap mechanism.

Additional class remapping for old prefab sequence content is performed by `RemapClasses()` for versions older than `VER_FIXED_PREFAB_SEQUENCES`. This logic may add imports and alter export ClassIndex values. It must not be reduced to an arbitrary name alias.

Detailed dependency implications belong in the separate UE3 dependency-resolution specification.

## Path and outer traversal

UE3 uses `OuterIndex`.

`ROOTPACKAGE_INDEX` terminates a path.

GetImportPathName can traverse imports and, for cooked seek-free content, exports in an import outer chain.

GetExportPathName follows export OuterIndex values and understands forced exports.

The source also distinguishes subobject notation from ordinary dot-separated package/object paths.

UnrealDB must preserve the signed resource graph instead of assuming every import outer is another import.

## Cross-version summary evolution

The earlier UE3 revisions were compared against the latest supplied tree.

January 2008 and March 2008 serialize the core summary through `DependsOffset`, then Guid/generations, EngineVersion, CookedContentVersion, CompressionFlags, CompressedChunks, PackageSource, and version-gated AdditionalPackagesToCook.

By May 2009, `ThumbnailTableOffset` is additionally serialized when `FileVersion >= VER_ASSET_THUMBNAILS_IN_PACKAGES`.

The latest supplied UDKUltimate tree additionally serializes the cross-level import/export GUID offset and counts when `FileVersion >= VER_ADDED_CROSSLEVEL_REFERENCES`, retains the thumbnail gate, and adds `TextureAllocations` when `FileVersion >= VER_TEXTURE_PREALLOCATION`.

These are real version/revision branches. UnrealDB must gate them by the source-defined package version rather than assuming the latest header shape for every UE3 UPK.

## Package compression

Package compression is explicitly part of UE3.

Summary serializes:

- CompressionFlags
- array of FCompressedChunk

Each FCompressedChunk contains:

1. UncompressedOffset
2. UncompressedSize
3. CompressedOffset
4. CompressedSize

When `PKG_StoreCompressed` is set, loader requires compressed chunks and calls `SetCompressionMap` with the summary chunks and CompressionFlags.

If the current loader cannot support the package compression map, source replaces it with `FArchiveAsync`, preserves current position and byte-swapping state, and installs the compression map there.

Therefore package compression is not an external wrapper assumption; it is a source-defined part of UE3 package loading.

The actual codec selected by each compression flag must be documented from compression-dispatch source separately rather than guessed here.

## Encryption

The reviewed package summary and normal ULinkerLoad package path establish compression but do not, by themselves, establish a generic package encryption descriptor or encryption dispatch.

No encryption scheme should be invented from the presence of compression.

Any game/platform-specific encryption requires its own source proof.

## Validation

After summary serialization the loader:

- propagates package cooked state;
- sets loader and linker Epic/licensee versions;
- configures package compression when PKG_StoreCompressed is set;
- propagates package flags/folder;
- checks the normalized package tag;
- rejects package versions below GPackageFileMinVersion;
- rejects Epic versions above GPackageFileVersion;
- rejects licensee versions above GPackageFileLicenseeVersion;
- on relevant console builds validates CookedContentVersion;
- sizes import/export/name maps from the summary.

Unlike older UE2 source which prompted for sufficiently old versions, this UE3 implementation throws for versions below its minimum.

## Serialized payload boundaries

Preload seeks to an export's SerialOffset, serializes the object, and checks:

`Tell() - Export.SerialOffset == Export.SerialSize`

Mismatch is fatal via `appErrorf`.

This remains a strict source-defined payload boundary.

## CreateExport behavior

UE3 CreateExport is substantially more complex than UE2 and must not be replaced by the earlier algorithm.

Relevant source behavior includes:

- context-flag filtering;
- editor handling for forced exports;
- class resolution through ClassIndex;
- if class resolution fails and ClassIndex is not `UCLASS_INDEX`, return NULL;
- only the UCLASS_INDEX case falls back to UClass::StaticClass();
- RF_Native validation;
- class preload/link behavior;
- OuterIndex resolution;
- special creation of forced-export root packages;
- unloadable marking when the outer cannot be loaded;
- ArchetypeIndex handling, including self-reference rejection;
- verification of imported archetypes in applicable uncooked/editor paths;
- fallback to class default object when archetype cannot be loaded;
- forced-export/cooked/async in-memory reconciliation behavior.

UnrealDB's metadata reader does not need to instantiate UObjects, but any structural validation must respect these serialized indices and not impose UE2 CreateExport assumptions.

## ArchetypeIndex

ArchetypeIndex is serialized for every export in this source.

It is a package object index with special `CLASSDEFAULTS_INDEX` semantics.

It can reference imports or exports and therefore participates in the serialized object graph even though it is not equivalent to a package dependency root by itself.

## Forced exports

UE3 adds `ExportFlags`, including `EF_ForcedExport`.

Forced exports alter path resolution and runtime loading: objects may retain an original package path even while serialized in another cooked package.

UnrealDB must preserve ExportFlags and avoid treating every export's physical containing file as necessarily its logical original package identity.

## Cross-level reference metadata

At versions supporting cross-level references the summary contains offsets/counts for import/export GUID data.

The runtime can resolve specially encoded cross-level object references through level/GUID tables and can defer fixups when the target level is not loaded.

These structures are part of UE3's reference model and should be retained by a complete reader. Detailed dependency treatment belongs in the dependency spec.

## Runtime/config-only behavior

Do not infer from package bytes:

- package search paths/cache contents;
- editor/game/server state;
- loaded native classes and objects;
- forced-export source package availability;
- async-loader state;
- external script patch data;
- platform-specific cooked-content policy.

## Major differences from the supplied UE2-family sources

UE3 source proves several structural changes:

- accepts normal and byte-swapped package tags;
- fixed 32-bit FString count rather than compact count;
- FName references contain name index plus instance number;
- object references use fixed 32-bit indices;
- summary has TotalHeaderSize, FolderName, DependsOffset, GUID-table metadata, thumbnail offset, engine/cooked versions, compression map, package source, additional cook packages and texture allocations;
- export records add ArchetypeIndex, ExportFlags, GenerationNetObjectCount, PackageGuid and PackageFlags;
- explicit DependsMap exists;
- compressed packages are directly supported;
- import fixups/remapping are part of linker initialization;
- cooked seek-free outer graphs can mix import and export indices;
- forced exports and cross-level references alter reference semantics.

A UE3 reader must therefore not be implemented as “UE2 with a newer version number.”

## UnrealDB conformance requirements

1. recognize both normal and swapped UE3 tags and reproduce byte-swap behavior;
2. preserve raw Epic/licensee versions and use the source version gates;
3. reject unsupported old/new versions only according to the target source policy;
4. use fixed UE3 integer serialization where source does; do not apply UE1/UE2 compact indices;
5. implement FString signed 32-bit count semantics;
6. implement exact FNameEntry ANSI/Unicode serialization;
7. preserve FName instance Number;
8. implement the exact summary field order and conditional fields;
9. preserve import OuterIndex;
10. preserve all export indices/flags including ArchetypeIndex and ExportFlags;
11. consume legacy ComponentMap only for source-gated versions;
12. parse compression flags and compressed chunk descriptors;
13. map compressed logical offsets through the source-defined compression mechanism;
14. preserve DependsOffset/DependsMap;
15. preserve cross-level GUID metadata;
16. preserve forced-export metadata;
17. apply only source-proven version-specific import/class fixups;
18. enforce SerialOffset/SerialSize payload boundaries;
19. do not invent package encryption;
20. keep runtime-only behavior separate from byte-level metadata.

## Source-reference matrix

| Rule | Proving source/symbol |
|---|---|
| magic/swapped magic | `Core/Inc/UnObjVer.h`, `FPackageFileSummary operator<<` |
| current/min versions | `Core/Inc/UnObjVer.h`, `Core/Src/UnObjVer.cpp` |
| summary | `Core/Src/UnLinker.cpp: operator<<(FPackageFileSummary&)` |
| generation info | `FGenerationInfo::Serialize` |
| compressed chunks | `operator<<(FCompressedChunk&)`, linker summary setup |
| import record | `operator<<(FObjectImport&)` |
| export record | `operator<<(FObjectExport&)` |
| names | `Core/Src/UnName.cpp: operator<<(FNameEntry&)` |
| FString | `Core/Src/UnMisc.cpp: operator<<(FString&)` |
| FName refs | `ULinkerLoad::operator<<(FName&)` |
| UObject refs | `ULinkerLoad::operator<<(UObject*&)` |
| table loading | `SerializeNameMap`, `SerializeImportMap`, `SerializeExportMap` |
| import fixups | `FixupImportMap`, `RemapClasses` |
| paths/outers | `GetImportPathName`, `GetExportPathName` |
| validation/compression | `SerializePackageFileSummary` |
| payload boundary | `ULinkerLoad::Preload` |
| export creation | `ULinkerLoad::CreateExport` |
| cross-level refs | `ULinkerLoad::operator<<(UObject*&)`, `ResolveCrossLevelReference` |

## UPK-specific result

The source review establishes that UPK is the ordinary UE3 Unreal package format in this engine line. Its binary structure is defined by `FPackageFileSummary`, the name/import/export/dependency tables, export payload ranges, and the version-gated structures above.

Compression is embedded in the UE3 package summary/chunk mechanism and will be specified separately in **UPK compression handling**. No generic UPK encryption descriptor is established by this package reader; encryption remains a separate source-verification item.

## Next specification

The next target is **UPK compression handling**, deriving codec dispatch and compressed-chunk framing from the applicable UE3 revisions rather than guessing from CompressionFlags.
