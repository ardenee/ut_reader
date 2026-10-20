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

## Epic-native AssetRegistry main-section acceptance

**U427-P06-D002 — raw AssetRegistry main fields unavailable to UnrealDB; corrected.** The production reader previously recorded only `assetRegistryDataOffset` without exposing raw asset objects/tags or the dependency-data offset. The new `getAssetRegistryObjects()` and `getAssetRegistryDependencyDataOffset()` retain **source-serialized** object names, classes and tag pairs, preserving byte offsets; this intentionally does **not** synthesize `FAssetData`, deserialize dependency bit arrays or interpret semantic search tags.

Pinned `AssetRegistry/Private/PackageReader.cpp::UE::AssetRegistry::ReadPackageDataMain` and `SavePackage/SavePackageUtilities.cpp::WritePackageData` use a second wire-format decision: `VersionUE4 < VER_UE4_ASSETREGISTRY_DEPENDENCYFLAGS` **or** `PKG_FilterEditorOnly` suppresses the initial signed `int64` dependency-data offset. The compiled Epic enum value is **521**. Otherwise the first `int64` locates separately serialized dependency bits. Both variants then read signed object count; each object is `FString ObjectPath`, `FString ObjectClassName`, signed tag count and FString key/value pairs.

The source-pinned compiled oracle's `-P06Registry` mode uses Epic's `FArchive/FString::operator<<` and logs each field, offset and UTF-8 identity. Reproducible native/PHP differential `php catalog/bin/verify-ue427-p06-native-registry.php <manifest> <native-log>`:

| Evidence | Installed UE4 corpus | Epic-cooked corpus |
| --- | ---: | ---: |
| Packages and registry sections | **1,749** | **245** |
| Pre-dependency-format sections | **1,714** | **245** |
| New dependency-offset-format sections | **35** | **0** |
| Serialized object records | **1,730** | **0** |
| Serialized string tag pairs | **12,058** | **0** |
| Native object fields compared | **6,920** | **0** |
| Native tag fields compared | **36,174** | **0** |
| Native header fields compared | **6,996** | **980** |
| Semantic mismatches | **0** | **0** |

Logs: `C:/Temp/ue427-p06-registry-installed-native.log` and `C:/Temp/ue427-p06-registry-cooked-native.log`. The empty cooked main lists are **expected**, not a missing parser result: Epic's cooker retains the older filtered format to avoid patch-size changes.

`php catalog/bin/verify-ue427-p06-registry-negatives.php` passes seven disposable real-asset tests (authentic positive control; negative/excessive object count; oversized object FString; negative/excessive tag count; oversized tag FString). All corruptions produce reader issues. The genuine source and installed engine files remain unchanged.

**Still unverified** in this subgate: separate AssetRegistry dependency bit arrays, path and map-world synthesis, editor filtering and class/tag semantics after `FAssetData` construction. Those are not equivalent to raw main-section parity and must be tested independently.

## Still open within P06

1. **Completed raw main-section decoding:** both pre-dependency and new dependency-offset wire formats, object records and 12,058 tags match compiled Epic; cooked filtered empty sections also match. **Still required:** actual dependency bit arrays, asset-path/map-world synthesis, class/tag semantic transformations and any production publication consumers.
2. Verify actual thumbnail image serialization / editor and non-editor skip conditions as applicable. The newly verified *index* is not a bitmap decoder.
3. Produce or locate source-grounded genuine/controlled **world-tile** and **chunk-ID** version-gate fixtures and compare Epic-native summary/operator behavior, including invalid/extreme counts.
4. Trace the active UnrealDB consumers before claiming end-to-end publication, search or V5 parity for these P06 sections. Preserve separate P08/P09 workflow obligations.

Single-command replay: `php catalog/bin/verify-ue427-p06-partial-acceptance.php`. Its seven independent scopes include native thumbnail and raw registry comparisons, both real-asset negative suites, the full already-verified P05 replay, and UT4 V5 regression. A passing combined driver is **partial P06 evidence only** and must not flip the primary ledger to VERIFIED before the remaining source branches and production consumers are accepted.

**P06 remains ACTIVE; overall count stays 5/10.** No production database writes, canonical Epic source edits or unproven certification claims in this checkpoint.
