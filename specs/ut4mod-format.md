# UT4MOD / Unreal Tournament 2004 Module Format

## 1. Scope

This specification covers the official `.ut4mod` module format used by **Unreal Tournament 2004**.

The name UT4MOD in this source means a **UT2004 module**. It is not an Unreal Tournament 4 / UE4 format.

Primary authority:

- repository: `ardenee/UT2004src`
- `System/setuput2004full.ini`
- `System/setuput2004demo.ini`
- `Core/Inc/FFileManagerArc.h`
- `Editor/Src/UMasterCommandlet.cpp`
- `Setup/Inc/Setup.h`
- `Setup/Src/USetupDefinition.cpp`

Cross-version comparison:

- `ardenee/Unreal_Tournament_2003_v2107`
- `ardenee/UE2.5`

Earlier sources are used to establish inheritance and differences. UT2004 is authoritative for UT4MOD.

## 2. Official extension identity

`System/setuput2004full.ini` explicitly registers:

```text
HKEY_CLASSES_ROOT\.ut4mod\ = UT2004.Module
```

and defines the class and action:

```text
UT2004.Module = UT2004 Module
UT2004.Module\Shell = open
UT2004.Module\Shell\open = &Install this UT2004 module
UT2004.Module\Shell\open\command = %DestPath%\System\Setup.exe install "%1"
```

This directly proves that `.ut4mod` is the UT2004 module extension.

The demo setup also defines `UT2004.Module` and the same Setup install command, although the inspected demo file does not itself contain the extension-association line.

## 3. Relationship to UMOD and UT2MOD

UT4MOD does not introduce a new physical archive structure in the supplied UT2004 implementation.

The shell association routes `.ut4mod` to the common Setup `install` path. `USetupDefinition::Init` handles `install` through the packed-module archive path. Its historical comment still calls this a packed `.umod` file because the underlying engine archive mechanism retained that name.

Keep these layers separate:

1. external identity: `.ut4mod` / `UT2004.Module`;
2. physical container: Unreal module archive version 1;
3. installer content/semantics: UT2004 Manifest/Setup data.

## 4. Physical layout

Logical layout:

```text
[member payloads and alignment padding]
[serialized TArray<FArchiveItem> directory]
[20-byte FArchiveHeader trailer]
EOF
```

The archive trailer is at EOF.

## 5. Archive constants

`Core/Inc/FFileManagerArc.h` defines:

```text
ARCHIVE_MAGIC       = 0x9fe3c5a3
ARCHIVE_HEADER_SIZE = 5*4 = 20
ARCHIVE_VERSION     = 1
```

The magic alone is not sufficient identification because the numeric value is used elsewhere in Unreal formats.

## 6. FArchiveHeader

The final 20 bytes serialize exactly:

```text
INT Magic
INT TableOffset
INT FileSize
INT Ver
INT CRC
```

Meanings:

- `Magic`: `0x9fe3c5a3`;
- `TableOffset`: absolute directory position;
- `FileSize`: total physical archive size including the trailer;
- `Ver`: 1;
- `CRC`: rolling `appMemCrc` of every byte before the trailer.

## 7. FArchiveItem

Each directory entry serializes:

```text
FString Filename
DWORD Offset
DWORD Size
DWORD Flags
```

`Offset` is absolute. `Size` bounds the member reader. The archive reader enforces reads within the member's stored size.

## 8. Directory serialization

The directory is `TArray<FArchiveItem>` at `Header.TableOffset`.

Its array count uses the engine's persistent compact-index serialization used by this source line; it is not an invented fixed-width count.

## 9. Archive flags

UT2004 declares:

```text
ARCHIVEF_Bootstrap  = 0x00000001
ARCHIVEF_Compressed = 0x00000004
```

No additional UT4MOD-specific archive flag is defined in the inspected header.

The presence of `ARCHIVEF_Compressed` does not mean `FFileManagerArc` transparently decompresses member bytes. The common member reader exposes the stored bounded range.

## 10. Writer alignment

`UMasterCommandlet::LocalCopyFile` aligns ordinary archive members. The normal module path calls it with alignment 16.

The writer emits zero padding until the output position is 16-byte aligned, then records the member's absolute offset.

That padding is part of the pre-trailer byte stream and therefore part of the archive CRC.

This is an official writer behavior, not by itself a parser requirement for every structurally readable archive.

## 11. Builder finalization

The Master commandlet:

1. sets `TableOffset` to the current output position;
2. serializes the item array;
3. stores the writer's running `ArchiveCRC`;
4. sets `FileSize = current size + 20`;
5. serializes the 20-byte header last.

Thus CRC coverage includes payloads, alignment padding and directory bytes, and excludes only the trailer.

## 12. Reader validation

The common reader:

1. obtains physical size;
2. opens the file;
3. seeks to `TotalSize()-20`;
4. deserializes the header;
5. requires no archive error;
6. requires the expected magic;
7. requires version 1;
8. requires stored `FileSize` to equal physical size;
9. if verification is enabled and the filename is not `.exe`, computes CRC over `[0, EOF-20)`;
10. requires the CRC to match;
11. seeks to `TableOffset`;
12. deserializes the item array.

Safety checks needed to prevent integer overflow, invalid seeks or out-of-bounds reads may be added by UnrealDB, but must not be presented as source-defined format restrictions when they are not.

## 13. CRC

The reader updates `appMemCrc` in 16 KiB chunks.

The exact CRC region is:

```text
[0, file_size - 20)
```

`UMasterCommandlet` and `UUpdateUModCommandlet` independently confirm the same pre-trailer CRC construction.

## 14. SFX exception

The reader skips its CRC pass for a filename ending in `.exe`, with the source comment that the self-extractor has already checked it.

That exception does not apply to ordinary `.ut4mod` validation.

## 15. Path lookup and overlay

Archive path matching canonicalizes backslash to slash and uppercases characters for comparison.

If a requested file is not found in the archive, the archive file manager can fall through to the underlying file manager. This is runtime overlay behavior, not evidence of another member in the archive.

## 16. Manifest and Setup

Setup retains:

```text
MANIFEST_FILE = "Manifest"
MANIFEST_EXT  = ".ini"
SETUP_INI     = "Manifest.ini"
```

After mounting a packed module, Setup reloads configuration through the archive-backed file manager.

`Manifest.ini` is installer content. It is not the physical archive directory.

## 17. UT2004 file-record semantics

UT2004 `FFileInfo` includes:

```text
Dest
Src
Ref
Lang
Size
RefSize
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

These are Setup/Manifest fields, not `FArchiveItem` fields.

`Optional` affects missing-source handling during installation.

## 18. Installer operations

UT2004 Setup retains operations including:

```text
File
Copy
Group
Folder
Backup
Delete
Ini
SaveIni
AddIni
Requires
```

They are installation instructions layered on top of the archive container.

## 19. Compressed manifest files

A file record may specify `Compressed=True`.

Setup then locates the compressed source representation and uses the file-copy decompression path.

This is distinct from `FArchiveItem.Flags` and must not be conflated with archive-reader decompression.

## 20. Delta/reference patch payload

The Master commandlet retains delta generation. A generated delta begins:

```text
INT Magic = 0x92f92912
INT OldSize
INT OldCRC
INT NewSize
INT NewCRC
```

followed by compact-index-coded literal/copy operations.

When placed in the archive it is still represented by an ordinary `FArchiveItem`. Delta coding is therefore an installer payload semantic, not an alternate UT4MOD container.

## 21. UUpdateUModCommandlet confirmation

Despite the historical UMOD name, the UT2004 updater independently reads and rebuilds the same common archive:

- header at EOF-20;
- directory at `TableOffset`;
- members by stored offset/size;
- item flags retained;
- rebuilt directory before trailer;
- CRC over the complete rebuilt pre-trailer data;
- `FileSize = pre-trailer size + 20`;
- trailer last.

This provides a second UT2004 implementation confirming the physical layout.

## 22. Cross-version verification

UT2003, the supplied UE2.5/Warfare tree and UT2004 were compared.

| Behavior | UT2003 | UE2.5/Warfare | UT2004 / UT4MOD |
|---|---|---|---|
| magic | `0x9fe3c5a3` | same | same |
| version | 1 | same | same |
| trailer | 20 bytes | same | same |
| header | five INTs | same | same |
| item | FString + 3 DWORDs | same | same |
| directory | TArray at TableOffset | same | same |
| ordinary writer alignment | 16 | same | same |
| CRC | all pre-trailer bytes | same | same |
| Bootstrap flag | `0x1` | same | same |
| Compressed flag | `0x4` | same | same |
| Setup packed-module path | yes | yes | yes |
| Optional file semantic | absent in inspected UT2003 FFileInfo | yes | yes |
| game module extension | `.ut2mod` | supplied setup registers `.umod` | **`.ut4mod`** |

The significant UT2004 distinction is therefore the official external UT2004 module identity and its Setup semantics. No new physical module container version appears.

## 23. Identification rules

UnrealDB must not identify a valid UT4MOD solely from the extension or magic.

For structural validation:

1. require enough bytes for the 20-byte trailer;
2. read trailer at EOF-20;
3. require magic `0x9fe3c5a3`;
4. require version 1;
5. require stored `FileSize` == physical size;
6. safely validate `TableOffset`;
7. deserialize the directory using the correct engine serialization;
8. safely validate member offset/size ranges;
9. for full verification, require CRC of the complete pre-trailer region to match.

After structural validation, the `.ut4mod` extension supplies the official UT2004 external module classification.

It must never be interpreted as Unreal Tournament 4 / UE4.

## 24. UnrealDB conformance requirements

UnrealDB should:

- classify `.ut4mod` as Unreal Tournament 2004 Module;
- use the proven version-1 Unreal module archive parser;
- read the trailer from EOF;
- preserve exact header and item serialization order;
- use the correct FString/TArray/compact-index serialization;
- treat item offsets as absolute;
- enforce bounded member reads;
- verify stored total size;
- verify CRC over exactly the pre-trailer bytes;
- keep archive flags separate from Manifest `Compressed=True`;
- keep delta payload interpretation separate from the outer container;
- keep UT2004 Setup semantics separate from the common physical archive;
- not invent UT4MOD-specific header fields, versions, flags, compression layers, limits or UE4 semantics.

## 25. Source-reference matrix

| Rule | Source | Behavior proved |
|---|---|---|
| UT4MOD extension | `System/setuput2004full.ini` | `.ut4mod=UT2004.Module` |
| module class/action | full/demo setup INIs | UT2004 Module and `Setup.exe install "%1"` |
| archive constants | `Core/Inc/FFileManagerArc.h` | magic, 20-byte header, version 1 |
| header layout | same | five serialized INTs |
| item layout | same | FString, Offset, Size, Flags |
| trailer position | same | EOF minus 20 |
| size/version/magic validation | same | reader validation |
| CRC range | same | bytes before trailer |
| directory | same | seek TableOffset and deserialize TArray |
| member bounds | same | offset/size bounded reader |
| path matching | same | archive canonicalization |
| flags | same | Bootstrap 0x1, Compressed 0x4 |
| packed install route | `Setup/Src/USetupDefinition.cpp` | `install` mounts packed module |
| manifest constants | `Setup/Inc/Setup.h` | Manifest.ini |
| UT2004 FFileInfo | same | file record fields |
| Optional | same + USetupDefinition.cpp | parser and install behavior |
| Setup compression | same + USetupDefinition.cpp | compressed-source copy/decompress |
| writer alignment | `Editor/Src/UMasterCommandlet.cpp` | LocalCopyFile alignment 16 |
| finalization | same | directory, CRC, size, trailer |
| delta format | same | DeltaCode |
| updater | same | independent rebuild of same archive |
| earlier persistence | corresponding UT2003 and UE2.5 Core/Editor/Setup source | unchanged physical archive contract |

## 26. Result

The official UT2004 source proves:

```text
.ut4mod
    -> UT2004.Module
    -> Setup.exe install
    -> common Unreal module archive version 1
```

UT4MOD is therefore **Unreal Tournament 2004's module extension**, not an Unreal Tournament 4/UE4 format.

Its physical container remains the same source-proven Unreal module archive family used by the earlier installer line; UT2004 does not introduce a replacement binary header or directory format.
