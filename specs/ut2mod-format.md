# UT2MOD (UT2003 module) format

## 1. Scope

This specification documents the `.ut2mod` module type registered and installed by Unreal Tournament 2003, using the supplied official UT2003/UE2 source and the later UT2004 source as a cross-check.

Primary source of truth:

- Repository: `ardenee/Unreal_Tournament_2003_v2107`
- Branch: `main`
- `Core/Inc/FFileManagerArc.h`
- `Setup/Inc/Setup.h`
- `Setup/Src/USetupDefinition.cpp`
- `Editor/Src/UMasterCommandlet.cpp`

Engine-line verification:

- Repository: `ardenee/UE2.5`
- Tree: `Unreal Engine [v2.5]_ Unreal Warfare [09-29-2007]`
- `Core/Inc/FFileManagerArc.h`
- `Editor/Src/UMasterCommandlet.cpp`
- `Setup/Inc/Setup.h`
- `Setup/Src/USetupDefinition.cpp`
- `System/SetupUnrealEngine2.ini`

Later game verification:

- Repository: `ardenee/UT2004src`
- Branch: `main`
- corresponding Core/Setup/Editor implementations
- `System/SetupUT2003_Full.ini` and `System/SetupUT2003_Demo.ini`, which explicitly register `.ut2mod` as `UT2003.Module`.

The important source-backed result, now independently checked through UT2003, the supplied UE2.5/Warfare tree, and UT2004, is that UT2003 does **not** define a second binary archive structure named UT2MOD. Its registered `.ut2mod` files are installed through the same packed module reader that the Setup source calls a packed `.umod` file. Therefore the extension/product association differs, but the binary container contract is the common version-1 Unreal module archive documented below.

This conclusion is based on the official installation path and archive implementation, not on extension similarity.

## 2. Extension and operating-system association

The UT2003 setup manifest registers:

```text
HKEY_CLASSES_ROOT\.ut2mod\ = UT2003.Module
```

and defines the module shell command as:

```text
%DestPath%\System\Setup.exe install "%1"
```

The user-visible action is:

```text
Install this UT2003 module
```

Thus a `.ut2mod` is handed directly to UT2003 `Setup.exe install`.

The Setup install path does not select a UT2MOD-specific decoder. For `install`, it wraps the supplied filename in `FFileManagerArc`, initializes that archive manager, and reloads `Manifest.ini` through it.

## 3. Relationship to UMOD

UT2003's `Setup/Inc/Setup.h` still defines:

```text
MOD_EXT   ".umod"
SETUP_INI "Manifest.ini"
```

and `USetupDefinition::Init` describes the `install` path as installing a packed `.umod` file.

However, UT2003's own installer registration assigns `.ut2mod` to that exact `Setup.exe install "%1"` path.

Therefore, for the supplied UT2003 source:

- `.ut2mod` is a UT2003 module filename/association;
- its binary archive is read by the common `FFileManagerArc` module container;
- no separate UT2MOD magic, header, directory structure, or version field is selected by extension;
- the module contains the setup manifest and payload members consumed by the normal Setup system.

UnrealDB should preserve the filename type `UT2MOD` while using the proven common module-container parser internally.

## 4. Binary layout

The UT2003 archive implementation defines:

```text
offset 0
+----------------------------------+
| member payload bytes             |
| optional alignment padding       |
| ...                              |
+----------------------------------+
| serialized TArray<FArchiveItem>  | <- TableOffset
+----------------------------------+
| FArchiveHeader, 20 bytes         | <- EOF - 20
+----------------------------------+
EOF
```

The trailer remains at the end of the file.

Constants in `Core/Inc/FFileManagerArc.h`:

```text
ARCHIVE_MAGIC       = 0x9fe3c5a3
ARCHIVE_HEADER_SIZE = 5 * 4 = 20
ARCHIVE_VERSION     = 1
```

## 5. FArchiveHeader

The fixed trailer contains five serialized 32-bit `INT` values:

| Trailer offset | Field | Meaning |
|---:|---|---|
| 0 | `Magic` | `0x9fe3c5a3` |
| 4 | `TableOffset` | absolute offset of serialized directory |
| 8 | `FileSize` | total archive size |
| 12 | `Ver` | archive version, 1 |
| 16 | `CRC` | rolling `appMemCrc` of bytes preceding trailer |

Serialization order is exactly:

```cpp
Ar << Head.Magic << Head.TableOffset << Head.FileSize << Head.Ver << Head.CRC;
```

## 6. FArchiveItem

Each directory entry contains:

```text
FString filename
DWORD   offset
DWORD   size
DWORD   flags
```

and is serialized exactly as:

```cpp
Ar << Item._Filename_ << Item.Offset << Item.Size << Item.Flags;
```

`Offset` is absolute within the archive. `Size` is the member payload length.

## 7. Directory count

The directory is a serialized `TArray<FArchiveItem>`.

The engine array serializer uses Unreal compact-index encoding for the array count, followed by the entries in array order. It is not a fixed-width 32-bit count.

## 8. Archive flags

The UT2003 common archive implementation declares:

```text
ARCHIVEF_Bootstrap  = 0x00000001
ARCHIVEF_Compressed = 0x00000004
```

These are the flags declared for archive items by this source revision.

The physical archive reader itself returns a bounded reader over `Offset..Offset+Size`; it does not automatically decompress the member because bit `0x4` is present.

Setup-level `Compressed=True` handling is separate manifest/install behavior and must not be conflated with archive-entry compression flags.

## 9. Builder behavior

`UMasterCommandlet` is the official archive builder.

When `[Setup] Archive=` is non-empty it creates an `FArchiveWriter`. Each ordinary archived source file is:

1. opened through the normal file manager;
2. aligned by writing zero bytes until the current archive size satisfies the requested alignment;
3. copied byte-for-byte into the archive;
4. added to `GArc._Items_` with destination name, absolute payload offset, original size and flags.

The normal `CopyGroup` path calls `LocalCopyFile(..., Align=16)`. Thus the official builder aligns ordinary archived member starts to 16-byte boundaries.

Alignment padding is not represented as a directory item and is simply part of the pre-trailer byte stream.

A parser must use directory offsets, not infer payload locations by concatenating member sizes.

## 10. Builder finalization

The official builder finishes a packed archive as follows:

```text
GArc.TableOffset = current archive size
serialize GArc._Items_
GArc.CRC          = ArchiveCRC
GArc.FileSize     = current archive size + 20
serialize GArc trailer
```

`FArchiveWriter::Serialize` updates `ArchiveCRC` for every byte written before the trailer.

Therefore the stored CRC covers:

- payloads;
- alignment padding;
- serialized directory;

and excludes the final 20-byte trailer.

## 11. CRC

The reader calculates CRC from byte zero through `HeaderPos-1`, where:

```text
HeaderPos = TotalSize - 20
```

using rolling `appMemCrc`.

It requires the result to equal `Header.CRC`.

This matches the builder's running CRC immediately after it serializes the directory and before it writes the trailer.

## 12. Reader validation

For the normal desktop path, `FFileManagerArc::Init`:

1. obtains the real module file size;
2. requires it to be positive;
3. opens the file;
4. seeks to `TotalSize - 20`;
5. deserializes `FArchiveHeader`;
6. requires no archive error;
7. requires the correct magic;
8. requires archive version 1;
9. requires stored `FileSize == real file size`;
10. if verification is enabled and the filename is not `.exe`, calculates the pre-trailer CRC;
11. requires the CRC to match;
12. seeks to `TableOffset`;
13. deserializes the item array;
14. requires successful directory deserialization.

The `Setup.exe install` path constructs `FFileManagerArc(..., Verify=1)`, so normal UT2003 module installation requests CRC verification.

## 13. Member reader

An archive member is exposed through `FFileReaderArc`.

It has:

```text
Base = item.Offset
Size = item.Size
Pos  = 0
```

Seeking is limited to `0..Size`, and serialization requires:

```text
Pos + Length <= Size
```

Physical reads are performed at `Base + Pos`.

This proves that directory offsets and sizes are the authoritative member boundaries.

## 14. Path handling

UT2003's common archive code canonicalizes filename matching by:

- treating `\` as `/`;
- uppercasing characters for case-insensitive matching.

For conversion into archive-visible paths:

- an input beginning `../` or `..\` loses that parent prefix;
- otherwise it is treated beneath `System`.

The archive is an overlay. If a requested member is absent, `CreateFileReader` falls through to the underlying file manager.

That behavior matters to reproducing Setup, but a standalone extractor need only preserve the stored directory filenames and boundaries.

## 15. Manifest.ini

After mounting the supplied `.ut2mod`, Setup explicitly:

1. flushes the existing `Manifest.ini` configuration;
2. reloads config through the archive-backed file manager;
3. reloads localization;
4. detaches the configuration so installation can modify it locally.

Thus the module's installation instructions are provided through the same Manifest/setup mechanism used by the packed module system.

The manifest is a member/content-level structure. It is not the physical archive directory.

## 16. UT2003 manifest file-record additions

Compared with the older retail UT99 setup record, the UT2003 `FFileInfo` source includes additional distribution/install fields. The inspected UT2003 structure contains:

```text
Dest, Src, Master, Ref, Lang
Size, RefSize
MasterRecurse
Flags
CDNum
Compressed
CompSize
```

The parser recognizes, among the normal file-record properties, `COMPRESSED=`.

These fields control installer/distribution behavior and are not extra fields in `FArchiveItem`.

In particular, `Compressed=True` causes Setup to look for/copy a source using the file manager's compressed-file copy mode. It is not proof that the corresponding archive entry itself uses a different physical member representation.

## 17. Delta payloads

`UMasterCommandlet` can produce a delta-coded source when a reference file is configured.

The delta stream starts with five fixed integers:

```text
Magic   = 0x92f92912
OldSize
OldCRC
NewSize
NewCRC
```

followed by compact-index-coded literal/copy commands.

When an archive is being generated, that delta byte stream is inserted as an ordinary `FArchiveItem` with flags zero.

This is not a different UT2MOD container layer. It is a possible member payload used by the Setup patching system.

UnrealDB should not try to classify every archive member with this magic as an archive subformat unless it is specifically implementing Setup delta semantics.

## 18. Self-extracting form

The builder can prepend `RunSFX.exe` when the configured archive filename ends in `.exe`.

The archive directory and trailer are still written after the resulting payload stream.

The reader skips its own CRC check for a filename ending in `.exe`, with the source comment that the SFX already verified it.

This behavior does not apply merely because a UT2MOD member contains an executable.

## 19. UUpdateUModCommandlet confirmation

UT2003/UT2004 also contain `UUpdateUModCommandlet`.

Its implementation independently confirms the physical contract by:

- loading the complete archive;
- seeking to `TotalSize - ARCHIVE_HEADER_SIZE`;
- reading `FArchiveHeader`;
- seeking to `TableOffset`;
- deserializing `_Items_`;
- using each item's absolute `Offset` and `Size` for extraction;
- rebuilding members;
- serializing the directory;
- calculating `appMemCrc` over the rebuilt pre-trailer bytes;
- writing the 20-byte trailer.

This is useful independent confirmation that the common module layout is intentional and not merely an installer-reader assumption.


## 20. UE2.5 / Unreal Warfare cross-check

The supplied UE2.5 tree was independently checked rather than inferred from UT2003.

`Core/Inc/FFileManagerArc.h` preserves the same physical archive contract byte-for-byte at the structural level:

- `ARCHIVE_MAGIC = 0x9fe3c5a3`;
- `ARCHIVE_HEADER_SIZE = 5*4`;
- `ARCHIVE_VERSION = 1`;
- `FArchiveHeader = INT Magic, TableOffset, FileSize, Ver, CRC`;
- header serialization order remains exactly those five fields;
- `FArchiveItem = FString _Filename_ + DWORD Offset + DWORD Size + DWORD Flags`;
- item serialization order is unchanged;
- the trailer is read at `TotalSize()-20`;
- stored size, magic and version are validated;
- optional CRC verification covers bytes `0..HeaderPos-1`;
- the directory is still deserialized from `Header.TableOffset`;
- member reads remain bounded by the stored offset and size.

The UE2.5 declaration retains both:

```text
ARCHIVEF_Bootstrap  = 0x00000001
ARCHIVEF_Compressed = 0x00000004
```

Thus there is no UE2.5 change to the on-disk trailer, directory-entry layout, version, or these declared flag bits.

### UE2.5 builder verification

`Editor/Src/UMasterCommandlet.cpp` independently confirms the writer side:

- archive writes maintain a rolling `ArchiveCRC`;
- ordinary archived files still go through `LocalCopyFile(..., Align=16)`;
- each directory item records the absolute position returned by the archive writer;
- `TableOffset` is captured before serializing the item array;
- the directory is serialized before the trailer;
- `GArc.CRC` is the writer CRC after the directory;
- `GArc.FileSize = current size + ARCHIVE_HEADER_SIZE`;
- the same 20-byte header is appended last.

The UE2.5 `UUpdateUModCommandlet` also reads the trailer from EOF-20, seeks to `TableOffset`, uses the stored item offsets/sizes, rebuilds the item array, calculates `appMemCrc` over the rebuilt pre-trailer data, and appends the same header. This is a second UE2.5 writer/editor confirmation of the format.

### UE2.5 installer verification

`Setup/Src/USetupDefinition.cpp` still routes the `install` disposition through the packed-module path and describes it as installing a packed `.umod` file. The common archive file manager is therefore still the installer container reader.

The supplied `System/SetupUnrealEngine2.ini` registers `.umod` as `Unreal.Module` and routes it to:

```text
%DestPath%\System\Setup.exe install "%1"
```

Importantly, this UE2.5/Warfare setup file does **not** prove that `.ut2mod` is its registered extension. It proves that the same module container and Setup installation machinery persisted in the engine line. The `.ut2mod` association remains specifically proven by the UT2003 setup data.

### UE2.5 manifest/install additions

The UE2.5 `FFileInfo` includes:

```text
Dest, Src, Ref, Lang
Size, RefSize
MasterRecurse
Flags
CDNum
Compressed
CompSize
Optional
```

and parses both:

```text
COMPRESSED=
OPTIONAL=
```

The `Optional` file property is therefore a later Setup/manifest semantic visible in the UE2.5 tree. It is **not** an additional `FArchiveItem` field and does not alter the physical module archive.

The UE2.5 install path also retains compressed-source copying and the same delta-patch stream machinery. These remain member/install semantics above the archive container.

## 21. UT2004 cross-check

The UT2004 tree was independently compared with both UT2003 and UE2.5.

The UT2004 `Core/Inc/FFileManagerArc.h` retains the same:

- magic `0x9fe3c5a3`;
- 20-byte trailer;
- version 1;
- five-field header and serialization order;
- four-field archive item and serialization order;
- EOF trailer lookup;
- total-size check;
- pre-trailer rolling CRC;
- `TableOffset` directory;
- bounded member reader;
- `ARCHIVEF_Bootstrap=0x1` and `ARCHIVEF_Compressed=0x4`.

`Editor/Src/UMasterCommandlet.cpp` likewise retains 16-byte ordinary-member alignment, the same directory finalization order and the same CRC/file-size calculation. `UUpdateUModCommandlet` retains the same update/rebuild contract.

UT2004's `FFileInfo` includes and parses `Optional`, matching the later UE2.5 setup structure. This is a manifest-level addition relative to the inspected UT2003 Setup header; it does not change the archive entry.

Most importantly for UT2MOD identity, the UT2004 source tree contains the retained UT2003 setup manifests `SetupUT2003_Full.ini` and `SetupUT2003_Demo.ini`. They explicitly register:

```text
.ut2mod = UT2003.Module
UT2003.Module open command = Setup.exe install "%1"
```

This independently confirms the UT2003 extension-to-common-installer routing.

## 22. Cross-version result

The comparison now covers all three relevant supplied stages:

| Behavior | UT2003 v2107 | UE2.5 / Warfare tree | UT2004 |
|---|---|---|---|
| archive magic | `0x9fe3c5a3` | same | same |
| trailer size | 20 | same | same |
| archive version | 1 | same | same |
| header fields/order | 5 INTs | same | same |
| item fields/order | FString + 3 DWORDs | same | same |
| directory | TArray at TableOffset | same | same |
| member alignment by Master commandlet | 16 | same | same |
| CRC range | all pre-trailer bytes | same | same |
| Bootstrap flag | `0x1` | same | same |
| Compressed flag | `0x4` | same | same |
| Setup install uses common archive manager | yes | yes | yes |
| `.ut2mod` registration proven in supplied tree | UT2003 identity; registration corroborated by retained UT2003 setup data | no: supplied setup registers `.umod` | yes, retained UT2003 setup manifests |
| `FFileInfo.Optional` parsed | not present in inspected UT2003 header | yes | yes |

The significant later difference found is therefore in **Setup manifest semantics**, not in the binary archive container.

This distinction matters: UnrealDB may safely share the physical module parser across these proven revisions, while it must not flatten their installer/manifest semantics into one revision-independent schema.


## 23. Previous UT2004 summary

The supplied UT2004 source retains:

- the same `ARCHIVE_MAGIC`;
- the same 20-byte trailer;
- archive version 1;
- the same `FArchiveItem` field order;
- the same EOF trailer lookup;
- the same directory serialization;
- the same CRC range;
- the same `UMasterCommandlet` archive construction pattern;
- the same `UUpdateUModCommandlet` archive editing pattern;
- the same Setup `install` path through `FFileManagerArc`.

UT2004's source also retains UT2003 setup manifests which explicitly register `.ut2mod` and route it to `Setup.exe install "%1"`.

No separate UT2MOD binary parser was found in these official supplied paths. The source evidence instead connects the UT2MOD association to the common module reader.

## 24. Structural validation for UnrealDB

A safe source-compatible parser should:

- require at least 20 bytes before reading a trailer;
- read the trailer at exactly EOF minus 20;
- require magic `0x9fe3c5a3`;
- require archive version 1 for this format;
- require stored total size to equal actual total size;
- validate `TableOffset` before seeking;
- decode the directory count with Unreal compact-index rules;
- decode each `FString + DWORD + DWORD + DWORD` entry completely;
- reject entry ranges that overflow or lie outside the valid archive byte range;
- use the stored offsets rather than assuming tightly packed members;
- account for legal alignment padding;
- calculate the official CRC over all pre-trailer bytes for full validation.

Do not introduce undocumented fixed limits for item count, member size, filename length or archive size as format rules. Resource-protection limits, if used operationally, must remain implementation safeguards rather than claimed engine-format restrictions.

## 25. Identification rules

Extension alone is insufficient for structural validation.

A `.ut2mod` should be classified as a UT2003 module when the filename/context indicates that type, but its physical validation should use the common module trailer:

```text
EOF - 20:
    Magic       == 0x9fe3c5a3
    Version     == 1
    FileSize    == actual size
    TableOffset == structurally valid directory location
```

followed by successful directory parsing and, when requested, CRC verification.

The magic cannot by itself distinguish this archive from an Unreal package because the same numeric value is used by Unreal package files. Location and structure are essential: the module magic is in the EOF trailer.

## 26. UnrealDB conformance requirements

UnrealDB should:

- retain `.ut2mod` as a distinct externally reported file/mod type;
- implement its physical container through the proven version-1 Unreal module archive contract;
- not invent a UT2MOD-specific header or magic;
- inspect the EOF trailer rather than byte zero;
- decode the compact-indexed item array exactly;
- preserve filenames, offsets, sizes and flags;
- support alignment gaps naturally through absolute offsets;
- verify the official total-size and CRC contracts;
- keep `Manifest.ini` parsing separate from physical archive parsing;
- keep Setup compressed-file and delta-patch semantics separate from archive-entry encoding;
- not assume that a file named `.umod` belongs to UT99 or a file named `.ut2mod` has a different physical container solely from extension;
- use game/profile context and manifest semantics where a game-level distinction is required.

## 27. Source-reference matrix

| Rule | Source | Proof |
|---|---|---|
| UT2MOD association | `UT2004src/System/SetupUT2003_Full.ini`; `SetupUT2003_Demo.ini` | `.ut2mod=UT2003.Module` |
| UT2MOD install command | same | `UT2003.Module\Shell\open\command=...Setup.exe install "%1"` |
| install mounts common archive | `Unreal_Tournament_2003_v2107/Setup/Src/USetupDefinition.cpp` | `Token=="install"` creates `FFileManagerArc` |
| Setup still calls packed form UMOD | same | source comment and common install branch |
| common magic/version/trailer | `Unreal_Tournament_2003_v2107/Core/Inc/FFileManagerArc.h` | archive constants and `FArchiveHeader` |
| directory entry layout | same | `FArchiveItem::operator<<` |
| EOF trailer and CRC verification | same | `FFileManagerArc::Init` |
| bounded member access | same | `FFileReaderArc` |
| path normalization/overlay | same | `ToArcFilename`, `WildcardMatch`, `Lookup`, `CreateFileReader` |
| builder | `Unreal_Tournament_2003_v2107/Editor/Src/UMasterCommandlet.cpp` | `FArchiveWriter`, `LocalCopyFile`, `Main` |
| 16-byte member alignment | same | `CopyGroup` -> `LocalCopyFile(...,16)` |
| CRC includes directory | same | running `ArchiveCRC`, then item array, then capture CRC |
| updater confirms layout | same | `UUpdateUModCommandlet` |
| Manifest fields | `Unreal_Tournament_2003_v2107/Setup/Inc/Setup.h` | `FFileInfo` |
| compressed install handling | `Unreal_Tournament_2003_v2107/Setup/Src/USetupDefinition.cpp` | `LocateSourceFile`, `FILECOPY_Decompress` |
| delta stream | `Unreal_Tournament_2003_v2107/Editor/Src/UMasterCommandlet.cpp` | `DeltaCode`, `Decompress` |
| UE2.5 physical archive persistence | `UE2.5/.../Core/Inc/FFileManagerArc.h` | same magic/version/header/item/directory/CRC contract |
| UE2.5 writer/updater persistence | `UE2.5/.../Editor/Src/UMasterCommandlet.cpp` | same 16-byte alignment, finalization, CRC and update layout |
| UE2.5 installer persistence | `UE2.5/.../Setup/Src/USetupDefinition.cpp` | `install` still mounts common packed module archive |
| UE2.5 extension evidence | `UE2.5/.../System/SetupUnrealEngine2.ini` | registers `.umod`, not evidence for a `.ut2mod` association |
| UE2.5 later manifest Optional | `UE2.5/.../Setup/Inc/Setup.h` | `FFileInfo.Optional`, `OPTIONAL=` parser |
| UT2004 physical persistence | `UT2004src/Core/Inc/FFileManagerArc.h` | same common archive contract |
| UT2004 builder/updater persistence | `UT2004src/Editor/Src/UMasterCommandlet.cpp` | same archive build/update contract |
| UT2004 later manifest Optional | `UT2004src/Setup/Inc/Setup.h` | `OPTIONAL=` remains present |
| UT2003 association corroboration | `UT2004src/System/SetupUT2003_Full.ini`; `SetupUT2003_Demo.ini` | retained `.ut2mod=UT2003.Module` and Setup install command |

## 28. Result

For the supplied official UT2003 source, **UT2MOD is not a second binary archive format**. It is the UT2003 module file association routed to the engine's common packed module/UMOD archive reader.

The correct UnrealDB design is therefore:

```text
external classification: UT2MOD / UT2003 module
physical parser: Unreal module archive v1
installer metadata: UT2003 Manifest.ini semantics
```

This distinction avoids both errors: treating UT2MOD as an undocumented independent container, or losing the useful game-specific UT2MOD identity by calling every such file merely UMOD.
