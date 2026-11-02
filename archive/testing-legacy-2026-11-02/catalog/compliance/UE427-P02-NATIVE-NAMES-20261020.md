# UE4.27.2 P02 — native name map and byte-swapped UCS-2

**Status: P02 ACTIVE.** This is independent native name-map acceptance and two confirmed production parser corrections; it is not yet a closure claim for all FName / FString branches. The fixed denominator remains **1/10 formally verified**.

## Epic source anchors

- Pinned engine: `L:/Source/Engine/UE4/UE 4.27.2` at `d94b38ae3446da52224bedd2568c078f828b4039`.
- `CoreUObject/Private/UObject/LinkerLoad.cpp::SerializeNameMap` seeks `Summary.NameOffset`, deserializes `FNameEntrySerialized` entries and inserts their display indices.
- `Core/Private/UObject/UnrealNames.cpp::operator<<(FArchive&,FNameEntrySerialized&)`: signed string count; negative means wide; string bytes are raw `Ar.Serialize` not per-element swapped, while signed count uses `Ar <<`; historic two uint16 hashes are skipped at source `VER_UE4_NAME_HASHES_SERIALIZED`. `NAME_SIZE=1024`.
- `CoreUObject/Public/UObject/LinkerLoad.h::operator<<(FName&)`: serialized `int32 NameIndex` plus `int32 Number`, followed by `NameMap.IsValidIndex(NameIndex)`. Invalid index calls `BadNameIndexError` and `SetCriticalError`.

## Positive native acceptance

The rebuilt `ue427-native-oracle` has a `-Names` mode. For every real package it executes Epic's `FPackageFileSummary`, configures `Ar.SetUE4Ver()`, and reads each `FNameEntrySerialized` from `Summary.NameOffset` using Epic C++ operators. Names are logged as UTF-8 hex with 64-bit byte offsets and input-ordinal identities. Test code: `catalog/bin/verify-ue427-native-name-differential.php`.

- First 100 genuine packages: **7,120/7,120** entries, no name/offset mismatch.
- Complete installed UE4 Engine/Content corpus: **1,749/1,749** genuine packages, **108,582/108,582** Epic-native name identities and byte offsets, **0 mismatches**. Native output `C:/Temp/ue427-p02-names-full-native.log`; manifest `C:/Temp/ue427-all-assets.txt`; verifier result `C:/Temp/ue427-p02-names-full-parity-after.json`.
- Test command: `php catalog/bin/verify-ue427-native-name-differential.php C:/Temp/ue427-p02-names-full-native.log C:/Temp/ue427-all-assets.txt`.

## Two source differences and acceptance

**U427-P02-D001 — byte-swapped UCS-2 name entry:** UnrealDB previously decoded wide `FNameEntrySerialized` payloads as UTF-16BE whenever a swapped package tag was seen. Epic's pinned `FNameEntrySerialized` instead swaps only the signed length and reads UCS-2 payload bytes with raw `Ar.Serialize` on the tested little-endian host. Two source-shaped name-map fixtures, built on the actual Epic-native summary baseline by `catalog/bin/generate-ue427-native-name-fixtures.php`, contain three entries each (ANSI, UTF-16 `CaféWide`, and numeric-looking `Object_42`) with historical hash words. Native loader read both little and swapped archives. Before fix: **5/6** entries matched; swapped wide entry decoded to nonsense; after fix: **6/6**, zero mismatches. Source-specific fix restricted to `readSerializedNameEntry`; general archive `fstring()` byte order is not altered.

**U427-P02-D002 — invalid linker FName indices:** `readFName` previously silently converted an out-of-range NameMap index to an empty string. Epic `FLinkerLoad` raises a critical error. Dedicated `catalog/bin/verify-ue427-invalid-fname-index.php` selects an authentic UE4 asset with imports, copies it to an isolated temp path and mutates the first import FName index to `-1` and `INT32_MAX`. Both mutations are rejected with exact source-grounded errors; the original asset remains accepted (**3/3**). The check is scoped to import/export FNames that use `FLinkerLoad::operator<<(FName&)`, rather than extending unverified behavior to P04's soft-reference decoder.

After these changes, the full installed reader corpus again passes **1,749/1,749**, including 20,207 imports, 37,552 exports and 112 soft references; the UT4 v511 V5 persistence contract also passes. No PRD database writes or Epic source modifications.

## Still open in P02

- Independent native FName instance-number and display-name parity for import/export name-index pairs (beyond source-code and negative-boundary coverage).
- General `FString` wide/ANSI, empty strings, endian and malformed length behaviors outside `FNameEntrySerialized`.
- Full P02 source-branch caller matrix, including name consumers in production V5/search/validators and numerical suffixes. Do not promote P02 to VERIFIED until accepted.

Outside P02: `UE427-SECONDARY-DIFFERENCES.md` S003 records a deferred P04 soft-reference wire-format concern discovered during validation. It is not proven to be a parser discrepancy yet.
