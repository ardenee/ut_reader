# UE4.27.2 P05 — verified export and bulk physical storage contract

**Disposition: U427-P05 VERIFIED for the pinned UE4.27.2 classic reader-format and physical payload operation on Windows**; **fixed denominator 5/10 VERIFIED**, P06 ACTIVE. This is not whole-application or whole-UE4 certification. P08/P09 retain the actual archive-ingress/backup/rebuild/V5 sidecar lifecycle. Do not count the same workflow work twice or invent new primary IDs.

## Pinned Epic source and operations

Engine pinned source `L:/Source/Engine/UE4/UE 4.27.2`, commit `d94b38ae3446da52224bedd2568c078f828b4039`; independent compiled reference `H:/Epic Games/UE_4.27/Engine/Binaries/Win64/UE4Editor-Cmd.exe` (4.27.2).

- `CoreUObject/Private/Serialization/BulkData.cpp::FUntypedBulkData::Serialize`, `NeedsOffsetFixup`, `LoadDataIntoMemory`, `CreateStreamingRequest` and `BulkDataCommon.h`: inline vs out-of-line, signed counts/offsets, 32/64-bit size, optional/duplicate routing, mapping, and `BulkDataStartOffset` added **only when the serialized flags require fixup and a real owner/linker exists**.
- `CoreUObject/Private/UObject/SavePackage/SavePackageUtilities.cpp`: cooker appends bulk bytes, rewrites the offset, optionally aligns mapped storage, places optional mips into `.uptnl`, normal bulk into `.ubulk`, and mapped bulk into `.m.ubulk` if the target platform enables mapping.
- `Engine/Private/TextureDerivedData.cpp` and `TextureLODSettings.cpp::CalculateNumOptionalMips`: real optional-mip cooking is controlled by target LOD settings. The isolated project fixture profile sets `TEXTUREGROUP_World` `OptionalMaxLODSize=128`; see versioned `ue427-native-oracle/fixtures/P05-Optional-DefaultDeviceProfiles.ini`. The generator `UE427OracleP05Cook.h` creates a disposable 2,048² `UTexture2D` with Epic's `FTextureSource` and saves it via Epic `UPackage::SavePackage`. UE4.27's real `-run=Cook -TargetPlatform=WindowsNoEditor -CookAll` creates its `.uasset/.uexp/.ubulk/.uptnl` family.
- Windows `WindowsPlatformProperties.h` inherits `GenericPlatformProperties.h` without overriding `SupportsMemoryMappedFiles() = false` or `GetMemoryMappingAlignment() = 0`. Therefore, the forced pointer-alignment-dependent `BulkData.MapFile` fast path is **not a supported Windows platform branch** and cannot be certified by switching to Windows `UE4Game.exe`. The compiled native test positively verifies `.m.ubulk` routing, streaming and direct `OpenMapped/MapRegion` byte reads; it does not misrepresent the earlier unsupported force-mapping assertion as a pass.

## Actual differences, corrections and limits

**U427-P05-D001 — format reader physical-export capability missing; corrected.** UnrealDB previously exposed Epic's `SerialOffset/SerialSize` and guessed `uexpPath` but had no checked physical export-provider operation. `UE4/UnrealPackageReader.php::resolveExportPayloadSpan(int)` now returns package-logical offset, length, real provider path, selected base/`.uexp` storage, and physical byte offset. It rejects absent/truncated split files, cross-file spans, negative/overflowing offsets and invalid indices. It reads no payload itself, imposes no invented archive size ceiling, and does not conflate export data with bulk flags. This was a **reader capability gap**, not evidence that older header decoding disagreed with Epic.

**Other inspected branches — no demonstrated UnrealDB decoder discrepancy.** The optional, normal, duplicated, 64-bit width, byte-swapped and conditional-fixup branches were demonstrated using compiled Epic serialization and byte-perfect physical specimens. No ungrounded PHP simulation was substituted for the native loader. The production reader is not currently a complete texture/property/FBulkData payload interpreter, and the audit does not pretend to certify one that is not present or used.

**P08/P09 handoff, not P05:** PAK handlers exclude `.uexp/.ubulk/.uptnl/.m.ubulk` as *standalone packages* while retaining parent container identities; durable association/extraction/retrieval after staging cleanup is not yet accepted. `Uedb5MigrationValidator` excludes transient `uexp_path` from normalized snapshots. These are concrete caller acceptance questions for the already fixed P08 and P09 operations, not new numbered findings or proof of data loss. P05 independently proves the physical format that those later consumers must preserve.

## Reproducible positive evidence

| Acceptance | Independent result |
| --- | ---: |
| Unsplit installed genuine UE4 corpus | **1,749 packages / 37,552 valid in-file export spans / 21,106,030 bytes** |
| Initial default cook (historical baseline, before optional-mip profile) | **244 genuine v522 packages**, 380 `.uexp` spans / 63,530,600 bytes, 38 `.ubulk` files / 123 native headers / 43,916,288 bytes; all matched Epic |
| Latest isolated **optional-mip** UE4 cooker output | **245 genuine v522 packages**, 381 `.uexp` spans / 63,533,978 bytes, 37 `.ubulk` + 32 `.uptnl` files; zero mismatches |
| Native `FObjectImport/FObjectExport` fields (latest cooked) | **1,418 imports / 381 exports / 23,923 fields**, zero mismatches |
| Native summary header fields (latest cooked) | **735/735**, zero mismatches |
| Genuine default bulk (latest cooked) | **37/37 files, 41/41 native-decoded headers, 2,211,840 bytes**, exact full-file coverage |
| Genuine optional bulk (latest cooked) | **32/32 files, 87/87 native-decoded headers, 44,497,920 bytes**, exact full-file coverage |
| Actual optional serialized flag | **0x10D01**, `PayloadAtEndOfFile | PayloadInSeperateFile | Force_NOT_InlinePayload | OptionalPayload | NoOffsetFixUp` |
| Compiled `FUntypedBulkData::Serialize` inline/tail cases | **2/2** native payload reads |
| Compiled owner/linker storage selection and async streaming | **8/8 routes, 128/128 bytes**, including optional/duplicate, fixup and 64-bit wire width |
| Separate native mapped-file region reads | **2/2, 32/32 bytes**; not forced pointer fast-path claims |
| Native policy/endian/width fixture cases | **10/10**, 148 fields and 160 bytes |
| Real-files and decoded-export negative/control suites | **6/6 default bulk, 6/6 optional bulk, 8/8 export addressing** |
| UT4 independently pinned v511 V5 persistence regression | **Passed** |
| Combined executable P05 source/physical acceptance | **12/12** operations, zero failures |

The **initial 244** and **latest 245** cooked datasets are separate proven configurations, not contradictory totals; the project-local optional-mip cook changes which bytes remain in normal `.ubulk` versus optional `.uptnl`. The 1,749 genuine editor assets are a third, unchanged corpus. No claim is made that the editor corpus itself contains split payload companions.

### Single-command acceptance and recovery

From DEV `C:/Users/arden/source/repos/ut_reader`:

`php catalog/bin/verify-ue427-p05-acceptance.php`

The 12 executable gates rerun original/optional cooked provider checks, native records/summary/default/optional decoders, corruption tests, controlled source-policy and genuine owner/linker runtime evidence, and UT4 regression. Temporary native logs and generated assets are retained in `C:/Temp/UE427Oracle` and `C:/Temp/ue427-p05-*.log` on DEV; **source, optional fixture configuration, generator, and every verifier are committed**. Rebuild with installed `H:/Epic Games/UE_4.27/Engine/Build/BatchFiles/RunUAT.bat BuildPlugin`. The oracle's `-P05OptionalTextureDir` generates the test asset; copy `P05-Optional-DefaultDeviceProfiles.ini` to the isolated project's `Config/DefaultDeviceProfiles.ini`, then execute `UE4Editor-Cmd.exe <project> -run=Cook -TargetPlatform=WindowsNoEditor -CookAll -unattended -nullrhi`. Generate the sorted current manifest of `.uasset/.umap` files and run `-run=UE427Oracle -List=<manifest> -P05 -P05Bulk -P05Optional` and `-Names -Records` to recreate native logs. Do not modify Epic's installed canonical engine source or files.

### Evidence integrity

SHA-256, representative source and native artifacts:

| File | SHA-256 |
| --- | --- |
| `BulkData.cpp` | `5687b8d5a56367cf8f8c2c5cbc2d072e5ce270e847ee9b21c352874c830846d1` |
| `SavePackageUtilities.cpp` | `d908f953b07e687e13a06f5ce96100815e27aee5181145ca2478b21d65261403` |
| `TextureDerivedData.cpp` | `ee3a532c221c34eef4ee5db69a1ca70513fef825de91e4853201203d92f0b67e` |
| `WindowsPlatformProperties.h` | `2e8b94233856b95bccd24fcf09de86ad42497482bc4b7356edb528f8bfe834a8` |
| `GenericPlatformProperties.h` | `549be2b1b4c20cb097ce1647f2d76c4ca24cd9ac3f8d118f490d68b7e385a8f0` |
| Installed `UE4Editor-Cmd.exe` | `af4c2b6ce5119553f23f73a7898dec3e341b8f6e7e1c59c7fa6faa6f61590e65` |
| Native latest-cook bulk/summary log | `ec13d37e6e24db5fe1fef1d5cfa71561036d3f691b2699243251ddcf0c6774df` |
| Native latest-cook import/export log | `f59477b0d0373c3c275a5ade9e888789e1670d2c4848ec52262675ec225d8031` |
| Cooker-produced `OptionalTexture.uptnl` | `18e11b01d65cb894f2904f4c90f7f25a12be8f27ee611ccbad8264a6f933340b` |

P05 is closed for its **tested and source-defined UE4.27.2 Windows classic package-format operation**, not future engines, unsupported Windows memory-map fast path, IoStore Zen packages, or the application-wide P08/P09 lifecycle. Advancing the primary ledger to P06 does not close or conceal those separate obligations. No PRD database writes were involved.
