# UE2.5 / Unreal Warfare Package Format and Reader

## Scope

This specification documents package serialization and reader behavior from the supplied UE2.5 source only:

- Repository: `ardenee/UE2.5`
- Branch: `main`
- Source tree: `Unreal Engine [v2.5]_ Unreal Warfare [09-29-2007]`
- `ENGINE_VERSION = 1226 + DEMO_VERSION_OFFSET`
- `PACKAGE_FILE_VERSION = 126`
- `PACKAGE_FILE_VERSION_LICENSEE = 0`
- `PACKAGE_MIN_VERSION = 60`

This source tree identifies itself as Unreal Engine v2.5 / Unreal Warfare. Its rules are documented independently. Similarity with Unreal II, UT2003, or UT2004 is not evidence that those games use identical behavior.

## Authoritative source references

| Area | Source |
|---|---|
| version constants | `Core/Inc/UnObjVer.h` |
| archive primitives/version state | `Core/Inc/UnArc.h` |
| linker structures | `Core/Inc/UnLinker.h` |
| package/import/export serializers and load path | `Core/Src/UnLinker.cpp` |
| compact index | `Core/Src/UnObj.cpp` |
| name serialization | `Core/Src/UnName.cpp` |
| FString serialization | `Core/Src/UnMisc.cpp` |

## Package identification

`PACKAGE_FILE_TAG = 0x9E2A83C1`.

On the normal little-endian persistent representation the leading bytes are:

`C1 83 2A 9E`

The current package version is 126 and minimum backward-compatible version is 60.

## Combined Epic/licensee version

The summary stores one 32-bit `FileVersion`, but this source interprets it as:

- Epic version = `FileVersion & 0xffff`
- licensee version = `(FileVersion >> 16) & 0xffff`

`SetFileVersions(Epic, Licensee)` stores only Epic when `GSys->LicenseeMode == 0`; otherwise it combines `(Licensee << 16) | Epic`.

The loader sets:

- `ArVer = Summary.GetFileVersion()`
- `ArLicenseeVer = Summary.GetFileVersionLicensee()`

after reading the summary.

UnrealDB must retain the raw combined value and both decoded components. Version gates shown below use the Epic version.

## Primitive serialization

Persistent fixed-width primitive fields use the archive's byte-order serialization rules. On the normal Intel target these are stored little-endian.

A field is not a compact index merely because it contains an index. Only source expressions using `AR_INDEX` use compact-index encoding.

## Compact index

`FCompactIndex` is serialized by the source implementation in `Core/Src/UnObj.cpp`.

Encoding is one to five bytes:

- byte 0 bit 7 = sign;
- byte 0 bit 6 = continuation;
- byte 0 low six bits = magnitude bits;
- bytes 1-3 bit 7 = continuation and low seven bits = magnitude;
- byte 4 = remaining magnitude.

The loader reconstructs the value exactly according to that algorithm and applies the sign from byte 0.

## FString

The exact serializer in `Core/Src/UnMisc.cpp` calculates:

`SaveNum = appIsPureAnsi(*A) ? A.Num() : -A.Num()`

and writes `SaveNum` as a compact index.

On load:

- nonnegative count -> that many ANSI bytes;
- negative count -> `Abs(SaveNum)` Unicode code units;
- the serialized count includes the string terminator for a nonempty FString;
- a one-element loaded string is normalized to empty.

`ArMaxSerializeSize`, when nonzero, can reject an allocation larger than that runtime archive limit. This is not evidence of a package-format maximum and must not become an arbitrary UnrealDB package-size restriction.

## Package summary

`FPackageFileSummary` serializes in this exact order:

1. `Tag` — INT
2. raw `FileVersion` — INT
3. `PackageFlags` — DWORD
4. `NameCount` — INT
5. `NameOffset` — INT
6. `ExportCount` — INT
7. `ExportOffset` — INT
8. `ImportCount` — INT
9. `ImportOffset` — INT

### Epic version >= 68

Then:

1. `Guid`
2. `GenerationCount` — INT
3. each generation:
   - `ExportCount` — INT
   - `NameCount` — INT

The branch is based on `GetFileVersion()`, not the raw combined 32-bit value.

### Epic version < 68

Then:

1. `HeritageCount` — INT
2. `HeritageOffset` — INT

The serializer saves its current position, seeks to the heritage offset, reads each GUID into `Sum.Guid`, restores the saved position, and on load synthesizes one generation using current export/name counts.

The source comment `//!!67 had: return` is historical commentary only. It is not enough to invent an otherwise undocumented version-67 layout.

## Name table

The loader seeks to `Summary.NameOffset` and reads `Summary.NameCount` entries.

`FNameEntry` behavior:

### Version < 64

Loading only. Read zero-terminated ANSI bytes one by one, convert them to TCHAR, then read `Flags`.

### Version >= 64

Serialize an `FString`, copy `Str.Left(NAME_SIZE-1)` into the fixed name buffer, then serialize `Flags`.

The linker's runtime `NameMap` retains the name only when `NameEntry.Flags & _ContextFlags`; otherwise it inserts `NAME_None`. UnrealDB's parser should still preserve the serialized name and flags rather than treating runtime context filtering as file-format absence.

## Import table

Each `FObjectImport` serializes:

1. `ClassPackage` — FName reference
2. `ClassName` — FName reference
3. `PackageIndex` — fixed-width INT
4. `ObjectName` — FName reference

On load:

- `SourceIndex = INDEX_NONE`
- `XObject = NULL`

`SourceLinker`, `SourceIndex`, and `XObject` are runtime linkage state, not serialized import fields.

## Export table

Each `FObjectExport` serializes:

1. `ClassIndex` — compact index
2. `SuperIndex` — compact index
3. `PackageIndex` — fixed-width INT
4. `ObjectName` — FName reference
5. `ObjectFlags` — DWORD
6. `SerialSize` — compact index
7. `SerialOffset` — compact index, only when `SerialSize != 0`

Therefore `PackageIndex` must not be decoded as a compact index.

## FName references

The load linker reads an FName reference as a compact name-table index.

It verifies `NameMap.IsValidIndex(NameIndex)`; an invalid index produces `Bad name index`.

The resulting runtime FName comes from `NameMap(NameIndex)`.

## UObject references and signed index semantics

Object references are read as compact signed indices and passed to `IndexToObject`.

Semantics:

- positive N -> export `N - 1`;
- negative N -> import `-N - 1`;
- zero -> null.

Both directions validate table bounds before object creation.

## Import and export full paths

`GetImportFullName(i)` starts from import `i`, follows negative import `PackageIndex` values toward zero, and constructs the dotted object path. The displayed class prefix is the starting import's `ClassName`.

`GetExportFullName(i)` starts from one-based export index `i+1`, follows positive export `PackageIndex` parents toward zero, constructs the dotted path, and prefixes the resolved class name.

These chains are source-backed and are the correct structural basis for dependency/object paths.

## Export class identity

`GetExportClassName`:

- negative ClassIndex -> referenced import's ObjectName;
- positive -> referenced local export's ObjectName;
- zero -> `Class`.

`GetExportClassPackage`:

- negative -> the class import's parent import ObjectName;
- positive -> current linker-root package name;
- zero -> `Core`.

## Loader sequence

`ULinkerLoad::ULinkerLoad` performs:

1. create ordinary file reader;
2. reject duplicate linker root;
3. initialize archive version 126/licensee 0 and loading/persistent state;
4. deserialize summary;
5. replace `ArVer` and `ArLicenseeVer` with summary values;
6. propagate package flags;
7. verify package tag;
8. apply minimum-version policy;
9. allocate import/export/name maps from summary counts;
10. seek/read names;
11. record end of name table;
12. seek/read imports;
13. seek/read exports;
14. compute QuickMD5;
15. build 256-bucket export hash;
16. add linker to global loader collection;
17. verify imports unless `LOAD_NoVerify`;
18. mark success.

## Minimum-version behavior

If `Summary.GetFileVersion() < PACKAGE_MIN_VERSION`, this source asks through `GWarn->YesNof(OldVersion,...)`.

It does not unconditionally reject every pre-60 package.

A headless catalogue reader may choose not to support such interactive legacy loading, but that is an UnrealDB policy distinction and must not be described as the source engine's behavior.

## QuickMD5

The source computes a structural QuickMD5 after loading names, imports, and exports.

Part 1:

- begins at offset 0;
- ends at the archive position immediately after the name table.

Part 2:

- begins at `Summary.ImportOffset`;
- length is the archive position immediately after export-table loading minus `Summary.ImportOffset`.

Both ranges are fed sequentially into one MD5 context.

`QuickMD5()` renders the 16-byte digest as 32 lowercase hexadecimal characters.

This is not the package GUID and is not necessarily a whole-file MD5.

## Export hash

The loader builds a 256-entry hash table after all import/export data is available.

Each export is hashed from:

- object name;
- resolved export class name;
- resolved export class package.

The hash is an acceleration structure generated at load time, not serialized package data.

## Export payload boundaries

`Preload`:

1. stores current file position;
2. seeks to `Export.SerialOffset`;
3. precaches `Export.SerialSize`;
4. clears `RF_NeedLoad`;
5. sets `RF_Preloading`;
6. calls `Object->Serialize(*this)`;
7. clears `RF_Preloading`;
8. compares consumed bytes with `SerialSize`;
9. restores the saved file position.

### Size mismatch is fatal in this UE2.5 source

If:

`Tell() - Export.SerialOffset != Export.SerialSize`

this source calls `appErrorf` with the `SerialSize` error.

This differs from the supplied Unreal II tree, whose corresponding path logs a warning. UnrealDB must not merge those two behaviors simply because the surrounding package layout is highly similar.

## Export construction

`CreateExport` resolves the class using `ClassIndex`.

If the class cannot be resolved and `ClassIndex != 0`, it returns null. The source comment describes this as a hack to load packages with classes that do not exist.

If the class remains null with `ClassIndex == 0`, it uses `UClass::StaticClass()`.

Exports whose resolved class is `Camera` or `PlayerInput` are skipped.

The outer is:

- `IndexToObject(Export.PackageIndex)` when nonzero;
- `LinkerRoot` when zero.

A newly constructed object receives load flags from `Export.ObjectFlags & RF_Load`, plus `RF_NeedLoad | RF_NeedPostLoad`.

For UStruct-derived objects, nonzero `SuperIndex` resolves the super field.

For UClass objects, the class is bound to C++.

## LinksToCode

Base `ULinker::LinksToCode()` returns false.

`ULinkerLoad::LinksToCode()` returns false for no exports; otherwise it returns true when any export has a nonzero `SuperIndex`.

This is a runtime classification helper, not a serialized package-summary field.

## Compression and encryption

The package load path reviewed here uses `GFileManager->CreateFileReader` and direct seek/read operations over summary, tables, and export payloads.

The package summary defined by this source has no package compression/encryption descriptor.

Therefore this specification establishes no package-level compression or encryption layer for this reader path. UZ/UZ2 or other wrappers/containers require separate source-backed specifications.

## Comparison with supplied Unreal II source

The UE2.5 tree and supplied Unreal II tree share much of the structural serialization, including version 126, licensee splitting, FString encoding, QuickMD5, table layouts, and export construction.

However, this specification is based on the UE2.5 repository itself.

One directly verified behavioral difference is critical:

- supplied Unreal II `Preload`: SerialSize mismatch -> warning;
- this UE2.5 `Preload`: SerialSize mismatch -> fatal `appErrorf`.

This demonstrates why UnrealDB must keep target-specific reader behavior source-backed rather than assuming all version-126 UE2-family implementations are interchangeable.

## Runtime/config boundary

Runtime context controls:

- edit/client/server name filtering;
- object/class availability;
- object construction;
- native class binding;
- import verification;
- filesystem package lookup.

Those states cannot all be reconstructed from package bytes.

This package-format specification therefore does not invent runtime mappings, aliases, or config-driven remaps.

## UnrealDB conformance requirements

For this UE2.5 source target UnrealDB should:

1. identify package magic exactly;
2. retain raw, Epic, and licensee version values;
3. use Epic version for the documented format branches;
4. implement exact compact-index serialization;
5. implement exact signed-count FString serialization;
6. implement the <64 and >=64 name branches;
7. implement the <68 and >=68 summary branches;
8. preserve fixed-width `PackageIndex` fields;
9. preserve conditional SerialOffset serialization;
10. use exact signed object-reference semantics;
11. retain import/export outer chains;
12. expose exact payload offsets/sizes;
13. not impose `ArMaxSerializeSize` as a package-format constant;
14. keep UE2.5's fatal payload-size mismatch behavior distinct from Unreal II;
15. not infer compression/encryption absent from this source path;
16. not borrow UT2003/UT2004/Unreal II rules unless separately proven by their source.

## Source-reference matrix

| Rule | Source |
|---|---|
| tag/current/minimum versions | `Core/Inc/UnObjVer.h` |
| Epic/licensee split | `Core/Inc/UnLinker.h: FPackageFileSummary` |
| archive version state | `Core/Inc/UnArc.h` |
| compact index | `Core/Src/UnObj.cpp: FCompactIndex operator<<` |
| FString | `Core/Src/UnMisc.cpp: FString operator<<` |
| FNameEntry | `Core/Src/UnName.cpp: FNameEntry operator<<` |
| summary | `Core/Src/UnLinker.cpp: FPackageFileSummary operator<<` |
| imports | `Core/Src/UnLinker.cpp: FObjectImport operator<<` |
| exports | `Core/Src/UnLinker.cpp: FObjectExport operator<<` |
| full paths | `ULinker::GetImportFullName`, `GetExportFullName` |
| class identity | `ULinkerLoad::GetExportClassName`, `GetExportClassPackage` |
| loader sequence | `ULinkerLoad::ULinkerLoad` |
| QuickMD5 | `ULinkerLoad::ULinkerLoad`, `ULinker::QuickMD5` |
| object index | `ULinkerLoad::IndexToObject` |
| payload boundaries | `ULinkerLoad::Preload` |
| export construction | `ULinkerLoad::CreateExport` |
| code classification | `ULinkerLoad::LinksToCode` |

## Next specification

The next specification should document **UE2.5 dependency and import resolution** from this same source tree. It must be independently compared with the Unreal II dependency implementation, especially where apparently similar code has materially different active/disabled branches.
