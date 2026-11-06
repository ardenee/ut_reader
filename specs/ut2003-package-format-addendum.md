# Unreal Tournament 2003 — UE2 Binary Package Addendum

This is the **UT2003-specific byte-format addendum** to [Unreal Engine 2 classic package binary format](ue2-package-binary-format.md). Only serialized types, their order, byte representation and independently evidenced game-specific variations are specified. Runtime package search, dependency publication and implementation-specific processing are outside this document.

## 1. Source revision and version boundary

The independently available UT2003 v2107 game Core source emits **package engine version 120**, **licensee version `0x001C` (28)** and declares **minimum loadable engine version 60**. The minimum is a loader declaration, not an additional field in the header.

Original UT2003 files have also been observed with other combinations, most significantly **121/licensee29**. Such packages can use the same **classic UE2 table encoding**, but the available v2107 source alone does not prove every native asset-body serializer of the later producer. Do not treat the source's emitted version 120 as a rule that forbids decoding the structural tables of version 121.

The full on-disk FileVersion field is a **single 32-bit value**: `engine = low16`, `licensee = high16`.

## 2. Header and table structures

UT2003 uses the standard **`C1 83 2A 9E`** leading bytes (tag `0x9E2A83C1`). The 36-byte fixed summary contains the package tag, combined version, package flags and six signed table count/offset integers, with the exact field positions from the UE2 base specification.

For versions 68 and newer, the tail contains `FGuid (16 bytes)`, `GenerationCount (i32)`, and 8 bytes per `ExportCount,NameCount` pair. For older versions, it contains `HeritageCount (i32)` and `HeritageOffset (i32)`, with 16-byte GUID records elsewhere at the specified offset. All table offsets are absolute file offsets; no claim is made that the tables are contiguous.

The UT2003 Import table is the same four-field sequence as UE2:
`ClassPackage(FName compact index)`, `ClassName(FName compact index)`, `PackageIndex(i32)`, `ObjectName(FName compact index)`.

Its Export table has the seven conceptual fields of the base:
`ClassIndex(compact)`, `SuperIndex(compact)`, `PackageIndex(i32)`, `ObjectName(FName compact index)`, `ObjectFlags(u32)`, `SerialSize(compact signed)`, and **conditionally** `SerialOffset(compact signed)`. The offset is present when `SerialSize != 0`, not only for positive sizes.

## 3. Name and string representation

- Before version 64: NUL-terminated 8-bit Name followed by `u32 Flags`.
- At version 64 and later: signed compact-count `FString` followed by `u32 Flags`. Positive count denotes single-byte ANSI characters, negative denotes UTF-16LE code units; both counts include the terminator.
- Each serialized `FName` in Imports/Exports is a compact **NameMap index**, not an inline string.
- The UT2003 v2107 source lacks the explicit `Str.Left(NAME_SIZE-1)` truncation present in certain later UE2 loaders. Consequently a format specification must preserve the full serialized text, and must not declare that every older game truncates the source string to 63 characters. Applying a safe bounded representation is an implementation concern separate from on-disk bytes.

The Name flag word remains serialized regardless of which names the live client, server or editor context later exposes.

## 4. Export payloads and limits

For any export with a positive serial size, the object bytes are exactly the span `[SerialOffset, SerialOffset+SerialSize)`. A zero size omits the offset from the table; a negative size still **serializes** its offset even though no positive payload span can be inferred.

The generic tables identify names, classes, outers and byte spans; class-dependent property tags, collision, animation, mesh and sound payloads are **not** additional fields in the Import or Export table. They require separate class/version-specific layouts.

UT2003's package summary contains no universal package compression/encryption descriptor. Compressed download formats are external wrappers, not another package-summary branch.

## 5. QuickMD5 is not a stored field

The v2107 loader calculates a QuickMD5 from two structural byte ranges:

1. File bytes from offset zero through the position immediately after the **Name table**.
2. Bytes beginning at `ImportOffset` through the position immediately after the **Export table**.

Both ranges feed the same MD5 context. The resulting digest is computed by the loader; it is not appended to the summary, Name table or Export records. Raw package-level MD5 of the entire file is **not** interchangeable with this QuickMD5 procedure.

## 6. Proven scope and residual unknowns

The source proves the v120/licensee28 writer and its complete summary, name and import/export table serializers. Native class-specific serialization can vary independently from the stable table format, including later third-party licensee versions. The standard binary schema is independently readable in original UT2003 **v121/licensee29** files that contain nonzero Names, Imports and Exports; that fact does **not** establish the exact later binary's `VerifyImport`, object instantiation or native payload serialization rules.

A conforming reader can decode the complete summary, names, imports, exports, package reference indexes and export byte boundaries without executing the game. It must explicitly distinguish **verified table-format compatibility** from an assertion about original game-release runtime behaviour.
