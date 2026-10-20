# UE4.27.2 P05 — split payload and bulk-data source-scope checkpoint

**Status: U427-P05 ACTIVE, NOT VERIFIED.** The fixed denominator remains **4/10 verified**. This is a bounded first source/corpus inventory only, not split-file parity acceptance. P01–P04 remain closed.

## Source rules to test

Pinned Epic source checkout `L:/Source/Engine/UE4/UE 4.27.2`, revision `d94b38ae3446da52224bedd2568c078f828b4039`.

- `CoreUObject/Private/Serialization/BulkData.cpp::SerializeBulkData`: inline payload iff `BULKDATA_PayloadAtEndOfFile` is not set; non-inline loading can add `Linker->Summary.BulkDataStartOffset` when `NeedsOffsetFixup()`. Actual adjustment is conditional, not unconditional for all bulk headers.
- `BulkData.cpp` separate-file routing: `BULKDATA_OptionalPayload` chooses the optional `.uptnl`; `BULKDATA_PayloadInSeperateFile` chooses default `.ubulk` or memory-mapped `.m.ubulk`, as appropriate; `BULKDATA_DuplicateNonOptionalPayload` also has an optional duplicate branch.
- For event-driven loading when an archive still references the `.uasset`/`.umap`, the streaming path may redirect into `.uexp` with an offset adjusted by the header file length. `BulkData.cpp` warns that such streaming belongs in `.ubulk` for best performance.
- IoDispatcher/IoStore has a separate flag/chunk identity path and must not be confused with legacy sidecar files. P05 must distinguish inline export bytes, out-of-line bulk bytes, optional and memory-mapped bytes and logical linker's combined offsets.

## Independent installed corpus limitation

Reproducible `php catalog/bin/verify-ue427-p05-payload-inventory.php C:/Temp/ue427-all-assets.txt` (verified 20 October 2026) inventories all authentic installed `H:/Epic Games/UE_4.27/Engine/Content` packages without modifying them:

| Scope | Grounded count |
| --- | ---: |
| Genuine package files | **1,749** |
| Source export spans wholly within their base files | **37,552** |
| Export spans exceeding base file size | **0** |
| Adjacent `.uexp` | **0** |
| Adjacent `.ubulk` | **0** |
| Adjacent `.uptnl` | **0** |
| Adjacent `.m.ubulk` | **0** |
| Files with positive `BulkDataStartOffset` field | **1,749** |
| Reader issues during inventory | **0** |

A positive `BulkDataStartOffset` field is **not** evidence that any payload is actually stored out-of-line. This corpus is unsuitable for independently certifying companion-file selection, offset fixups, optional payloads or cooked exporter behavior.

## Additional genuine UE4.27 cooked split-package acceptance

An isolated disposable project `C:/Temp/UE427Oracle/UE427Oracle.uproject` was cooked by the installed authentic UE4.27 editor, using an asset copied from Engine/Content into `Content/UE427PayloadProbe/Cube.uasset` (no Epic canonical source/assets modified). Executed `UE4Editor-Cmd.exe UE427Oracle.uproject -run=Cook -TargetPlatform=WindowsNoEditor -CookAll -unattended -nop4 -NoSound -nullrhi`. Cook exited 0 with zero engine errors; temporary output `Saved/Cooked/WindowsNoEditor`.

- **244** stock-v522 cooked packages, **244 .uasset/.umap + 244 matching .uexp** files, **38 .ubulk** companions totalling **43,916,288 bytes**. No optional `.uptnl` or memory-mapped `.m.ubulk` sidecar appeared.
- Native `UE427Oracle -Names -Records` imported all 244 cooked headers through compiled Epic `FObjectImport/FObjectExport` operators and independently matched UnrealDB across **1,415 imports, 380 exports and 23,866 record fields**, zero mismatches. An additional native `-P05` mode reads `FPackageFileSummary::TotalHeaderSize`, `BulkDataStartOffset` and `ExportCount`: `php catalog/bin/verify-ue427-p05-native-header.php C:/Temp/ue427-p05-native-summary.log C:/Temp/ue427-p05-cooked-manifest.txt` passed **732/732 Epic-native header fields**, zero mismatches. Native log `C:/Temp/ue427-p05-cooked-native-records.log`, manifest `C:/Temp/ue427-p05-cooked-manifest.txt`, verifier `catalog/bin/verify-ue427-native-records.php`.
- Versioned `php catalog/bin/verify-ue427-p05-cooked-split.php` checked actual physical export reads from `.uexp` using `SerialOffset - UAssetFileSize`: **380/380** ranges, totalling **63,530,600 bytes**, no invalid spans or short reads. Aggregate SHA-256 of individual physical-span digests `d2a3d33d074976adf7cc237287b3eeb822fedf374ada8295b225e178ea318b6e`.
- All **244** `.uexp` files terminate with Epic's little-endian package-tag bytes `C1 83 2A 9E`. All 244 `Summary.TotalHeaderSize` values equal their corresponding `.uasset` size, and all `BulkDataStartOffset` values equal **UAssetFileSize + UExpFileSize - 4** (excluding the .uexp trailer tag).
- The original 1,749 editor assets remain the separate unsplit reference corpus; do not add these 244 cooked assets to the established P01–P04 denominators.

**Scope of this positive test:** genuine split **export** physical offset parity is independently verified. Presence/size of 38 `.ubulk` companions does *not* certify their per-object `FBulkData` flags, payload-offset fixups or optional storage decisions; P05 is still ACTIVE.

## UnrealDB active inspection — no production change in this checkpoint

- `UE4/UnrealPackageReader.php` parses `bulkDataStartOffset` and raw export `serialOffset`/`serialSize`. It derives `uexpPath` and `hasUexp` by looking for a companion filename, but does **not** thereby prove that a serialized export or bulk payload can be opened through the correct physical provider. Do not call this a confirmed Epic incompatibility until the actual production consumer is tested.
- `catalog/src/Infrastructure/Metadata/Uedb5Ut4SnapshotBuilder.php` retains `bulk_data_start_offset`, `uexp_path` and `has_uexp` as metadata. UT4's independent source profile has different historical gates; do not silently apply stock UE4 assumptions to UT4.
- `catalog/src/Infrastructure/Jobs/CatalogBucketPakJobHandler.php` and `CatalogPakImportJobHandler.php` recognize sidecar extension families. These are possible P08/P09 integration boundaries, but P05 must first establish native physical-payload truth.

## Required next acceptance, not yet performed

1. **Completed for UEXP/UBULK:** generate and preserve the genuine 244-package cooked corpus and verify its 380 split-export ranges. **Still missing:** positive optional `.uptnl` and memory-mapped `.m.ubulk` fixtures, which may require targeted Epic-native generation.
2. Instrument compiled UE4.27 `FBulkData` serialization / real engine archive selection (or a targeted native probe) and compare bulk flags, raw offsets, conditional fixup, sidecar target and actual bytes/hashes to UnrealDB's physical-provider decisions. Must positively test inline, end-of-file, separate, optional and memory-mapped branches, with correct non-availability treatment.
3. Test missing/truncated sidecar negatives, swapped-endian header words where supported, offset overflow and package-source physical-boundary assertions without changing the canonical engine.
4. Trace actual production reader/V5 consumers and ensure metadata neither invents bytes in `.uasset` nor silently drops valid sidecars. P08/P09 will separately certify ingest/backup/dependency persistence.

**No overall P05 source parity or production certification is claimed.** Genuine `.uexp` physical-range coverage is established; `.ubulk` header/flag and optional/memory-mapped routing remain unverified. Commit the scope record and executable corpus verifiers. No new primary operation was added, and no PRD database writes are involved.
