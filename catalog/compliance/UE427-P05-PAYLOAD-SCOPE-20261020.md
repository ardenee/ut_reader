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

**Historical scope of these ten policy fixtures (now complemented by the later owner/linker runtime tests):** the other eight policy-fixture cases do **not** invoke owner/linker-driven `FUntypedBulkData::Serialize` sidecar selection: their companion filename policy follows the reviewed `BulkData.cpp` branch logic in controlled fixture code. Epic `BulkDataExt` filename constants are defined in pinned `BulkData2.cpp` as `.uexp`, `.ubulk`, `.m.ubulk`, `.uptnl` but are not exported for direct linking from the isolated plugin; exact pinned-source extension literals were used. Consequently, these fixtures validate native flags, source-shaped wire widths/endian behavior and physical file contents, **not** genuine cooked optional/mapped packages or real engine loading through an owner/linker. The conditional fixup fixture validates the source-derived arithmetic but has not exercised the real Linker->Summary fixup branch. No artificial fixture can replace the missing native runtime evidence.

## Genuine UE4 UObject/FLinkerLoad native bulk runtime acceptance

The disposable `UE427Oracle` project includes `Content/UE427PayloadProbe/Cube.uasset`, copied from the installed Epic engine. The compiled commandlet loads `/Game/UE427PayloadProbe/Cube.Cube` and obtains its real `FLinkerLoad`, whose `Summary.BulkDataStartOffset` is **87,250** in this fixture. In `-P05OwnerRuntime` mode, the oracle gives a persistent native `FMemoryReader` the genuine linker's cacheable archive. It then calls **Epic's own `FUntypedBulkData::Serialize`** with the loaded UObject owner. This exercises actual editor loader attachment/cleanup, flag decoding, selected filename, optional fallback, and the conditional linker bulk-offset fixup. Each case also calls **`FUntypedBulkData::CreateStreamingRequest`** on the resulting bulk object and waits for the engine async request to read/verify 16 physical payload bytes.

- **8/8 compiled owner/linker routes passed**, zero mismatches: ordinary `.ubulk`, separate with offset fixup, optional `.uptnl`, memory-mapped-flag `.m.ubulk`, memory-mapped-flag with fixup, 64-bit size, duplicated optional preferred, and duplicated default fallback when optional absent.
- **128/128 bytes** returned by the compiled streaming-request API matched the native-written disposable sidecars. `BulkDataStartOffset=87,250` was applied to raw negative offset `-87,234` in the fixup cases, yielding physical byte offset 16; the `NoOffsetFixUp` cases stayed at 16 without addition.
- Two `.m.ubulk` cases also loaded **32/32 bytes** correctly using Epic's mapped-file platform interface `OpenMapped/MapRegion`. The selected `.m.ubulk` paths streamed correctly through the real bulk API.
- **Important platform boundary:** this Editor executable reports `FPlatformProperties::GetMemoryMappingAlignment() = 0`. A separate earlier attempt to force the `FUntypedBulkData::Serialize(bAttemptFileMapping=true)` fast path hit its compiled `IsAligned(Ptr, 0)` assertion. The successful final tests therefore do not force that unsupported pointer-mapping fast path. They verify actual mapped file regions and streaming, **not** `BulkData.MapFile` fast-path certification on a platform reporting zero mapping alignment. Do not count the earlier fatal disposable run as a passing acceptance.
- All sidecars are written **only beside the disposable Cube asset**, removed on completion, and verified absent afterward. Epic's original installed engine/asset files and canonical source remain untouched.

Native log `C:/Temp/ue427-p05-owner-stream-final.log`; replay `php catalog/bin/verify-ue427-p05-owner-runtime.php C:/Temp/ue427-p05-owner-stream-final.log` verifies all eight cases, 56 exact runtime-field expectations, two mapped-region checks, native completeness markers and error-free output. Rebuild from the versioned `ue427-native-oracle` plugin using installed UE4.27 `RunUAT BuildPlugin`, then execute `UE4Editor-Cmd.exe C:/Temp/UE427Oracle/UE427Oracle.uproject -run=UE427Oracle -P05OwnerRuntime -unattended -nullrhi -nosplash -nop4 -NoSound`.

**P05 still ACTIVE:** source-defined sidecar streaming/selection and real-linker fixups are proven for these controlled inputs. The Windows platform source excludes the forced pointer-mapping fast path (`SupportsMemoryMappedFiles=false`), which should not block Windows certification. Genuine cooked optional bulk assets remain the key uncaptured writer output; durable physical-sidecar identities/recovery are explicitly P08/P09 workflow obligations.

## Production reader physical-export addressing — genuine corpus parity

**New production reader capability:** `UE4/UnrealPackageReader.php::resolveExportPayloadSpan(int $exportIndex)` returns the source-defined logical `FObjectExport::SerialOffset/SerialSize` mapped to an actual physical `.uasset/.umap` or `.uexp` path/byte offset; does not copy payload bytes or assert bulk providers based on filenames. This makes previously metadata-only physical export ranges available to callers and prevents treating missing companions as satisfied reads.

Pinned Epic evidence: `FLinkerLoad` reads exports with package-logical serial offsets; the installed cooker emits a `.uasset/.umap` header and `.uexp` data stream, so `.uexp` offsets subtract the original base-file byte length. The resolver chooses the base file only for a wholly contained span; otherwise it demands an existing separate `.uexp` and checks physical bounds. It rejects spans crossing the file boundary, arithmetic overflow, missing/truncated sidecars, invalid indices and negative sizes. It does **not** claim that `.uexp` alone contains `FBulkData` payload bytes or that arbitrary package-container compressed chunks have the same physical layout.

Versioned independent verifier `php catalog/bin/verify-ue427-p05-export-providers.php C:/Temp/ue427-all-assets.txt C:/Temp/ue427-p05-cooked-manifest.txt`:

| Evidence | Result |
| --- | ---: |
| Genuine installed unsplit UE4.27 packages | **1,749/1,749** |
| Genuine Epic-cooked split packages | **244/244** |
| In-file resolved export byte spans | **37,552/37,552**, 21,106,030 bytes |
| `.uexp` resolved export spans | **380/380**, 63,530,600 bytes |
| Negative/valid-control cases | **8/8** |
| Physical-provider errors or mismatches | **0** |

The eight checks comprise untouched split control, missing `.uexp`, truncated `.uexp`, invalid negative/oversized export indexes, in-memory metadata crossing the base/split boundary, signed-integer arithmetic overflow, and negative serial size. Negative mutation only affects disposable copies or in-memory decoded export metadata. Both corpus denominators are fixed to installed genuine assets, not synthetic fixtures. This API is a **new exported code path**, not yet a claim that PAK ingestion/backup uses it. P08/P09 must verify durable sidecar associations independently.

## Windows memory mapping source-defined exclusion

Pinned Epic `Core/Public/Windows/WindowsPlatformProperties.h::FWindowsPlatformProperties` inherits `FGenericPlatformProperties` without overriding `SupportsMemoryMappedFiles()` or `GetMemoryMappingAlignment()`. `Core/Public/GenericPlatform/GenericPlatformProperties.h` returns **false** and **0**, respectively. This applies to Windows Editor and WindowsNoEditor/UE4Game. Consequently, merely switching to `UE4Game.exe` does **not** furnish a supported alignment-enabled `FUntypedBulkData::MapFile` pointer fast path; do not demand or claim it passes on this Windows platform. The earlier asserted force-map fixture is retained as a failed unsupported-path attempt, excluded from successful runtime evidence.

Pinned `SavePackage/SavePackageUtilities.cpp` gates mapped bulk archiving behind `TargetPlatform->SupportsFeature(ETargetPlatformFeatures::MemoryMappedFiles)`, and `BulkData.cpp` handles mapping as optional; the cooked Windows corpus has no `.m.ubulk` output. `.m.ubulk` physical sidecar routing and actual `OpenMapped/MapRegion` byte reads are already positively exercised by the controlled native loader/streaming fixtures, but that does **not** turn the platform-disabled pointer fast path into a supported requirement. A real map-enabled target platform is outside this Windows audit environment.

## Scope distinction

Genuine split **exports**, genuine separate bulk payload layout, and compiled owner/linker-selected optional/mapped/duplicate sidecar **streaming** are verified on controlled inputs. The pointer-based `BulkData.MapFile` fast path, genuine cooked optional/mapped specimens, and durable production physical-provider linkage remain separate. **P05 is ACTIVE**, pending genuine cooked optional-writer evidence; Windows-disabled pointer mapping is not an outstanding Windows test, and durable ingestion remains P08/P09.

## UnrealDB active inspection — no production change in this checkpoint

- `UE4/UnrealPackageReader.php` parses `bulkDataStartOffset` and raw export `serialOffset`/`serialSize`. It derives `uexpPath` and `hasUexp` by looking for a companion filename, but does **not** thereby prove that a serialized export or bulk payload can be opened through the correct physical provider. Do not call this a confirmed Epic incompatibility until the actual production consumer is tested.
- `catalog/src/Infrastructure/Metadata/Uedb5Ut4SnapshotBuilder.php` retains `bulk_data_start_offset`, `uexp_path` and `has_uexp` as metadata. UT4's independent source profile has different historical gates; do not silently apply stock UE4 assumptions to UT4.
- `catalog/src/Infrastructure/Jobs/CatalogBucketPakJobHandler.php::planMembers` removes `uexp`, `ubulk`, `uptnl`, `m_ubulk` from standalone accepted package extensions. `CatalogPakImportJobHandler.php` similarly marks these sidecar entries `skipped` when considered as standalone packages. This is **not** itself evidence of missing bytes: the parent PAK is retained and its members can be located by their container identity. Whether the complete sidecar family is subsequently extracted/reassociated with its `.uasset` and retained for durable V5 consumers remains **unverified P08/P09 workflow acceptance**.
- `catalog/src/Infrastructure/Metadata/Uedb5MigrationValidator.php::sourceComparableSnapshot` explicitly excludes `uexp_path` from deterministic snapshot comparisons because the path is a transient host-local locator. No equivalent durable V5 sidecar binding was established by the current P05 native tests. The metadata summary's `has_uexp` flag is not a proof of durable retrievability after workspace cleanup. Treat this as a **specific production acceptance gap**, not a proven data-loss claim or a new primary audit operation.

## Required next acceptance, not yet performed

1. **Completed for UEXP/UBULK:** generate and preserve the genuine 244-package cooked corpus, its 380 split export ranges and 123 ordinary bulk headers. Positive **native-controlled** `.uptnl` and `.m.ubulk` physical providers also pass the real owner/linker loader and streaming tests. **Still missing:** an actual **Epic-cooked `.uptnl` package specimen** rather than a native-created sidecar; Windows's disabled mapping feature rules out a normally cooked `.m.ubulk` specimen on this target.
2. **Completed for controlled positive paths:** compiled `FUntypedBulkData::Serialize` loaded 2 inline/tail null-owner cases and selected 8 real-owner/linker sidecar paths (including conditional fixup and optional duplicate fallback); `CreateStreamingRequest` returned all 128 expected sidecar bytes, and direct mapped-file regions returned another 32. **Still required for P05:** genuine cooked optional-payload writer output; mapped-pointer loading is unsupported on pinned Windows and is excluded from the Windows acceptance denominator.
3. **Covered through native controlled fixtures:** 64-bit element sizes, swapped-endian bulk headers, optional duplicate-presence/fallback and two direct mapped-region reads all passed. The 5-file corruption mutations plus genuine unchanged control and 8 export-provider control/negative scenarios pass. **Still required:** negative breadth for corrupt optional/mapped inputs in the compiled native loader; missing-sidecar workflow preservation is assigned to P08/P09.
4. Trace actual production reader/V5 consumers and ensure metadata neither invents bytes in `.uasset` nor silently drops valid sidecars. P08/P09 will separately certify ingest/backup/dependency persistence.

**No overall P05 source parity or production certification is claimed.** Genuine `.uexp`/`.ubulk` range coverage and compiled Epic owner/linker dispatch, streaming, optional/mapped file selection and fixups are established. The remaining P05 specimen gap is genuine cooked optional bulk output. Windows-disabled pointer mapping is explicitly excluded; production durable sidecar binding is deferred to P08/P09. Commit the scope record and executable corpus verifiers. No new primary operation was added, and no PRD database writes are involved.
