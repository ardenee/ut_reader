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

**Still unverified** in this subgate: path and map-world synthesis, editor filtering and class/tag semantics after `FAssetData` construction. Separate AssetRegistry dependency bit arrays now have independent acceptance below. Those are not equivalent to raw main-section parity and must be tested independently.

## Source-native AssetRegistry dependency-array parity — U427-P06-D003

**Corrected:** the production reader retained only the AssetRegistry dependency-section offset; Epic's two serialized `TBitArray<>` fields were not decoded and unavailable to readers. `UnrealPackageReader4::getAssetRegistryDependencyBits()` now retains source-ordered booleans and the section physical start/end. Its decoder follows Epic `Core/Public/Containers/BitArray.h::operator<<(FArchive&, TBitArray&)`: signed `int32 NumBits` followed by `ceil(NumBits/32)` raw 32-bit words. `FArchive::Serialize` does not integer-swap the raw words; individual bit order is low-bit-first. The reader also enforces the import/soft reference counts required by Epic `AssetRegistry/Private/PackageDependencyData.h::IsValid`.

Compiled `UE427OracleP06Dependencies.h` invokes Epic's own `TBitArray<>` archive operator on genuine files. Independent `php catalog/bin/verify-ue427-p06-native-dependencies.php` compares each decoded bit, array count, physical start and end across **all 1,749 installed packages**:

| Acceptance | Result |
|---|---:|
| Older-format packages with no serialized bits | **1,714** |
| Newer-format native/reader dependency sections | **35 / 35** |
| Import-used bits | **598** (280 true) |
| Soft-package-used bits | **9** (1 true) |
| Exact compared bit/metadata fields | **747** |
| Native mismatches | **0** |

Native log `C:/Temp/ue427-p06-deps-native.log`. The executable `php catalog/bin/verify-ue427-p06-dependency-negatives.php` passes **8/8** disposable-genuine-asset tests: unchanged control, negative and excessive import counts, import map length mismatch, negative soft count, soft map length mismatch, invalid dependency-section offset, and valid one-bit mutation. Each malformed case reports a reader issue. Source files and actual Epic assets remain unchanged.

These results certify the raw dependency-bit wire contract, **not** full `FAssetData` synthesis, publisher/search semantics, world-tile data or application durability.

## World-tile data and chunk-ID version gates — U427-P06-D004

**Corrected:** the production reader exposed `WorldTileInfoDataOffset` but not the pointed-to world composition record. `UnrealPackageReader4::getWorldTileInfo()` now decodes Epic `FWorldTileInfo` and `FWorldTileLayer` source structures: Fortnite custom-version 2D/3D position, FBox float bounds and validity, layer, parent, visibility, LOD records, Z-order, and UE4-version-dependent field presence. It reports malformed arrays and offsets. The existing `ChunkIDs` array versus single-ID summary version gates remain intact.

**Compiled positive fixtures:** `UE427OracleP06WorldChunk.h` uses Epic `FPackageFileSummary` and `FWorldTileInfo` operators to generate little-endian and byte-swapped 4096-byte version-522 packages. Actual compiled Epic loads both: IDs `[3,7,19]`, position `(12,-34,56)`, bounds `[-100,-200,-300]` to `[100,200,300]`, layer `TestLayer`, streaming distance 75000, parent `/Game/Map/Parent`, LOD distance 12345 and Z-order -7.

**Legacy genuine source:** `php catalog/bin/generate-ue427-p06-legacy-chunk-fixture.php` takes version-288 `StandardMacros.uasset`, makes an isolated copy, and mutates the confirmed 32-bit chunk-ID field from -1 to 7. The original Epic package is untouched; both the compiled native loader and UnrealDB return `[7]`. This is a validated real-asset *mutation*, not a claim Epic published the changed file.

**Independent parity:** `php catalog/bin/verify-ue427-p06-native-world-chunks.php` verifies three native summary heads, two native world records, 7 chunk IDs and 38 world fields: **zero mismatches**. Native log `C:/Temp/ue427-p06-world-native-all.log`; manifest `C:/Temp/ue427-p06-world-manifest.txt`. `php catalog/bin/verify-ue427-p06-world-chunk-negatives.php` passes **11/11** controls and corruptions, including endian-aware oversized chunk arrays, corrupt world offsets, invalid FString, invalid LOD counts, and sentinel/zero legacy cases.

**Limit:** positive world-record testing here covers current v522 plus the old v288 chunk-ID header. Historical 2D/custom-version world payloads, `FAssetData` semantics, thumbnail image bytes and production publication are not automatically certified. The 1,749 genuine installed files have no nonzero world-tile offset and no nonempty chunk arrays. No extra top-level audit IDs were created.

## Genuine thumbnail compressed image data — U427-P06-D005

**Corrected:** the production reader's thumbnail index exposed only class, object path and file offset. It now also provides `UnrealPackageReader4::getThumbnailPayload(int)`, which *lazily* reads one `FObjectThumbnail::Serialize` width/height/compressed-byte-array record and returns the exact compressed bytes, sizes and byte offsets. This does not decode or synthesize thumbnail image pixels and does not allocate all images when the package reader is constructed.

**Source:** pinned Epic `Core/Private/Misc/ObjectThumbnail.cpp::FObjectThumbnail::Serialize` stores `int32 ImageWidth`, `int32 ImageHeight`, then `TArray<uint8> CompressedImageData` (signed count plus raw bytes). Its editor-only linker caller `CoreUObject/Private/UObject/LinkerLoad.cpp::SerializeThumbnails` skips loading when not in editor unless forced, and skips entirely without `WITH_EDITORONLY_DATA`; this reader API remains deliberately explicit rather than inventing an editor-only execution state.

**Independent native evidence:** `UE427OracleP06ThumbPayload.h` invokes Epic's actual compiled `FObjectThumbnail::Serialize` on every indexed genuine thumbnail and uses `FSHA1::HashBuffer` over the retained compressed bytes. `php catalog/bin/verify-ue427-p06-native-thumbnail-payloads.php` calls the new lazy reader API and checks dimensions, physical offsets, array counts and exact SHA-1. Across **1,749 installed UE4 packages, 1,729 thumbnail payloads, 234 empty compressed payloads, and 84,244,023 compressed payload bytes**, the **10,374 compared native fields/hashes match with zero differences**. This is physical serialized-byte parity, not an image/decompression claim. Native log `C:/Temp/ue427-p06-thumppayload-native.log`.

`php catalog/bin/verify-ue427-p06-thumbnail-payload-negatives.php` also passes **8/8** independent mutations against an isolated genuine asset: untouched positive, negative width/height, negative/excessive compressed count, truncation, a valid byte mutation changing the hash, and an invalid index. Neither Epic's installed content nor production databases are modified.

**Remaining P06 scope:** automatic Unreal editor-only thumbnail-map policy, actual pixel decompression, `FAssetData` class/object path and tag semantics, and production publication/consumer behavior are not certified by raw compressed-byte matching.

## Open production semantic gap — U427-P06-D006

**Finding (OPEN; not a solved format mismatch):** Epic's `AssetRegistry/Private/PackageReader.cpp::UE::AssetRegistry::ReadPackageDataMain` does more than deserialize raw strings. It derives `PackagePath` from a caller-supplied logical long package name, synthesizes `World` FAssetData for some missing/legacy map records, drops empty tag keys/values, rejects legacy external object paths and invalid relative subobject paths, and constructs `FAssetData` via the source's `CoreUObject/Private/AssetRegistry/AssetData.cpp` constructors. Our reader currently exposes the native-verified raw objects and dependency flags but does **not** provide that complete semantic `FAssetData` derivation. A physical filename alone does not supply Epic's logical package mount path, so none should be guessed.

**Production reachability measured:** `php catalog/bin/verify-ue427-p06-production-use-inventory.php` scanned **816 production PHP source files** under `catalog` and `UE4` (excluding audit commands, compliance artifacts, vendors and the reader method declarations). It found **zero direct production call sites** for the six P06 getters (`getThumbnailIndex`, `getThumbnailPayload`, `getAssetRegistryObjects`, `getAssetRegistryDependencyDataOffset`, `getAssetRegistryDependencyBits`, `getWorldTileInfo`). This negative inventory is source-static: reflection, dynamic method names and external code are not ruled out. The separate **UT4 clean-master v511** `Uedb5Ut4SnapshotBuilder` publishes summary offsets, name/import/export maps and some references, but has no AssetRegistry, thumbnail or WorldTile data sections; it must not be called a stock 4.27.2 `FAssetData` publisher.

**Closure condition:** construct and independently verify Epic-equivalent semantic `FAssetData` output with explicit caller-supplied package identity (including map fallback, tag filtering and version gates), then decide and test the actual application consumer's requirements. This belongs to P06 and must not be silently treated as fulfilled by passing the raw 13/13 format gates. Durable archive ingestion, backup/recovery and V5 projectors are separately tracked by fixed P08/P09—not an excuse to invent a P11.

## Still open within P06

1. **Completed raw main-section decoding:** both pre-dependency and new dependency-offset wire formats, object records and 12,058 tags match compiled Epic; cooked filtered empty sections also match. **Dependency bit arrays are verified** (35 genuine sections / 607 bits). **Still required:** asset-path/map-world synthesis, class/tag semantic transformations and any production publication consumers.
2. **Thumbnail compressed-byte payload serialization is now verified** (1,729 native payloads, 84,244,023 bytes). Remaining: editor/non-editor linker thumbnail-map conditions and actual pixel decompression, only if used by production.
3. **Current-format WorldTile/ChunkID fixtures pass native parity** (3 files, 7 IDs, 38 world fields, 11 negatives). Historical world-tile custom-version branches remain an explicit evidence limit.
4. **Production consumers traced (816 PHP files, zero literal P06 getter callers):** the actual semantic FAssetData derivation/published use is still absent and is now recorded precisely as open finding D006. Preserve separate P08/P09 workflow obligations.

Single-command replay: `php catalog/bin/verify-ue427-p06-partial-acceptance.php`. Its thirteen independent scopes include native thumbnail index/payload, raw registry and dependency-bit comparisons, both real-asset negative suites, the full already-verified P05 replay, and UT4 V5 regression. A passing combined driver is **partial P06 evidence only** and must not flip the primary ledger to VERIFIED before the remaining source branches and production consumers are accepted.

**P06 remains ACTIVE; overall count stays 5/10.** No production database writes, canonical Epic source edits or unproven certification claims in this checkpoint.
