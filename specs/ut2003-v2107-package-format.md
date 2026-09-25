# Unreal Tournament 2003 v2107 Package Format and Reader

## Scope

This specification documents package serialization and reader behavior from the supplied UT2003 source only:

- Repository: `ardenee/Unreal_Tournament_2003_v2107`
- Branch: `main`
- `ENGINE_VERSION = 1107 + DEMO_VERSION_OFFSET`
- `PACKAGE_FILE_VERSION = 120`
- `PACKAGE_FILE_VERSION_LICENSEE = 0x1C`
- `PACKAGE_MIN_VERSION = 60`

The repository name identifies this source set as Unreal Tournament 2003 v2107. The engine constant in the supplied Core source is 1107 plus the build's demo offset. These are recorded separately rather than conflated.

No UE2.5, Unreal II, UT2004, or other source is used to fill missing behavior.

## Authoritative source references

| Area | Source |
|---|---|
| versions/tag | `Core/Inc/UnObjVer.h` |
| summary and linker declarations | `Core/Inc/UnLinker.h` |
| archive primitives | `Core/Inc/UnArc.h` |
| loader/read behavior | `Core/Src/UnLinker.cpp` |
| compact index | `Core/Src/UnObj.cpp` |
| FString | `Core/Src/UnMisc.cpp` |
| names | `Core/Src/UnName.cpp` |

## Identification and versions

`PACKAGE_FILE_TAG = 0x9E2A83C1`.

Normal little-endian file bytes are:

`C1 83 2A 9E`

Current source constants:

- Epic package version: 120
- licensee package version: 28 (`0x1C`)
- minimum package version: 60

## Raw FileVersion and Epic/licensee split

`FPackageFileSummary` stores one 32-bit `FileVersion`.

The source decodes:

- Epic = `FileVersion & 0xffff`
- licensee = `(FileVersion >> 16) & 0xffff`

`SetFileVersions(Epic, Licensee)` stores Epic alone when `GSys->LicenseeMode == 0`; otherwise it stores `(Licensee << 16) | Epic`.

The saver calls:

`Summary.SetFileVersions(PACKAGE_FILE_VERSION, PACKAGE_FILE_VERSION_LICENSEE)`

UnrealDB should preserve raw, Epic, and licensee values.

## Summary magic guard

A UT2003-specific detail visible in the supplied header serializer is important.

The summary serializer first reads/writes `Tag`, then continues with the rest of the summary only when:

`!Ar.IsLoading() || Sum.Tag == PACKAGE_FILE_TAG`

Therefore, while loading, a bad magic tag prevents the serializer itself from consuming FileVersion and subsequent summary fields.

The linker then explicitly checks `Summary.Tag` and aborts on mismatch.

UnrealDB should not parse arbitrary bytes as a UT2003 summary after magic failure.

## Primitive serialization

Persistent fixed-width values use the archive byte-order rules from `UnArc.h`. On the normal Intel target the package representation is little-endian.

Only source fields wrapped with `AR_INDEX` are compact indices.

## Compact index

The source compact-index serializer uses the UE1/UE2 signed variable-length representation:

- 1-5 bytes;
- first byte bit 7 sign;
- first byte bit 6 continuation;
- first byte low 6 magnitude bits;
- bytes 1-3 use bit 7 continuation and low 7 magnitude bits;
- byte 4 carries remaining magnitude.

UnrealDB must implement the exact source algorithm rather than a generic signed-varint substitute.

## FString

UT2003 serializes FString length as a signed compact index:

`SaveNum = appIsPureAnsi(*A) ? A.Num() : -A.Num()`

On load:

- nonnegative -> ANSI bytes;
- negative -> `Abs(SaveNum)` Unicode code units;
- a loaded one-element string is normalized to empty.

Unlike the later UE2.5 FString source reviewed previously, this UT2003 serializer contains **no `ArMaxSerializeSize` rejection check**.

Therefore an UnrealDB size restriction must not be attributed to this UT2003 FString format unless independently justified by another exact source path.

## Package summary layout

After valid Tag, serialized order is:

1. `FileVersion` — INT
2. `PackageFlags` — DWORD
3. `NameCount` — INT
4. `NameOffset` — INT
5. `ExportCount` — INT
6. `ExportOffset` — INT
7. `ImportCount` — INT
8. `ImportOffset` — INT

Including Tag, complete leading order is:

1. Tag
2. FileVersion
3. PackageFlags
4. NameCount
5. NameOffset
6. ExportCount
7. ExportOffset
8. ImportCount
9. ImportOffset

### Epic version >= 68

Then:

1. Guid
2. GenerationCount
3. generation entries, each:
   - ExportCount
   - NameCount

### Epic version < 68

Then:

1. HeritageCount
2. HeritageOffset

The serializer saves the current position, seeks to HeritageOffset, reads HeritageCount GUIDs into `Sum.Guid`, restores position, and synthesizes one generation on load.

The branch uses low-16-bit Epic version.

## Name table

`FNameEntry`:

### Version < 64

Loading only:

- read null-terminated ANSI bytes;
- convert each to TCHAR;
- then serialize Flags.

### Version >= 64

- serialize FString;
- copy the complete resulting string with `appStrcpy(E.Name, *Str)`;
- serialize Flags.

This differs from the supplied UE2.5 source, which copies `Str.Left(NAME_SIZE-1)`. The UT2003 source does not perform that explicit truncation in this serializer.

UnrealDB must preserve this source distinction rather than silently treating the two readers as identical.

## Runtime name filtering

When constructing NameMap, UT2003 retains a name only when:

`NameEntry.Flags & _ContextFlags`

Otherwise runtime NameMap receives `NAME_None`.

This is runtime load-context behavior. A catalogue parser should retain the actual serialized name and flags.

## Import table

The serialized import fields are:

1. ClassPackage — FName
2. ClassName — FName
3. PackageIndex — fixed INT
4. ObjectName — FName

On loading, runtime fields are reset:

- SourceIndex = INDEX_NONE
- XObject = NULL

`SourceLinker` is initialized by the import constructor and is runtime state.

## Export table

The supplied declarations serialize export fields in this order:

1. ClassIndex — compact index
2. SuperIndex — compact index
3. PackageIndex — fixed INT
4. ObjectName — FName
5. ObjectFlags — DWORD
6. SerialSize — compact index
7. SerialOffset — compact index only when SerialSize is nonzero

PackageIndex is explicitly not compact.

## FName references

The linker reads a compact name-table index, checks `NameMap.IsValidIndex`, and resolves through NameMap.

Invalid indices produce a `Bad name index` fatal error.

## Object references

Object references use a compact signed index.

`IndexToObject` semantics:

- positive -> ExportMap[Index - 1]
- negative -> ImportMap[-Index - 1]
- zero -> null

The relevant map is bounds-checked.

## Full object paths

`GetImportFullName` follows negative import PackageIndex parents until zero.

`GetExportFullName` follows positive export PackageIndex parents until zero.

These signed parent chains are part of the structural package model and should be preserved by UnrealDB.

## Export class identity

`GetExportClassName`:

- ClassIndex < 0 -> referenced import ObjectName
- ClassIndex > 0 -> referenced export ObjectName
- zero -> Class

`GetExportClassPackage`:

- ClassIndex < 0 -> class import's parent import ObjectName
- ClassIndex > 0 -> LinkerRoot package name
- zero -> Core

## Loader sequence

`ULinkerLoad`:

1. creates ordinary file reader;
2. rejects a duplicate linker root;
3. initializes ArVer to 120 and ArLicenseeVer to 0x1C;
4. enables loading/persistent state;
5. reads summary;
6. checks package tag;
7. only after tag check, sets ArVer/ArLicenseeVer from the summary;
8. copies PackageFlags;
9. handles pre-minimum version through interactive `YesNof`;
10. allocates tables from summary counts;
11. seeks/loads names;
12. records end of name table;
13. seeks/loads imports;
14. seeks/loads exports;
15. computes QuickMD5;
16. builds export hash;
17. adds linker to global loaders;
18. verifies imports unless `LOAD_NoVerify`;
19. sets Success.

The tag-before-version-state ordering is explicit in this UT2003 source.

## QuickMD5

Part 1 is bytes from file offset 0 through the position immediately after the name table.

Part 2 starts at `Summary.ImportOffset` and extends through the position after export-table loading.

Both are fed into one MD5 context.

The 16-byte result is formatted as lowercase 32-character hexadecimal.

This is neither the package GUID nor necessarily the whole-file MD5.

## Export hash

The 256-bucket runtime hash uses:

`ObjectName + 7 * ClassName + 31 * ClassPackage`

in terms of the respective FName indices.

It is built after import/export tables are loaded because class identity can depend on them.

## Payload boundaries

`Preload`:

1. saves current position;
2. seeks SerialOffset;
3. precaches SerialSize;
4. clears RF_NeedLoad;
5. sets RF_Preloading;
6. serializes the object;
7. clears RF_Preloading;
8. verifies consumed bytes;
9. restores position.

If consumed bytes do not equal SerialSize, UT2003 calls fatal `appErrorf(SerialSize,...)`.

## CreateExport

UT2003 resolves `LoadClass = IndexToObject(Export.ClassIndex)`.

If LoadClass is null, it unconditionally substitutes `UClass::StaticClass()`.

This is an important difference from the supplied UE2.5 source. UE2.5 first returns null when a **nonzero** ClassIndex cannot be resolved; this UT2003 source does not contain that guard.

The reader then:

- requires LoadClass itself to be a UClass instance;
- skips Camera and PlayerInput classes;
- preloads LoadClass;
- resolves outer from PackageIndex or LinkerRoot;
- constructs the export with serialized load flags plus RF_NeedLoad and RF_NeedPostLoad;
- sets linker/index;
- assigns SuperField for UStruct-derived exports with nonzero SuperIndex;
- binds UClass exports to C++.

UnrealDB must not import UE2.5's missing-nonzero-class behavior into UT2003.

## LinksToCode

Base `ULinker::LinksToCode()` returns false.

UT2003 `ULinkerLoad::LinksToCode()` returns `ContainsCode()`.

This differs from the supplied UE2.5 implementation, whose linker-load implementation checks exports/SuperIndex directly.

The meaning and implementation of `ContainsCode()` belong to its own source path; UnrealDB should not replace it with the UE2.5 heuristic merely because both functions serve a similarly named purpose.

## Compression and encryption

The reviewed UT2003 package linker uses an ordinary `CreateFileReader` and direct seeks over summary, names, imports, exports, and payloads.

The summary shown here contains no package compression/encryption descriptor.

No package-level compression/encryption scheme should therefore be invented for this exact reader path. Redirect/archive formats are separate specifications.

## Source-specific differences already proven

Compared with the supplied UE2.5 source, UT2003 differs in at least these directly verified areas:

- package version 120 vs 126;
- licensee version 0x1C vs 0;
- summary serializer has a magic-tag guard before reading remaining fields;
- FString lacks UE2.5's ArMaxSerializeSize check;
- >=64 FNameEntry copies full FString rather than explicit `Left(NAME_SIZE-1)`;
- CreateExport substitutes UClass for any null LoadClass rather than rejecting unresolved nonzero ClassIndex;
- LinksToCode calls `ContainsCode()` rather than using the UE2.5 implementation.

These differences prove that the UT2003 reader must remain source-specific.

## Runtime/config-only behavior

The package bytes do not establish:

- filesystem search paths;
- runtime-loaded packages;
- native object/class availability;
- editor/client/server context;
- arbitrary INI remaps.

UnrealDB must not infer such behavior into the package parser.

## UnrealDB conformance requirements

For UT2003 v2107 source compatibility:

1. require exact package magic before interpreting the remainder as this summary;
2. retain raw, Epic, and licensee versions;
3. use Epic version for summary/name version branches;
4. use exact compact-index encoding;
5. use exact signed FString encoding;
6. do not attribute an ArMaxSerializeSize FString cap to this source;
7. implement exact <64 and >=64 FNameEntry branches;
8. preserve UT2003's full-string >=64 FName copy behavior as a source distinction;
9. preserve fixed INT PackageIndex;
10. preserve conditional compact SerialOffset;
11. preserve signed object-index semantics;
12. preserve import/export parent chains;
13. treat payload SerialSize mismatch as fatal when emulating this reader;
14. preserve UT2003 CreateExport's null-class behavior;
15. do not substitute UE2.5 LinksToCode logic;
16. do not invent package compression/encryption;
17. do not import behavior from UT2004, UE2.5, or Unreal II without source proof.

## Source-reference matrix

| Rule | Source |
|---|---|
| engine/package versions | `Core/Inc/UnObjVer.h` |
| summary/tag guard | `Core/Inc/UnLinker.h: FPackageFileSummary operator<<` |
| version split | `Core/Inc/UnLinker.h` |
| archive primitives | `Core/Inc/UnArc.h` |
| compact index | `Core/Src/UnObj.cpp` |
| FString | `Core/Src/UnMisc.cpp` |
| FNameEntry | `Core/Src/UnName.cpp` |
| imports/exports | `Core/Inc/UnLinker.h` |
| loader sequence | `Core/Src/UnLinker.cpp: ULinkerLoad::ULinkerLoad` |
| QuickMD5 | `Core/Src/UnLinker.cpp` |
| hash | `Core/Src/UnLinker.cpp: HashNames` |
| class identity | `GetExportClassName`, `GetExportClassPackage` |
| payload boundary | `Preload` |
| export construction | `CreateExport` |
| signed object references | `IndexToObject` |
| code classification | `ULinkerLoad::LinksToCode` |

## Next specification

The next specification should document **UT2003 dependency and import resolution**, using only this repository and revision as authority.
