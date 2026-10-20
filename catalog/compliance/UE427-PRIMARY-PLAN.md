# UE4.27.2 fixed primary source-compliance plan

Pinned Epic source: `L:/Source/Engine/UE4/UE 4.27.2` at `d94b38ae3446da52224bedd2568c078f828b4039`. Production UnrealDB reader `UE4/UnrealPackageReader.php`; production callers and V5 integration must be compared against this source. **Do not reopen closed UE5.8.3 audits or conflate UT4's own v511 clean-master source with UE4.27.2.**

## Ordered primary list (fixed denominator: 10)

| ID | Epic-to-UnrealDB operation | Status |
|---|---|---|
| U427-P01 | Package tag, endian, legacy-summary marker, custom versions and package file-version policy | VERIFIED |
| U427-P02 | Name map, FName wire representations and serialized string handling | VERIFIED |
| U427-P03 | Import/export index, object flags, serialized offsets and table extents | VERIFIED |
| U427-P04 | DependsMap, preload dependency blocks, soft/string asset references | VERIFIED |
| U427-P05 | Bulk payload offsets, separate UEXP/UBULK and optional payloads | VERIFIED |
| U427-P06 | AssetRegistry data, thumbnails, world/chunk IDs, serialization conditions | VERIFIED |
| U427-P07 | FLinkerLoad VerifyImportInner, redirects and optional/failed provider resolution | ACTIVE |
| U427-P08 | Active production ingress (upload, archive, file add, profile dispatch) | OPEN |
| U427-P09 | Retain/backup/restore, resync, repair, V5 persistence and dependency projection | OPEN |
| U427-P10 | Search, validator, negative cases and independent Epic-native differential/certification | OPEN |

Process: handle primary ID in order; compare all relevant Epic source-defined branches and active UnrealDB production callers; identify **actual** differences, correct code, run focused acceptance, document source and after-effects, commit on DEV and deploy PRD. Any discovered issue outside active ID goes in **secondary** list, not a new primary or diversion. Mark VERIFIED only with independent Epic-source/native evidence and the relevant production caller acceptance. Preserve genuine unknowns explicitly. Do not broaden beyond the 10 headings or invent additional numbered audit batches.

## Evidence and source boundaries

Existing source audit resumption: `UE427-AUDIT-RESUMPTION-20261018.md`; existing specs `specs/ue4-4.27.2-package-format.md`, `specs/ue4-4.27.2-dependency-resolution.md`, and `specs/ue4-4.27.2-verifyimport-conformance-audit.md`. Historical V4-only diagnostic scripts retired in `ac5e4a8d` are **not** UE4.27.2 parity evidence. Main target is UnrealDB's source-aligned UE4 reader and its active V5 consumers. Test against isolated DB and real assets where possible; do not write PRD DB during acceptance. Current tally **6/10 VERIFIED**: P01 `UE427-P01-DEPRECATED-GUID-CLOSURE-20261020.md`; P02 `UE427-P02-FNAME-FSTRING-CLOSURE-20261020.md`; P03 `UE427-P03-NATIVE-RECORD-CLOSURE-20261020.md`; P04 `UE427-P04-NATIVE-DEPENDENCY-CLOSURE-20261020.md`; P05 `UE427-P05-NATIVE-SPLIT-OPTIONAL-CLOSURE-20261020.md`; P06 `UE427-P06-NATIVE-ASSETREGISTRY-CLOSURE-20261020.md`. **P07 ACTIVE.** P06 strict verification passes **17/17 operations** and **13 pinned source/production contract assertions**, with 1,749 native installed packages, 245 native-cooked packages, 1,730 compiled FAssetData records, 11,442 searchable tags, 1,729 thumbnail payloads, dependency flags, and native WorldTile/chunk fixtures; no mismatches. This certifies source-defined classic reader and bounded FAssetData semantic output, **not** additional production workflows. Static inventory found 0 direct calls to the seven new P06 accessors across 816 production PHP files, so no active caller requires changing the P06 read-only source contracts. P08/P09 remain responsible for ingress, retained sidecars, backup/restore, repair and durable V5 projection. P10 remains responsible for application search and validators, including deciding whether these source-derived fields are ever published. Unsupported Windows memory mapping and untested localized/typed AssetBundleData/thumbnail-pixel/historical WorldTile variants remain explicitly noncertified; do not invent new primary IDs. Whole UnrealDB UE4 compliance is **not** certified by completion of six operations.
