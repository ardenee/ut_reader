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

## Genuine cooked UBULK native-header and physical-span acceptance

The extended native oracle `UE427OracleP05.h`, invoked with `-P05Bulk` against the same 244-file cooked manifest, now reads candidate serialized bulk records from the real `.uexp` files using **Epic's compiled** `FMemoryReader` and `EBulkDataFlags::operator<<`, signed 32-bit element counts/sizes and signed 64-bit offsets. Candidate detection is deliberately restricted to the exact real-corpus observed flag value `0x10501` and positive extents; it is **not** a general-purpose bulk-header locator. The corresponding source flags from `BulkDataCommon.h` are `PayloadAtEndOfFile (0x1)`, `PayloadInSeperateFile (0x100)`, `Force_NOT_InlinePayload (0x400)` and `NoOffsetFixUp (0x10000)`. Therefore, the 64-bit wire offsets point directly into the real `.ubulk` without a `BulkDataStartOffset` adjustment.

`php catalog/bin/verify-ue427-p05-native-bulk-headers.php C:/Temp/ue427-p05-native-bulk.log C:/Temp/ue427-p05-cooked-manifest.txt` independently re-reads each wire header and the referenced physical `.ubulk` bytes, checking equality with native decoded flags, counts, sizes, offsets, no overlapping spans and exact whole-file coverage:

- **244/244** native package inventory rows; **38/38** real `.ubulk` files completely covered by **123/123** native-decoded bulk headers.
- **43,916,288 bytes** physically read and hashed, all ranges contiguous, no mismatches. Reproducible aggregate SHA-256 of per-span digests: `c422bd963f3905b097c375eed491626c42fe31690f9b58deac0e406f2702a26d`.
- Six disposable-copy tests through `verify-ue427-p05-bulk-negatives.php`: genuine untouched case accepted; one-byte truncation, missing `.ubulk`, mutated header flags, out-of-file bulk offset and missing `.uexp` all rejected. Original Epic-cooked files remain unchanged.

**Scope:** this is authentic, strong positive proof of the **separate-file, no-offset-fixup, 32-bit-size** format that occurs in the generated corpus. It is not proof of all possible `FBulkData::Serialize` branches. The source may take distinct paths for Size64Bit, NoOffsetFixUp absent, inline data, duplicate optional payloads, `.uptnl`, `.m.ubulk` and IoStore. The native scanner must not be used to certify those unobserved formats.

## Controlled Epic-native flag/width/storage-policy fixtures (not cooked)

An additional **ten-case** fixture generator in `UE427OracleP05Policy.h` runs with `-P05PolicyDir=C:/Temp/ue427-p05-policy-fixtures` in the compiled UE4.27 Editor. It tests `FByteBulkData::SetBulkDataFlags`, `IsInlined`, `IsInSeparateFile` and `IsOptional` directly, then writes source-shaped persistent metadata with Epic `FMemoryWriter` (32-bit and 64-bit count/size, signed 64-bit offset, swapped archive header in one case) and deterministic byte payloads. The cases cover inline, same-archive end-of-file, separate/no-fixup, conditional-fixup, optional, mapped, 64-bit width, duplicate-optional, duplicate-default fallback and swapped-endian separate. `php catalog/bin/verify-ue427-p05-native-policy-fixtures.php C:/Temp/ue427-p05-policy-fixtures C:/Temp/ue427-p05-policy-native.log` passes **10/10 cases, 148 source/fixture fields and 160 payload bytes** with zero mismatches.

In **two** controlled cases (inline and same-archive non-separate end-of-file), the oracle additionally calls **Epic's compiled `FUntypedBulkData::Serialize` itself**, with a persistent `FMemoryReader` and null owner. Both correctly read 16 bytes (sum 136) with their original flags and offsets (20 and 48). These are actual engine runtime serializer acceptances, not simply repeated conditions in PHP.

**Explicit limit:** the other eight cases do **not** invoke owner/linker-driven `FUntypedBulkData::Serialize` sidecar selection: their companion filename policy follows the reviewed `BulkData.cpp` branch logic in controlled fixture code. Epic `BulkDataExt` filename constants are defined in pinned `BulkData2.cpp` as `.uexp`, `.ubulk`, `.m.ubulk`, `.uptnl` but are not exported for direct linking from the isolated plugin; exact pinned-source extension literals were used. Consequently, these fixtures validate native flags, source-shaped wire widths/endian behavior and physical file contents, **not** genuine cooked optional/mapped packages or real engine loading through an owner/linker. The conditional fixup fixture validates the source-derived arithmetic but has not exercised the real Linker->Summary fixup branch. No artificial fixture can replace the missing native runtime evidence.

## Scope distinction

Genuine split **exports** and the observed genuine separate **bulk** file layout are now verified. Optional/memory-mapped/duplicate payloads and runtime owner/linker filename selection still need distinct acceptance; **P05 remains ACTIVE**.

## UnrealDB active inspection — no production change in this checkpoint

- `UE4/UnrealPackageReader.php` parses `bulkDataStartOffset` and raw export `serialOffset`/`serialSize`. It derives `uexpPath` and `hasUexp` by looking for a companion filename, but does **not** thereby prove that a serialized export or bulk payload can be opened through the correct physical provider. Do not call this a confirmed Epic incompatibility until the actual production consumer is tested.
- `catalog/src/Infrastructure/Metadata/Uedb5Ut4SnapshotBuilder.php` retains `bulk_data_start_offset`, `uexp_path` and `has_uexp` as metadata. UT4's independent source profile has different historical gates; do not silently apply stock UE4 assumptions to UT4.
- `catalog/src/Infrastructure/Jobs/CatalogBucketPakJobHandler.php` and `CatalogPakImportJobHandler.php` recognize sidecar extension families. These are possible P08/P09 integration boundaries, but P05 must first establish native physical-payload truth.

## Required next acceptance, not yet performed

1. **Completed for UEXP/UBULK:** generate and preserve the genuine 244-package cooked corpus and verify its 380 split-export ranges. **Still missing:** positive optional `.uptnl` and memory-mapped `.m.ubulk` fixtures, which may require targeted Epic-native generation.
2. **Partly completed:** the native header reader and PHP physical verifier accept all 123 genuine separate/no-fixup bulk headers and their bytes. **Still required:** compiled `FBulkData::Serialize` owner/linker-driven path decisions, conditional offset-fixup (when `NoOffsetFixUp` is absent), actual optional/duplicate and memory-mapped path selection, plus inline/end-of-file variants.
3. **Completed for existing genuine format:** five negative mutations (missing/truncated sidecars, flags and bulk offset) are rejected with a genuine unchanged positive control. **Still required:** swapped-endian bulk headers, 64-bit element sizes, optional absence/fallback and complete source-defined boundary handling.
4. Trace actual production reader/V5 consumers and ensure metadata neither invents bytes in `.uasset` nor silently drops valid sidecars. P08/P09 will separately certify ingest/backup/dependency persistence.

**No overall P05 source parity or production certification is claimed.** Genuine `.uexp` and existing `.ubulk` separate/no-fixup range coverage are established; optional/memory-mapped routing, other flag variants and actual loader owner/linker behavior remain unverified. Commit the scope record and executable corpus verifiers. No new primary operation was added, and no PRD database writes are involved.
