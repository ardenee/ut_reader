# UE4.27.2: Epic source-only package loader and format reference

Source: pinned original Epic C++ only. No UnrealDB result or PHP code is
used to generate this reference. The original UE4.27.2 source remains
authoritative when reading any abbreviated table or diagram.

**Revision:** `d94b38ae3446da52224bedd2568c078f828b4039`
**Source checkout:** `L:/Source/Engine/UE4/UE 4.27.2`
**Completion:** **INCOMPLETE** — Original Epic C++ classic package linker initialization, summary serialization, FObjectImport/FObjectExport and table loading. Not a full engine behavior or object payload trace. Also includes Epic engine-version, package-flag C++ callees and legacy rejection behavior.

## Source integrity

| Original Epic file | Source SHA-256 |
|---|---|
| `LinkerLoad.cpp` | `39DB1F3E1A48D829E65991259EAEFB955680CCF6165D5E31B9DB862B02560569` |
| `PackageFileSummary.cpp` | `8FF60DD29D5A0E50EB1222DA0D9C5DF71E088A1F2641E8512E6F6CE05CBA4146` |
| `ObjectResource.cpp` | `5FB996D0AAFB7DC9B2615484976A487E97E1188B42E48229FDBA024BC5BC4DD5` |
| `ObjectVersion.h` | `378C49E3AF3542C87C95D7259B81FCB77A98077D438CCCFA227B2F90870A000A` |
| `SavePackage.cpp` | `F36086C193B2496982CC283AED14849695F16D5D68BB062FE3BADD00D1093202` |
| `SavePackage2.cpp` | `FCAD38167A8612068F1300419447A9BD379253057E35F0AD2E08AF82609B4200` |
| `EngineVersion.cpp` | `BCFCE393AF77F09527EF2C7BA4EA9E6550A07E7C037265FD8DD87A4E64D81DFB` |
| `EngineVersionBase.h` | `13FA34A8F2D6D5C01AC492E998AF8D25543B7ED159296CD08BCD1EB29A6DFB4C` |
| `ObjectMacros.h` | `2BCF3EB5BA3013602FB5C02325DCFEF9B427779D3391A32D0F90D5D7A12FE20F` |
| `Linker.h` | `040C05FF670E730CDC5A4CE83A1F2985A88F7ABC1E72C369FC5FC6A75CE5E2B3` |
| `ObjectResource.h` | `E5ACE0B0A756ED4E1E0F79427F5B48F9F7086779CA4B1E7DD0E4124F2A038B57` |

## How to read these views

The diagrams show **the original source statement order** within a named
function, not an assertion that every stage always executes. To determine
a branch outcome, follow the separately listed **exact C++ conditions**
and the real source at the cited line; do not interpolate skipped branches.
No inferred numeric enum values or PHP assumptions appear here.

The field tables are **serialization sites**, not fixed offsets or fixed-size
records. Format, byte width and presence depend on Epic's archive and
version/compiler conditions. In particular, the header and Import/Export
fields must not be read as unconditional sequential fixed-width columns.

## Version symbols — original Epic declarations

No derived or manually typed numeric enum values. Look up these
definitions in the pinned Epic header, including explicit assignments
and applicable preprocessor conditions.

| Used C++ symbol | Original Epic declaration location |
|---|---|
| `VER_UE4_64BIT_EXPORTMAP_SERIALSIZES` | `ObjectVersion.h:621` |
| `VER_UE4_ADDED_CHUNKID_TO_ASSETDATA_AND_UPACKAGE` | `ObjectVersion.h:149` |
| `VER_UE4_ADDED_PACKAGE_OWNER` | `ObjectVersion.h:635` |
| `VER_UE4_ADDED_PACKAGE_SUMMARY_LOCALIZATION_ID` | `ObjectVersion.h:631` |
| `VER_UE4_ADDED_SEARCHABLE_NAMES` | `ObjectVersion.h:619` |
| `VER_UE4_ADD_STRING_ASSET_REFERENCES_MAP` | `ObjectVersion.h:364` |
| `VER_UE4_BLUEPRINT_GENERATED_CLASS_COMPONENT_TEMPLATES_PUBLIC` | `ObjectVersion.h:475` |
| `VER_UE4_CHANGED_CHUNKID_TO_BE_AN_ARRAY_OF_CHUNKIDS` | `ObjectVersion.h:245` |
| `VER_UE4_COOKED_ASSETS_IN_EDITOR_SUPPORT` | `ObjectVersion.h:569` |
| `VER_UE4_CORRECT_LICENSEE_FLAG` | `ObjectVersion.h:643` |
| `VER_UE4_ENGINE_VERSION_OBJECT` | `ObjectVersion.h:265` |
| `VER_UE4_LOAD_FOR_EDITOR_GAME` | `ObjectVersion.h:326` |
| `VER_UE4_NON_OUTER_PACKAGE_IMPORT` | `ObjectVersion.h:639` |
| `VER_UE4_OLDEST_LOADABLE_PACKAGE` | `ObjectVersion.h:660` |
| `VER_UE4_PACKAGE_SUMMARY_HAS_COMPATIBLE_ENGINE_VERSION` | `ObjectVersion.h:487` |
| `VER_UE4_PRELOAD_DEPENDENCIES_IN_COOKED_EXPORTS` | `ObjectVersion.h:613` |
| `VER_UE4_SERIALIZE_TEXT_IN_PACKAGES` | `ObjectVersion.h:517` |
| `VER_UE4_SKIP_DUPLICATE_EXPORTS_ON_SAVE_PACKAGE` | `ObjectVersion.h:515` |
| `VER_UE4_TemplateIndex_IN_COOKED_EXPORTS` | `ObjectVersion.h:615` |
| `VER_UE4_WORLD_LEVEL_INFO` | `ObjectVersion.h:39` |

## Package linker initialization stages

**Epic function:** `LinkerLoad.cpp:689`

| Step | Original source location | Exact C++ statement |
|---|---|---|
| `LOADER01` | `LinkerLoad.cpp:715` | `Status = CreateLoader(TFunction<void()>([]() {}));` |
| `LOADER02` | `LinkerLoad.cpp:724` | `Status = SerializePackageFileSummary();` |
| `LOADER03` | `LinkerLoad.cpp:731` | `Status = SerializeNameMap();` |
| `LOADER04` | `LinkerLoad.cpp:738` | `Status = SerializeGatherableTextDataMap();` |
| `LOADER05` | `LinkerLoad.cpp:745` | `Status = SerializeImportMap();` |
| `LOADER06` | `LinkerLoad.cpp:752` | `Status = SerializeExportMap();` |
| `LOADER07` | `LinkerLoad.cpp:760` | `Status = ReconstructImportAndExportMap();` |
| `LOADER08` | `LinkerLoad.cpp:768` | `Status = FixupImportMap();` |
| `LOADER09` | `LinkerLoad.cpp:775` | `Status = PopulateInstancingContext();` |
| `LOADER10` | `LinkerLoad.cpp:782` | `Status = FixupExportMap();` |
| `LOADER11` | `LinkerLoad.cpp:789` | `Status = SerializeDependsMap();` |
| `LOADER12` | `LinkerLoad.cpp:796` | `Status = CreateExportHash();` |
| `LOADER13` | `LinkerLoad.cpp:803` | `Status = FindExistingExports();` |
| `LOADER14` | `LinkerLoad.cpp:809` | `Status = SerializePreloadDependencies();` |
| `LOADER15` | `LinkerLoad.cpp:816` | `Status = FinalizeCreation(ObjectNameWithOuterToExportMap);` |

### Actual conditional statements in the original Epic source

| Source line | Original C++ condition or compile-time directive |
|---|---|
| `LinkerLoad.cpp:693` | `if( bHasFinishedInitialization == false )` |
| `LinkerLoad.cpp:705` | `if (GEventDrivenLoaderEnabled)` |
| `LinkerLoad.cpp:721` | `if (bCanSerializePackageFileSummary)` |
| `LinkerLoad.cpp:728` | `if( Status == LINKER_Loaded )` |
| `LinkerLoad.cpp:735` | `if( Status == LINKER_Loaded )` |
| `LinkerLoad.cpp:742` | `if( Status == LINKER_Loaded )` |
| `LinkerLoad.cpp:749` | `if( Status == LINKER_Loaded )` |
| `LinkerLoad.cpp:755` | `#if WITH_TEXT_ARCHIVE_SUPPORT` |
| `LinkerLoad.cpp:757` | `if (Status == LINKER_Loaded)` |
| `LinkerLoad.cpp:765` | `if( Status == LINKER_Loaded )` |
| `LinkerLoad.cpp:772` | `if (Status == LINKER_Loaded)` |
| `LinkerLoad.cpp:779` | `if( Status == LINKER_Loaded )` |
| `LinkerLoad.cpp:786` | `if( Status == LINKER_Loaded )` |
| `LinkerLoad.cpp:793` | `if( Status == LINKER_Loaded )` |
| `LinkerLoad.cpp:800` | `if( Status == LINKER_Loaded )` |
| `LinkerLoad.cpp:806` | `if (Status == LINKER_Loaded)` |
| `LinkerLoad.cpp:813` | `if( Status == LINKER_Loaded )` |
| `LinkerLoad.cpp:825` | `if (Status == LINKER_Failed)` |
| `LinkerLoad.cpp:828` | `#if WITH_EDITOR` |
| `LinkerLoad.cpp:830` | `if (LoadProgressScope)` |

### Serialized field sites in the Epic implementation

| Source line | Field written/read by Epic |
|---|---|
| — | No direct SA_VALUE sites inside this section; follow its called serializer |

**15 source-anchored navigation nodes; 20 original conditional-directive lines; 0 serialized field sites.**

## Package summary serialization

**Epic function:** `PackageFileSummary.cpp:48`

| Step | Original source location | Exact C++ statement |
|---|---|---|
| `SUMMARY01` | `PackageFileSummary.cpp:68` | `Record << SA_VALUE(TEXT("Tag"), Sum.Tag);` |
| `SUMMARY02` | `PackageFileSummary.cpp:71` | `if (Sum.Tag == PACKAGE_FILE_TAG \|\| Sum.Tag == PACKAGE_FILE_TAG_SWAPPED)` |
| `SUMMARY03` | `PackageFileSummary.cpp:102` | `const int32 CurrentLegacyFileVersion = -7;` |
| `SUMMARY04` | `PackageFileSummary.cpp:104` | `Record << SA_VALUE(TEXT("LegacyFileVersion"), LegacyFileVersion);` |
| `SUMMARY05` | `PackageFileSummary.cpp:124` | `Record << SA_VALUE(TEXT("FileVersionUE4"), Sum.FileVersionUE4);` |
| `SUMMARY06` | `PackageFileSummary.cpp:129` | `Sum.CustomVersionContainer.Serialize(Record.EnterField(SA_FIELD_NAME(TEXT("CustomVersions"))), GetCustomVersionFormatForArchive(LegacyFileVersion));` |
| `SUMMARY07` | `PackageFileSummary.cpp:180` | `Record << SA_VALUE(TEXT("TotalHeaderSize"), Sum.TotalHeaderSize);` |
| `SUMMARY08` | `PackageFileSummary.cpp:182` | `Record << SA_VALUE(TEXT("PackageFlags"), Sum.PackageFlags);` |
| `SUMMARY09` | `PackageFileSummary.cpp:196` | `Record << SA_VALUE(TEXT("NameCount"), Sum.NameCount) << SA_VALUE(TEXT("NameOffset"), Sum.NameOffset);` |
| `SUMMARY10` | `PackageFileSummary.cpp:208` | `Record << SA_VALUE(TEXT("ExportCount"), Sum.ExportCount) << SA_VALUE(TEXT("ExportOffset"), Sum.ExportOffset);` |
| `SUMMARY11` | `PackageFileSummary.cpp:209` | `Record << SA_VALUE(TEXT("ImportCount"), Sum.ImportCount) << SA_VALUE(TEXT("ImportOffset"), Sum.ImportOffset);` |
| `SUMMARY12` | `PackageFileSummary.cpp:210` | `Record << SA_VALUE(TEXT("DependsOffset"), Sum.DependsOffset);` |
| `SUMMARY13` | `PackageFileSummary.cpp:219` | `Record << SA_VALUE(TEXT("SoftPackageReferencesCount"), Sum.SoftPackageReferencesCount) << SA_VALUE(TEXT("SoftPackageReferencesOffset"), Sum.SoftPackageReferencesOffset);` |
| `SUMMARY14` | `PackageFileSummary.cpp:227` | `Record << SA_VALUE(TEXT("ThumbnailTableOffset"), Sum.ThumbnailTableOffset);` |
| `SUMMARY15` | `PackageFileSummary.cpp:258` | `Record << SA_VALUE(TEXT("GenerationCount"), GenerationCount);` |
| `SUMMARY16` | `PackageFileSummary.cpp:320` | `Record << SA_VALUE(TEXT("CompressionFlags"), Sum.CompressionFlags);` |
| `SUMMARY17` | `PackageFileSummary.cpp:329` | `Record << SA_VALUE(TEXT("CompressedChunks"), CompressedChunks);` |
| `SUMMARY18` | `PackageFileSummary.cpp:354` | `Record << SA_VALUE(TEXT("AssetRegistryDataOffset"), Sum.AssetRegistryDataOffset);` |
| `SUMMARY19` | `PackageFileSummary.cpp:355` | `Record << SA_VALUE(TEXT("BulkDataStartOffset"), Sum.BulkDataStartOffset);` |
| `SUMMARY20` | `PackageFileSummary.cpp:383` | `Record << SA_VALUE(TEXT("PreloadDependencyCount"), Sum.PreloadDependencyCount) << SA_VALUE(TEXT("PreloadDependencyOffset"), Sum.PreloadDependencyOffset);` |

### Actual conditional statements in the original Epic source

| Source line | Original C++ condition or compile-time directive |
|---|---|
| `PackageFileSummary.cpp:53` | `if (BaseArchive.IsLoading())` |
| `PackageFileSummary.cpp:66` | `if (bCanStartSerializing)` |
| `PackageFileSummary.cpp:71` | `if (Sum.Tag == PACKAGE_FILE_TAG \|\| Sum.Tag == PACKAGE_FILE_TAG_SWAPPED)` |
| `PackageFileSummary.cpp:75` | `if (Sum.Tag == PACKAGE_FILE_TAG_SWAPPED)` |
| `PackageFileSummary.cpp:80` | `if (BaseArchive.ForceByteSwapping())` |
| `PackageFileSummary.cpp:106` | `if (BaseArchive.IsLoading())` |
| `PackageFileSummary.cpp:108` | `if (LegacyFileVersion < 0) // means we have modern version numbers` |
| `PackageFileSummary.cpp:110` | `if (LegacyFileVersion < CurrentLegacyFileVersion)` |
| `PackageFileSummary.cpp:119` | `if (LegacyFileVersion != -4)` |
| `PackageFileSummary.cpp:127` | `if (LegacyFileVersion <= -2)` |
| `PackageFileSummary.cpp:132` | `if (!Sum.FileVersionUE4 && !Sum.FileVersionLicenseeUE4)` |
| `PackageFileSummary.cpp:134` | `#if WITH_EDITOR` |
| `PackageFileSummary.cpp:135` | `if (!GAllowUnversionedContentInEditor)` |
| `PackageFileSummary.cpp:158` | `if (Sum.bUnversioned)` |
| `PackageFileSummary.cpp:184` | `#if WITH_EDITOR` |
| `PackageFileSummary.cpp:185` | `if (BaseArchive.IsLoading())` |
| `PackageFileSummary.cpp:192` | `if (Sum.PackageFlags & PKG_FilterEditorOnly)` |
| `PackageFileSummary.cpp:197` | `if (!BaseArchive.IsFilterEditorOnly())` |
| `PackageFileSummary.cpp:199` | `if (BaseArchive.IsSaving() \|\| Sum.FileVersionUE4 >= VER_UE4_ADDED_PACKAGE_SUMMARY_LOCALIZATION_ID)` |
| `PackageFileSummary.cpp:204` | `if (Sum.FileVersionUE4 >= VER_UE4_SERIALIZE_TEXT_IN_PACKAGES)` |
| `PackageFileSummary.cpp:212` | `if (BaseArchive.IsLoading() && (Sum.FileVersionUE4 < VER_UE4_OLDEST_LOADABLE_PACKAGE \|\| Sum.FileVersionUE4 > GPackageFileUE4Version))` |
| `PackageFileSummary.cpp:217` | `if (BaseArchive.IsSaving() \|\| Sum.FileVersionUE4 >= VER_UE4_ADD_STRING_ASSET_REFERENCES_MAP)` |
| `PackageFileSummary.cpp:222` | `if (BaseArchive.IsSaving() \|\| Sum.FileVersionUE4 >= VER_UE4_ADDED_SEARCHABLE_NAMES)` |
| `PackageFileSummary.cpp:233` | `#if WITH_EDITORONLY_DATA` |
| `PackageFileSummary.cpp:234` | `if (!BaseArchive.IsFilterEditorOnly())` |
| `PackageFileSummary.cpp:236` | `if (BaseArchive.IsSaving() \|\| Sum.FileVersionUE4 >= VER_UE4_ADDED_PACKAGE_OWNER)` |
| `PackageFileSummary.cpp:249` | `if (BaseArchive.IsLoading() && Sum.FileVersionUE4 >= VER_UE4_ADDED_PACKAGE_OWNER && Sum.FileVersionUE4 < VER_UE4_NON_OUTER_PACKAGE_IMPORT)` |
| `PackageFileSummary.cpp:259` | `if (BaseArchive.IsLoading() && GenerationCount > 0)` |
| `PackageFileSummary.cpp:269` | `if (BaseArchive.IsLoading() && BaseArchive.IsError())` |
| `PackageFileSummary.cpp:275` | `if (Sum.GetFileVersionUE4() >= VER_UE4_ENGINE_VERSION_OBJECT)` |
| `PackageFileSummary.cpp:277` | `if (BaseArchive.IsCooking() \|\| (BaseArchive.IsSaving() && !FEngineVersion::Current().HasChangelist()))` |
| `PackageFileSummary.cpp:293` | `if (BaseArchive.IsLoading() && EngineChangelist != 0)` |
| `PackageFileSummary.cpp:299` | `if (Sum.GetFileVersionUE4() >= VER_UE4_PACKAGE_SUMMARY_HAS_COMPATIBLE_ENGINE_VERSION)` |
| `PackageFileSummary.cpp:301` | `if (BaseArchive.IsCooking() \|\| (BaseArchive.IsSaving() && !FEngineVersion::Current().HasChangelist()))` |
| `PackageFileSummary.cpp:314` | `if (BaseArchive.IsLoading())` |
| `PackageFileSummary.cpp:321` | `if (!FCompression::VerifyCompressionFlagsValid(Sum.CompressionFlags))` |
| `PackageFileSummary.cpp:331` | `if (CompressedChunks.Num())` |
| `PackageFileSummary.cpp:346` | `if (LegacyFileVersion > -7)` |
| `PackageFileSummary.cpp:357` | `if (Sum.GetFileVersionUE4() >= VER_UE4_WORLD_LEVEL_INFO)` |
| `PackageFileSummary.cpp:362` | `if (Sum.GetFileVersionUE4() >= VER_UE4_CHANGED_CHUNKID_TO_BE_AN_ARRAY_OF_CHUNKIDS)` |
| `PackageFileSummary.cpp:366` | `else if (Sum.GetFileVersionUE4() >= VER_UE4_ADDED_CHUNKID_TO_ASSETDATA_AND_UPACKAGE)` |
| `PackageFileSummary.cpp:369` | `if (BaseArchive.IsLoading())` |
| `PackageFileSummary.cpp:375` | `if (ChunkID >= 0)` |
| `PackageFileSummary.cpp:381` | `if (BaseArchive.IsSaving() \|\| Sum.FileVersionUE4 >= VER_UE4_PRELOAD_DEPENDENCIES_IN_COOKED_EXPORTS)` |

### Serialized field sites in the Epic implementation

| Source line | Field written/read by Epic |
|---|---|
| `PackageFileSummary.cpp:68` | `Tag` |
| `PackageFileSummary.cpp:104` | `LegacyFileVersion` |
| `PackageFileSummary.cpp:122` | `LegacyUE3Version` |
| `PackageFileSummary.cpp:124` | `FileVersionUE4` |
| `PackageFileSummary.cpp:125` | `FileVersionLicenseeUE4` |
| `PackageFileSummary.cpp:161` | `LegacyUE3version` |
| `PackageFileSummary.cpp:162` | `FileVersionUE4` |
| `PackageFileSummary.cpp:163` | `FileVersionLicenseeUE4` |
| `PackageFileSummary.cpp:172` | `LegacyUE3Version` |
| `PackageFileSummary.cpp:173` | `FileVersionUE4` |
| `PackageFileSummary.cpp:174` | `FileVersionLicenseeUE4` |
| `PackageFileSummary.cpp:180` | `TotalHeaderSize` |
| `PackageFileSummary.cpp:181` | `FolderName` |
| `PackageFileSummary.cpp:182` | `PackageFlags` |
| `PackageFileSummary.cpp:196` | `NameCount` |
| `PackageFileSummary.cpp:196` | `NameOffset` |
| `PackageFileSummary.cpp:201` | `LocalizationId` |
| `PackageFileSummary.cpp:206` | `GatherableTextDataCount` |
| `PackageFileSummary.cpp:206` | `GatherableTextDataOffset` |
| `PackageFileSummary.cpp:208` | `ExportCount` |
| `PackageFileSummary.cpp:208` | `ExportOffset` |
| `PackageFileSummary.cpp:209` | `ImportCount` |
| `PackageFileSummary.cpp:209` | `ImportOffset` |
| `PackageFileSummary.cpp:210` | `DependsOffset` |
| `PackageFileSummary.cpp:219` | `SoftPackageReferencesCount` |
| `PackageFileSummary.cpp:219` | `SoftPackageReferencesOffset` |
| `PackageFileSummary.cpp:224` | `SearchableNamesOffset` |
| `PackageFileSummary.cpp:227` | `ThumbnailTableOffset` |
| `PackageFileSummary.cpp:230` | `Guid` |
| `PackageFileSummary.cpp:238` | `PersistentGuid` |
| `PackageFileSummary.cpp:252` | `OwnerPersistentGuid` |
| `PackageFileSummary.cpp:258` | `GenerationCount` |
| `PackageFileSummary.cpp:280` | `SavedByEngineVersion` |
| `PackageFileSummary.cpp:284` | `SavedByEngineVersion` |
| `PackageFileSummary.cpp:291` | `EngineChangelist` |
| `PackageFileSummary.cpp:304` | `CompatibleWithEngineVersion` |
| `PackageFileSummary.cpp:308` | `CompatibleWithEngineVersion` |
| `PackageFileSummary.cpp:320` | `CompressionFlags` |
| `PackageFileSummary.cpp:329` | `CompressedChunks` |
| `PackageFileSummary.cpp:339` | `PackageSource` |
| `PackageFileSummary.cpp:344` | `AdditionalPackagesToCook` |
| `PackageFileSummary.cpp:349` | `NumTextureAllocations` |
| `PackageFileSummary.cpp:354` | `AssetRegistryDataOffset` |
| `PackageFileSummary.cpp:355` | `BulkDataStartOffset` |
| `PackageFileSummary.cpp:359` | `WorldTileInfoDataOffset` |
| `PackageFileSummary.cpp:364` | `ChunkIDs` |
| `PackageFileSummary.cpp:372` | `ChunkID` |
| `PackageFileSummary.cpp:383` | `PreloadDependencyCount` |
| `PackageFileSummary.cpp:383` | `PreloadDependencyOffset` |

**20 source-anchored navigation nodes; 44 original conditional-directive lines; 49 serialized field sites.**

## Serialized FObjectImport

**Epic function:** `ObjectResource.cpp:283`

| Step | Original source location | Exact C++ statement |
|---|---|---|
| `IMPORTS01` | `ObjectResource.cpp:287` | `Record << SA_VALUE(TEXT("ClassPackage"), I.ClassPackage);` |
| `IMPORTS02` | `ObjectResource.cpp:288` | `Record << SA_VALUE(TEXT("ClassName"), I.ClassName);` |
| `IMPORTS03` | `ObjectResource.cpp:289` | `Record << SA_VALUE(TEXT("OuterIndex"), I.OuterIndex);` |
| `IMPORTS04` | `ObjectResource.cpp:290` | `Record << SA_VALUE(TEXT("ObjectName"), I.ObjectName);` |
| `IMPORTS05` | `ObjectResource.cpp:294` | `if (Slot.GetUnderlyingArchive().UE4Ver() >= VER_UE4_NON_OUTER_PACKAGE_IMPORT && !Slot.GetUnderlyingArchive().IsFilterEditorOnly())` |
| `IMPORTS06` | `ObjectResource.cpp:296` | `Record << SA_VALUE(TEXT("PackageName"), I.PackageName);` |
| `IMPORTS07` | `ObjectResource.cpp:302` | `I.SourceLinker = NULL;` |

### Actual conditional statements in the original Epic source

| Source line | Original C++ condition or compile-time directive |
|---|---|
| `ObjectResource.cpp:293` | `#if WITH_EDITORONLY_DATA` |
| `ObjectResource.cpp:294` | `if (Slot.GetUnderlyingArchive().UE4Ver() >= VER_UE4_NON_OUTER_PACKAGE_IMPORT && !Slot.GetUnderlyingArchive().IsFilterEditorOnly())` |
| `ObjectResource.cpp:300` | `if (Slot.GetUnderlyingArchive().IsLoading())` |

### Serialized field sites in the Epic implementation

| Source line | Field written/read by Epic |
|---|---|
| `ObjectResource.cpp:287` | `ClassPackage` |
| `ObjectResource.cpp:288` | `ClassName` |
| `ObjectResource.cpp:289` | `OuterIndex` |
| `ObjectResource.cpp:290` | `ObjectName` |
| `ObjectResource.cpp:296` | `PackageName` |

**7 source-anchored navigation nodes; 3 original conditional-directive lines; 5 serialized field sites.**

## Serialized FObjectExport

**Epic function:** `ObjectResource.cpp:105`

| Step | Original source location | Exact C++ statement |
|---|---|---|
| `EXPORTS01` | `ObjectResource.cpp:110` | `Record << SA_VALUE(TEXT("ClassIndex"), E.ClassIndex);` |
| `EXPORTS02` | `ObjectResource.cpp:111` | `Record << SA_VALUE(TEXT("SuperIndex"), E.SuperIndex);` |
| `EXPORTS03` | `ObjectResource.cpp:113` | `if (BaseArchive.UE4Ver() >= VER_UE4_TemplateIndex_IN_COOKED_EXPORTS)` |
| `EXPORTS04` | `ObjectResource.cpp:118` | `Record << SA_VALUE(TEXT("OuterIndex"), E.OuterIndex);` |
| `EXPORTS05` | `ObjectResource.cpp:119` | `Record << SA_VALUE(TEXT("ObjectName"), E.ObjectName);` |
| `EXPORTS06` | `ObjectResource.cpp:122` | `Record << SA_VALUE(TEXT("ObjectFlags"), Save);` |
| `EXPORTS07` | `ObjectResource.cpp:129` | `if (BaseArchive.UE4Ver() < VER_UE4_64BIT_EXPORTMAP_SERIALSIZES)` |
| `EXPORTS08` | `ObjectResource.cpp:145` | `Record << SA_VALUE(TEXT("bForcedExport"), E.bForcedExport);` |
| `EXPORTS09` | `ObjectResource.cpp:152` | `Record << SA_VALUE(TEXT("PackageFlags"), E.PackageFlags);` |
| `EXPORTS10` | `ObjectResource.cpp:164` | `if (BaseArchive.UE4Ver() >= VER_UE4_PRELOAD_DEPENDENCIES_IN_COOKED_EXPORTS)` |

### Actual conditional statements in the original Epic source

| Source line | Original C++ condition or compile-time directive |
|---|---|
| `ObjectResource.cpp:113` | `if (BaseArchive.UE4Ver() >= VER_UE4_TemplateIndex_IN_COOKED_EXPORTS)` |
| `ObjectResource.cpp:124` | `if (BaseArchive.IsLoading())` |
| `ObjectResource.cpp:129` | `if (BaseArchive.UE4Ver() < VER_UE4_64BIT_EXPORTMAP_SERIALSIZES)` |
| `ObjectResource.cpp:154` | `if (BaseArchive.UE4Ver() >= VER_UE4_LOAD_FOR_EDITOR_GAME)` |
| `ObjectResource.cpp:159` | `if (BaseArchive.UE4Ver() >= VER_UE4_COOKED_ASSETS_IN_EDITOR_SUPPORT)` |
| `ObjectResource.cpp:164` | `if (BaseArchive.UE4Ver() >= VER_UE4_PRELOAD_DEPENDENCIES_IN_COOKED_EXPORTS)` |

### Serialized field sites in the Epic implementation

| Source line | Field written/read by Epic |
|---|---|
| `ObjectResource.cpp:110` | `ClassIndex` |
| `ObjectResource.cpp:111` | `SuperIndex` |
| `ObjectResource.cpp:115` | `TemplateIndex` |
| `ObjectResource.cpp:118` | `OuterIndex` |
| `ObjectResource.cpp:119` | `ObjectName` |
| `ObjectResource.cpp:122` | `ObjectFlags` |
| `ObjectResource.cpp:132` | `SerialSize` |
| `ObjectResource.cpp:136` | `SerialOffset` |
| `ObjectResource.cpp:141` | `SerialSize` |
| `ObjectResource.cpp:142` | `SerialOffset` |
| `ObjectResource.cpp:145` | `bForcedExport` |
| `ObjectResource.cpp:146` | `bNotForClient` |
| `ObjectResource.cpp:147` | `bNotForServer` |
| `ObjectResource.cpp:150` | `PackageGuid` |
| `ObjectResource.cpp:152` | `PackageFlags` |
| `ObjectResource.cpp:156` | `bNotAlwaysLoadedForEditorGame` |
| `ObjectResource.cpp:161` | `bIsAsset` |
| `ObjectResource.cpp:166` | `FirstExportDependency` |
| `ObjectResource.cpp:167` | `SerializationBeforeSerializationDependencies` |
| `ObjectResource.cpp:168` | `CreateBeforeSerializationDependencies` |
| `ObjectResource.cpp:169` | `SerializationBeforeCreateDependencies` |
| `ObjectResource.cpp:170` | `CreateBeforeCreateDependencies` |

**10 source-anchored navigation nodes; 6 original conditional-directive lines; 22 serialized field sites.**

## Named table readers

**Epic function:** `LinkerLoad.cpp:1496`

| Step | Original source location | Exact C++ statement |
|---|---|---|
| `TABLES01` | `LinkerLoad.cpp:1544` | `*this << NameEntry;` |
| `TABLES02` | `LinkerLoad.cpp:1613` | `Stream.EnterElement() << *Import;` |
| `TABLES03` | `LinkerLoad.cpp:1893` | `Stream.EnterElement() << *Export;` |
| `TABLES04` | `LinkerLoad.cpp:2118` | `Stream.EnterElement() << Depends;` |
| `TABLES05` | `LinkerLoad.cpp:2134` | `if (bHasSerializedPreloadDependencies \|\| Summary.PreloadDependencyCount < 1 \|\| Summary.PreloadDependencyOffset <= 0)` |

### Actual conditional statements in the original Epic source

| Source line | Original C++ condition or compile-time directive |
|---|---|
| `LinkerLoad.cpp:1501` | `if (IsTextFormat())` |
| `LinkerLoad.cpp:1511` | `if (NameMap.Num() == 0 && NameCount > 0)` |
| `LinkerLoad.cpp:1516` | `if (Summary.TotalHeaderSize > 0)` |
| `LinkerLoad.cpp:1521` | `if (bIsAsyncLoader)` |
| `LinkerLoad.cpp:1531` | `if (!bFinishedPrecaching)` |
| `LinkerLoad.cpp:1548` | `if (Idx % TimeSliceGranularity == TimeSliceGranularity - 1 &&` |
| `LinkerLoad.cpp:1598` | `if (IsTextFormat())` |
| `LinkerLoad.cpp:1603` | `if(ImportMapIndex == 0 && Summary.ImportCount > 0 )` |
| `LinkerLoad.cpp:1878` | `if (IsTextFormat())` |
| `LinkerLoad.cpp:1883` | `if(ExportMapIndex == 0 && Summary.ExportCount > 0)` |
| `LinkerLoad.cpp:2089` | `if( FPlatformProperties::RequiresCookedData()` |
| `LinkerLoad.cpp:2096` | `if ( Summary.DependsOffset == 0 )` |
| `LinkerLoad.cpp:2103` | `if (DependsMapIndex == 0 && Summary.ExportCount > 0)` |
| `LinkerLoad.cpp:2105` | `if (!IsTextFormat())` |
| `LinkerLoad.cpp:2134` | `if (bHasSerializedPreloadDependencies \|\| Summary.PreloadDependencyCount < 1 \|\| Summary.PreloadDependencyOffset <= 0)` |
| `LinkerLoad.cpp:2139` | `if (!IsTextFormat())` |
| `LinkerLoad.cpp:2146` | `if ((IsSaving()            // if we are saving, we always do the ordinary serialize as a way to make sure it matches up with bulk serialization` |

### Serialized field sites in the Epic implementation

| Source line | Field written/read by Epic |
|---|---|
| — | No direct SA_VALUE sites inside this section; follow its called serializer |

**5 source-anchored navigation nodes; 17 original conditional-directive lines; 0 serialized field sites.**

## Classic package writer cross-check

**Epic function:** `SavePackage.cpp:3720`

| Step | Original source location | Exact C++ statement |
|---|---|---|
| `WRITERS01` | `SavePackage.cpp:3720` | `Linker->Summary.DependsOffset = Linker->Tell();` |
| `WRITERS02` | `SavePackage.cpp:3723` | `TArray<FPackageIndex>& Depends = Linker->DependsMap[i];` |
| `WRITERS03` | `SavePackage.cpp:3724` | `DependsStream.EnterElement() << Depends;` |
| `WRITERS04` | `SavePackage.cpp:3743` | `Linker->Summary.SoftPackageReferencesCount = Linker->SoftPackageReferenceList.Num();` |
| `WRITERS05` | `SavePackage.cpp:3750` | `for (FName& SoftPackageName : Linker->SoftPackageReferenceList)` |
| `WRITERS06` | `SavePackage.cpp:3752` | `SoftReferenceStream.EnterElement() << SoftPackageName;` |

### Actual conditional statements in the original Epic source

| Source line | Original C++ condition or compile-time directive |
|---|---|
| `SavePackage.cpp:3737` | `if (!(Linker->Summary.PackageFlags & PKG_FilterEditorOnly))` |
| `SavePackage.cpp:3744` | `if (!bTextFormat)` |
| `SavePackage.cpp:3746` | `#if WITH_EDITOR` |

### Serialized field sites in the Epic implementation

| Source line | Field written/read by Epic |
|---|---|
| — | No direct SA_VALUE sites inside this section; follow its called serializer |

**6 source-anchored navigation nodes; 3 original conditional-directive lines; 0 serialized field sites.**

## Epic UE4.26 engine-version correction inside package summary

**Epic function:** `PackageFileSummary.cpp:33`

| Step | Original source location | Exact C++ statement |
|---|---|---|
| `VERSION_CORRECTION01` | `PackageFileSummary.cpp:33` | `static void FixCorruptEngineVersion(int ObjectVersion, FEngineVersion& Version)` |
| `VERSION_CORRECTION02` | `PackageFileSummary.cpp:37` | `if (ObjectVersion < VER_UE4_CORRECT_LICENSEE_FLAG` |
| `VERSION_CORRECTION03` | `PackageFileSummary.cpp:38` | `&& Version.GetMajor() == 4` |
| `VERSION_CORRECTION04` | `PackageFileSummary.cpp:39` | `&& Version.GetMinor() == 26` |
| `VERSION_CORRECTION05` | `PackageFileSummary.cpp:40` | `&& Version.GetPatch() == 0` |
| `VERSION_CORRECTION06` | `PackageFileSummary.cpp:41` | `&& Version.GetChangelist() >= 12740027` |
| `VERSION_CORRECTION07` | `PackageFileSummary.cpp:42` | `&& Version.IsLicenseeVersion())` |
| `VERSION_CORRECTION08` | `PackageFileSummary.cpp:44` | `Version.Set(4, 26, 0, Version.GetChangelist(), Version.GetBranch());` |

### Actual conditional statements in the original Epic source

| Source line | Original C++ condition or compile-time directive |
|---|---|
| `PackageFileSummary.cpp:37` | `if (ObjectVersion < VER_UE4_CORRECT_LICENSEE_FLAG` |

### Serialized field sites in the Epic implementation

| Source line | Field written/read by Epic |
|---|---|
| — | No direct SA_VALUE sites inside this section; follow its called serializer |

**8 source-anchored navigation nodes; 1 original conditional-directive lines; 0 serialized field sites.**

## FEngineVersionBase licensee-bit decoding and normalisation

**Epic function:** `EngineVersion.cpp:20`

| Step | Original source location | Exact C++ statement |
|---|---|---|
| `ENGINE_CHANGELIST01` | `EngineVersion.cpp:20` | `uint32 FEngineVersionBase::GetChangelist() const` |
| `ENGINE_CHANGELIST02` | `EngineVersion.cpp:23` | `return Changelist & (uint32)0x7fffffffU;` |
| `ENGINE_CHANGELIST03` | `EngineVersion.cpp:26` | `bool FEngineVersionBase::IsLicenseeVersion() const` |
| `ENGINE_CHANGELIST04` | `EngineVersion.cpp:29` | `return (Changelist & (uint32)0x80000000U) != 0;` |
| `ENGINE_CHANGELIST05` | `EngineVersion.cpp:90` | `void FEngineVersion::Set(uint16 InMajor, uint16 InMinor, uint16 InPatch, uint32 InChangelist, const FString &InBranch)` |
| `ENGINE_CHANGELIST06` | `EngineVersion.cpp:95` | `Changelist = InChangelist;` |

### Actual conditional statements in the original Epic source

| Source line | Original C++ condition or compile-time directive |
|---|---|

### Serialized field sites in the Epic implementation

| Source line | Field written/read by Epic |
|---|---|
| — | No direct SA_VALUE sites inside this section; follow its called serializer |

**6 source-anchored navigation nodes; 0 original conditional-directive lines; 0 serialized field sites.**

## Editor-only transient package flag and editor filter flag

**Epic function:** `ObjectMacros.h:102`

| Step | Original source location | Exact C++ statement |
|---|---|---|
| `PACKAGE_FLAGS01` | `ObjectMacros.h:103` | `enum EPackageFlags` |
| `PACKAGE_FLAGS02` | `ObjectMacros.h:106` | `PKG_NewlyCreated				= 0x00000001,	///< Newly created package, not saved yet. In editor only.` |
| `PACKAGE_FLAGS03` | `ObjectMacros.h:136` | `PKG_FilterEditorOnly			= 0x80000000,	///< Package has editor-only data filtered out` |

### Actual conditional statements in the original Epic source

| Source line | Original C++ condition or compile-time directive |
|---|---|

### Serialized field sites in the Epic implementation

| Source line | Field written/read by Epic |
|---|---|
| — | No direct SA_VALUE sites inside this section; follow its called serializer |

**3 source-anchored navigation nodes; 0 original conditional-directive lines; 0 serialized field sites.**

## FLinker::FilterExport after serialization

**Epic function:** `Linker.h:528`

| Step | Original source location | Exact C++ statement |
|---|---|---|
| `EXPORT_FILTER01` | `Linker.h:528` | `bool FilterExport(const FObjectExport& Export)` |
| `EXPORT_FILTER02` | `Linker.h:530` | `if (Export.bExportLoadFailed \|\| Export.bWasFiltered)` |
| `EXPORT_FILTER03` | `Linker.h:535` | `if (!Export.bNotAlwaysLoadedForEditorGame) // Always load, even if is editor only` |
| `EXPORT_FILTER04` | `Linker.h:540` | `if (FilterClientButNotServer && Export.bNotForServer) // "we are a dedicated server"` |
| `EXPORT_FILTER05` | `Linker.h:544` | `if (FilterServerButNotClient && Export.bNotForClient) // "we are a client only"` |
| `EXPORT_FILTER06` | `Linker.h:548` | `if (Export.ThisIndex.IsNull()) // Export is invalid and shouldn't be processed.` |
| `EXPORT_FILTER07` | `Linker.h:552` | `return false;` |

### Actual conditional statements in the original Epic source

| Source line | Original C++ condition or compile-time directive |
|---|---|
| `Linker.h:530` | `if (Export.bExportLoadFailed \|\| Export.bWasFiltered)` |
| `Linker.h:534` | `#if WITH_EDITOR` |
| `Linker.h:535` | `if (!Export.bNotAlwaysLoadedForEditorGame) // Always load, even if is editor only` |
| `Linker.h:540` | `if (FilterClientButNotServer && Export.bNotForServer) // "we are a dedicated server"` |
| `Linker.h:544` | `if (FilterServerButNotClient && Export.bNotForClient) // "we are a client only"` |
| `Linker.h:548` | `if (Export.ThisIndex.IsNull()) // Export is invalid and shouldn't be processed.` |

### Serialized field sites in the Epic implementation

| Source line | Field written/read by Epic |
|---|---|
| — | No direct SA_VALUE sites inside this section; follow its called serializer |

**7 source-anchored navigation nodes; 6 original conditional-directive lines; 0 serialized field sites.**

## FObjectExport constructor defaults before archive version gates

**Epic function:** `ObjectResource.cpp:30`

| Step | Original source location | Exact C++ statement |
|---|---|---|
| `EXPORT_DEFAULTS01` | `ObjectResource.cpp:43` | `, bForcedExport(false)` |
| `EXPORT_DEFAULTS02` | `ObjectResource.cpp:44` | `, bNotForClient(false)` |
| `EXPORT_DEFAULTS03` | `ObjectResource.cpp:45` | `, bNotForServer(false)` |
| `EXPORT_DEFAULTS04` | `ObjectResource.cpp:46` | `, bNotAlwaysLoadedForEditorGame(true)` |
| `EXPORT_DEFAULTS05` | `ObjectResource.cpp:47` | `, bIsAsset(false)` |
| `EXPORT_DEFAULTS06` | `ObjectResource.cpp:48` | `, bExportLoadFailed(false)` |
| `EXPORT_DEFAULTS07` | `ObjectResource.cpp:50` | `, bWasFiltered(false)` |
| `EXPORT_DEFAULTS08` | `ObjectResource.cpp:52` | `, FirstExportDependency(-1)` |
| `EXPORT_DEFAULTS09` | `ObjectResource.cpp:53` | `, SerializationBeforeSerializationDependencies(0)` |
| `EXPORT_DEFAULTS10` | `ObjectResource.cpp:54` | `, CreateBeforeSerializationDependencies(0)` |
| `EXPORT_DEFAULTS11` | `ObjectResource.cpp:55` | `, SerializationBeforeCreateDependencies(0)` |
| `EXPORT_DEFAULTS12` | `ObjectResource.cpp:56` | `, CreateBeforeCreateDependencies(0)` |

### Actual conditional statements in the original Epic source

| Source line | Original C++ condition or compile-time directive |
|---|---|

### Serialized field sites in the Epic implementation

| Source line | Field written/read by Epic |
|---|---|
| — | No direct SA_VALUE sites inside this section; follow its called serializer |

**12 source-anchored navigation nodes; 0 original conditional-directive lines; 0 serialized field sites.**

## FLinkerLoad::FixupImportMap — CoreRedirects and package additions

**Epic function:** `LinkerLoad.cpp:1624`

| Step | Original source location | Exact C++ statement |
|---|---|---|
| `IMPORT_FIXUP01` | `LinkerLoad.cpp:1624` | `FLinkerLoad::ELinkerStatus FLinkerLoad::FixupImportMap()` |
| `IMPORT_FIXUP02` | `LinkerLoad.cpp:1628` | `if( bHasFixedUpImportMap == false )` |
| `IMPORT_FIXUP03` | `LinkerLoad.cpp:1637` | `if (!FPlatformProperties::RequiresCookedData())` |
| `IMPORT_FIXUP04` | `LinkerLoad.cpp:1641` | `TArray<int32> PackageIndexesToClear;` |
| `IMPORT_FIXUP05` | `LinkerLoad.cpp:1644` | `while (!bDone)` |
| `IMPORT_FIXUP06` | `LinkerLoad.cpp:1649` | `for( int32 i=0; i<ImportMap.Num(); i++ )` |
| `IMPORT_FIXUP07` | `LinkerLoad.cpp:1657` | `FCoreRedirects::RedirectNameAndValues(ECoreRedirectFlags::Type_Class, OldClassName, NewClassName, &ClassValueRedirect);` |
| `IMPORT_FIXUP08` | `LinkerLoad.cpp:1662` | `const FString* NewInstanceName = ClassValueRedirect->ValueChanges.Find(Import.ObjectName.ToString());` |
| `IMPORT_FIXUP09` | `LinkerLoad.cpp:1667` | `Import.ObjectName = FName(**NewInstanceName);` |
| `IMPORT_FIXUP10` | `LinkerLoad.cpp:1681` | `FCoreRedirectObjectName OldObjectName(GetImportPathName(i)), NewObjectName;` |
| `IMPORT_FIXUP11` | `LinkerLoad.cpp:1685` | `FCoreRedirects::RedirectNameAndValues(ObjectRedirectFlags, OldObjectName, NewObjectName, &ValueRedirect);` |
| `IMPORT_FIXUP12` | `LinkerLoad.cpp:1687` | `if (ValueRedirect && ValueRedirect->OverrideClassName.IsValid())` |
| `IMPORT_FIXUP13` | `LinkerLoad.cpp:1693` | `if (NewObjectName != OldObjectName)` |
| `IMPORT_FIXUP14` | `LinkerLoad.cpp:1695` | `if (Import.OuterIndex.IsNull())` |
| `IMPORT_FIXUP15` | `LinkerLoad.cpp:1699` | `PackageIndexesToClear.Add(i);` |
| `IMPORT_FIXUP16` | `LinkerLoad.cpp:1704` | `if (NewObjectName.OuterName == NAME_None && NewObjectName.PackageName != OldObjectName.PackageName)` |
| `IMPORT_FIXUP17` | `LinkerLoad.cpp:1708` | `if (FindImportPackage(NewObjectName.PackageName, NewPackageIndex))` |
| `IMPORT_FIXUP18` | `LinkerLoad.cpp:1711` | `Import.OuterIndex = NewPackageIndex;` |
| `IMPORT_FIXUP19` | `LinkerLoad.cpp:1716` | `NewPackageImports.AddUnique(NewObjectName.PackageName);` |
| `IMPORT_FIXUP20` | `LinkerLoad.cpp:1736` | `if (NewClassName != OldClassName)` |
| `IMPORT_FIXUP21` | `LinkerLoad.cpp:1743` | `if (!FindImportPackage(NewClassName.PackageName, NewPackageIndex))` |
| `IMPORT_FIXUP22` | `LinkerLoad.cpp:1746` | `NewPackageImports.AddUnique(NewClassName.PackageName);` |
| `IMPORT_FIXUP23` | `LinkerLoad.cpp:1755` | `Import.ClassPackage = NewClassName.PackageName;` |
| `IMPORT_FIXUP24` | `LinkerLoad.cpp:1761` | `if (NewDefaultObjectName.StartsWith(DEFAULT_OBJECT_PREFIX))` |
| `IMPORT_FIXUP25` | `LinkerLoad.cpp:1765` | `Import.ObjectName = FName(*NewDefaultObjectName);` |
| `IMPORT_FIXUP26` | `LinkerLoad.cpp:1773` | `for (FName NewPackage : NewPackageImports)` |
| `IMPORT_FIXUP27` | `LinkerLoad.cpp:1776` | `FObjectImport* NewImport = new (ImportMap) FObjectImport();` |
| `IMPORT_FIXUP28` | `LinkerLoad.cpp:1788` | `// Clear any packages that got renamed, once all children have been fixed up` |
| `IMPORT_FIXUP29` | `LinkerLoad.cpp:1793` | `Import.ObjectName = NAME_None;` |
| `IMPORT_FIXUP30` | `LinkerLoad.cpp:1798` | `bHasFixedUpImportMap = true;` |
| `IMPORT_FIXUP31` | `LinkerLoad.cpp:1800` | `return IsTimeLimitExceeded( TEXT("fixing up import map") ) ? LINKER_TimedOut : LINKER_Loaded;` |

### Actual conditional statements in the original Epic source

| Source line | Original C++ condition or compile-time directive |
|---|---|
| `LinkerLoad.cpp:1628` | `if( bHasFixedUpImportMap == false )` |
| `LinkerLoad.cpp:1630` | `#if WITH_EDITOR` |
| `LinkerLoad.cpp:1631` | `if (LoadProgressScope)` |
| `LinkerLoad.cpp:1637` | `if (!FPlatformProperties::RequiresCookedData())` |
| `LinkerLoad.cpp:1659` | `if (ClassValueRedirect)` |
| `LinkerLoad.cpp:1663` | `if (NewInstanceName)` |
| `LinkerLoad.cpp:1669` | `if (Import.ObjectName != NAME_None)` |
| `LinkerLoad.cpp:1687` | `if (ValueRedirect && ValueRedirect->OverrideClassName.IsValid())` |
| `LinkerLoad.cpp:1693` | `if (NewObjectName != OldObjectName)` |
| `LinkerLoad.cpp:1695` | `if (Import.OuterIndex.IsNull())` |
| `LinkerLoad.cpp:1704` | `if (NewObjectName.OuterName == NAME_None && NewObjectName.PackageName != OldObjectName.PackageName)` |
| `LinkerLoad.cpp:1708` | `if (FindImportPackage(NewObjectName.PackageName, NewPackageIndex))` |
| `LinkerLoad.cpp:1721` | `#if WITH_EDITOR` |
| `LinkerLoad.cpp:1723` | `if (ObjectRedirectFlags == ECoreRedirectFlags::Type_Class)` |
| `LinkerLoad.cpp:1736` | `if (NewClassName != OldClassName)` |
| `LinkerLoad.cpp:1739` | `if (Import.ClassPackage != NewClassName.PackageName && !IsCoreUObjectPackage(NewClassName.PackageName))` |
| `LinkerLoad.cpp:1743` | `if (!FindImportPackage(NewClassName.PackageName, NewPackageIndex))` |
| `LinkerLoad.cpp:1751` | `#if WITH_EDITOR` |
| `LinkerLoad.cpp:1761` | `if (NewDefaultObjectName.StartsWith(DEFAULT_OBJECT_PREFIX))` |

### Serialized field sites in the Epic implementation

| Source line | Field written/read by Epic |
|---|---|
| — | No direct SA_VALUE sites inside this section; follow its called serializer |

**31 source-anchored navigation nodes; 19 original conditional-directive lines; 0 serialized field sites.**

## FLinkerLoad::PopulateInstancingContext — source package mapping

**Epic function:** `LinkerLoad.cpp:1803`

| Step | Original source location | Exact C++ statement |
|---|---|---|
| `INSTANCING_FIXUP01` | `LinkerLoad.cpp:1803` | `FLinkerLoad::ELinkerStatus FLinkerLoad::PopulateInstancingContext()` |
| `INSTANCING_FIXUP02` | `LinkerLoad.cpp:1807` | `if (!bHasPopulatedInstancingContext)` |
| `INSTANCING_FIXUP03` | `LinkerLoad.cpp:1809` | `#if WITH_EDITOR` |
| `INSTANCING_FIXUP04` | `LinkerLoad.cpp:1811` | `if (IsContextInstanced())` |
| `INSTANCING_FIXUP05` | `LinkerLoad.cpp:1817` | `for (const FObjectExport& Export : ExportMap)` |
| `INSTANCING_FIXUP06` | `LinkerLoad.cpp:1819` | `if (Export.OuterIndex.IsImport())` |
| `INSTANCING_FIXUP07` | `LinkerLoad.cpp:1822` | `while (Import->OuterIndex.IsImport())` |
| `INSTANCING_FIXUP08` | `LinkerLoad.cpp:1824` | `if (Import->HasPackageName())` |
| `INSTANCING_FIXUP09` | `LinkerLoad.cpp:1826` | `InstancingPackageName.Add(Import->PackageName);` |
| `INSTANCING_FIXUP10` | `LinkerLoad.cpp:1830` | `check(Import->OuterIndex.IsNull() && !Import->HasPackageName());` |
| `INSTANCING_FIXUP11` | `LinkerLoad.cpp:1836` | `auto HasExportOuterChain = [this](const FObjectImport* InImport) -> bool` |
| `INSTANCING_FIXUP12` | `LinkerLoad.cpp:1845` | `for (const FObjectImport& Import : ImportMap)` |
| `INSTANCING_FIXUP13` | `LinkerLoad.cpp:1847` | `if (Import.HasPackageName() && HasExportOuterChain(&Import))` |
| `INSTANCING_FIXUP14` | `LinkerLoad.cpp:1849` | `InstancingPackageName.Add(Import.PackageName);` |
| `INSTANCING_FIXUP15` | `LinkerLoad.cpp:1854` | `for (const FName& InstancingName : InstancingPackageName)` |
| `INSTANCING_FIXUP16` | `LinkerLoad.cpp:1856` | `FName& InstancedName = InstancingContext.Mapping.FindOrAdd(InstancingName);` |
| `INSTANCING_FIXUP17` | `LinkerLoad.cpp:1858` | `if (InstancedName.IsNone())` |
| `INSTANCING_FIXUP18` | `LinkerLoad.cpp:1860` | `InstancedName = *FString::Printf(TEXT("%s_InstanceOf_%s"), *InstancingName.ToString(), *LinkerPackageName);` |
| `INSTANCING_FIXUP19` | `LinkerLoad.cpp:1866` | `bHasPopulatedInstancingContext = true;` |
| `INSTANCING_FIXUP20` | `LinkerLoad.cpp:1868` | `return IsTimeLimitExceeded(TEXT("populating instancing context")) ? LINKER_TimedOut : LINKER_Loaded;` |

### Actual conditional statements in the original Epic source

| Source line | Original C++ condition or compile-time directive |
|---|---|
| `LinkerLoad.cpp:1807` | `if (!bHasPopulatedInstancingContext)` |
| `LinkerLoad.cpp:1809` | `#if WITH_EDITOR` |
| `LinkerLoad.cpp:1811` | `if (IsContextInstanced())` |
| `LinkerLoad.cpp:1819` | `if (Export.OuterIndex.IsImport())` |
| `LinkerLoad.cpp:1824` | `if (Import->HasPackageName())` |
| `LinkerLoad.cpp:1847` | `if (Import.HasPackageName() && HasExportOuterChain(&Import))` |
| `LinkerLoad.cpp:1858` | `if (InstancedName.IsNone())` |

### Serialized field sites in the Epic implementation

| Source line | Field written/read by Epic |
|---|---|
| — | No direct SA_VALUE sites inside this section; follow its called serializer |

**20 source-anchored navigation nodes; 7 original conditional-directive lines; 0 serialized field sites.**

## FLinkerLoad::FixupExportMap — class validation, corrections, redirects

**Epic function:** `LinkerLoad.cpp:5814`

| Step | Original source location | Exact C++ statement |
|---|---|---|
| `EXPORT_FIXUP01` | `LinkerLoad.cpp:5814` | `FLinkerLoad::ELinkerStatus FLinkerLoad::FixupExportMap()` |
| `EXPORT_FIXUP02` | `LinkerLoad.cpp:5818` | `#if WITH_EDITOR` |
| `EXPORT_FIXUP03` | `LinkerLoad.cpp:5819` | `if (UE4Ver() < VER_UE4_SKIP_DUPLICATE_EXPORTS_ON_SAVE_PACKAGE && !bExportsDuplicatesFixed)` |
| `EXPORT_FIXUP04` | `LinkerLoad.cpp:5821` | `FixupDuplicateExports();` |
| `EXPORT_FIXUP05` | `LinkerLoad.cpp:5827` | `if (!FPlatformProperties::RequiresCookedData())` |
| `EXPORT_FIXUP06` | `LinkerLoad.cpp:5829` | `if (bFixupExportMapDone)` |
| `EXPORT_FIXUP07` | `LinkerLoad.cpp:5834` | `for ( int32 ExportMapIdx = 0; ExportMapIdx < ExportMap.Num(); ExportMapIdx++ )` |
| `EXPORT_FIXUP08` | `LinkerLoad.cpp:5837` | `if (!IsValidPackageIndex(Export.ClassIndex))` |
| `EXPORT_FIXUP09` | `LinkerLoad.cpp:5848` | `if (UE4Ver() < VER_UE4_BLUEPRINT_GENERATED_CLASS_COMPONENT_TEMPLATES_PUBLIC)` |
| `EXPORT_FIXUP10` | `LinkerLoad.cpp:5850` | `if ((Export.ObjectFlags & RF_Public) == 0)` |
| `EXPORT_FIXUP11` | `LinkerLoad.cpp:5855` | `if (OuterClassName == NAME_BlueprintGeneratedClass \|\| OuterClassName == NAME_Blueprint)` |
| `EXPORT_FIXUP12` | `LinkerLoad.cpp:5865` | `if (    ((Class != nullptr) && Class->IsChildOf(ActorComponentClass))` |
| `EXPORT_FIXUP13` | `LinkerLoad.cpp:5868` | `Export.ObjectFlags \|= RF_Public;` |
| `EXPORT_FIXUP14` | `LinkerLoad.cpp:5877` | `const TMap<FString, FString>* ValueChanges = FCoreRedirects::GetValueRedirects(ECoreRedirectFlags::Type_Class, OldClassName);` |
| `EXPORT_FIXUP15` | `LinkerLoad.cpp:5879` | `if (ValueChanges)` |
| `EXPORT_FIXUP16` | `LinkerLoad.cpp:5883` | `if (NewInstanceName)` |
| `EXPORT_FIXUP17` | `LinkerLoad.cpp:5887` | `Export.ObjectName = FName(**NewInstanceName);` |
| `EXPORT_FIXUP18` | `LinkerLoad.cpp:5896` | `Export.bExportLoadFailed = true;` |
| `EXPORT_FIXUP19` | `LinkerLoad.cpp:5903` | `if (!StrObjectName.StartsWith(DEFAULT_OBJECT_PREFIX))` |
| `EXPORT_FIXUP20` | `LinkerLoad.cpp:5905` | `FCoreRedirectObjectName NewClassInstanceName = FCoreRedirects::GetRedirectedName(ECoreRedirectFlags::Type_Class \| ECoreRedirectFlags::Category_InstanceOnly, OldClassName);` |
| `EXPORT_FIXUP21` | `LinkerLoad.cpp:5907` | `bool bClassInstanceDeleted = FCoreRedirects::IsKnownMissing(ECoreRedirectFlags::Type_Class \| ECoreRedirectFlags::Category_InstanceOnly, OldClassName);` |
| `EXPORT_FIXUP22` | `LinkerLoad.cpp:5908` | `if (bClassInstanceDeleted)` |
| `EXPORT_FIXUP23` | `LinkerLoad.cpp:5913` | `Export.ClassIndex = FPackageIndex();` |
| `EXPORT_FIXUP24` | `LinkerLoad.cpp:5915` | `Export.ObjectName = NAME_None;` |
| `EXPORT_FIXUP25` | `LinkerLoad.cpp:5920` | `else if (NewClassInstanceName != OldClassName)` |
| `EXPORT_FIXUP26` | `LinkerLoad.cpp:5925` | `if (CreateImportClassAndPackage(NewClassInstanceName.ObjectName, NewClassInstanceName.PackageName, NewClassIndex, NewPackageIndex))` |
| `EXPORT_FIXUP27` | `LinkerLoad.cpp:5927` | `Export.ClassIndex = NewClassIndex;` |
| `EXPORT_FIXUP28` | `LinkerLoad.cpp:5942` | `bFixupExportMapDone = true;` |
| `EXPORT_FIXUP29` | `LinkerLoad.cpp:5943` | `return !IsTimeLimitExceeded( TEXT("fixing up export map") ) ? LINKER_Loaded : LINKER_TimedOut;` |

### Actual conditional statements in the original Epic source

| Source line | Original C++ condition or compile-time directive |
|---|---|
| `LinkerLoad.cpp:5818` | `#if WITH_EDITOR` |
| `LinkerLoad.cpp:5819` | `if (UE4Ver() < VER_UE4_SKIP_DUPLICATE_EXPORTS_ON_SAVE_PACKAGE && !bExportsDuplicatesFixed)` |
| `LinkerLoad.cpp:5827` | `if (!FPlatformProperties::RequiresCookedData())` |
| `LinkerLoad.cpp:5829` | `if (bFixupExportMapDone)` |
| `LinkerLoad.cpp:5837` | `if (!IsValidPackageIndex(Export.ClassIndex))` |
| `LinkerLoad.cpp:5848` | `if (UE4Ver() < VER_UE4_BLUEPRINT_GENERATED_CLASS_COMPONENT_TEMPLATES_PUBLIC)` |
| `LinkerLoad.cpp:5850` | `if ((Export.ObjectFlags & RF_Public) == 0)` |
| `LinkerLoad.cpp:5855` | `if (OuterClassName == NAME_BlueprintGeneratedClass \|\| OuterClassName == NAME_Blueprint)` |
| `LinkerLoad.cpp:5865` | `if (    ((Class != nullptr) && Class->IsChildOf(ActorComponentClass))` |
| `LinkerLoad.cpp:5879` | `if (ValueChanges)` |
| `LinkerLoad.cpp:5883` | `if (NewInstanceName)` |
| `LinkerLoad.cpp:5889` | `if (Export.ObjectName != NAME_None)` |
| `LinkerLoad.cpp:5903` | `if (!StrObjectName.StartsWith(DEFAULT_OBJECT_PREFIX))` |
| `LinkerLoad.cpp:5908` | `if (bClassInstanceDeleted)` |
| `LinkerLoad.cpp:5916` | `#if WITH_EDITOR` |
| `LinkerLoad.cpp:5920` | `else if (NewClassInstanceName != OldClassName)` |
| `LinkerLoad.cpp:5925` | `if (CreateImportClassAndPackage(NewClassInstanceName.ObjectName, NewClassInstanceName.PackageName, NewClassIndex, NewPackageIndex))` |
| `LinkerLoad.cpp:5928` | `#if WITH_EDITOR` |

### Serialized field sites in the Epic implementation

| Source line | Field written/read by Epic |
|---|---|
| — | No direct SA_VALUE sites inside this section; follow its called serializer |

**29 source-anchored navigation nodes; 18 original conditional-directive lines; 0 serialized field sites.**

## FPackageIndex default null constructor

**Epic function:** `ObjectResource.h:38`

| Step | Original source location | Exact C++ statement |
|---|---|---|
| `PACKAGE_INDEX_CTOR01` | `ObjectResource.h:38` | `FORCEINLINE FPackageIndex()` |
| `PACKAGE_INDEX_CTOR02` | `ObjectResource.h:39` | `: Index(0)` |

### Actual conditional statements in the original Epic source

| Source line | Original C++ condition or compile-time directive |
|---|---|

### Serialized field sites in the Epic implementation

| Source line | Field written/read by Epic |
|---|---|
| — | No direct SA_VALUE sites inside this section; follow its called serializer |

**2 source-anchored navigation nodes; 0 original conditional-directive lines; 0 serialized field sites.**

## Unexpanded calls and formats

Not traced to their end here; no end-to-end completeness claim is permitted.

- CreateLoader dispatch and archive selection
- GetPackageLinker / provider selection
- SerializePreloadDependencies full body
- FinaliseCreation / LoadAllObjects
- Object-property serialization and per-class payloads
- Bulk/UEXP/UBULK, compressed and optional data
- Platform, target, cooked, text archive, IoStore branch separation
- SavePackage2 complete writer branches
- FLinker::FilterExport target-context inputs: WITH_EDITOR, FilterClientButNotServer, FilterServerButNotClient, bExportLoadFailed and previously filtered state
- FCoreRedirects registered source configuration, RedirectNameAndValues and ValueChanges implementation
- FLinkerLoad::FixupDuplicateExports, FindImportPackage and CreateImportClassAndPackage implementation
- Runtime HasExportOuterChain, linker instancing remap inputs, UObject actor-component class hierarchy and target platform cooked-data state
