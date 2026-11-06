# Unreal Engine 2 — Tagged Export-Property Stream Binary Format

**Normative subject:** a *tagged property sequence* within a classic UE2 export's class-defined serialized body. This is an additional binary subformat of the [classic package container](ue2-package-binary-format.md), not a replacement for its summary, Name, Import or Export tables. It is language-independent. Game-specific struct version gates are defined in the Unreal II, UT2003 and UT2004 addenda.

## 1. Location and enclosing object

An Export table's positive SerialSize gives the **entire serialized object span**, not necessarily the beginning or the exact length of a property stream. The object's actual class serializer determines which fields, superclass records, optional state data and tagged property sequences occur within that span. **Do not assume every export payload starts with a property name.** A class's defaults are themselves serialized as a tagged-property sequence at its class-defined position.

Only start decoding a tagged stream once that position has been established by the enclosing class layout. The stream consists of zero or more tagged records followed by an end marker. The decoder must not advance beyond the enclosing export's positive byte span.

## 2. Tagged-property record header

The following fields appear **in this order**. The NameMap indices use the same signed compact index type as the package Import/Export tables.

| Order | Type | Meaning / presence |
|---:|---|---|
| 1 | Serialized FName (compact NameMap index) | Property name. If this resolves to the reserved name `None`, **the stream ends immediately**: no Info, struct name, size or value follows for this record |
| 2 | `u8 Info` | Present for a nonterminating name. Low 4 bits = property type ID, next 3 bits = payload length encoding, high bit = array-index-present flag **except for BoolProperty** |
| 3 | Serialized FName (compact NameMap index) | Struct type name, **only when `Info & 0x0F == 10` (StructProperty)** |
| 4 | Conditional length field | Chosen by `Info & 0x70` as defined below |
| 5 | Conditional array index | Present if bit 7 is set **and** type is not BoolProperty |
| 6 | Property value bytes | Exactly the encoded Size bytes for ordinary known or unknown properties; **BoolProperty uses the Info bit as its value and has no value bytes in this tagged form** |

Property Size counts **value bytes only**: it does not include the Name, Info, optional struct name, size-word or array-index header bytes. A zero Size is representable. End-of-stream is identified by the reserved **name**, not by a size of zero or type ID zero. The stored FName index for `None` is a resolved name-table identity; do not assume that every file encodes the terminator as the literal byte `00`.

### 2.1 Info byte masks

`type = Info & 0x0F`, `sizeCode = Info & 0x70`, `highBit = (Info & 0x80) != 0`.

| `sizeCode` | Encoded property Size | Extra Size bytes |
|---|---|---|
| `0x00` | 1 | None |
| `0x10` | 2 | None |
| `0x20` | 4 | None |
| `0x30` | 12 | None |
| `0x40` | 16 | None |
| `0x50` | Following `u8` | 1 |
| `0x60` | Following `u16` (little-endian) | 2 |
| `0x70` | Following `i32` (little-endian) | 4 |

The writer selects the fixed sizes first, then the one-byte size for other sizes up to 255, a two-byte size for sizes up to 65,536 and a four-byte size beyond that. **The reader decodes the value actually stored in the width given by the code**, including a zero-length value; it must not infer a larger value from the writer's nominal size-selection threshold. Bound the resulting payload within the enclosing export.

### 2.2 Static array index

If `highBit` is set for a type **other than 3 (BoolProperty)**, read the next byte `B`:

| Prefix | Additional bytes | Decoded nonnegative ArrayIndex |
|---|---:|---|
| `B < 0x80` | 0 | `B` (0–127) |
| `0x80 <= B < 0xC0` | 1 (`C`) | `((B & 0x7F) << 8) | C` (up to 16,383) |
| `B >= 0xC0` | 3 (`C,D,E`) | `((B & 0x3F) << 24) | (C << 16) | (D << 8) | E` |

The index is a **separate variable-length unsigned field**, **not** the package FCompactIndex format. When bit 7 is absent, ArrayIndex is zero. Check the declared array dimension when the property's class metadata is available. A runtime array bounds failure does not alter the serialized tag width.

For `BoolProperty` (type 3), bit 7 represents the **stored Boolean value**; no array-index bytes are consumed. Tagged Boolean values are represented by the tag itself, not by the separate one-byte `UBoolProperty::SerializeItem` encoding used in other contexts. Its tagged property payload Size is zero.

## 3. Property type IDs

The low four Info bits identify the property type according to the UE2 fixed-name IDs. The following IDs are defined by the covered source revisions; a type's **value serialization** may require further property-class metadata.

| Type ID | Name | Value category |
|---:|---|---|
| 1 | ByteProperty | One unsigned octet |
| 2 | IntProperty | 32-bit signed integer |
| 3 | BoolProperty | Value in Info bit 7; zero tagged payload bytes |
| 4 | FloatProperty | 32-bit floating-point value |
| 5 | ObjectProperty | Archive-mapped signed object reference |
| 6 | NameProperty | Archive-mapped compact NameMap index |
| 7 | DelegateProperty | Delegate-specific representation; must be decoded using its property serializer |
| 8 | ClassProperty | Class reference, subject to its property serializer |
| 9 | ArrayProperty | Compact element count and recursively serialized elements |
| 10 | StructProperty | Struct name in header; value uses a version/game-specific binary or tagged struct layout |
| 11 | VectorProperty | Type-specific value encoding |
| 12 | RotatorProperty | Type-specific value encoding |
| 13 | StrProperty | Serialized FString |
| 14 | MapProperty | Type-specific map serialization |
| 15 | FixedArrayProperty | Type-specific fixed-array serialization |

A registered native property ID outside the four-bit domain does **not** justify inventing a new, unmarked property-tag header layout. Preserve an unrecognised tag as a type-and-bounds-checked opaque value unless its serializer has been independently specified.

## 4. Basic property-value encodings

For the standard package archive, `ByteProperty` occupies 1 byte; `IntProperty` and `FloatProperty` occupy 4 little-endian bytes each. `NameProperty` and an ordinary `ObjectProperty` use the package archive's **signed compact indices**, with the latter following the Import/Export signed reference interpretation. `StrProperty` uses the versioned signed-length FString described in the base specification.

A dynamic `ArrayProperty` begins with a **signed FCompactIndex element count**. The array then serializes each element in order using the declared inner property's serializer. Neither the outer tag's Size nor the array's element count is by itself a declaration of a fixed element byte width.

A `StructProperty` is **not universally a fixed-width Vector or a recursively tagged struct**. The available Unreal II source uses an engine-version gate, whereas the UT2003/UT2004 sources use a licensee-version gate. These distinctions—and Vector/Rotator/Color exceptions—are specified individually in their game addenda. Even when a struct is tagged, decoding it requires its declaration or a separately verified nested subformat.

A property serializer can carry additional details beyond these basic scalar rules. For example, class-specific `DelegateProperty`, `MapProperty`, `FixedArrayProperty` and native user-defined object payloads must not be decoded by inventing a fixed-size layout from their type labels. Preserve their exact bounded bytes where no authoritative typed schema is available.

## 5. Enclosing class information and correctness rules

A typed reader must identify an export's actual class through its signed ClassIndex, follow the applicable class/subclass serialized field order, and locate the tagged-property stream at the position required by that serializer. A class export may contain superclass field references, script text/child references, flags, source/compiler metadata, serialized expressions, state metadata, dependencies and class-default property streams **before** or **around** the tagged-property values.

When a tagged stream is located:
1. Consume a compact name index and resolve the special end name.
2. For an ordinary name, read Info and the optional struct name.
3. Decode Size and any non-Boolean ArrayIndex **in the documented order**.
4. Decode the typed payload when the property class/version is established; otherwise advance by the **stored** bounded Size, without inventing a value.
5. Repeat until the terminal reserved `None` name, checking the current export boundary.

**Distinct claims:** the outer UE2 container and Name/Import/Export table structures are independently established; the property-tag header above is established by engine/game serialization source. This document does **not** claim every exported class's body or every historical compiled game's native loader semantics has been exhaustively validated. Original class-body decoding and class-version variations require additional class-specific evidence.
