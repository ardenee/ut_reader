# Unreal Warfare / UE2.5 — Binary Package Format Addendum

This addendum specifies Unreal Warfare's **generic UE2.5 package bytes** as an explicit variant of the [UE2 classic package binary format](ue2-package-binary-format.md). It is an engine-format addendum, not evidence that Unreal II, UT2003 or UT2004 has the same game-specific native object-body serializations.

## Identification and header

The supplied UE2.5 engine revision emits **engine package version 126**, **licensee 0** and declares **minimum loadable version 60**. Its standard raw package tag is `0x9E2A83C1` (bytes `C1 83 2A 9E`). The leading 36 bytes and version-dependent v68 GUID/generation versus heritage summary branches follow the common UE2 structure exactly.

The low 16 bits of FileVersion are the engine version and the high 16 bits are the licensee version. All Name/Export/Import table offsets are absolute, and their counts are signed 32-bit integers.

## Strings, Name table and references

For versions below 64, a Name record is a NUL-terminated ANSI string followed by `u32 Flags`. For v64 and higher, it is a signed compact-length FString followed by `u32 Flags`; positive counts contain single-byte ANSI and negative counts contain UTF-16LE code units, including terminators.

The v126 loader copies at most the first **63 decoded characters** into its runtime 64-character name buffer. This does **not** alter the originally serialized Name string. Runtime flags can replace a name's effective identity with `None` depending on load context; no extra serialized name field is added.

## Import and Export record widths

Import fields are `Compact(ClassPackage)`, `Compact(ClassName)`, **`i32(PackageIndex)`**, `Compact(ObjectName)`. Export fields are `Compact(ClassIndex)`, `Compact(SuperIndex)`, **`i32(PackageIndex)`**, `Compact(ObjectName)`, `u32(ObjectFlags)`, `Compact(SerialSize)` and `Compact(SerialOffset)` **only if SerialSize != 0**.

The signed object-reference map and positive export byte spans are unchanged from the base UE2 description. No generic package compression, native export-object schema or additional universal trailer is specified by these table fields.

## Computed QuickMD5

The v126 loader computes a structural MD5 by updating one digest with (1) bytes from file offset zero to the end of the Name table, then (2) bytes from the Import table's absolute offset to the end of the Export table. The digest is **not stored in the package summary** or appended to the file. It must not be confused with UT2004's structurally **different** QuickMD5 procedure.

## Source version boundary

A v126 generic UE2 reader may parse earlier classic UE2 table structures with the relevant version branches, but that does not prove the original writer's class-specific body format or another game's runtime import-verification choices. A complete typed payload reader needs the appropriate version/class serializer definitions. Unreal Warfare's file-format addendum cannot replace the three separately sourced game addenda.
