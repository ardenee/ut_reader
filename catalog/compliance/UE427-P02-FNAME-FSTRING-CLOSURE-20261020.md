# UE4.27.2 P02 — source and native name/FName/FString closure (20 October 2026)

**Disposition: U427-P02 VERIFIED.** The source-defined binary name-map, FName index/number, instance display, archive FString and endian layout checks now have independent compiled Epic 4.27.2 evidence and targeted current reader acceptance. Fixed audit denominator: **2/10 operations verified**. P03 import/export *full record* semantics and P08–P10 production flows remain open.

## Pinned authority and caller boundary

- Epic UE4.27.2 at `L:/Source/Engine/UE4/UE 4.27.2`, revision `d94b38ae3446da52224bedd2568c078f828b4039`.
- `LinkerLoad.cpp::SerializeNameMap`; `Core/Private/UObject/UnrealNames.cpp::operator<<(FArchive&,FNameEntrySerialized&)`; `Core/Private/Containers/String.cpp::operator<<(FArchive&, FString&)`; `CoreUObject/Public/UObject/LinkerLoad.h::operator<<(FName&)`.
- `UE4/UnrealPackageReader.php` is the active shared format reader; `CatalogReaderResolver` selects it for UE4 profiles. Current `Uedb5SourceSnapshotFactory` registers the UE4 reader for **UT4** (its independent clean-master v511 profile), not a general live stock-UE4.27 game source key. No claim that stock UE4.27 ingress is deployed. P08/P09 explicitly govern active full production workflows; P02 only certifies format/reader behavior.

## Native parity

Compiled disposable `catalog/compliance/ue427-native-oracle` under matching installed UE4.27 Editor (Win64 BuildPlugin successful). Replay manifests/logs on DEV:

| Epic-native acceptance | Files / fields | Mismatches |
| --- | ---: | ---: |
| Real C++ `FNameEntrySerialized` string + byte offset | 1,749 genuine files; 108,582 names | 0 |
| Source C++ `FName::CreateFromDisplayId` with instance number 43 | 1,749 files; 5,244 rendered suffixes | 0 |
| Raw serialized first-import FName index and number, resolved with Epic native name map | 1,748 files with imports; 5,244 compared fields (index/number/rendered name) | 0 |
| Epic `FPackageFileSummary` decoded `FolderName` and `LocalizationId` FString values | 1,749 files; 3,498 fields | 0 |
| Two source-shaped normal/swapped name-table fixtures (ANSI, UTF-16, number-like name) | 6 entries | 0 after fix |
| Two source-shaped normal/swapped summary FString fixtures (wide `CaféFolder`, ANSI `Locale-ID`) | 4 string fields | 0 |
| Genuine import mutation: valid / -1 / INT32_MAX FName index | 3/3 accept/reject expectations | 0 |

Relevant DEV logs: `C:/Temp/ue427-p02-names-full-native.log`, `C:/Temp/ue427-p02-full-suffix-string-native.log`, `C:/Temp/ue427-p02-full-wire-native.log`, `C:/Temp/ue427-p02-fstring-native.log`, `C:/Temp/ue427-name-fixtures-native.log`. Corpus manifest `C:/Temp/ue427-all-assets.txt`. Commands:

- `php catalog/bin/verify-ue427-native-name-differential.php C:/Temp/ue427-p02-names-full-native.log C:/Temp/ue427-all-assets.txt`
- `php catalog/bin/verify-ue427-native-fname-strings.php C:/Temp/ue427-p02-full-suffix-string-native.log C:/Temp/ue427-all-assets.txt`
- `php catalog/bin/verify-ue427-native-import-fname-wire.php C:/Temp/ue427-p02-full-wire-native.log C:/Temp/ue427-all-assets.txt`
- `php catalog/bin/verify-ue427-native-fname-strings.php C:/Temp/ue427-p02-fstring-native.log C:/Temp/ue427-fstring-fixtures/list.txt`
- `php catalog/bin/verify-ue427-invalid-fname-index.php`

The first-import comparison deliberately validates the FName wire operator shared by import fields; it is **not** independent complete per-row FObjectImport acceptance (P03).

## Corrected production discrepancies

- **U427-P02-D001:** `FNameEntrySerialized` wide UCS-2 bytes must not be swapped when the archive's scalar length is swapped. Epic's `Ar.Serialize` raw-byte path differs from `FString`, which explicitly swaps wide 16-bit code units. Before correction the swapped wide fixture matched 5/6 names; after correction 6/6. The general `UE4BinaryReader::fstring()` was left unchanged and separately verified for wide/ANSI on both endian modes.
- **U427-P02-D002:** `FLinkerLoad::operator<<(FName&)` treats an index outside NameMap as a critical archive error. UnrealDB previously resolved it as an empty name. The reader now rejects an invalid index for linker import/export FNames. Mutation test uses a copy of an authentic asset with seven imports and demonstrates valid acceptance and rejection of index -1 and INT32_MAX. Full installed reader corpus still passes 1,749/1,749, with 20,207 imports, 37,552 exports and 112 soft references; UT4 v511 V5 persistence still passes.

## Deferred / scope

- P03: independent full import/export rows, field widths, flags, extent checks, and per-row native differential.
- P04 / secondary `U427-S003`: some apparent NameMap-out-of-range values were observed when applying the linker index rule to UnrealDB's existing soft-package-reference reader. They are **not proven a source discrepancy**; soft-reference parsing behavior was preserved pending source/native P04 audit.
- P08/P09: actual file-ingress, game-profile dispatch, archive/restore, V5 durability and query consumers.
- P10: malformed/negative-suite breadth beyond P02's explicit source gates (e.g., zero-length serialized name behavior). No assumption that malformed engine inputs are safe to load.

No canonical Epic source edits, executable binaries in Git, or production database changes. Versioned executable test drivers and the native oracle source preserve reproducibility.
