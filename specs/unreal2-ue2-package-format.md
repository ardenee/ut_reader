# Unreal II / UE2 Package Format and Reader

## Scope

Section 4C re-audited this format from first principles using **two separate authorities**:

1. **Unreal-II-specific source:** `L:\Source\Games\Unreal II\Unreal II The Awakening [12-09-2000]\Unreal2_old`
   - `ENGINE_VERSION = 411`
   - `PACKAGE_FILE_VERSION = 69`
   - `PACKAGE_FILE_VERSION_LICENSEE = 0x7F`
   - `PACKAGE_MIN_VERSION = 60`
2. **Later generic UE2/Warfare source:** `L:\Source\Engine\UE2\Unreal Engine [v2.5]_ Unreal Warfare [09-29-2007]`
   - `ENGINE_VERSION = 1226 + DEMO_VERSION_OFFSET`
   - `PACKAGE_FILE_VERSION = 126`
   - `PACKAGE_FILE_VERSION_LICENSEE = 0`
   - `PACKAGE_MIN_VERSION = 60`

The directory labelled `L:\Source\Games\Unreal II\Unreal II The Awakening [01-07-2003]` is **not UE2 source** in the current source archive: it is UE4 4.0.2 and contains no `U2XMP_all` subtree. The old path previously cited by this document is therefore withdrawn as an authority.

For UnrealDB attribution:

- package versions 60-69 use the complete Unreal-II-specific v69 source;
- package versions 70-126 may use the generic UE2/Warfare source for serialization and pre-dependency preprocessing only;
- Warfare v126 does **not** prove Unreal-II-specific `VerifyImport` behavior for versions 70-126;
- admitted versions above 126 remain source-unresolved rather than inheriting v126 behavior.

UT2003 and UT2004 remain independently audited game profiles. Generic UE2 evidence is not silently promoted into their game-specific dependency semantics.

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

Warfare v126 inserts one additional non-transforming operation between steps 11 and 12: it computes `QuickMD5`. Unreal-II v69 has no corresponding QuickMD5 block.

## QuickMD5 — generic UE2/Warfare v126 only

Warfare v126 hashes two raw structural byte ranges:

1. file offset 0 through the position immediately after the Name table;
2. `Summary.ImportOffset` through the position immediately after the Export table.

The ranges are fed sequentially into one MD5 context. The resulting 16-byte digest is rendered as 32 lowercase hexadecimal characters by `QuickMD5()`.

This is a cheat-protection/structural hash. It does not mutate the parsed Name/Import/Export tables and does not participate in the audited static dependency identity. UnrealDB therefore documents it but does not need to synthesize it for dependency conformance. If a future feature requires source-compatible QuickMD5 identity, it must reproduce these exact raw ranges rather than substitute the package GUID, whole-file MD5, or UEDB5 payload hash.

## Code indication — generic UE2/Warfare v126 only

Warfare's `ULinker::LinksToCode()` returns false when there are no exports and otherwise reports code when any export has nonzero `SuperIndex`. This is runtime/package classification, not serialized package identity, and is not attributed to Unreal-II v69 unless separately proven there.

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

Configuration-driven class remapping is not serialized package identity. Unreal-II dependency verification remains governed by `unreal2-ue2-dependency-resolution.md` and the complete Unreal-II v69 source. Warfare's complete generic v126 `VerifyImport` body is useful generic UE2 evidence but is not promoted to Unreal-II-specific behavior for package versions 70-126.

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
15. keep Unreal-II-specific VerifyImport bounded to versions 60-69 unless matching later game source is obtained;
16. attribute versions 70-126 to generic UE2/Warfare serialization/preprocessing, not to an invented Unreal-II v126 source;
17. leave versions above 126 source-unresolved rather than calling them forward-compatible;
18. keep UT2003 and UT2004 dependency semantics independent;
19. treat Warfare QuickMD5 as optional structural identity, not as a dependency transformation.

## Source-reference matrix

| Rule | Unreal-II v69 authority | generic UE2/Warfare v126 authority |
|---|---|---|
| tag/version/minimum | `Core/Inc/UnObjVer.h` | `Core/Inc/UnObjVer.h` |
| Epic/licensee split | `Core/Inc/UnLinker.h: FPackageFileSummary` | `Core/Inc/UnLinker.h: FPackageFileSummary` |
| compact index | `Core/Src/UnObj.cpp: FCompactIndex operator<<` | same |
| FString representation | `Core/Src/UnMisc.cpp` | `Core/Src/UnMisc.cpp` |
| summary | inline `Core/Inc/UnLinker.h` serializer | `Core/Src/UnLinker.cpp` |
| Imports | inline `FObjectImport operator<<` | `Core/Src/UnLinker.cpp` |
| Exports | inline `FObjectExport operator<<` | `Core/Src/UnLinker.cpp` |
| Name entry | `Core/Src/UnName.cpp` | `Core/Src/UnName.cpp` |
| context NameMap | `ULinker::ULinker`, `ULinkerLoad::LoadNames` | same |
| load sequence/export hash | `ULinkerLoad::ULinkerLoad` | same |
| QuickMD5 | absent from audited constructor | `ULinkerLoad::ULinkerLoad`, `ULinker::QuickMD5` |
| payload mismatch | `ULinkerLoad::Preload` fatal `appErrorf` | same |
| missing-class/export skips | `ULinkerLoad::CreateExport` | same symbol, later behavior |
| Unreal-II VerifyImport | authoritative through v69 | **not Unreal-II authority** |

## UEDB5 source policies

- versions 60-69: `ue2-unreal2-2000-12-09-package-v69`;
- versions 70-126: `ue2-warfare-v126-serialization`;
- versions above 126: `ue2-unreal2-post-v126-profile-admitted-unresolved`.

The generic v126 policy certifies serialization/preprocessing only. It does not activate the Unreal-II v69 VerifyImport profile.

## Deferred

Unreal-II-specific dependency/import verification after package v69 remains unresolved until matching game-branch source is available. It is deliberately not inferred from Warfare v126, UT2003, or UT2004.
