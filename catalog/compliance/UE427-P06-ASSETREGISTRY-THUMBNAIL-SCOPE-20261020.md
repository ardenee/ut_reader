# UE4.27.2 P06 — native thumbnail index and remaining source scope

**Status: U427-P06 ACTIVE.** P01–P05 remain verified (**5/10** fixed original operations). This is partial acceptance, **not** closure for AssetRegistry data, world-tile or chunk-ID branch behavior.

Pinned Epic source: `L:/Source/Engine/UE4/UE 4.27.2` commit `d94b38ae3446da52224bedd2568c078f828b4039`; native oracle rebuilt and executed against `H:/Epic Games/UE_4.27` binary.

## Source contracts and relevant production reader

- `CoreUObject/Private/UObject/LinkerLoad.cpp::FLinkerLoad::SerializeThumbnails` (only with `WITH_EDITORONLY_DATA`, editor or forced in-game) requires `Summary.ThumbnailTableOffset > 0`, seeks to the thumbnail index, reads signed `int32` count and per-item `FString ObjectClassName`, `FString ObjectPathWithoutPackageName`, signed `int32 FileOffset`. Thumbnail *image content* is subsequently decoded separately from each offset through `FObjectThumbnail::Serialize`. No in-game thumbnail map is inferred when editor-only execution excludes it.
- `AssetRegistry/Private/PackageReader.cpp::FPackageReader::ReadAssetRegistryData` seeks to `AssetRegistryDataOffset`, requires a package-root identity, then invokes `UE::AssetRegistry::ReadPackageDataMain`. The dependency-data payload is a distinct source stage, not interchangeable with thumbnail fallback.
- `PackageFileSummary.cpp` version-gates `WorldTileInfoDataOffset`, and serializes `ChunkIDs` as an array only after `VER_UE4_CHANGED_CHUNKID_TO_BE_AN_ARRAY_OF_CHUNKIDS`; older permitted versions decode a single nonnegative ID, with negative sentinel omitted. Existing `UnrealPackageReader4` contains the corresponding version gates but not independent native P06 acceptance.
- `catalog/src/Infrastructure/Metadata/Uedb5Ut4SnapshotBuilder.php` preserves the four **summary** fields, but belongs to UT4's separately pinned v511 profile. Do not treat UT4 V5 summary persistence as full stock UE4.27 AssetRegistry or thumbnail-image interpretation.

## Actual difference and initial correction

**U427-P06-D001 — thumbnail index not available through the reader.** The production `UE4/UnrealPackageReader.php` parsed only `thumbnailTableOffset`, not the table itself. It now exposes `getThumbnailIndex()` containing index, byte record offset, class name, object path and image-data offset. It uses existing endian-aware FString primitives and bounded count/offset validation, adds a clear reader issue when an index is corrupt, and **does not** misrepresent it as decoded thumbnail pixels or a complete AssetRegistry reader.

## Genuine corpus and independent Epic-native acceptance

Two genuine data scopes have been measured:

| Evidence | Original installed editor corpus | Latest isolated UE4.27 cooked corpus |
| --- | ---: | ---: |
| Packages | **1,749** | **245** |
| Nonzero AssetRegistry summary offsets | **1,749** | **245** |
| Nonzero thumbnail table offsets | **1,724** | **0** |
| Nonzero world-tile offsets | **0** | **0** |
| Populated chunk-ID arrays | **0** | **0** |
| Reader issues | **0** | **0** |

In the editor corpus, those 1,724 tables contain **1,729 records** (at most 3 per file). A compiled `UE427Oracle -List=C:/Temp/ue427-all-assets.txt -P06Thumb` reads each table with Epic's actual `FArchive` operators and logs field values, table byte positions, UTF-8 class and path strings, and image offsets. The reproducible independent `php catalog/bin/verify-ue427-p06-native-thumbnails.php` compares **6,916 record fields**, plus **1,749 index-presence decisions**, against UnrealDB: **zero mismatches**. Native log: `C:/Temp/ue427-p06-thumbnail-native.log`. All 25 packages with absent thumbnail tables correctly yield no index entries.

`php catalog/bin/verify-ue427-p06-thumbnail-negatives.php` passes five disposable-asset tests: original genuine positive control, negative count, excessive count, oversized class FString, and out-of-range image byte offset. Corruption is reported by the reader; no original Epic assets are modified.

Re-run `php catalog/bin/verify-ue427-real-reader-table-corpus.php` and `php catalog/bin/verify-uedb5-ut4-persistence.php` after the production reader change: both pass.

## Still open within P06

1. Decode and independently compare the **real AssetRegistry section**, including legacy/cooked main object data, tags, and dependency flag branches. Do not equate a positive offset with fully decoded contents.
2. Verify actual thumbnail image serialization / editor and non-editor skip conditions as applicable. The newly verified *index* is not a bitmap decoder.
3. Produce or locate source-grounded genuine/controlled **world-tile** and **chunk-ID** version-gate fixtures and compare Epic-native summary/operator behavior, including invalid/extreme counts.
4. Trace the active UnrealDB consumers before claiming end-to-end publication, search or V5 parity for these P06 sections. Preserve separate P08/P09 workflow obligations.

**P06 remains ACTIVE; overall count stays 5/10.** No production database writes, canonical Epic source edits or unproven certification claims in this checkpoint.
