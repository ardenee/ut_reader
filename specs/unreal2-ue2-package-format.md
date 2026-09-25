# Unreal II / UE2 Package Format and Reader

## Scope

This specification documents package serialization and reader behavior from the supplied Unreal II source, using the newest available source tree as the authority for this document:

- Repository: `ardenee/unreal2src`
- Branch: `main`
- Tree: `Unreal II The Awakening [01-07-2003]/U2XMP_all/depot`
- `ENGINE_VERSION = 1226 + DEMO_VERSION_OFFSET`
- `PACKAGE_FILE_VERSION = 126`
- `PACKAGE_FILE_VERSION_LICENSEE = 0`
- `PACKAGE_MIN_VERSION = 60`

The repository also contains older dated Unreal II trees. Their behavior is not silently merged into this specification. Differences require explicit source comparison.

This is an **Unreal II-specific UE2 specification**. UT2003, UT2004, and generic UE2/UE2.5 behavior must not be inferred from it.

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

For version >=64, it reads an `FString`, copies at most `NAME_SIZE-1` characters into the name buffer, then reads flags.

The loader's runtime `NameMap` applies edit/client/server context filtering. An entry whose flags do not match `_ContextFlags` maps to `NAME_None`. This filtering must not be mistaken for absence of the serialized name.

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

A significant Unreal II behavior differs from the UT99 retail source reviewed earlier: when consumed size differs, this source calls `debugf(NAME_Warning,...)`; it does **not** call the UT99 retail fatal `appErrorf` at this point.

UnrealDB must preserve this distinction in source-conformance documentation rather than claiming the UT99 fatal behavior for Unreal II.

## Missing export classes

`CreateExport` contains an Unreal II-specific tolerance:

If resolving `Export.ClassIndex` returns no class and `ClassIndex != 0`, it returns null instead of constructing the export.

If `ClassIndex == 0`, it falls back to `UClass::StaticClass()`.

The loader also refuses to create exports whose resolved class name is `Camera` **or `PlayerInput`**.

These are reader/runtime behaviors, not changes to the serialized table layout.

## Loader sequence

`ULinkerLoad` performs:

1. open file;
2. initialize `ArVer=126`, `ArLicenseeVer=0`, loading/persistent state;
3. deserialize summary;
4. set `ArVer = Summary.GetFileVersion()`;
5. set `ArLicenseeVer = Summary.GetFileVersionLicensee()`;
6. copy package flags;
7. validate tag;
8. apply minimum-version policy to the low 16-bit Epic version;
9. allocate table storage;
10. seek/read names;
11. record end-of-name-table position;
12. seek/read imports;
13. seek/read exports;
14. compute QuickMD5;
15. construct export hash;
16. add linker;
17. verify imports unless `LOAD_NoVerify`.

## QuickMD5

This Unreal II linker adds a package `QuickMD5` calculation.

After loading tables it hashes two raw byte ranges:

1. from file offset 0 through `SizeOfPart1`, where `SizeOfPart1` is the archive position immediately after reading the name table;
2. from `Summary.ImportOffset` for `SizeOfPart2 = Tell() - Summary.ImportOffset`, where `Tell()` is the position immediately after reading the export table.

The two ranges are fed sequentially into one MD5 context. The resulting 16-byte digest is stored in `QuickMD5Digest` and rendered as 32 lowercase hexadecimal characters by `QuickMD5()`.

The source comment describes this as a cheat-protection hash over the summary/header, names, imports, and exports.

This hash is not the package GUID and is not a whole-file MD5.

## Code indication

`ULinker::LinksToCode()` returns false when there are no exports; otherwise it returns true if any export has nonzero `SuperIndex`. The load linker also exposes `LinksToCode()` through archive code-state logic elsewhere.

This is runtime/package classification behavior and should not be confused with a serialized header flag unless separately proven.

## Compression and encryption

The reviewed Unreal II package linker opens the package through the ordinary file reader and directly seeks to summary/table/export offsets. The reviewed package summary contains no package compression or encryption descriptor.

No package-level compression/encryption rule should therefore be invented for this exact reader path. UZ2 and other container/redirect behavior belong in their own specifications.

## Explicit differences from the UT99 retail specification

Although the basic table structure is related, this Unreal II source independently proves several differences:

- package version 126;
- 16-bit Epic + 16-bit licensee interpretation of the raw version field;
- separate archive `LicenseeVer()`;
- exact ANSI/Unicode `FString` serializer is present;
- QuickMD5 over structural byte ranges;
- export payload-size mismatch is a warning here, not the UT99 retail fatal error;
- missing nonzero export classes can cause the export to be skipped;
- both `Camera` and `PlayerInput` export classes are skipped.

These differences are why UnrealDB must not route Unreal II merely through assumptions taken from the UT99 reader.

## Runtime/config-only boundary

Package bytes provide the serialized summary, tables, names, object references, and payload spans.

Runtime context determines such things as edit/client/server name filtering, object/class availability, object construction, and import verification.

No generic configuration-driven class remap is part of the package format. In this Unreal II source's `VerifyImport`, a `ClassRemap` block exists only inside `#if 0 //!!MERGE`; it is disabled and is therefore **not active behavior**. UnrealDB must not implement it as an Unreal II fallback.

## UnrealDB conformance requirements

For this Unreal II source target, UnrealDB should:

1. use tag `0x9E2A83C1`;
2. split the raw version into low-16 Epic and high-16 licensee values;
3. use only the Epic version for source version gates;
4. preserve the raw combined version as well as both decoded components;
5. use exact fixed-width versus compact-index encodings;
6. implement the exact FString signed-count ANSI/Unicode representation;
7. retain the v64 name-entry and v68 summary branches for older compatible packages;
8. preserve fixed-width import/export `PackageIndex`;
9. omit `SerialOffset` for zero-sized exports;
10. preserve signed import/export object references;
11. expose exact payload spans;
12. treat payload-size mismatch according to the Unreal II source when modeling engine behavior;
13. not invent ClassRemap behavior from the disabled block;
14. keep Unreal II rules separate from UT2003, UT2004, and UE2.5 rules;
15. optionally reproduce QuickMD5 exactly if UnrealDB needs source-compatible structural identity.

## Source-reference matrix

| Rule | Source |
|---|---|
| tag/version/minimum | `Core/Inc/UnObjVer.h` |
| Epic/licensee split | `Core/Inc/UnLinker.h: FPackageFileSummary` |
| archive licensee state | `Core/Inc/UnArc.h: FArchive` |
| byte order | `Core/Inc/UnArc.h: ByteOrderSerialize` |
| compact index | `Core/Src/UnObj.cpp: FCompactIndex operator<<` |
| FString signed ANSI/Unicode count | `Core/Src/UnMisc.cpp: FString operator<<` |
| summary | `Core/Src/UnLinker.cpp: FPackageFileSummary operator<<` |
| imports | `Core/Src/UnLinker.cpp: FObjectImport operator<<` |
| exports | `Core/Src/UnLinker.cpp: FObjectExport operator<<` |
| name version branch | `Core/Src/UnName.cpp: FNameEntry operator<<` |
| load sequence | `Core/Src/UnLinker.cpp: ULinkerLoad::ULinkerLoad` |
| QuickMD5 | same constructor; `ULinker::QuickMD5` |
| payload boundary/mismatch behavior | `ULinkerLoad::Preload` |
| missing-class behavior | `ULinkerLoad::CreateExport` |
| disabled ClassRemap | `ULinkerLoad::VerifyImport`, `#if 0 //!!MERGE` |

## Deferred

The next specification covers **Unreal II dependency and import resolution**. It must use this same source tree and must document the actual Unreal II `VerifyImport` behavior rather than copying UT99 fallback rules.
