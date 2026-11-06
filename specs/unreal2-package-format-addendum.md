# Unreal II — Unreal Engine 2 Binary Package Addendum

**Scope:** Unreal II package bytes and game-specific variation of the [UE2 classic package binary format](ue2-package-binary-format.md). This document defines serialized structures, byte-widths and version distinctions. It does not describe installation search order, any application's database representation, or its import queue.

## 1. Identified game revisions and package versions

| Original game revision | Engine version declared by its Core build | Emitted package version | Emitted licensee | Declared minimum loadable version |
|---|---:|---:|---:|---:|
| Unreal II, December 2000 revision | 411 | 69 | `0x007F` (127) | 60 |
| Unreal II, January 2003 revision | `1226 + DEMO_VERSION_OFFSET` | 126 | `0x0000` | 60 |

These are **two different game source branches**; they must not be collapsed into one hypothetical Unreal II serializer. The January 2003 source is a genuine UE2 game revision and directly describes the complete v126 package summary, import and export serializers.

The source-advertised minimum version is a loader-compatibility condition, **not an on-disk schema field**. It also does not imply that every third-party licensee variation has the original game's native object-body layout.

## 2. Package prefix and summary

The Unreal II branches use the standard `0x9E2A83C1` package tag. A valid unwrapped package starts with the 36-byte summary prefix defined in the base document. Its packed 32-bit FileVersion separates into engine version (low 16 bits) and licensee version (high 16 bits).

- If the decoded engine version is **68 or newer**, bytes `0x24..0x33` encode the 16-byte GUID; byte `0x34` starts the signed GenerationCount, followed by 8 bytes per `{ExportCount, NameCount}` generation.
- If the decoded version is **below 68**, bytes `0x24..0x2B` encode the HeritageCount/HeritageOffset pair. The historical GUIDs are at the absolute heritage offset; the last entry is the resulting package GUID.

The later January 2003 summary serializer still uses these same core structures; its difference is in some loader operations and native asset-body serializers, **not** in an additional mandatory global package-header field.

## 3. Names and string data

For package versions **below 64**, each Name entry is `ANSI-NUL + DWORD Flags`. For **64 and later**, each is `signed-compact-length FString + DWORD Flags`, with positive ANSI character counts and negative UTF-16LE code-unit counts. A serialized FName is a compact signed Name-table index. Source ANSI octets represent individual characters, not a UTF-8 byte stream.

The December 2000 and January 2003 revisions are **not** identical in effective in-memory name handling:

- The older loader does not perform the later explicit `NAME_SIZE-1` truncation when copying the decoded FString into the runtime fixed-name representation.
- The January 2003 loader explicitly limits the effective name to the first **63 characters** (for its 64-character name buffer).

The **serialized** FString and `Flags` remain unchanged in either case. Reader implementations should preserve that raw value even where the engine creates a shortened effective runtime name. Name availability can depend on serialized `RF_LoadForEdit/Client/Server` context bits; these are not extra bytes in the Name entry.

## 4. Imports, exports and object bodies

Both game revisions use the UE2 table layout:

- Import: `Compact(ClassPackage)`, `Compact(ClassName)`, `i32(PackageIndex)`, `Compact(ObjectName)`.
- Export: `Compact(ClassIndex)`, `Compact(SuperIndex)`, `i32(PackageIndex)`, `Compact(ObjectName)`, `u32(ObjectFlags)`, `Compact(SerialSize)`, then `Compact(SerialOffset)` **whenever SerialSize is not zero**.

The signed reference index maps positive values to `Export[index-1]` and negative values to `Import[-index-1]`; zero is the root or null reference. These are indices, not direct file offsets. The only per-export physical payload position is the conditional SerialOffset field.

For positive sizes, the export's class-specific serialized object bytes occupy `[SerialOffset, SerialOffset + SerialSize)`. Neither game revision adds a general compressed-archive descriptor to this summary. Class-specific meshes, textures, sounds, script objects, property serializations and other payloads require their **own** class/version rules; absence of a global compression header does not prove those object bytes are uncompressed.

## 5. Non-serialized and revision-specific behaviour

The import map stores **only references**. Physical provider selection, class/object lookup, alias behaviour, broken-import handling and native/transient lookup do not modify the binary table layout. The December 2000 source has `UnrealI`/`UnrealShare` compatibility and a strict private-export path; the January 2003 source has different private-export behaviour and no active equivalent package-remap blocks. These distinctions belong to a **loader-behaviour** specification, not to new serialized file fields.

The January 2003 revision computes an optional **QuickMD5** over structural byte ranges used by that loader. The digest is calculated while reading; it is **not** an additional exported field in the package summary or a trailer. The December 2000 branch does not have that operation.

## 6. Later/custom Unreal II package populations

Original packages have been independently observed with **engine version 110** and licensee values **2481** and **2609** in Unreal II-labelled collections. Their standard summary/Name/Import/Export table widths and ordering can be decoded by the UE2 schema. Those version/licensee combinations do **not** become January 2003 v126 game-specific serializers merely because the same generic structural schema can decode them.

A full binary reader can truthfully report their decoded table fields and class-specific export spans. A comprehensive reader of the **inside of every export body** would require the original revision's native class serializers, compiler/archive flags, or comparative compiled-game evidence. Until then the class payload is a typed, length-bounded but **opaque** byte region.

## 7. Decoding agreement

An Unreal II reader conforming to the binary addendum:

1. Decodes the base summary and the v64/v68 structural branches independently of filename or game directory.
2. Interprets names, imports, exports and references using the base schema with the correct fixed-versus-compact widths.
3. Retains both raw serialized and optional effective name forms; it never rewrites the raw table.
4. Consumes an export's compact offset for **every nonzero** size, and bounds-checks every positive payload span.
5. Preserves the licensee version and leaves unknown body-specific additions explicitly unclassified rather than attaching another game's source rules.

All prescribed byte structures are language-independent. The source-specific loader differences are tracked outside this file-format addendum.
