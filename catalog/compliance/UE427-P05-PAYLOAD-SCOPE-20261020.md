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

## UnrealDB active inspection — no production change in this checkpoint

- `UE4/UnrealPackageReader.php` parses `bulkDataStartOffset` and raw export `serialOffset`/`serialSize`. It derives `uexpPath` and `hasUexp` by looking for a companion filename, but does **not** thereby prove that a serialized export or bulk payload can be opened through the correct physical provider. Do not call this a confirmed Epic incompatibility until the actual production consumer is tested.
- `catalog/src/Infrastructure/Metadata/Uedb5Ut4SnapshotBuilder.php` retains `bulk_data_start_offset`, `uexp_path` and `has_uexp` as metadata. UT4's independent source profile has different historical gates; do not silently apply stock UE4 assumptions to UT4.
- `catalog/src/Infrastructure/Jobs/CatalogBucketPakJobHandler.php` and `CatalogPakImportJobHandler.php` recognize sidecar extension families. These are possible P08/P09 integration boundaries, but P05 must first establish native physical-payload truth.

## Required next acceptance, not yet performed

1. Obtain controlled, **genuine cooked UE4.27** package sets with `.uasset/.uexp/.ubulk` and optional `.uptnl/.m.ubulk` when possible, or produce precise native Epic serializer fixtures for branches that cannot be cooked reliably.
2. Instrument compiled UE4.27 `FBulkData` serialization / real engine archive selection (or a targeted native probe) and compare bulk flags, raw offsets, conditional fixup, sidecar target and actual bytes/hashes to UnrealDB's physical-provider decisions. Must positively test inline, end-of-file, separate, optional and memory-mapped branches, with correct non-availability treatment.
3. Test missing/truncated sidecar negatives, swapped-endian header words where supported, offset overflow and package-source physical-boundary assertions without changing the canonical engine.
4. Trace actual production reader/V5 consumers and ensure metadata neither invents bytes in `.uasset` nor silently drops valid sidecars. P08/P09 will separately certify ingest/backup/dependency persistence.

**No P05 source parity or production certification is claimed.** Commit only this scope record and executable inventory. No new primary operation was added, and no PRD database writes are involved.
