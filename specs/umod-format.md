# UMOD archive format and installer semantics

## 1. Scope

This specification documents the packed Unreal Module (UMOD) archive as implemented by the official Unreal/Unreal Tournament setup code available in the supplied source trees.

Primary baseline:

- Repository: `ardenee/UT99src`
- Revision/tree: `Unreal Tournament [v1.400] [1999-11-30] (Retail)`
- Primary implementation: `Setup/Inc/Setup.h`, `Setup/Src/USetupDefinition.cpp`, and `Setup/Src/FFileManagerArc.cpp`.

Later/source-line verification:

- `ardenee/UT99src-ext` (`master`), especially `Core/Inc/FFileManagerArc.h` and `Setup/Src/USetupDefinition.cpp`.
- `ardenee/unreal2src`, including the later `U2XMP_all/depot/Core/Inc/FFileManagerArc.h`.
- `ardenee/UE2.5`, `Core/Inc/FFileManagerArc.h`.
- `ardenee/Unreal_Tournament_2003_v2107`, `Core/Inc/FFileManagerArc.h`.
- `ardenee/UT2004src`, `Core/Inc/FFileManagerArc.h`.

These later trees are used only to establish whether the archive container contract persisted or changed. Game-specific installer/mod semantics must not be back-projected into UT99.

## 2. Terminology

The source calls the packed file a module/archive and uses:

- `MOD_EXT ".umod"` in the UT99 Setup code.
- `MOD_MAGIC 0x9fe3c5a3` in the retail Setup header.
- later shared archive code names the same value `ARCHIVE_MAGIC`.
- `FArchiveHeader` for the fixed trailer.
- `FArchiveItem` for one directory entry.

A UMOD is not an Unreal package file. It does not use the UE package summary/name/import/export tables merely because its magic value happens to equal the familiar Unreal package tag. Identification must follow this archive layout.

## 3. Physical archive layout

The source establishes this logical layout:

```text
offset 0
+-------------------------------+
| member payload bytes          |
| ...                           |
+-------------------------------+
| serialized TArray<FArchiveItem>  <- FArchiveHeader.TableOffset
+-------------------------------+
| FArchiveHeader (20 bytes)     |  <- file_size - 20
+-------------------------------+
EOF
```

The header is deliberately a trailer. The reader calculates:

```text
HeaderPos = TotalSize - ARCHIVE_HEADER_SIZE
ARCHIVE_HEADER_SIZE = 5 * 4 = 20
```

It seeks there first, validates the trailer, then seeks to `TableOffset` and deserializes the directory.

There is therefore no requirement that the UMOD magic occur at byte zero.

## 4. Fixed 20-byte trailer

`FArchiveHeader` contains exactly five serialized `INT` values, in this order:

| Offset within trailer | Field | Type | Meaning |
|---:|---|---|---|
| 0 | `Magic` | `INT` | archive magic, `0x9fe3c5a3` |
| 4 | `TableOffset` | `INT` | absolute file offset of the serialized item array |
| 8 | `FileSize` | `INT` | total archive size; checked against the real file size |
| 12 | `Ver` | `INT` | archive format version |
| 16 | `CRC` | `INT` | rolling `appMemCrc` result for all bytes preceding the trailer |

The source explicitly warns that the serialization operator must match `ARCHIVE_HEADER_SIZE`.

Constants:

```text
ARCHIVE_MAGIC / MOD_MAGIC = 0x9fe3c5a3
ARCHIVE_HEADER_SIZE       = 20
ARCHIVE_VERSION           = 1
```

The retail constructor initializes `TableOffset=-1`, `FileSize=0`, version 1 and CRC 0 before a writer fills the real values.

## 5. Byte order of fixed-width fields

The fields above are serialized through `FArchive::operator<<` for `INT`/`DWORD`, which uses `ByteOrderSerialize`. Persistent archives preserve Unreal's byte-order-neutral serialization contract; on Intel builds this is the native little-endian representation.

A parser for the Windows UT99/UE2-family files covered here should therefore read the five 32-bit trailer fields as little-endian signed/unsigned 32-bit values as appropriate.

## 6. Directory serialization

After validating the trailer the official reader does:

```text
Seek(Header.TableOffset)
Ar << Header._Items_
```

`_Items_` is `TArray<FArchiveItem>`.

The Unreal `TArray<T>` archive operator serializes the element count as `AR_INDEX` (the engine compact-index encoding), followed by each element in array order.

Therefore the directory is:

```text
CompactIndex item_count

repeat item_count times:
    FString filename
    DWORD   offset
    DWORD   size
    DWORD   flags
```

The array count is not a fixed 32-bit integer.

## 7. FArchiveItem

The exact serialization order is source-defined as:

```cpp
Ar << Item._Filename_ << Item.Offset << Item.Size << Item.Flags
```

Semantics:

| Field | Meaning |
|---|---|
| `_Filename_` | archive-visible member path |
| `Offset` | absolute start offset of the member payload |
| `Size` | member payload length |
| `Flags` | archive item flags |

The archive-backed reader creates a bounded sub-reader with `Base=Offset`, `Size=Size`, and local position zero. Reads are constrained so `Pos + Length <= Size`.

## 8. Item flags

The retail UT99 Setup header defines:

```text
ARCHIVEF_Bootstrap   = 0x00000001
ARCHIVEF_Unbootstrap = 0x00000002
ARCHIVEF_Compressed  = 0x00000004
```

The later shared `FFileManagerArc.h` found in UT99src-ext, Unreal II, UE2.5, UT2003 and UT2004 defines:

```text
ARCHIVEF_Bootstrap  = 0x00000001
ARCHIVEF_Compressed = 0x00000004
```

Thus bit `0x2` is proven in the UT99 retail Setup source but must not automatically be assigned later-engine semantics where the later source no longer declares it.

The basic archive reader does not transparently decompress a member merely because `ARCHIVEF_Compressed` is set. No generic per-entry decompression is performed by the `FFileReaderArc` shown here. Code using this flag must be documented from its actual consumer/writer rather than inferred.

## 9. Validation sequence

For a normal packed UMOD, UT99 retail performs the following:

1. Resolve the specified module and require a positive file size.
2. Open it as an archive.
3. Compute `HeaderPos = TotalSize - 20`.
4. Seek to `HeaderPos`.
5. Deserialize the five trailer fields.
6. Reject if archive I/O is in error.
7. Require `Magic == 0x9fe3c5a3`.
8. Require `Ver == 1`.
9. Require the stored `FileSize` to equal the actual file size.
10. For a non-`.exe` module, seek to zero and compute CRC over exactly `[0, HeaderPos)`.
11. Require that calculated CRC equals `Header.CRC`.
12. Seek to `TableOffset`.
13. Deserialize the `TArray<FArchiveItem>`.
14. Require directory deserialization not to leave the archive in error.

The CRC calculation is chunked in 16,384-byte buffers, but the chunk size is an implementation detail; the covered byte range and rolling `appMemCrc` semantics are the format-relevant rules.

## 10. Self-extracting executable exception

The retail installer skips its UMOD CRC pass when the supplied module filename ends in `.exe`, with the source comment that the SFX has already performed that verification.

This is installer behavior, not a different archive trailer format. `SFX_STUB` is defined as `RunSFX.exe`.

UnrealDB should not weaken validation of an arbitrary file merely because its extension is `.exe`; if SFX support is implemented, it must be based on the corresponding SFX source contract.

## 11. Member path mapping

The archive file manager maps setup-relative paths into archive paths.

UT99 retail:

- a path beginning `..\` has that prefix removed;
- otherwise the path is treated beneath `System`.

Later shared code accepts either `../` or `..\` for this test and canonicalizes `\` to `/` during case-insensitive wildcard comparison.

The later code therefore demonstrates a path-normalization improvement; it must not be represented as proof that retail v1.400 originally performed identical slash normalization.

When mapping archive names back, a member beneath `System\` is exposed relative to System; other members are exposed with a parent-directory prefix.

UT2004 additionally makes the `FromArcFilename` System test explicitly accept both `System\` and `System/`.

## 12. Lookup semantics

UT99 retail's local `FFileManagerArc::CreateFileReader` searches archive items and compares the mapped filename with the item filename. The later shared implementation factors this into `Lookup` and uses its wildcard matcher.

If an archive member is found, a bounded reader over that member is returned. If no member matches, the operation falls through to the underlying file manager.

Consequently, the archive acts as an overlay on the ordinary installer filesystem rather than an isolated virtual filesystem.

## 13. Manifest role

The UT99 setup constants establish:

```text
MANIFEST_FILE = "Manifest"
MANIFEST_EXT  = ".ini"
SETUP_INI     = "Manifest.ini"
```

After mounting a packed module, the retail installer flushes the old manifest config, reloads configuration/localization through the archive-backed file manager, and detaches the configuration so it can be modified locally.

Therefore `Manifest.ini` is an archived installer-control file, not the archive directory itself. A UMOD parser can enumerate/extract members without interpreting Manifest.ini; reproducing installation semantics requires interpreting the manifest according to Setup.

## 14. Manifest/setup file records

The retail `FFileInfo` parser recognizes the following named values in a file record:

- `DEST=`
- `SRC=`
- `MASTER=`
- `REF=`
- `REFSIZE=`
- `SIZE=`
- `LANG=`
- `FLAGS=`
- `MASTERRECURSE=`

Outer parentheses are accepted and removed before parsing.

These are textual Manifest.ini semantics. They are not fields in `FArchiveItem`.

The setup group configuration additionally exposes actions including `File`, `Copy`, `Group`, `Folder`, `Backup`, `Delete`, `Ini`, `SaveIni`, `AddIni`, and `Requires`. Installation walks selected groups and processes the manifest entries in configured order.

## 15. Installation file behavior relevant to extraction

For a selected `File` entry matching the current language, the installer resolves the source through the mounted archive/file-manager overlay, creates the destination directory, and copies to the destination named by `Dest`, or by `Src` when `Dest` is empty.

The Setup implementation also supports reference/delta patching through `Ref`/`RefSize`. That is installer behavior layered above the UMOD container. It must not be interpreted as a second UMOD member encoding.

Later UT99 source also recognizes a source ending in `.uz` and decodes it during installation when the destination is not `.uz`. Again, that is content/install processing, not transparent compression of the UMOD archive entry.

## 16. CRC coverage

The official check computes:

```text
CRC = appMemCrc(all bytes from offset 0 up to but excluding the 20-byte trailer)
```

Thus the serialized directory is inside the CRC-covered region, because it precedes the trailer.

The stored `CRC` field itself and the other trailer fields are outside that calculation.

A conforming validator must not calculate only member payload CRCs or exclude the directory.

## 17. Bounds and structural validation for UnrealDB

The official code relies partly on archive/check assertions. A safe parser reproducing the format should validate the same structural invariants before seeking or allocating:

- file is at least 20 bytes before attempting to read the trailer;
- magic is exactly `0x9fe3c5a3`;
- version is exactly 1 for the documented format;
- stored total size equals actual size;
- `TableOffset` lies before the trailer and is a valid seek target;
- the compact array count can be decoded without overrun;
- each filename and fixed entry field can be fully decoded;
- each member `Offset + Size` remains within the archive's data region/valid file range;
- directory parsing does not cross the available bytes;
- CRC is checked over the official byte range.

These bounds checks are defensive implementation requirements; they must not be confused with invented format fields or arbitrary size caps. No undocumented maximum member count, filename length, member size, or archive size should be introduced merely for convenience.

## 18. Cross-version verification

The supplied later trees were checked specifically because this container survived beyond UT99.

The following core on-disk contract remains present in the inspected Unreal II, UE2.5, UT2003 and UT2004 `FFileManagerArc.h` implementations:

- archive magic `0x9fe3c5a3`;
- 20-byte five-INT trailer;
- archive version 1;
- `FArchiveItem = FString + DWORD Offset + DWORD Size + DWORD Flags`;
- compact-indexed `TArray<FArchiveItem>` directory;
- trailer located at EOF minus 20;
- `TableOffset` seek followed by directory deserialization;
- stored total-size validation;
- CRC of the pre-trailer bytes when verification is enabled;
- archive-member bounded readers.

Observed later differences include path separator normalization, platform-specific validation branches, archive open/close lifecycle changes, and ordinary file-manager interface additions. These do not alter the five-field archive trailer or four-field directory-entry layout in the inspected implementations.

This cross-version result proves persistence of the container contract only. It does **not** make UMOD, UT2MOD, Unreal II installers, or later game installers semantically interchangeable. UT2MOD remains a separate specification task.

## 19. Identification rules for UnrealDB

A file must not be classified as UMOD solely because some 4-byte location contains `0x9fe3c5a3`; Unreal package files use the same numeric tag.

For this documented archive form, identification should be based on the trailer contract:

1. read the last 20 bytes;
2. decode the five fields;
3. verify trailer magic/version/total-size;
4. validate `TableOffset`;
5. decode a structurally valid item array at that offset;
6. when full verification is requested, validate the official pre-trailer CRC.

The filename extension is useful context but is not a substitute for the structural contract.

## 20. UnrealDB conformance requirements

UnrealDB should:

- recognize the UMOD archive from its EOF trailer, not a package-style leading magic;
- preserve the exact version-1 trailer field order and widths;
- decode the directory count with Unreal compact-index rules;
- decode every directory item in official order;
- treat offsets as absolute archive offsets;
- bound member reads to the declared member size;
- verify stored total file size;
- implement the official rolling CRC algorithm/range when validating;
- retain member flags without inventing behavior for flags whose consumer is not being implemented;
- keep Manifest.ini semantics distinct from physical archive-directory semantics;
- distinguish retail UT99 path behavior from later normalization improvements;
- not infer UT2MOD behavior from UMOD merely because later engines retain `FFileManagerArc`;
- not impose arbitrary parser limits absent from source, beyond resource/safety bounds that are clearly implementation protections.

## 21. Source-reference matrix

| Rule | Repository/source | Symbol/operation proving it |
|---|---|---|
| `.umod`, Manifest names, magic | `UT99src/.../Setup/Inc/Setup.h` | `MOD_EXT`, `MANIFEST_FILE`, `MOD_MAGIC` |
| 20-byte trailer/version | same | `ARCHIVE_HEADER_SIZE`, `ARCHIVE_VERSION`, `FArchiveHeader` |
| trailer serialized order | same | `operator<<(FArchive&, FArchiveHeader&)` |
| item serialized order | same | `operator<<(FArchive&, FArchiveItem&)` |
| trailer is at EOF | `UT99src/.../Setup/Src/USetupDefinition.cpp` | `USetupDefinition::Init` computes `TotalSize()-ARCHIVE_HEADER_SIZE` |
| size/magic/version checks | same | packed-module validation in `Init` |
| CRC range and 16 KiB chunking | same | CRC loop in `Init` |
| directory at TableOffset | same | `Seek(Arc->TableOffset); Ar << Arc->_Items_` |
| bounded member reader | `UT99src/.../Setup/Src/FFileManagerArc.cpp` | `FFileReaderArc` |
| archive path overlay | same | `ToArcFilename`, `CreateFileReader`, fallback to underlying FM |
| compact TArray count | `UT99src-ext/Core/Inc/UnTemplate.h` | `TArray<T>::operator<<` uses `AR_INDEX` |
| fixed integer byte-order serialization | `UT99src-ext/Core/Inc/UnArc.h` | `FArchive::ByteOrderSerialize`, integer operators |
| later common archive contract | `UT99src-ext/Core/Inc/FFileManagerArc.h` | `FArchiveHeader`, `FArchiveItem`, `Init` |
| Unreal II persistence | `unreal2src/.../U2XMP_all/depot/Core/Inc/FFileManagerArc.h` | same archive structures/reader |
| UE2.5 persistence/platform branches | `UE2.5/.../Core/Inc/FFileManagerArc.h` | same structures plus platform branches |
| UT2003 persistence | `Unreal_Tournament_2003_v2107/Core/Inc/FFileManagerArc.h` | same archive structures/reader |
| UT2004 persistence/path refinement | `UT2004src/Core/Inc/FFileManagerArc.h` | same structures; slash-aware `FromArcFilename` |
| textual file record | `UT99src/.../Setup/Inc/Setup.h` | `FFileInfo` |
| installer group actions | same | `USetupGroup` config arrays |
| install copy/delta behavior | `UT99src/.../Setup/Src/USetupDefinition.cpp` | `ProcessPreCopy`, `ProcessCopy`, `ProcessExtra`, `ProcessPostCopy` |

## 22. Deliberately unresolved/separate areas

This document does not silently fill the following from related formats:

- UT2MOD-specific metadata or installer behavior;
- UT4MOD-specific behavior;
- a generic meaning for `ARCHIVEF_Compressed` beyond what a particular source consumer proves;
- self-extracting executable stub internals beyond the Setup-side CRC exception;
- arbitrary third-party UMOD extensions.

Those require their own source-backed specification work.
