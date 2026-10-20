# UE4.27.2 P06 — AssetRegistry, thumbnail and WorldTile source-format closure

**Primary disposition: U427-P06 VERIFIED for the pinned Windows UE4.27.2 classic package reader and the explicitly tested `FAssetData` semantic view.** The original fixed denominator advances to **6/10 VERIFIED**, with **P07 ACTIVE**. This closure is not an application-wide claim about search indexing, archive lifecycle, other engine versions or all historical world/locale variants.

## Pinned reference and concrete gaps

Epic source revision `d94b38ae3446da52224bedd2568c078f828b4039`, local `L:/Source/Engine/UE4/UE 4.27.2`, runtime `H:/Epic Games/UE_4.27/Engine/Binaries/Win64/UE4Editor-Cmd.exe`.

| Finding | Original UnrealDB difference | Correction and evidence |
| --- | --- | --- |
| D001 — thumbnail index | Only summary table offset, not class/path/image offset records | `UnrealPackageReader4::getThumbnailIndex()`; 1,729 native entries / 6,916 fields, zero mismatch |
| D002 — AssetRegistry main data | AssetRegistry objects and raw tags inaccessible beyond section offset | `getAssetRegistryObjects()`; 1,730 records and 12,058 raw tags in 1,749 installed packages, native matched; cooker 245-package filtered section accepted |
| D003 — dependency bit arrays | Serialized `TBitArray` usage fields inaccessible | `getAssetRegistryDependencyBits()`; 35 new-format sections, 598 import and 9 soft bits, 747 field/bit checks; source count checks and eight malformed-control cases |
| D004 — world and chunk | Raw WorldTileInfo offset but no decoded record; array/single chunk conditional gaps | `getWorldTileInfo()`; native serializer/loader for two endian variants plus original version-288 single-chunk source mutation; 7 IDs / 38 fields / 11 negative/positive checks |
| D005 — thumbnail payload | Index only, no image dimensions/compressed payload accessor | Lazy `getThumbnailPayload()`; compiled Epic `FObjectThumbnail::Serialize`, 1,729 images, 10,374 fields, 84,244,023 bytes SHA-1 checked, eight negative/control cases |
| D006 — derived FAssetData | No source-shaped asset identity, map fallback, tag or version filtering | `getAssetRegistryAssetData($longPackageName)` with mandatory logical mount identity. Full 1,749-package differential and three source-authored controlled fixtures, detailed below |

## D006 — independently verified semantic and source-gate corrections

The decoder retains serialized AssetRegistry strings and wire flags through existing raw getters. The **separate read-only semantic API** mirrors `AssetRegistry/Private/PackageReader.cpp::ReadPackageDataMain` predicates and uses the caller's Unreal mount path. It constructs package/object/asset names, flags and chunk IDs, synthesizes a `World` record when the source requires it, removes unused pre-public map assets, rejects illegal relative subobjects and pre-v520 external references, filters empty tag keys/values, and derives searchable tag values. Never infer `/Engine` or `/Game` from a staged physical filename.

Two initial off-by-one gates were corrected using **actual compiled constants**, not an inferred enumeration count: `VER_UE4_PUBLIC_WORLDS=337` and `VER_UE4_NON_OUTER_PACKAGE_IMPORT=520`. `CoreUObject/Private/UObject/PackageFileSummary.cpp` clears transient `PKG_NewlyCreated (0x1)` on Editor loading; only the **derived** effective `FAssetData` flags are masked, leaving `getHeader()['packageFlags']` as the serialized truth. `CoreUObject/Private/AssetRegistry/AssetDataTagMap.cpp::LocalizeIfComplexString` converts `NSLOCTEXT` values to English display strings through `FAssetTagValueRef::AsString`; the semantic view resolves these source strings and separately retains `rawValue`. In the installed corpus the corrections affect **37 assets / 38 complex tag values** (`Title`, `SearchTags`).

Native oracle `UE427OracleP06AssetSemantic.h` uses **the compiled `FAssetData` constructors and tag-map implementation**. The filtering control flow is independently mirrored from Epic's pinned **private, non-exported** `ReadPackageDataMain`; the test must **not** be described as direct execution of that private method. Independent verifier `php catalog/bin/verify-ue427-p06-native-assetdata.php`:

| Full original installed reference | Result |
| --- | ---: |
| UE4.27 packages | 1,749 / 1,749 |
| Native constructed `FAssetData` | 1,730 / 1,730 |
| Semantic identity, names, flags, class, row fields | 15,570 |
| Native `TagsAndValues` vs semantic PHP searchable tags | 11,442 |
| Correctly filtered empty raw key/value tags | 616 |
| Native mismatches | **0** |

**Controlled positive branch fixtures:** `UE427OracleP06AssetSemantic.h::UE427MakeP06SemanticFixtures` writes three 4,096-byte native UE4 version-522 packages into `C:/Temp/UE427Oracle/Saved/UE427AssetFixtures`. They are deliberately **not** under the Editor's automatically scanned `Content` directory. Fixtures verify a synthetic missing World row, actual map records including cross-package external object path, localization/empty-tag filtering and a rejected relative dotted subobject: 3 native built semantic rows / 27 compared fields / 2 filtered tags / 1 synthesized World, zero mismatches. Native execution exits 0. `php catalog/bin/verify-ue427-p06-assetdata-negatives.php` passes 16/16 cases, including the exact 336/337 and 519/520 branch decisions, valid/invalid package mount names and Editor flag masking.

### Operation-level acceptance

Run on DEV: `php catalog/bin/verify-ue427-p06-acceptance.php`.

**17/17 acceptance gates pass**, and the strict closure driver verifies **13 pinned source/production contracts**. It replays original thumbnail, registry raw, dependency bit arrays, thumbnail compressed bytes, current WorldTile/chunk IDs and their negative cases, native FAssetData on the real corpus and controlled semantic fixture, FAssetData branch negatives, the actual production caller inventory, full P05 native acceptance and UT4 clean-master v511 persistence regression. The full genuine 1,749-package reader corpus also passes with the new APIs. The native logs and fixtures live on DEV in `C:/Temp/ue427-p06-*.log` and `C:/Temp/UE427Oracle/Saved/UE427AssetFixtures`; the generator/oracle and all acceptance scripts are versioned in the repository.

## Precisely bounded nonclaims and required P08/P09/P10 handoffs

- Static source inventory: **816 production PHP files, zero literal calls to all seven P06 getters**. No evidence of active production publication from these new read-only accessors. This does not prove a negative for reflection or external systems.
- P08/P09 continue to own package-container members/physical sidecar durability, backups, resynchronization, V5 dependency and metadata projections. **Passing P06 does not certify those workflows.**
- P10 search/validator acceptance must decide and test which derived FAssetData values (if any) are actually published and queried by the application. Existing UT4 v511 V5 snapshots do **not** automatically become stock UE4.27.2 FAssetData snapshots.
- Typed `AssetBundleData` parsing has no genuine positive input in the retained reference set; its raw string is recorded, but the semantic PHP view does not claim Epic's typed `FAssetBundleData` struct. Localization checks are source-English; non-English localization manager output is **not** certified.
- Actual compressed thumbnail bytes are verified; decompression into pixels or automatic Editor ThumbnailMap policy is neither used nor certified. The two current v522 world variants and the version-288 chunk case do not prove every historical world-tile layout. Windows memory-map fast path exclusions belong to P05, not a new P06 obligation.

**P06 closure means the audited physical classic reader and explicitly exposed source-defined semantic operations have passed all 17 retained gates; remaining production workflow obligations retain their original fixed P08/P09/P10 identities.** No canonical Epic files or PRD databases were modified for this operation.
