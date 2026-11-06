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

## 7. Export property and script-structure body formats

The standard [UE2 tagged export-property subformat](ue2-export-property-stream-format.md) applies at the position identified by an export's **class serializer**. It defines the compact property name, one-byte packed type/size/index flags, optional struct name, optional encoded Size and static array index, typed payload, and terminal `None` FName. A class-specific export may serialize field/script/state data **before** its default tagged-property stream.

The v127, v128 and later v129 game class serializers contain the same relevant `UStruct` segment rule: after inherited `UField` bytes, serialize `ScriptText`, `Children`, `FriendlyName`, and **u32 `StructFlags` if licensee version is at least 26 (`0x1A`)**; follow these with i32 `Line`, i32 `TextPos`, i32 `ScriptSize` and native script expressions. A logical `ScriptSize` must **not** be treated as a universal contiguous byte span or as an extra Export table member.

`StructProperty` member encoding is **version-dependent**. In each of these game source revisions the selected property serializer uses **binary struct members** if licensee version is **below 28 (`0x1C`)**, and **tagged members** for licensee version **28 or higher**. The `Vector`, `Rotator` and `Color` types remain binary-serialized exceptions even for licensee 29. The field belongs to the object's class-specific body, not to the Name/Import/Export table schema. For a v118/licensee2 package consumed by this newer serializer, the older binary-struct branch is selected; for v121/licensee29, v127/licensee29 or v128/licensee29, the later tagged-struct branch is selected. That describes the available *consumer source's decoding rule*, not independent proof of every historical writer.

**Legacy tagged-property load compatibility (v127/v128/v129 source family):** the loader can convert serialized fixed-array elements into current dynamic-array entries where the saved type matches the dynamic array's inner type, or convert a saved `ByteProperty` into a current `FloatProperty` using its single-byte value. Other unknown, mismatched, out-of-range or nonserializable tags are skipped according to their stored `Size`. For licensee versions below 28 the struct reader additionally seeks to the stored tag boundary when an old binary struct layout consumes a different number of bytes. These are class-loader rules, not additional package header/table fields; applying them to a historical binary requires the correctly selected consumer build.

**Native patch distribution confirmation:** UT2004 patches **3204, 3236 and 3270** contain original `Core.u` and `Engine.u` at **v127/licensee29**; patches **3323, 3339, 3355 and 3369** contain both script packages at **v128/licensee29**. All these files have independently matched the UE2 Name/Import/Export wire layout field-for-field. Their actual DLL and EXE hashes distinguish individual revisions despite repeated package version values. Compiled native class-specific serializers and historical runtime import resolution are **not** established by the package header alone.

**Original-byte class-default observation (not native-certified):** original patch 3204 and 3369 `Engine.u` archives yield candidate `None`-terminated `Actor`, `Pawn` and `Texture` tagged-property suffixes (229 tags across six candidate streams; 225 agree with exported property type declarations). `Material` has no detected chain of three or more tags. The recorded suffix offsets are backward-inferred, not exact starts independently established by compiled native `UClass` serialization, so this cannot certify all class-specific payloads or native patch behavior.

## 8. Limits of this addendum

The complete package **summary and tables** are specified here, including byte order, version, names, classes, parents, GUID/generations, export flags and payload spans. Class-specific property streams, skeletal collisions, decoration layers, animations and code payloads need their own typed serialization addenda. A field-level descriptor for every possible native export class cannot be inferred from the standard Export table alone.

Original UT2004 v121/licensee29 and v118/licensee2 files with nonempty Names/Imports/Exports have been successfully independently decoded using this shared binary structure. This proves their **table-format compatibility**; it does **not** prove which historical game binary created each file, nor that every class-specific property body is identical across releases or licensee forks.
