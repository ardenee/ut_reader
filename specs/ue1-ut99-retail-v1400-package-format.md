# UE1 / Unreal Tournament 99 Retail v1.400 Package Format and Reader

## Scope

This specification documents the Unreal package format and package-reader behavior proven by the retail Unreal Tournament v1.400 source tree dated 1999-11-30.

- Repository: `ardenee/UT99src`
- Branch: `main`
- Tree: `Unreal Tournament [v1.400] [1999-11-30] (Retail)`
- Engine build: 400
- Package file version written by this revision: 68
- Minimum-version policy: 60

The supplied retail source is authoritative. Rules are not borrowed from UE2, later UT99 patches, third-party readers, or UnrealDB's historical implementation. `ardenee/UT99src-ext` is discussed only as an explicitly later revision.

## Authoritative source references

| Rule area | Retail source | Symbol |
|---|---|---|
| Package tag and versions | `Core/Inc/UnObjVer.h` | `PACKAGE_FILE_TAG`, `PACKAGE_FILE_VERSION`, `PACKAGE_MIN_VERSION` |
| Persistent primitive byte order | `Core/Inc/UnArc.h` | `FArchive::ByteOrderSerialize` and primitive `operator<<` overloads |
| Compact indices | `Core/Src/UnObj.cpp` | `operator<<(FArchive&, FCompactIndex&)` |
| Summary/generations | `Core/Src/UnLinker.h` | `FPackageFileSummary`, `FGenerationInfo` serializers |
| Names | `Core/Src/UnName.cpp` | `operator<<(FArchive&, FNameEntry&)` |
| Imports/exports | `Core/Src/UnLinker.h` | `FObjectImport`, `FObjectExport` serializers |
| Reader/validation | `Core/Src/UnLinker.h` | `ULinkerLoad` |
| Object index semantics | `Core/Src/UnLinker.h` | `IndexToObject` |
| Class identity | `Core/Src/UnLinker.h` | `GetExportClassName`, `GetExportClassPackage` |
| Outer/path traversal | `Core/Src/UnLinker.h` | `GetImportFullName`, `GetExportFullName` |
| Export payload bounds | `Core/Src/UnLinker.h` | `Preload` |

Paths are relative to the retail tree above.

## Identification

`PACKAGE_FILE_TAG = 0x9E2A83C1`. The loader deserializes the summary and rejects it when `Summary.Tag != PACKAGE_FILE_TAG`. With the persistent little-endian Intel representation, offset zero contains `C1 83 2A 9E`.

Retail v1.400 defines `PACKAGE_FILE_VERSION = 68` and `PACKAGE_MIN_VERSION = 60`. The loader initially sets `ArVer` to 68, reads the summary, then sets `ArVer = Summary.FileVersion`. A version below 60 enters the engine's old-version confirmation path and aborts if permission is refused; 60 is therefore the retail engine's policy, not an invented structural limit.

## Persistent primitive serialization

`FArchive::ByteOrderSerialize` writes/reads persistent integral primitives in Intel little-endian byte order; non-Intel hosts reverse the bytes for persistent archives. Fixed fields here therefore use their C++ widths: BYTE 1, WORD 2, INT/DWORD 4, QWORD 8 bytes.

Fields passed through `AR_INDEX` are not fixed 32-bit integers.

## Compact index encoding

`AR_INDEX(x)` reinterprets the integer as `FCompactIndex`. The source serializer uses one to five bytes.

Byte 0:
- bit 7 = sign (set for negative)
- bit 6 = continuation
- bits 0..5 = low six magnitude bits

Bytes 1-3:
- bit 7 = continuation
- bits 0..6 = seven magnitude bits

Byte 4, when reached, stores the remaining magnitude byte; there is no further continuation branch.

On decoding, the source accumulates the later bytes in seven-bit groups, then shifts six bits and adds `B0 & 0x3f`; if `B0 & 0x80` is set it negates the result. This exact codec is required for every `AR_INDEX` field.

## Package summary

The summary starts at offset 0 and serializes in this exact order:

| # | Field | Encoding |
|---:|---|---|
| 1 | `Tag` | INT |
| 2 | `FileVersion` | INT |
| 3 | `PackageFlags` | DWORD |
| 4 | `NameCount` | INT |
| 5 | `NameOffset` | INT |
| 6 | `ExportCount` | INT |
| 7 | `ExportOffset` | INT |
| 8 | `ImportCount` | INT |
| 9 | `ImportOffset` | INT |

### Version >= 68

The serializer then reads/writes:
1. `Guid`
2. `GenerationCount` as INT
3. exactly `GenerationCount` generation records

Each `FGenerationInfo` is exactly `ExportCount` INT followed by `NameCount` INT.

### Version < 68

The summary instead contains `HeritageCount` INT and `HeritageOffset` INT. The loader saves its current position, seeks to `HeritageOffset`, reads `HeritageCount` GUIDs sequentially into `Sum.Guid`, then seeks back. If multiple GUIDs exist, this implementation leaves the last one in `Sum.Guid`.

On loading it creates one synthetic generation from the summary's `ExportCount` and `NameCount`.

A pre-68 package must therefore not be parsed as a v68 GUID/generation-array header.

## Name table

The loader seeks to `NameOffset` and reads exactly `NameCount` `FNameEntry` records.

For `Ar.Ver() < 64`, the name is read one ANSI byte at a time through the terminating zero byte. Entry flags follow it.

For version 64 and later, the name is serialized through `FString`, copied into the entry's name buffer, and then the entry flags are serialized. Version 64 is therefore an authoritative name-entry format boundary.

The retail runtime only maps a name into its runtime `NameMap` when its flags intersect the edit/client/server context flags; otherwise it inserts `NAME_None`. That is runtime context filtering, not absence of the serialized name. UnrealDB should preserve the actual on-disk entry.

## Import table

The loader seeks to `ImportOffset` and reads exactly `ImportCount` records.

Each `FObjectImport` serializes:
1. `ClassPackage` — FName
2. `ClassName` — FName
3. `PackageIndex` — **normal fixed-width INT**
4. `ObjectName` — FName

`SourceIndex`, `XObject`, and `SourceLinker` are runtime state and are not serialized.

## Export table

The loader seeks to `ExportOffset` and reads exactly `ExportCount` records.

Each `FObjectExport` serializes:
1. `ClassIndex` — compact index
2. `SuperIndex` — compact index
3. `PackageIndex` — **normal fixed-width INT**
4. `ObjectName` — FName
5. `ObjectFlags` — DWORD
6. `SerialSize` — compact index
7. `SerialOffset` — compact index **only when SerialSize != 0**

`_Object` and `_iHashNext` are runtime-only.

The conditional `SerialOffset` is mandatory format behavior: zero-sized exports do not serialize it.

## FName references in tables

`ULinkerLoad::operator<<(FName&)` reads a compact `NAME_INDEX` through `AR_INDEX`, validates it against `NameMap`, then resolves the name. Import/export FNames are therefore compact name-table references, not inline strings. An invalid name index is an engine error.

## Signed object-index semantics

`ULinkerLoad::IndexToObject` proves:
- index > 0 -> export `index - 1`
- index < 0 -> import `-index - 1`
- index == 0 -> null

It validates the resulting table index.

Import outer traversal follows negative `PackageIndex` values via `-PackageIndex-1`. Export outer/path traversal uses positive one-based export references and terminates at zero/the linker root. These signed semantics must not be flattened to unsigned indices.

## Export class identity

For an export's class name:
- `ClassIndex < 0`: imported class object's `ObjectName`
- `ClassIndex > 0`: local export `ClassIndex-1` object's `ObjectName`
- `ClassIndex == 0`: `Class`

For class package:
- imported class: the class import's parent import supplies the package `ObjectName`
- locally exported class: current linker root package
- zero class index: `Core`

## Export payload boundaries

`ULinkerLoad::Preload` saves the current position, seeks to `SerialOffset`, precaches `SerialSize`, invokes the object's serializer, then requires:

`Tell() - SerialOffset == SerialSize`

and restores the prior position.

Thus `SerialOffset` and `SerialSize` are the authoritative payload span for a nonzero-sized export. A package-table reader can expose this span without decoding every class-specific payload.

## Retail load and validation sequence

The relevant loader order is:
1. open file
2. initialize archive state
3. deserialize summary
4. set `ArVer = Summary.FileVersion`
5. copy package flags
6. validate package tag
7. apply minimum-version policy
8. reserve table arrays
9. seek/read names
10. seek/read imports
11. seek/read exports
12. build export hash
13. optionally verify imports unless `LOAD_NoVerify`

Other proven checks include valid FName indices, valid signed object indices, and exact export payload byte consumption.

The retail source does **not** prove arbitrary hard caps such as maximum table counts, maximum string bytes, or maximum export size. Such UnrealDB safety limits must not be presented as UT99 format rules.

## Parsing versus import verification

Retail `ULinkerLoad::VerifyImport` goes beyond structural parsing. It recursively resolves import parents and provider packages and checks object/class/package/outer identity and public visibility. It also contains old-version/runtime compatibility behavior including `Mesh` -> `LodMesh`, `UnrealI` / `UnrealShare` handling, native transient objects, and `CLASS_SafeReplace`.

Those behaviors will be specified separately in the **UT99 dependency and import-resolution** document. They are not generic package-format fallback rules.

## Later revision evidence: UT99src-ext

`ardenee/UT99src-ext` is supplemental later code, not the retail v1.400 authority. Its available headers prove at least:
- `ENGINE_VERSION = 430`
- `PACKAGE_FILE_VERSION = 69`
- `PACKAGE_MIN_VERSION = 60`
- same `PACKAGE_FILE_TAG = 0x9E2A83C1`
- a logical split of the serialized 32-bit version into low 16-bit Epic version and high 16-bit licensee version
- summary version gates using the low 16-bit `GetFileVersion()`

Its header defines `GetFileVersion() = FileVersion & 0xffff` and `GetFileVersionLicensee() = (FileVersion >> 16) & 0xffff`.

This later licensee-version interpretation must not be retroactively claimed for the retail v68 source. The supplemental repository lacks the same complete Core/Src base implementation set, so unproven later behavior remains unresolved.

## Compression and encryption

No package-level compression or encryption layer appears in the retail v1.400 summary or `ULinkerLoad` package-opening path documented here. The package reader opens a normal file reader and seeks directly to tables and export payloads. Redirect/archive formats such as UZ are separate specifications.

## UnrealDB conformance requirements

A retail-compatible UnrealDB reader must:
1. identify the integer tag `0x9E2A83C1`;
2. parse the summary in exact source order;
3. branch at version 68 between generation and heritage layouts;
4. branch at version 64 for name serialization;
5. implement the exact retail compact-index codec;
6. distinguish compact-index fields from fixed-width INT fields;
7. read import/export `PackageIndex` as fixed-width INT;
8. omit export `SerialOffset` when `SerialSize == 0`;
9. validate table references before dereferencing;
10. preserve signed import/export index semantics;
11. preserve serialized names rather than applying runtime context filtering as data loss;
12. expose payload spans from `SerialOffset`/`SerialSize`;
13. avoid invented format caps or fallbacks;
14. keep later v69/licensee semantics distinct from retail v68;
15. allow structural metadata extraction without requiring external provider packages.

## Source-reference matrix

| Requirement | Proving source/symbol |
|---|---|
| Tag/version/min version | `Core/Inc/UnObjVer.h` constants |
| Primitive byte order | `Core/Inc/UnArc.h: FArchive::ByteOrderSerialize` |
| Compact index | `Core/Src/UnObj.cpp: FCompactIndex operator<<` |
| Summary/version branches | `Core/Src/UnLinker.h: FPackageFileSummary operator<<` |
| Generation layout | `Core/Src/UnLinker.h: FGenerationInfo operator<<` |
| Name v64 boundary | `Core/Src/UnName.cpp: FNameEntry operator<<` |
| Import layout | `Core/Src/UnLinker.h: FObjectImport operator<<` |
| Export layout | `Core/Src/UnLinker.h: FObjectExport operator<<` |
| FName reference decoding | `ULinkerLoad::operator<<(FName&)` |
| Signed object indices | `ULinkerLoad::IndexToObject` |
| Class identity | `GetExportClassName`, `GetExportClassPackage` |
| Outer/path traversal | `GetImportFullName`, `GetExportFullName` |
| Payload exact-size rule | `ULinkerLoad::Preload` |
| Load ordering | `ULinkerLoad::ULinkerLoad` |
| Later v69/licensee split | `UT99src-ext/Core/Inc/UnObjVer.h`, `UnLinker.h` |

## Deliberately deferred

The following are not flattened into this format document: complete dependency/provider selection, full `VerifyImport` fallback semantics, runtime/config package remapping, UZ/archive formats, and class-specific export payload schemas.

The next UE1 specification is **UT99 dependency and import resolution**, using only behavior proven by the retail source.
