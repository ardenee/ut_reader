# Unreal Tournament 2004 Package Format and Reader

## Scope

This specification documents package serialization and reader behavior from the supplied UT2004 source only:

- Repository: `ardenee/UT2004src`
- Branch: `main`
- `ENGINE_VERSION = 3369`
- `PACKAGE_FILE_VERSION = 128`
- `PACKAGE_FILE_VERSION_LICENSEE = 0x1D`
- `PACKAGE_MIN_VERSION = 60`

No UT2003, UE2.5, Unreal II, or other implementation supplies missing behavior.

## Authoritative source references

| Area | Source |
|---|---|
| versions/tag/history | `Core/Inc/UnObjVer.h` |
| archive model | `Core/Inc/UnArc.h` |
| linker declarations | `Core/Inc/UnLinker.h` |
| package/table serializers and loader | `Core/Src/UnLinker.cpp` |
| compact index | `Core/Src/UnObj.cpp` |
| FString | `Core/Src/UnMisc.cpp` |
| FNameEntry | `Core/Src/UnName.cpp` |

## Identification and version

`PACKAGE_FILE_TAG = 0x9E2A83C1`.

Normal little-endian bytes: `C1 83 2A 9E`.

Current source constants:

- engine build 3369
- minimum network version 3180
- Epic package version 128
- licensee package version 29 / `0x1D`
- minimum package version 60

The source's package-version history explicitly records:

- 122 skeletal collision merge for SVehicle
- 123 removal of InclusiveSphereBound from FBSPNode
- 124 bBlockKarma on per-bone primitives
- 125 bBlockNonZeroExtent / bBlockZeroExtent on per-bone primitives
- 126 k-dop static-mesh collision merge
- 127 static-mesh collision for skeletal meshes
- 128 DetailMode added to FDecorationLayer

These history entries establish version purposes; they do not by themselves define unrelated serialization branches.

## Raw FileVersion

Summary stores a 32-bit FileVersion.

- Epic = low 16 bits
- licensee = high 16 bits
- GetFileVersionComplete returns raw value

SetFileVersions stores Epic only when `GSys->LicenseeMode == 0`; otherwise `(Licensee << 16) | Epic`.

UnrealDB should preserve all three representations.

## Primitive serialization

Persistent fixed-width primitives use `ByteOrderSerialize`. On normal Intel targets this is direct little-endian representation.

Compact integers are used only where source invokes `AR_INDEX`.

## Compact index

The UT2004 `FCompactIndex` serializer is the source authority for compact values.

It uses the established signed 1-5 byte representation:

- first byte: sign bit 7, continuation bit 6, low 6 magnitude bits;
- following bytes carry 7 magnitude bits with continuation through byte 3;
- fifth byte carries the remaining magnitude.

Do not replace it with a generic signed-varint codec.

## FString

FString serializes a signed compact count:

`SaveNum = appIsPureAnsi(*A) ? A.Num() : -A.Num()`

On load:

- positive/nonnegative count -> ANSI bytes;
- negative count -> Unicode code units;
- one-element loaded string normalizes to empty.

UT2004 includes the archive safety check:

`if (Ar.ArMaxSerializeSize && Abs(SaveNum) > Ar.ArMaxSerializeSize)`

which marks the archive error and critical-error states.

This is a conditional runtime archive allocation guard. It does **not** establish an unconditional package-format maximum and must not be converted into an arbitrary UnrealDB package/string limit.

## Summary magic behavior

UT2004 serializes Tag first and immediately returns from the summary serializer when Tag does not equal `PACKAGE_FILE_TAG`.

Thus invalid magic prevents FileVersion and subsequent summary fields from being consumed by this serializer.

The linker later performs an explicit tag failure check.

## Summary layout

For a valid tag, serialized order is:

1. Tag — INT
2. FileVersion — INT
3. PackageFlags — DWORD
4. NameCount — INT
5. NameOffset — INT
6. ExportCount — INT
7. ExportOffset — INT
8. ImportCount — INT
9. ImportOffset — INT

### Epic version >= 68

Then:

10. Guid
11. GenerationCount — INT
12. each generation:
   - ExportCount — INT
   - NameCount — INT

### Epic version < 68

Then:

10. HeritageCount
11. HeritageOffset

The serializer saves its position, seeks to HeritageOffset, reads HeritageCount GUIDs into Sum.Guid, restores the position, and on load synthesizes one generation from summary ExportCount/NameCount.

The branch uses the low-16-bit Epic version.

The comment `//!!67 had: return` is not enough to invent a version-67 layout.

## Name table

FNameEntry behavior:

### Version < 64

Loading only:

- read ANSI bytes through terminator;
- convert to TCHAR;
- serialize Flags.

### Version >= 64

- serialize FString;
- copy `Str.Left(NAME_SIZE-1)` into the fixed name buffer;
- serialize Flags.

This is a directly verified difference from the supplied UT2003 source, whose >=64 serializer copied the complete FString with `appStrcpy(E.Name,*Str)`.

## Runtime context filtering

After each name entry is read, NameMap receives:

- the serialized name if `NameEntry.Flags & _ContextFlags`;
- otherwise NAME_None.

The serialized name and flags still exist in the file. UnrealDB should preserve them rather than destructively applying runtime client/server/editor filtering.

## Import table

Serialized FObjectImport order:

1. ClassPackage — FName
2. ClassName — FName
3. PackageIndex — fixed INT
4. ObjectName — FName

On load:

- SourceIndex = INDEX_NONE
- XObject = NULL

SourceLinker is runtime state.

## Export table

Serialized FObjectExport order:

1. ClassIndex — compact
2. SuperIndex — compact
3. PackageIndex — fixed INT
4. ObjectName — FName
5. ObjectFlags — DWORD
6. SerialSize — compact
7. SerialOffset — compact iff SerialSize != 0

PackageIndex is not compact.

## FName references

ULinkerLoad reads a compact name index and validates it against NameMap.

An invalid name index triggers the source's fatal friendly-error path.

## UObject references

Serialized UObject references use compact signed indices.

IndexToObject:

- >0 -> export Index-1
- <0 -> import -Index-1
- 0 -> null

The selected table is bounds-checked.

## Full paths

GetImportFullName walks negative PackageIndex import parents to zero.

GetExportFullName walks positive export PackageIndex parents to zero.

UnrealDB should preserve these chains structurally.

## Export class identity

GetExportClassName:

- negative ClassIndex -> referenced import ObjectName
- positive -> referenced export ObjectName
- zero -> Class

GetExportClassPackage:

- negative -> class import's parent import ObjectName
- positive -> LinkerRoot package name
- zero -> Core

## Loader sequence

UT2004 ULinkerLoad:

1. opens ordinary file reader;
2. rejects duplicate LinkerRoot;
3. initializes MD5 context;
4. initializes ArVer=128 and ArLicenseeVer=0x1D;
5. sets loading/persistent and context state;
6. reads Summary;
7. feeds summary fields into QuickMD5 context;
8. feeds generation entries into QuickMD5 context;
9. validates tag;
10. sets ArVer/ArLicenseeVer from summary;
11. propagates PackageFlags;
12. for Epic version <60, asks YesNof rather than unconditionally rejecting;
13. allocates import/export/name maps;
14. loads names while adding selected name data to QuickMD5;
15. loads imports;
16. loads exports;
17. finalizes QuickMD5;
18. builds export hash;
19. adds linker to GObjLoaders;
20. verifies unless LOAD_NoVerify;
21. sets Success.

## UT2004 QuickMD5

This implementation is materially different from the earlier byte-range QuickMD5 implementation reviewed in other supplied UE2-family source.

UT2004 creates an MD5 context before table loading and explicitly feeds values into it.

Summary contribution consists of 32-bit updates for:

- Tag
- raw complete FileVersion
- PackageFlags
- NameCount
- NameOffset
- ExportCount
- ExportOffset
- ImportCount
- ImportOffset
- Guid A/B/C/D

Each generation contributes:

- ExportCount
- NameCount

For every loaded name entry, it then contributes:

- NameEntry.Flags as 32-bit
- NameEntry.Name[0] as 16-bit
- NameEntry.Name[1] as 16-bit

The source comment explicitly notes that an older expression accidentally hashed four bytes due to `sizeof`, and the replacement deliberately preserves a byte-safe four-byte equivalent by hashing the first two 16-bit TCHARs.

Imports and exports are loaded but this constructor does not feed their serialized bytes into the MD5 context.

The class header comment describes QuickMD5 as checking four major tables, but the executable source shown here is authoritative for the actual computation and must be followed exactly.

QuickMD5() formats the 16-byte digest as lowercase 32-character hex.

Do not substitute the earlier raw-file-range QuickMD5 algorithm.

## Export hash

After imports and exports load, UT2004 builds a 256-bucket export hash using ObjectName, GetExportClassName and GetExportClassPackage.

The hash is built only after both object tables because class identity can reference them.

## Payload boundary

Preload:

1. recursively preloads a struct's SuperField when applicable;
2. saves reader position;
3. seeks SerialOffset;
4. precaches SerialSize;
5. clears RF_NeedLoad;
6. sets RF_Preloading;
7. calls Object->Serialize;
8. clears RF_Preloading;
9. requires consumed bytes to equal SerialSize;
10. restores saved position.

Mismatch is fatal via `appErrorf`.

## CreateExport

UT2004:

- resolves LoadClass through ClassIndex;
- if null, substitutes UClass::StaticClass();
- does not first reject an unresolved nonzero ClassIndex;
- requires resulting object to be UClass;
- skips Camera and PlayerInput;
- preloads class;
- resolves outer from PackageIndex or LinkerRoot;
- constructs object with serialized RF_Load subset plus RF_NeedLoad and RF_NeedPostLoad;
- sets linker/index;
- records loaded object;
- resolves SuperField for struct/class exports;
- binds UClass exports.

This behavior must be preserved as UT2004-specific source behavior.

## LinksToCode

Base ULinker::LinksToCode returns false.

UT2004 ULinkerLoad::LinksToCode does **not** use ContainsCode; that call is commented out.

If ExportMap is empty, it returns false.

It iterates exports and examines each export's ClassIndex:

- negative class index -> referenced ImportMap ObjectName;
- otherwise -> referenced ExportMap ObjectName;

and returns true when that class object name is `Function`.

Otherwise false.

This differs from the supplied UT2003 implementation, which returned `ContainsCode()`.

The exact code also indexes ExportMap in the nonnegative branch using the ClassIndex value as written. A source-faithful specification must record the implementation rather than silently correcting it.

## Package lookup / GUID behavior relevant to reading

UT2004's package-file search contains source-specific GUID handling.

`CheckPotentialFileGuid`:

- accepts any candidate when no GUID is requested;
- always accepts a found `.u` file without GUID comparison;
- otherwise reads the package summary;
- if summary tag is invalid, returns true;
- otherwise requires Summary.Guid == requested Guid.

`appFindPackageFile` can also search the configured cache by GUID, including a generation-level suffix.

These are runtime filesystem/package-location rules, not serialized package layout. UnrealDB should not use them to invent file-format fields.

## Compression and encryption

The reviewed normal package linker opens the package with `CreateFileReader` and uses direct seeks.

The package summary documented here contains no package compression or encryption descriptor.

No package-level compression/encryption should be invented for this exact reader. UZ2 and other containers are separate specifications.

## Proven UT2004 vs UT2003 differences

From the supplied sources:

- engine 3369 vs UT2003 source's 1107+demo offset;
- package version 128 vs 120;
- licensee 0x1D vs 0x1C;
- UT2004 >=64 FNameEntry explicitly truncates with Left(NAME_SIZE-1), unlike supplied UT2003;
- UT2004 FString includes ArMaxSerializeSize safety logic, unlike supplied UT2003;
- UT2004 QuickMD5 algorithm is explicit field/name hashing rather than the UT2003 raw-range behavior previously documented;
- UT2004 LinksToCode scans class identities for Function, while supplied UT2003 calls ContainsCode().

Therefore these readers must not be collapsed merely because both are UE2-era package formats.

## Runtime/config-only behavior

The package bytes do not establish configured filesystem paths, cache directories, language search behavior, runtime loaded objects/classes, client/server/editor context, or arbitrary remap configuration.

These must not be inferred into the byte parser.

## UnrealDB conformance requirements

1. require exact package magic;
2. preserve raw/Epic/licensee versions;
3. use Epic version for version branches;
4. implement exact compact index;
5. implement signed ANSI/Unicode FString;
6. treat ArMaxSerializeSize as conditional runtime protection, not an unconditional file-format cap;
7. implement exact FNameEntry version branches;
8. preserve fixed PackageIndex and conditional compact SerialOffset;
9. preserve signed object-index semantics;
10. preserve parent chains;
11. make SerialSize mismatch fatal when emulating the source reader;
12. preserve UT2004 CreateExport behavior;
13. reproduce UT2004 QuickMD5 only from the exact source inputs above if QuickMD5 compatibility is needed;
14. do not substitute another engine/game's QuickMD5;
15. preserve UT2004 LinksToCode behavior separately;
16. do not invent package compression/encryption;
17. do not import UT2003/UE2.5 behavior without UT2004 source proof.

## Source-reference matrix

| Rule | Source |
|---|---|
| versions/history | `Core/Inc/UnObjVer.h` |
| archive state/byte order | `Core/Inc/UnArc.h` |
| summary | `Core/Src/UnLinker.cpp: FPackageFileSummary operator<<` |
| import/export serialization | `Core/Src/UnLinker.cpp` |
| compact index | `Core/Src/UnObj.cpp` |
| FString | `Core/Src/UnMisc.cpp` |
| FNameEntry | `Core/Src/UnName.cpp` |
| loader sequence | `ULinkerLoad::ULinkerLoad` |
| QuickMD5 | `ULinkerLoad::ULinkerLoad`, `ULinker::QuickMD5` |
| payload | `ULinkerLoad::Preload` |
| export construction | `ULinkerLoad::CreateExport` |
| signed refs | `ULinkerLoad::IndexToObject` |
| code detection | `ULinkerLoad::LinksToCode` |
| package GUID search | `Core/Src/UnMisc.cpp: CheckPotentialFileGuid, appFindPackageFile` |

## Next specification

The next target should be **UT2004 dependency and import resolution**, using only `ardenee/UT2004src` as authority.
