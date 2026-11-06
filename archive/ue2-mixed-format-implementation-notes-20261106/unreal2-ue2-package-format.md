# Unreal II / UE2 Package Format and Reader

## Scope

This source-based specification distinguishes **three complete, independently inspected UE2 source authorities**:

1. **Unreal II, 9 December 2000:** `L:\Source\Games\Unreal II\Unreal II The Awakening [12-09-2000]\Unreal2_old`. `ENGINE_VERSION=411`, `PACKAGE_FILE_VERSION=69`, licensee `0x7F`, minimum loadable version `60`. Its loader handles earlier versions within its explicit supported range.
2. **Unreal II, 7 January 2003:** `L:\Source\Games\Unreal II\Unreal II The Awakening [01-07-2003]`. Its real `Core/Inc/UnObjVer.h` declares `ENGINE_VERSION=1226+DEMO_VERSION_OFFSET`, `PACKAGE_FILE_VERSION=126`, licensee `0`, and minimum loadable version `60`. Its `Core/Src/UnLinker.cpp` includes the complete `ULinkerLoad::VerifyImport` implementation. This is **Unreal II game source**, not UE4 source.
3. **Generic UE2 / Unreal Warfare, 29 September 2007:** `L:\Source\Engine\UE2\Unreal Engine [v2.5]_ Unreal Warfare [09-29-2007]`. Also package version `126` and licensee `0`, but retained as **separate generic-engine evidence**. Identical version constants alone do not prove identical game-specific behaviour.

The v69 loader supplies a game-specific source path for loadable v60–69 packages (where the source's licensee conditions apply). Exact v126/licensee-0 Unreal II packages have their own January 2003 game-source rules, including distinct `VerifyImport` behaviour. Intermediate versions and different licensee branches can remain structurally readable without receiving an unsupported game-specific `VerifyImport` conclusion. Versions after 126 do not inherit the v126 source policy without evidence.

UT2003 and UT2004 are independent game profiles; neither supplies missing Unreal II behaviour.

## Authoritative source references

| Area | Source |
|---|---|
| package/version constants | `Core/Inc/UnObjVer.h` |
| archive primitives/licensee version | `Core/Inc/UnArc.h` |
| linker structures/API | `Core/Inc/UnLinker.h` |
| package/import/export serializers and reader | `Core/Src/UnLinker.cpp` |
| compact index | `Core/Src/UnObj.cpp` |
| name entry | `Core/Src/UnName.cpp` |
| FString serialization | `Core/Src/UnMisc.cpp` |

## Package identification and versions

`PACKAGE_FILE_TAG = 0x9E2A83C1`. On the normal Intel persistent representation, the first four bytes are `C1 83 2A 9E`.

The serialized `FileVersion` field is a 32-bit INT but this source interprets it as two 16-bit values:

- Epic/engine package version: `FileVersion & 0xffff`
- licensee package version: `(FileVersion >> 16) & 0xffff`

`FPackageFileSummary::GetFileVersion()` and `GetFileVersionLicensee()` are authoritative. The loader sets both `ArVer` and `ArLicenseeVer` from those accessors after reading the summary.

The saver uses `SetFileVersions(PACKAGE_FILE_VERSION, PACKAGE_FILE_VERSION_LICENSEE)`. When `GSys->LicenseeMode == 0`, that function stores only the Epic version; otherwise it stores `(Licensee << 16) | Epic`.

Therefore UnrealDB must not treat the entire 32-bit value as a single engine version.

## Primitive persistent serialization

Persistent fixed-width integer fields use `FArchive::ByteOrderSerialize`. Intel hosts serialize directly; persistent archives on non-Intel hosts reverse byte order.

Relevant fixed fields remain normal little-endian INT/DWORD values unless their serializer explicitly uses `AR_INDEX`.

The archive separately tracks `ArVer` and `ArLicenseeVer`.

## Compact index

`FCompactIndex` uses the same source algorithm present in this Unreal II tree:

- first byte: sign in bit 7, continuation in bit 6, six magnitude bits;
- following bytes 1-3: continuation in bit 7 and seven magnitude bits;
- fifth byte: remaining magnitude;
- maximum five serialized bytes.

The decoder reconstructs later seven-bit groups first, then the low six bits, then applies the sign.

Only fields explicitly serialized through `AR_INDEX` use this representation.

## FString serialization

This source provides the exact `FString` serializer.

It computes:

`SaveNum = appIsPureAnsi(*A) ? A.Num() : -A.Num()`

and serializes `SaveNum` as a compact index.

On load:

- `SaveNum >= 0`: read `SaveNum` one-byte ANSI characters;
- `SaveNum < 0`: read `Abs(SaveNum)` `UNICHAR` values;
- the count includes the terminator because `FString::Num()` includes it for nonempty strings;
- a loaded array of one character (only the terminator) is normalized to empty.

The archive may enforce `ArMaxSerializeSize`; if set and `Abs(SaveNum)` exceeds it, it sets archive error and critical-error state. This is an archive runtime safety mechanism, not a package-format maximum established by the serialized field.

## Package summary

`FPackageFileSummary` serializes, in exact order:

1. `Tag` — INT
2. raw combined `FileVersion` — INT
3. `PackageFlags` — DWORD
4. `NameCount` — INT
5. `NameOffset` — INT
6. `ExportCount` — INT
7. `ExportOffset` — INT
8. `ImportCount` — INT
9. `ImportOffset` — INT

The following branch uses **GetFileVersion()**, i.e. only the low 16-bit Epic version.

### Epic version >= 68

Then serialize:

1. `Guid`
2. `GenerationCount` — INT
3. `GenerationCount` entries, each:
   - `ExportCount` — INT
   - `NameCount` — INT

### Epic version < 68

Then serialize:

1. `HeritageCount` — INT
2. `HeritageOffset` — INT

The loader saves the current position, seeks to the heritage table, reads each GUID into `Sum.Guid`, restores the saved position, and synthesizes one generation from the current export/name counts.

Licensee bits must not affect this >=68 test.

## Name table

The loader seeks to `NameOffset` and reads exactly `NameCount` entries.

For `Ar.Ver() < 64`, `FNameEntry` reads a zero-terminated ANSI byte sequence, then flags.

For version >=64, both sources read an `FString` and then flags. Their runtime copy differs:

- Unreal-II v69 uses `appStrcpy(E.Name, *Str)`;
- Warfare v126 uses `appStrcpy(E.Name, *Str.Left(NAME_SIZE-1))`, so the effective runtime name is capped at 63 TCHARs while the serialized FString itself remains unchanged.

Both linkers build `_ContextFlags` from `GIsEditor`, `GIsClient`, and `GIsServer`, and both load Names before Imports/Exports. An entry whose flags do not intersect that mask maps to `NAME_None`.

For deterministic cataloguing, UnrealDB uses the source-backed UCC/editor all-context mask `RF_LoadForEdit | RF_LoadForClient | RF_LoadForServer = 0x00070000`. Raw serialized name text and flags remain preserved. Versions 60-69 use the Unreal-II v69 effective text; versions 70-126 additionally apply the v126 63-character runtime limit.

## Import table

Each `FObjectImport` is:

1. `ClassPackage` — FName reference
2. `ClassName` — FName reference
3. `PackageIndex` — **fixed-width INT**
4. `ObjectName` — FName reference

On load, `SourceIndex` becomes `INDEX_NONE` and `XObject` null. Runtime linkage fields are not serialized.

## Export table

Each `FObjectExport` is:

1. `ClassIndex` — compact index
2. `SuperIndex` — compact index
3. `PackageIndex` — **fixed-width INT**
4. `ObjectName` — FName reference
5. `ObjectFlags` — DWORD
6. `SerialSize` — compact index
7. `SerialOffset` — compact index only when `SerialSize != 0`

This source therefore retains the important distinction between compact object/class/size fields and fixed-width package/outer indices.

## FName references

`ULinkerLoad::operator<<(FName&)` reads a compact name index from the underlying loader, verifies that `NameMap` contains it, and resolves the mapped FName.

An invalid name-table index is an error.

## Signed object-reference semantics

`IndexToObject` proves:

- positive -> export `Index - 1`
- negative -> import `-Index - 1`
- zero -> null

Both import and export references are bounds-checked before use.

## Export class and outer identity

Class identity follows the source:

- negative `ClassIndex`: class name from the referenced import;
- positive: class name from the referenced local export;
- zero: `Class`.

For class package:

- negative class index: class import's parent import `ObjectName`;
- positive: current linker root package;
- zero: `Core`.

Import paths follow negative parent indices to zero. Export paths follow positive one-based export parents to zero.

## Export payload boundaries

`Preload` seeks to `SerialOffset`, precaches `SerialSize`, calls the object's serializer, then compares consumed bytes with `SerialSize`.

Both audited UE2 sources treat a payload-size mismatch as fatal: when `Tell() - SerialOffset != SerialSize`, `ULinkerLoad::Preload` calls `appErrorf`. The previous documentation claim that the v126 source only warned was incorrect and is withdrawn.

## Missing export classes

This is a real v69/v126 runtime difference and must not be flattened:

- Unreal-II v69 falls back to `UClass::StaticClass()` whenever resolving the export class returns null, and skips `Camera` exports.
- Warfare v126 first returns null when `ClassIndex != 0` but the referenced class cannot be resolved; only a zero `ClassIndex` falls back to `UClass::StaticClass()`. It skips both `Camera` and `PlayerInput`.

These are object-creation/runtime behaviors, not changes to the serialized table layout or static dependency identity.

## Loader sequence

The source-common load order is:

1. open the package;
2. initialize loading/persistent archive state and the source revision's current Epic/licensee versions;
3. deserialize the summary;
4. replace `ArVer` and `ArLicenseeVer` with the package's decoded versions;
5. copy package flags;
6. validate the package tag;
7. apply minimum-version policy to the low-16-bit Epic version;
8. reserve Names/Imports/Exports;
9. read Names and construct the effective context-filtered NameMap;
10. read Imports through that NameMap;
11. read Exports through that NameMap;
12. construct the export hash from effective object/class/package FNames;
13. add the linker to the global loader set;
14. verify imports unless `LOAD_NoVerify`.

The January 2003 Unreal II v126 and generic Warfare v126 sources compute `QuickMD5` between table loading and export-hash construction. Unreal II v69 has no corresponding QuickMD5 block.

## QuickMD5 — January 2003 Unreal II and generic Warfare v126

The January 2003 Unreal II loader hashes two raw structural byte ranges:

1. file offset 0 through the position immediately after the Name table;
2. `Summary.ImportOffset` through the position immediately after the Export table.

The ranges are fed sequentially into one MD5 context. The resulting 16-byte digest is rendered as 32 lowercase hexadecimal characters by `QuickMD5()`.

This is a cheat-protection/structural hash. It does not mutate the parsed Name/Import/Export tables and does not participate in the audited static dependency identity. UnrealDB therefore documents it but does not need to synthesize it for dependency conformance. If a future feature requires source-compatible QuickMD5 identity, it must reproduce these exact raw ranges rather than substitute the package GUID, whole-file MD5, or UEDB5 payload hash.

## Code indication — January 2003 Unreal II and generic Warfare v126

The January 2003 source's `ULinker::LinksToCode()` returns false when there are no exports and otherwise reports code when any export has nonzero `SuperIndex`. This is runtime/package classification, not serialized package identity, and is not attributed to Unreal-II v69 unless separately proven there.

## Compression and encryption

The reviewed Unreal II package linker opens the package through the ordinary file reader and directly seeks to summary/table/export offsets. The reviewed package summary contains no package compression or encryption descriptor.

No package-level compression/encryption rule should therefore be invented for this exact reader path. UZ2 and other container/redirect behavior belong in their own specifications.

## Explicit source differences and non-differences

The first-principles comparison establishes:

- both UE2 sources use the 16-bit Epic + 16-bit licensee interpretation of the raw version field;
- both use the same summary, Import and Export field order for the audited range;
- both use compact FName/class/super/size/offset indices and fixed-width Import/Export `PackageIndex`;
- both serialize `SerialOffset` whenever `SerialSize != 0`;
- both context-filter Names before Imports/Exports and therefore before dependency verification;
- both treat payload-size mismatch as fatal;
- v126 truncates the effective v64+ FName text to 63 TCHARs; v69 does not contain that truncation;
- v126 computes QuickMD5; v69 does not;
- v126 can skip an export whose nonzero class reference cannot resolve; v69 falls back to `UClass::StaticClass()`;
- v69 skips `Camera`; v126 skips `Camera` and `PlayerInput`.

These comparisons are why UnrealDB can share a UE2 byte parser while still carrying revision-specific preprocessing and game-specific VerifyImport profiles.

## Runtime/config-only boundary

Package bytes provide the serialized summary, tables, names, object references, and payload spans.

Runtime context determines such things as edit/client/server name filtering, object/class availability, object construction, and import verification.

Configuration-driven class remapping is not serialized package identity. Unreal II has distinct complete game-specific `VerifyImport` bodies for December 2000 v69 and January 2003 v126. The generic Warfare v126 implementation is independent evidence and is not silently substituted for the Unreal II body on unverified version/licensee combinations.

## UnrealDB conformance requirements

For this Unreal II / UE2 boundary, UnrealDB must:

1. use tag `0x9E2A83C1`;
2. split the raw version into low-16 Epic and high-16 licensee values;
3. use only the Epic version for package-version gates;
4. preserve the raw combined version as well as both decoded components;
5. use exact fixed-width versus compact-index encodings;
6. implement the exact FString signed-count ANSI/Unicode representation;
7. retain the v64 Name-entry and v68 summary branches;
8. preserve fixed-width Import/Export `PackageIndex`;
9. read compact `SerialOffset` whenever `SerialSize != 0`, including a negative compact value;
10. preserve the serialized Name text/flags while deriving effective FNames from the explicit all-context mask before Imports/Exports/dependencies;
11. use Unreal-II v69 effective name text for versions 60-69 and apply the v126 63-TCHAR runtime limit only to versions 70-126;
12. preserve signed Import/Export object-reference semantics;
13. expose exact payload spans;
14. keep payload-size mismatch fatal when modeling engine object loading;
15. apply the December 2000 Unreal II game-specific verification branch only within its supported v60–69 loader range and compatible licensee cases;
16. use the actual January 2003 Unreal II v126/licensee-0 game source, rather than inventing game-specific semantics for unrelated versions or using the generic Warfare implementation as its substitute;
17. leave versions above 126 source-unresolved rather than calling them forward-compatible;
18. keep UT2003 and UT2004 dependency semantics independent;
19. treat Warfare QuickMD5 as optional structural identity, not as a dependency transformation.

## Source-reference matrix

| Rule | Unreal II Dec 2000 v69 | Unreal II Jan 2003 v126 | Generic UE2/Warfare v126 |
|---|---|---|---|
| tag/version/minimum | `Core/Inc/UnObjVer.h` | `Core/Inc/UnObjVer.h` | `Core/Inc/UnObjVer.h` |
| Epic/licensee split | `Core/Inc/UnLinker.h: FPackageFileSummary` | `Core/Inc/UnLinker.h: FPackageFileSummary` | `Core/Inc/UnLinker.h: FPackageFileSummary` |
| compact index | `Core/Src/UnObj.cpp: FCompactIndex operator<<` | `Core/Src/UnObj.cpp` | `Core/Src/UnObj.cpp` |
| FString representation | `Core/Src/UnMisc.cpp` | `Core/Src/UnMisc.cpp` | `Core/Src/UnMisc.cpp` |
| summary serializer | inline `Core/Inc/UnLinker.h` | `Core/Src/UnLinker.cpp` | `Core/Src/UnLinker.cpp` |
| Import serialization | inline `FObjectImport operator<<` | `Core/Src/UnLinker.cpp` | `Core/Src/UnLinker.cpp` |
| Export serialization | inline `FObjectExport operator<<` | `Core/Src/UnLinker.cpp` | `Core/Src/UnLinker.cpp` |
| Name entry | `Core/Src/UnName.cpp` | `Core/Src/UnName.cpp` | `Core/Src/UnName.cpp` |
| context NameMap | `ULinker::ULinker`, `ULinkerLoad::LoadNames` | same-named functions in Jan 2003 Core | independently defined in Warfare Core |
| load sequence/export hash | `ULinkerLoad::ULinkerLoad` | `ULinkerLoad::ULinkerLoad`, `HashNames` | `ULinkerLoad::ULinkerLoad`, `HashNames` |
| QuickMD5 | absent from audited constructor | `ULinkerLoad::ULinkerLoad`, `ULinker::QuickMD5` | `ULinkerLoad::ULinkerLoad`, `ULinker::QuickMD5` |
| payload-size mismatch | `ULinkerLoad::Preload` | `ULinkerLoad::Preload` | `ULinkerLoad::Preload` |
| export construction | `ULinkerLoad::CreateExport` | `ULinkerLoad::CreateExport` | `ULinkerLoad::CreateExport` |
| game-specific VerifyImport | `ULinkerLoad::VerifyImport`; includes v69 aliases and public checks | `ULinkerLoad::VerifyImport`; distinct non-forgiving private-export and inactive remap paths | **Separate generic-engine body, not an Unreal II game source substitute** |

## UEDB5 source policies

- versions 60-69: `ue2-unreal2-2000-12-09-package-v69`;
- exact version 126/licensee 0: `ue2-unreal2-2003-01-07-package-v126`;
- other versions 70-126 without matching game-version evidence: `ue2-warfare-v126-serialization` (structural fallback only);
- versions above 126: `ue2-unreal2-post-v126-profile-admitted-unresolved`.

The January 2003 game-specific v126 policy includes its own import-verification behaviour. The generic Warfare policy covers only source-supported structural serialization/preprocessing; it does not grant a game-specific `VerifyImport` conclusion.

## Deferred

The January 2003 v126/licensee-0 game-specific source is available and separately documented. Other version/licensee combinations without a proven game loader remain source-unresolved for exact static `VerifyImport`; they are not automatically assigned the v126, UT2003 or UT2004 rules.
