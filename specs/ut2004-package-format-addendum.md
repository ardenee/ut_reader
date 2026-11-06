# Unreal Tournament 2004 — UE2/2.5 Binary Package Addendum

This specification describes **UT2004 file bytes** and exceptions to the [UE2 classic package binary format](ue2-package-binary-format.md). It does not include a game-installation search strategy, a web-catalogue workflow or another game's undocumented native payload serializer.

## 1. Source-verified release revisions

| UT2004 source revision | Declared package engine version | Declared licensee version | Declared minimum |
|---|---:|---:|---:|
| v3186 | 127 | 29 (`0x1D`) | 60 |
| v3369 | 128 | 29 (`0x1D`) | 60 |
| Later UT2004 Core revision | 129 | 29 (`0x1D`) | 60 |

Version 129 also appears in existing original-package evidence with licensee value 27; that combination is structurally readable but must not be conflated with the source's **emitted** licensee-29 header. The v129 source history labels v127 as skeletal-mesh static collision, v128 as `FDecorationLayer.DetailMode`, and v129 as a 64-bit-related change. **None of those labels alone authorizes replacing every generic package-table 32-bit field with 64-bit fields.**

UT2004 distributions and downloaded packages also contain engine versions **99, 118 and 121**, among others. Those older versions can carry the standard UE2 summary/table format and are independently parseable. The game's declared minimum file version is a compatibility boundary, not an exact-file-source-identity test.

## 2. Serialized summary

The leading 36 bytes are identical to the core UE2 table layout:

`Tag(u32)`, `FileVersion(u32)`, `PackageFlags(u32)`,
`NameCount(i32)`, `NameOffset(i32)`,
`ExportCount(i32)`, `ExportOffset(i32)`,
`ImportCount(i32)`, `ImportOffset(i32)`.

The tag value is `0x9E2A83C1` (little-endian bytes `C1 83 2A 9E`). Unlike some related UE2 revisions, the UT2004 summary serializer checks the tag **immediately after reading it** and returns without consuming the rest of the summary when it is invalid. A reader must not interpret the unread bytes of a bad-tag archive as valid counts or offsets.

The packed FileVersion's low 16 bits contain the engine version; high 16 bits contain the licensee version.

- Version 68+: 16-byte GUID, `GenerationCount(i32)`, and `GenerationCount × {i32 ExportCount, i32 NameCount}` (8 bytes each).
- Earlier versions: `HeritageCount(i32)`, `HeritageOffset(i32)` and external 16-byte GUID history at the referenced offset.

Table offsets are absolute. They are not relative to the previous record or guaranteed to be ordered by table type.

## 3. Names

Name entries before version 64 are ANSI NUL-terminated strings followed by `u32 Flags`. From version 64 onward they are `FString` (compact signed count, positive ANSI / negative UTF-16LE) followed by the same flags. Serialized `FName` references are compact NameMap indices.

The available UT2004 revisions explicitly use a **63-character effective-name cap** when copying v64+ text into a fixed-size runtime name buffer. This does not truncate or rewrite the original serialized Name entry; a reader should retain the full source text and, when needed, derive a separate effective name.

The load-for-edit/client/server name-context bits are in the `Flags` word already stored in each Name entry; the actual active context depends on the running game and is not a global extra field.

## 4. Imports and exports

**Import entry** — read sequentially from `ImportOffset`, count `ImportCount`:

| Order | Type | Meaning |
|---:|---|---|
| 1 | compact FName index | ClassPackage |
| 2 | compact FName index | ClassName |
| 3 | signed 32-bit `INT` | PackageIndex / parent |
| 4 | compact FName index | ObjectName |

**Export entry** — read sequentially from `ExportOffset`, count `ExportCount`:

| Order | Type | Meaning |
|---:|---|---|
| 1 | signed compact index | ClassIndex |
| 2 | signed compact index | SuperIndex |
| 3 | signed 32-bit `INT` | PackageIndex / outer |
| 4 | compact FName index | ObjectName |
| 5 | unsigned 32-bit `DWORD` | ObjectFlags |
| 6 | signed compact index | SerialSize |
| 7 | signed compact index, conditional | SerialOffset if **SerialSize != 0** |

No mandatory `ArchetypeIndex`, 64-bit class index, additional runtime `SourceLinker` pointer or alternate file path is serialized into these fields by the v127/v128/v129 table sources. The later v129 “64bit” revision therefore does **not** imply 64-bit generic class/parent/offset fields.

Signed references are interpreted as **positive = Export[n−1]**, **negative = Import[−n−1]**, and zero = null/root, never as byte offsets. A positive SerialSize defines a bounded object span `[SerialOffset, SerialOffset+SerialSize)`. Class-specific serialized export bytes require additional class/version specifications.

## 5. UT2004 QuickMD5 — important variant

UT2004's v129 `QuickMD5` is **not** the same raw-range hash as UT2003's v2107 algorithm. It hashes a constructed sequence of structural values:

1. The 32-bit package tag, **complete packed FileVersion**, package flags and all six Name/Export/Import counts and offsets.
2. The four 32-bit GUID words.
3. For every generation, the 32-bit ExportCount and 32-bit NameCount.
4. For every Name entry in order, the 32-bit Name flags **followed by the first two UTF-16 name code units** (each 16 bits) of the effective in-memory name.

It does **not** hash the complete Name string, or serialize Import/Export tables into this digest. The particular two-character selection is part of this game's actual algorithm and must not be “corrected” into a whole-string hash. The resulting digest is **computed**, not an extra stored field, not a trailer and not a substitute for whole-file MD5.

The package format remains valid when a reader chooses not to calculate this optional runtime integrity/identity value.

## 6. Legacy package compatibility

A later UT2004 loader can consume classic packages carrying earlier engine versions, including some that originated from UE1-compatible wire formats. **Engine runtime compatibility does not rewrite the original binary layout.** For source-era versions below 50, the old Import/Export PackageIndex serialization rules differ and require the separately specified historical package layout; those legacy fields must not be read as UT2004's newer 4-byte outer index.

For normal UE2-format original packages from versions 60+, UT2004's tables keep their fixed PackageIndex and compact FName/size/offset rules. The compiled loader's source includes version checks for some native payload structures, but its generic import/export table descriptions do not add per-file class-specific fields solely because the package was produced by an older game or mod.

## 7. Limits of this addendum

The complete package **summary and tables** are specified here, including byte order, version, names, classes, parents, GUID/generations, export flags and payload spans. Class-specific property streams, skeletal collisions, decoration layers, animations and code payloads need their own typed serialization addenda. A field-level descriptor for every possible native export class cannot be inferred from the standard Export table alone.

Original UT2004 v121/licensee29 and v118/licensee2 files with nonempty Names/Imports/Exports have been successfully independently decoded using this shared binary structure. This proves their **table-format compatibility**; it does **not** prove which historical game binary created each file, nor that every class-specific property body is identical across releases or licensee forks.
