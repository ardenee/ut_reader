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

## 6. Export property and script-structure body formats

Beyond the package's generic export table, a tagged property stream has the concrete Name/Info/optional-struct-name/size/optional-array-index/value layout in [UE2 tagged export-property streams](ue2-export-property-stream-format.md). This stream begins at a class-determined position **inside** a positive export payload; it is not guaranteed to begin at the Export table's SerialOffset.

In this game's v2107 class serializer, a `UStruct` segment follows inherited `UField` bytes. Its serialized fields occur in the following order: `ScriptText` object reference, `Children` object reference, `FriendlyName` (FName), optional `StructFlags` (**u32, present when package licensee version is at least 26 / `0x1A`**), then `Line` (i32), `TextPos` (i32), `ScriptSize` (i32), and script expressions in native token-serialization order. `ScriptSize` is a logical code count, not a guaranteed flat physical byte count for those token expressions.

The **struct property value** format changes on the **licensee version**, not the engine version. When licensee version is **below 28 (`0x1C`)**, a `StructProperty` uses **binary member** serialization. When licensee version is **28 or higher**, it uses **tagged member** serialization. In both cases `Vector`, `Rotator` and `Color` remain binary-serialized exceptions. The tag's declared struct-name FName and byte Size still appear in the enclosing property header where applicable; no new field is added to the package summary.

**Legacy tagged-property load compatibility (v2107 loader):** a stored fixed-array element can be converted into the matching element of a current dynamic-array property when the tag type matches its inner type. A saved `ByteProperty` targeting a current `FloatProperty` is converted from one unsigned byte to floating point. Unknown, mismatched, out-of-bounds and nonserializable properties are skipped by the tag's stored `Size`. A declaration/type disagreement therefore does not, by itself, prove malformed package bytes. These are game-loader conversion rules, not additions to the package tables.

The recovered early patch **2111 Beta 2** illustrates why versions must be checked **per package**: its actual `Core.u` header is **v121/licensee28**, while its `Engine.u` header is **v121/licensee29**. Patches **2136, 2166, 2186, 2199 and 2225** contain `Core.u` and `Engine.u` with **v121/licensee29**. These authentic distribution files match the shared Name/Import/Export table schema, including every decoded field and record byte offset; neither this observation nor the v2107 v120 source proves every v121 native export-body serializer or runtime import verifier is identical.

**Original-byte class-default observation (not native-certified):** original patch 2136 and 2225 `Engine.u` archives contain candidate `None`-terminated tagged-property suffixes for `Actor`, `Pawn` and `Texture` (197 tags in six candidate streams; 193 agree with exported property type declarations). `Material` does not yield a chain meeting this probe's three-tag minimum. The start of each stream was inferred from its export boundary, not traced through the compiled native `UClass` loader. This supports the property header layout, not complete class-body or patch-loader equivalence.

## 7. Proven scope and residual unknowns

The source proves the v120/licensee28 writer and its complete summary, name and import/export table serializers. Native class-specific serialization can vary independently from the stable table format, including later third-party licensee versions. The standard binary schema is independently readable in original UT2003 **v121/licensee29** files that contain nonzero Names, Imports and Exports; that fact does **not** establish the exact later binary's `VerifyImport`, object instantiation or native payload serialization rules.

A conforming reader can decode the complete summary, names, imports, exports, package reference indexes and export byte boundaries without executing the game. It must explicitly distinguish **verified table-format compatibility** from an assertion about original game-release runtime behaviour.
