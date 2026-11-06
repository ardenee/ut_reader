# Unreal Engine 2 — Classic Package Binary Format

**Normative subject:** serialized bytes in a classic Unreal Engine 2 package, not a particular program's package-search path, database workflow, runtime load policy, or implementation language. Game-specific changes are specified in the accompanying Unreal II, UT2003 and UT2004 addenda.

## 1. Container and vocabulary

A UE2 classic package is a **binary file** consisting of a fixed leading summary, a version-dependent summary tail, indexed name/import/export tables at **absolute offsets**, and zero or more serialized export-object payloads. The tables need not appear directly after the summary or in a predefined physical order. There is no universal fixed-length end-of-file record after the last export.

All offsets here are **byte offsets from the beginning of the unwrapped package**. All fixed 16- and 32-bit scalar fields are stored little-endian in the audited Intel persistent archives. Integers used by the loader for references are signed where specified, even if a later application presents them as IDs. Package extensions (e.g. `.u`, `.utx`, `.usx`, `.ukx`, `.uax`, `.umx`, `.ut2`) do not define alternate summary/table encodings. The payload schema depends on the export class, not its filename suffix.

A download wrapper, including compressed `.uz`/`.uz2` transport files or an ISO archive, is **not** part of this raw package layout. Unwrap it before applying the following byte schema.

## 2. Scalar wire types

| Wire type | Stored representation | Interpretation |
|---|---|---|
| `u8` | 1 byte | Unsigned octet |
| `u16` | 2 bytes, little-endian | Unsigned 16-bit scalar / UTF-16 code unit |
| `u32` / `DWORD` | 4 bytes, little-endian | Unsigned flags and bit patterns |
| `i32` / `INT` | 4 bytes, little-endian, two's-complement | Counts, offsets, and fixed-width package parent indices |
| `FGuid` | 16 bytes | Four consecutive 32-bit words, retained in source order |
| `FCompactIndex` | 1–5 bytes, variable | Signed integer, defined below |
| Serialized `FName` | One `FCompactIndex` | Index into this file's Name table; **not** an inline string |
| Serialized `FString` | Compact signed character count and character bytes | See §4 |
| `FGenerationInfo` | `i32 ExportCount` followed by `i32 NameCount` | Exactly 8 bytes |

### 2.1 Compact signed integer

The first byte contains the sign in bit 7, a continuation flag in bit 6, and **six** low magnitude bits in bits 0–5. When bit 6 is set, byte 2 contributes seven more magnitude bits (bits 0–6) and indicates continuation with bit 7. Bytes 3 and 4 use the same rule. A fifth byte (if needed) contributes the final high magnitude bits and has **no next-byte continuation**. This is a signed *magnitude* representation, **not** LEB128 with two's-complement sign extension.

Decode the low six bits first, then apply magnitude contributions at bit positions 6, 13, 20 and 27. Negate the total if the first byte had bit 7 set. Restrict decoded values to the signed 32-bit domain. The maximum serialized width is five bytes. For the five-byte case, the last byte is a value byte, not another continuation marker. This encoding is used only where a field is explicitly identified as compact; ordinary `INT` values remain 4 bytes.

`ClassIndex`, `SuperIndex`, serialized `FName` indices, `SerialSize`, and (when present) `SerialOffset` are compact. **Import/Export PackageIndex is a fixed four-byte signed integer** in the UE2 table layouts specified below.

### 2.2 Signed package object references

Where a field has package-object-reference semantics:

| Value | Meaning |
|---|---|
| `0` | Null or package root, depending on the field |
| Positive `n` | Export-table entry `n-1` |
| Negative `-n` | Import-table entry `n-1` |

The sign is structural identity. Do not reinterpret a negative reference as a negative file position. An **import** parent normally references another import or zero; an **export** parent, class or superclass may reference imports, exports or zero according to the consuming field's semantics. A structurally invalid index is not an empty name.

## 3. Leading package summary

The first 36 bytes have the same field positions across the audited UE2 game/engine sources.

| Offset | Type | Field | Decode rule |
|---:|---|---|---|
| `0x00` | `u32` | `Tag` | Standard tag `0x9E2A83C1` (file bytes `C1 83 2A 9E`) |
| `0x04` | `u32` | `FileVersion` | Engine version = low 16 bits; licensee version = high 16 bits |
| `0x08` | `u32` | `PackageFlags` | Raw serialized flag bitmask |
| `0x0C` | `i32` | `NameCount` | Number of Name entries |
| `0x10` | `i32` | `NameOffset` | Absolute byte offset to Name table |
| `0x14` | `i32` | `ExportCount` | Number of Export entries |
| `0x18` | `i32` | `ExportOffset` | Absolute byte offset to Export table |
| `0x1C` | `i32` | `ImportCount` | Number of Import entries |
| `0x20` | `i32` | `ImportOffset` | Absolute byte offset to Import table |

The `FileVersion` value is a **single on-disk u32**, not two consecutive fields:

`engineVersion = FileVersion & 0xFFFF`; `licenseeVersion = (FileVersion >> 16) & 0xFFFF`.

The engine version, not the full packed integer, controls the structural version branches below. The licensee component is retained independently for variant-specific code. Validate positive table counts and absolute offset/size ranges before seeking.

### 3.1 Package version 68 and later

Immediately after offset `0x24`:

| Offset | Type | Field |
|---:|---|---|
| `0x24` | 16 bytes | `Guid` |
| `0x34` | `i32` | `GenerationCount` |
| `0x38` | `GenerationCount × 8` bytes | Array of `{i32 ExportCount, i32 NameCount}` |

The variable summary length is **56 + 8 × GenerationCount bytes**. Generation records contain table counts, **not** extra object payloads. Decode the declared count as signed, reject impossible lengths, and retain the sequence.

### 3.2 Package version below 68 (heritage form)

Immediately after offset `0x24`:

| Offset | Type | Field |
|---:|---|---|
| `0x24` | `i32` | `HeritageCount` |
| `0x28` | `i32` | `HeritageOffset` |

The leading summary occupies **44 bytes**. At the absolute `HeritageOffset`, the older archive may contain `HeritageCount` GUIDs, each 16 bytes. The loader reads these GUIDs in sequence, ending with the final GUID as the loaded package GUID when the count is positive; the summary read position is restored afterward. The older generation view can be synthesised from the summary's ExportCount/NameCount but that synthesis is **not another serialized field**.

## 4. String and name records

### 4.1 Version below 64

A Name entry contains:

1. A NUL-terminated sequence of single-byte ANSI characters (no preceding length).
2. `u32 Flags`.

The textual octets and flags both belong to the original record. Do not assume an embedded length or an extra FName number field.

### 4.2 Version 64 and later

A Name entry contains:

1. `FString`: signed `FCompactIndex` character count followed by character bytes.
2. `u32 Flags`.

`FString` interpretation:

| Compact count | Payload |
|---|---|
| `0` | Empty string, no characters |
| Positive `n` | Exactly `n` **single-byte** ANSI characters, with the terminator counted |
| Negative `-n` | Exactly `n` **16-bit** little-endian `UNICHAR` code units, with the terminator counted |

The saved string count is a character count, **not** always a byte count. Do not misread `-n` as a negative number of bytes. For ANSI, each source byte widens independently to a character value (so a pair that happens to look like UTF-8 is still two characters); UTF-16 decoding uses little-endian code units. Preserve the raw textual identity, plus any documented variant-specific **effective** runtime name treatment; do not overwrite one with the other. Name flags are serialized even when the runtime context later makes that name unavailable.

### 4.3 Serialized references to names

Each ClassPackage, ClassName and ObjectName `FName` field in the tables below is a compact **NameMap index**. It does not embed text, a separate counter or the object path. Decode names before interpreting references. The loaded runtime name table may map context-excluded entries to `None` according to `Flags` and the chosen engine edit/client/server mode. This is a name-resolution operation, not a change in the original Name bytes.

## 5. Import table

Seek to `ImportOffset` and read exactly `ImportCount` successive variable-width entries:

| Order | Type | Field |
|---:|---|---|
| 1 | `FCompactIndex` (`FName`) | `ClassPackage` |
| 2 | `FCompactIndex` (`FName`) | `ClassName` |
| 3 | `i32` | `PackageIndex` (parent/outer import) |
| 4 | `FCompactIndex` (`FName`) | `ObjectName` |

The root package appears as an import with a zero parent and source-defined `Core.Package` identity. Children normally have a negative `PackageIndex` referring to an earlier or later import entry. **Import.SourceLinker, SourceIndex and resolved runtime object pointers are not serialized.** The Import table contains no filename, installation path or game identifier.

## 6. Export table

Seek to `ExportOffset` and read exactly `ExportCount` entries:

| Order | Type | Field |
|---:|---|---|
| 1 | `FCompactIndex` | `ClassIndex` |
| 2 | `FCompactIndex` | `SuperIndex` |
| 3 | `i32` | `PackageIndex` (export outer/parent) |
| 4 | `FCompactIndex` (`FName`) | `ObjectName` |
| 5 | `u32` | `ObjectFlags` |
| 6 | `FCompactIndex` | `SerialSize`, signed |
| 7 | `FCompactIndex` (conditional) | `SerialOffset` **if and only if `SerialSize != 0`** |

The conditional rule is based on non-zero **truth**, not positive size. A negative signed `SerialSize` still causes a compact offset to be consumed. Do not silently convert negative size to zero when locating the following export entry. No padding to 4-byte boundaries is specified for these variable-length records.

An export with `ClassIndex=0` represents the default Class identity in applicable UE2 operations. Positive/negative ClassIndex and SuperIndex values use the signed object-reference mapping in §2.2. `ObjectFlags` is a raw 32-bit flag word; `RF_Public` and related bits affect runtime import visibility but do not change the width of this field in the covered UE2 revisions. Export class instances, superclass objects and outer object relationships are described **by references**, not by physically nested records.

## 7. Export object bytes

For a positive `SerialSize`, the corresponding export's object body occupies the half-open byte range

`[SerialOffset, SerialOffset + SerialSize)`.

Check that both the offset and the entire positive span lie inside the file. The body's layout is **class/version-specific**: native properties, object references and property tags may appear there, but the summary, Name, Import and Export table schema alone cannot decode arbitrary body bytes. A valid complete table reader can record an opaque body span without interpreting meshes, textures, audio, skeletal data or script bytecode.

For `SerialSize == 0`, **no** `SerialOffset` field is present in the Export table. For negative size, retain the stored signed fields and do not invent a positive byte range.

No universal package-section ordering, alignment, compression flag, encryption table, export checksum, class property layout or full-file trailer is established by this summary/table format. A transport wrapper or game-specific payload codec requires a separate documented schema.

## 8. Format-consistent decoding procedure

1. Validate the signature and read the 36-byte fixed summary prefix.
2. Separate the engine and licensee version fields.
3. Decode the version-dependent summary tail (GUID/generations or heritage).
4. Validate table counts and absolute offsets within the file.
5. Decode each Name entry using the v64 string boundary; retain raw flags and textual bytes.
6. Decode Import entries as two compact names, a fixed 32-bit parent, and a compact object name.
7. Decode Export entries in the exact order from §6; consume SerialOffset whenever SerialSize is nonzero.
8. Validate Name references and signed object-reference bounds, without renumbering the arrays.
9. Record positive export payload spans; decode class-specific bodies only when their own format is established.

This is a **binary parsing procedure**; deciding which physical package wins a search, how the engine constructs native objects, and whether a runtime import resolves are separate behaviours. None of those runtime choices changes the serialized fields described above.

## 9. Variant and evidence boundaries

The canonical wire-schema rules in this document are derived from the complete **UE2 engine** serialization implementation, independently of any game's extra code. The Unreal II, UT2003 and UT2004 addenda derive their game-specific structures from **their respective game sources**, not from one another. Differences in name truncation, tag guards, QuickMD5, class payload serialization and game-specific compatibility belong in those addenda and must not be flattened into a generic UE2 rule. Corroborating original game packages are independent validation evidence, not the rule-making authority. The minimum loadable engine version advertised by a particular binary is not a guarantee that every older game-specific asset class or third-party licensee payload has identical serialization.

The binary format is implementation-language-independent. A conforming decoder needs bounded byte reads, signed compact-index decoding, explicit absolute table seeks and UTF-16LE support.
