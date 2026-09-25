# PAK Format

## Scope and authority

This specification documents the Unreal Engine 4 PAK container format from the supplied Epic UE4 source.

Primary authority:

- Repository: `ardenee/UnrealEngine4`
- Branch: `UE4.27.2`
- Engine lineage: UE4 4.27.2
- Runtime implementation: `Engine/Source/Runtime/PakFile`

This document covers the container structure, version identification, trailer, indexes, entry metadata, file placement, and version-dependent layout. Compression and encryption algorithms are intentionally separated into the following README items, although the structural fields that identify compressed/encrypted data are documented here because they are part of the PAK format.

Do not project UE5 IoStore/Zen container behavior backward into this format.

## Authoritative source references

| Area | Source |
|---|---|
| PAK magic/version/trailer | `Runtime/PakFile/Public/IPlatformFilePak.h: FPakInfo` |
| entry structure and serialization | `IPlatformFilePak.h: FPakEntry` |
| compressed-block structure | `IPlatformFilePak.h: FPakCompressedBlock` |
| compact entry encoding | `IPlatformFilePak.cpp: FPakFile::EncodePakEntry, DecodePakEntry` |
| trailer discovery | `IPlatformFilePak.cpp: FPakFile::Initialize` |
| legacy index loading | `IPlatformFilePak.cpp: FPakFile::LoadLegacyIndex` |
| v10+ index loading | `IPlatformFilePak.cpp: FPakFile::LoadIndexInternal` |
| index validation | `IPlatformFilePak.cpp: FPakFile::DecryptAndValidateIndex` |
| path hashing | `IPlatformFilePak.cpp: FPakFile::HashPath` |
| entry/index reconstruction | `FPakFile::EncodePakEntriesIntoIndex, AddEntryToIndex, GetPakEntry` |
| payload/index consistency | `FPakEntry::VerifyPakEntriesMatch, FPakFile::Check` |

## Container orientation

A PAK is not identified by a header at byte zero.

The authoritative `FPakInfo` record is a **trailer at the end of the file**. It tells the loader where the index is located.

Conceptually:

```text
[file payload/header regions ...]
[index region(s) ...]
[FPakInfo trailer]
EOF
```

The loader begins at EOF, finds a valid `FPakInfo`, validates its version and index bounds, then loads the index.

Therefore UnrealDB must not require `0x5A6F12E1` at offset zero.

## PAK magic

`FPakInfo::PakFile_Magic` is:

```text
0x5A6F12E1
```

It occurs inside the serialized `FPakInfo` trailer.

The extension `.pak` alone is not proof of this format.

## PAK versions

UE4 4.27.2 defines:

| Version | Value | Change marker |
|---|---:|---|
| `PakFile_Version_Initial` | 1 | initial format |
| `PakFile_Version_NoTimestamps` | 2 | timestamps removed |
| `PakFile_Version_CompressionEncryption` | 3 | compression/encryption entry metadata |
| `PakFile_Version_IndexEncryption` | 4 | encrypted-index support |
| `PakFile_Version_RelativeChunkOffsets` | 5 | compressed-block offsets may be relative |
| `PakFile_Version_DeleteRecords` | 6 | delete-record generation |
| `PakFile_Version_EncryptionKeyGuid` | 7 | trailer encryption-key GUID |
| `PakFile_Version_FNameBasedCompressionMethod` | 8 | named compression methods |
| `PakFile_Version_FrozenIndex` | 9 | frozen-index generation |
| `PakFile_Version_PathHashIndex` | 10 | path-hash/new split index |
| `PakFile_Version_Fnv64BugFix` | 11 | corrected FNV64 path hashing |

`PakFile_Version_Latest` is 11 in this source.

These are serialized-format compatibility versions, not engine package versions.

## Trailer discovery

`FPakFile::Initialize` does not assume the latest trailer size.

It starts with `PakFile_Version_Latest` and tries trailer layouts from newest to oldest:

1. calculate `FileInfoPos = TotalSize - Info.GetSerializedSize(candidateVersion)`;
2. seek there;
3. call `Info.Serialize(..., candidateVersion)`;
4. accept the candidate when the serialized magic equals `PakFile_Magic`;
5. otherwise try the next older version down to version 1.

After finding the trailer, the loader validates:

- `Info.Version >= PakFile_Version_Initial`;
- `Info.Version <= CompatibleVersion`;
- `IndexOffset >= 0`;
- `IndexOffset < file size`;
- `IndexOffset + IndexSize` remains inside the file.

A reader must reproduce version-aware trailer sizing. Looking only at the final latest-version trailer size will reject valid old PAKs.

## FPakInfo serialized fields

The logical fields are:

- `FGuid EncryptionKeyGuid`;
- `uint8 bEncryptedIndex`;
- `uint32 Magic`;
- `int32 Version`;
- `int64 IndexOffset`;
- `int64 IndexSize`;
- `FSHAHash IndexHash`;
- version-dependent compression-method table;
- version-9-only frozen-index boolean.

The serialized order is version dependent.

### Versions 7 and newer

`EncryptionKeyGuid` is serialized first, followed by:

1. `bEncryptedIndex`
2. `Magic`
3. `Version`
4. `IndexOffset`
5. `IndexSize`
6. `IndexHash`

For versions older than 7 the GUID is absent and invalidated on load.

### Index-encryption flag compatibility

The byte `bEncryptedIndex` participates in the trailer layout used by the current serializer, but after loading a version older than `PakFile_Version_IndexEncryption` (4), UE4 forcibly sets it false.

Do not treat arbitrary nonzero legacy bytes as proof of encrypted index data.

## Trailer serialized size

`FPakInfo::GetSerializedSize` starts with the fixed sizes of:

- Magic
- Version
- IndexOffset
- IndexSize
- IndexHash
- bEncryptedIndex

and conditionally adds:

- `sizeof(FGuid)` for version >= 7;
- `32 * 5 = 160` bytes of compression-method storage for version >= 8;
- `sizeof(bool)` for version 9 only (`>= FrozenIndex && < PathHashIndex`).

This size calculation is part of trailer discovery and must match the candidate version.

## Compression-method names in the trailer

For version >= 8, `FPakInfo` serializes a fixed 160-byte method-name area:

- `CompressionMethodNameLen = 32`;
- `MaxNumCompressionMethods = 5`;
- five fixed 32-byte ANSI slots.

`CompressionMethods[0]` is always `NAME_None` and is **not** stored in those slots.

Nonempty serialized slots are appended to the in-memory method array.

The source zero-fills the entire fixed area before saving.

For versions < 8, the trailer does not contain this table. The loader instead installs the legacy known names:

1. index 0: None
2. index 1: Zlib
3. index 2: Gzip
4. index 3: Oodle/custom legacy slot

Exact compression behavior belongs to the PAK compression specification.

## Version 9 frozen index

For:

```text
Version >= 9 && Version < 10
```

the trailer contains a serialized boolean `bIndexIsFrozen`.

UE4 4.27.2 explicitly rejects a true value with a fatal message stating that this frozen format is no longer supported and the PAK must be regenerated.

This field is not present in version 10+ trailers.

A format reader should recognize the field and report the unsupported frozen-index state rather than shifting the rest of the trailer.

## FPakEntry

A file stored in a PAK is described by `FPakEntry`:

- `int64 Offset`
- `int64 Size`
- `int64 UncompressedSize`
- 20-byte SHA1 `Hash`
- `TArray<FPakCompressedBlock> CompressionBlocks`
- `uint32 CompressionBlockSize`
- `uint32 CompressionMethodIndex`
- `uint8 Flags`

`Verified` is runtime state and is **not serialized**.

Flags:

- `Flag_Encrypted = 0x01`
- `Flag_Deleted = 0x02`

The detailed encryption operation is covered separately.

## FPakEntry serialized order

`FPakEntry::Serialize` writes/reads:

1. `Offset` — int64
2. `Size` — int64
3. `UncompressedSize` — int64
4. compression method field
5. version-1 timestamp, when applicable
6. 20-byte SHA1 hash
7. for version >= 3, compression blocks when compressed
8. for version >= 3, `Flags`
9. for version >= 3, `CompressionBlockSize`

### Compression method field

For version < 8 it is a legacy int32 bitmask/value. Loading maps:

- `COMPRESS_None` -> method index 0
- `COMPRESS_ZLIB` -> index 1
- `COMPRESS_GZIP` -> index 2
- `COMPRESS_Custom` -> index 3

An unknown legacy compression type is fatal in the source.

For version >= 8, the field is serialized directly as `CompressionMethodIndex`.

### Timestamp

Only version 1 serializes `FDateTime Timestamp`.

Version 2 introduced `NoTimestamps`.

### Compression blocks

For version >= 3, a compressed entry (`CompressionMethodIndex != 0`) serializes a `TArray<FPakCompressedBlock>`.

Each block contains:

1. `int64 CompressedStart`
2. `int64 CompressedEnd`

The array uses normal UE `TArray` serialization, so its count precedes its records.

## Relative compressed offsets

Version 5 introduced `PakFile_Version_RelativeChunkOffsets`.

`FPakInfo::HasRelativeCompressedChunkOffsets()` returns true for version >= 5.

When reconstructing compact entries:

- version >= 5 uses base offset 0 for compressed-block metadata and later adds the entry's `Offset` when reading;
- older versions use the entry's absolute `Offset` as the base.

This distinction is serialized-version behavior and must not be guessed from block values.

## Payload placement

`FPakEntry.Offset` points to the **serialized per-file FPakEntry header**, not directly to the file's payload bytes.

For an ordinary uncompressed entry, payload data begins at:

```text
Offset + FPakEntry.GetSerializedSize(PakVersion)
```

The runtime mapped-file path uses exactly this expression.

For compressed entries, the compression-block descriptors identify the physical compressed ranges. Version >= 5 relative block offsets are resolved relative to the entry offset as described above.

## Duplicate entry metadata: index versus payload

PAK files keep entry metadata in the index and also serialize an `FPakEntry` immediately before each stored file payload.

This is intentional.

`FPakFile::Check`:

1. obtains an entry from the index;
2. seeks to `Entry.Offset`;
3. serializes the payload-side `FPakEntry`;
4. compares its index-relevant fields with the index entry;
5. reads the physical stored payload;
6. SHA1-hashes those stored bytes;
7. compares the result with the payload-side entry hash.

The index representation and payload representation are therefore related but not always byte-identical.

## Entry hash semantics

`FPakEntry.Hash` is a 20-byte SHA1.

The source's full PAK check hashes `Entry.Size` physical stored bytes following the payload-side header and compares that SHA1 with the header hash.

For compact/new indexes, index entries do not retain the hash. `GetPakEntry` zeroes the returned hash and marks it verified because hash validation from that index representation is impossible. The payload-side header remains the source for that hash when needed.

## Legacy index: versions 1 through 9

For versions before `PakFile_Version_PathHashIndex` (10), `LoadLegacyIndex` reads one index blob from:

- `Info.IndexOffset`
- length `Info.IndexSize`

After index decryption if required, SHA1 of the resulting index bytes must equal `Info.IndexHash`.

The logical legacy index contains:

1. `FString MountPoint`
2. `int32 NumEntries`
3. repeated `NumEntries` times:
   - `FString Filename`
   - versioned `FPakEntry`

UE4 4.27.2 then converts these legacy entries into its newer in-memory encoded-entry/index representation. That conversion is runtime implementation and does not change the legacy on-disk index layout.

## Version 10+ primary index

For version >= 10, `LoadIndexInternal` reads a **primary index** at `Info.IndexOffset/IndexSize`.

After validation, its serialized order begins:

1. `FString MountPoint`
2. `int32 NumEntries`
3. `uint64 PathHashSeed`
4. `bool bReaderHasPathHashIndex`
5. if true:
   - `int64 PathHashIndexOffset`
   - `int64 PathHashIndexSize`
   - `FSHAHash PathHashIndexHash`
6. `bool bReaderHasFullDirectoryIndex`
7. if true:
   - `int64 FullDirectoryIndexOffset`
   - `int64 FullDirectoryIndexSize`
   - `FSHAHash FullDirectoryIndexHash`
8. `TArray<uint8> EncodedPakEntries`
9. `int32 FilesNum`
10. `FilesNum` ordinary versioned `FPakEntry` records for entries that could not use the compact encoding

At least one secondary lookup index — path hash or full directory — must be present. The source treats a primary index containing neither as corrupt.

## Secondary indexes in version 10+

The primary index may point to:

- a path-hash index;
- a full-directory index;
- both.

Each secondary index is a separately stored byte region with its own:

- offset;
- size;
- SHA1 hash.

The loader bounds-checks, decrypts if the PAK index is encrypted, and SHA1-validates each secondary index independently before deserializing it.

The runtime may choose not to retain/read the full directory index depending on pruning settings. That is a memory/runtime policy; it does not alter the serialized PAK structure.

## Path-hash index

The path-hash index maps:

```text
uint64 path hash -> FPakEntryLocation
```

`FPakEntryLocation` serializes one int32.

Its value represents either:

- a byte offset into `EncodedPakEntries`;
- an index into the non-encodable `Files` list;
- an invalid marker, also used to represent a delete record in the reconstructed index.

Path strings are lowercased before hashing.

Version 11 changed the FNV64 implementation because the previous constants were accidentally swapped. UE4 retains `LegacyMemFnv64` for versions < 11 and uses corrected `FFnv::MemFnv64` for version >= 11.

A reader must select the hash algorithm by PAK version.

## Full-directory index

The full-directory index is conceptually:

```text
TMap<FString directory, TMap<FString filename, FPakEntryLocation>>
```

The filename/path information is relative to the PAK mount point.

This index can preserve filenames for enumeration, whereas a path-hash-only runtime index does not inherently provide filenames.

## Mount point

Both legacy and new indexes serialize a `MountPoint` FString.

UE4 normalizes it into directory form and resolves indexed relative paths underneath it.

The mount point is part of PAK path identity. It is not the host filesystem path of the PAK itself.

## Compact FPakEntry encoding

Version 10+ indexes can store most entries in `EncodedPakEntries` using `FPakFile::EncodePakEntry`/`DecodePakEntry`.

The first uint32 is a bitfield:

```text
bit 31       Offset fits uint32
bit 30       UncompressedSize fits uint32
bit 29       Size fits uint32
bits 28..23  CompressionMethodIndex (6 bits)
bit 22       Encrypted
bits 21..6   CompressionBlocks count (16 bits)
bits 5..0    CompressionBlockSize encoding
```

If bits 5..0 equal `0x3f`, an explicit uint32 compression-block size follows the bitfield.

Otherwise the old compact representation derives it as:

```text
(bits5..0) << 11
```

The remaining values follow in compact 32- or 64-bit form according to bits 31..29.

If the entry is uncompressed, serialized `Size` is omitted because it equals `UncompressedSize`.

Delete records are not compact-encoded as normal entries; their index location is invalid and `GetPakEntry` reconstructs a dummy entry with `Flag_Deleted`.

## Compact compression-block reconstruction

The compact representation can omit data derivable from other fields.

If there is exactly one compression block and the entry is not encrypted, no explicit block-size record is needed; the decoder derives:

```text
CompressedStart = BaseOffset + entry-header-size
CompressedEnd   = CompressedStart + Entry.Size
```

For other compressed entries, compact data stores per-block compressed byte lengths. The decoder reconstructs starts/ends sequentially.

Encrypted compressed blocks are aligned to `FAES::AESBlockSize`; unencrypted blocks use alignment 1.

The actual encryption algorithm belongs to the PAK encryption specification.

## Entry-location encoding

`FPakEntryLocation` uses one signed int32 with these source-defined ranges:

- `0x00000000 .. 0x7ffffffe`: byte offset into `EncodedPakEntries`;
- `0x7fffffff`: unused/invalid;
- `0x80000000`: invalid;
- `0x80000001 .. 0xffffffff`: reverse encoding of an index into the non-encodable `Files` array.

For a list index `N`, the serialized value is:

```text
-N - 1
```

subject to the source's valid range.

## Delete records

`Flag_Deleted = 0x02` exists from the compression/encryption-era entry structure, while PAK version 6 marks the format generation that introduced delete records.

In the newer reconstructed index, delete records are represented by invalid `FPakEntryLocation` values rather than ordinary compact entries. `GetPakEntry` turns an invalid location into an `FPakEntry` with `Flag_Deleted`.

Do not interpret a delete record as a zero-byte physical payload.

## Index integrity

Every index blob is SHA1-validated after any required index decryption:

```text
SHA1(index bytes) == expected index hash
```

For the primary index the expected hash is `FPakInfo.IndexHash`.

For v10+ secondary indexes, their expected hashes are stored in the primary index.

An index that fails its hash is corrupt or cannot yet be correctly decrypted; it must not be parsed as trusted structural data.

## Load sequence

A source-compatible PAK reader should:

1. get the physical file size;
2. try `FPakInfo` trailer layouts from version 11 down to version 1;
3. accept only a candidate with `PakFile_Magic`;
4. validate serialized `Info.Version` against the candidate;
5. validate `IndexOffset` and `IndexSize` against file bounds;
6. retain encryption-key GUID, encrypted-index state, compression-method table and version;
7. read/decrypt the primary or legacy index as required;
8. verify its SHA1 before structural parsing;
9. for versions < 10, parse mount point + filename/`FPakEntry` pairs;
10. for versions >= 10, parse the primary index, compact-entry blob, non-encodable entries and secondary-index descriptors;
11. independently bounds-check/decrypt/hash-check any secondary index before parsing;
12. reconstruct filenames/entry locations from the applicable directory/path-hash index;
13. decode compact entries exactly when referenced;
14. treat `FPakEntry.Offset` as pointing to the payload-side entry header;
15. use version-aware compressed-block offset semantics;
16. when payload verification is requested, read the payload-side entry and validate its stored-byte SHA1.

## Validation requirements

A catalogue reader must at minimum reject or quarantine:

- no valid versioned trailer/magic;
- serialized version outside the source-supported range;
- trailer before byte zero;
- index offset outside file;
- index end outside file or integer overflow;
- failed index SHA1;
- negative/unsafe counts or sizes before allocation;
- truncated FString/TArray/TMap data;
- v10+ primary index with neither secondary lookup index;
- secondary-index offset/size outside file;
- failed secondary-index SHA1;
- compact-entry reference outside `EncodedPakEntries`;
- non-encodable entry reference outside `Files`;
- unsupported true frozen-index flag in version 9;
- invalid compression-method index;
- compressed-block ranges outside the applicable payload region;
- payload-side/index entry disagreement where verification is performed;
- stored payload SHA1 mismatch.

Safety bounds added by UnrealDB must remain implementation guards, not be misreported as Epic format limits.

## Runtime/configuration behavior that is not file format

The following UE4 behavior must not be mistaken for serialized format rules:

- whether runtime keeps the full directory index in memory;
- index pruning wildcards;
- validation/pruning command-line switches;
- whether a signed-reader wrapper is enabled;
- cache selection;
- thread-local reader management;
- pakchunk number inferred from the host PAK filename;
- whether non-PAK files are allowed by the platform layer.

These affect runtime behavior but do not change the bytes defined above.

## Conformance requirements

1. identify PAK from its versioned EOF trailer, not offset zero;
2. support trailer-size probing from version 11 through version 1;
3. require magic `0x5A6F12E1`;
4. preserve the serialized PAK version;
5. parse every version-dependent `FPakInfo` field in the source-defined order;
6. support the fixed compression-name table from version 8;
7. recognize and report unsupported frozen version-9 indexes without shifting fields;
8. distinguish legacy (<10) and new (>=10) index layouts;
9. validate every index blob's SHA1 before trusting it;
10. preserve mount point and relative filenames;
11. parse versioned `FPakEntry` exactly;
12. treat entry offset as the location of the payload-side entry header;
13. preserve the 20-byte payload SHA1;
14. support version-5 relative compressed-block offsets;
15. support compact `FPakEntry` encoding and `FPakEntryLocation`;
16. reconstruct delete records from invalid entry locations where applicable;
17. select legacy/corrected FNV64 path hashing by version 11;
18. never turn runtime pruning/cache policy into file-format requirements;
19. keep compression algorithm details in the PAK compression specification;
20. keep encryption/key/decryption details in the PAK encryption specification.

## Source-reference matrix

| Rule | Proving source/symbol |
|---|---|
| magic and versions | `IPlatformFilePak.h: FPakInfo` |
| trailer fields/order | `FPakInfo::Serialize` |
| trailer size by version | `FPakInfo::GetSerializedSize` |
| EOF/version probing | `IPlatformFilePak.cpp: FPakFile::Initialize` |
| trailer/index bounds | `FPakFile::Initialize` |
| compression-name table | `FPakInfo::Serialize, GetCompressionMethodIndex, GetCompressionMethod` |
| frozen-index compatibility | `FPakInfo::Serialize` |
| entry fields/version layout | `FPakEntry::Serialize, GetSerializedSize` |
| compressed block fields | `FPakCompressedBlock, operator<<` |
| entry flags | `FPakEntry::Flag_Encrypted, Flag_Deleted` |
| relative compressed offsets | `FPakInfo::HasRelativeCompressedChunkOffsets` |
| payload begins after entry header | `FPakReaderPolicy::OffsetToFile` |
| legacy index layout | `FPakFile::LoadLegacyIndex` |
| v10+ primary index layout | `FPakFile::LoadIndexInternal` |
| index SHA1 validation | `FPakFile::DecryptAndValidateIndex` |
| compact entry bit layout | `FPakFile::DecodePakEntry` |
| compact entry generation constraints | `FPakFile::EncodePakEntry` |
| entry location representation | `FPakEntryLocation` |
| path-hash algorithm/version split | `FPakFile::HashPath, LegacyMemFnv64` |
| directory/path-hash construction | `FPakFile::AddEntryToIndex` |
| delete-record reconstruction | `FPakFile::GetPakEntry` |
| payload/index duplicate metadata | `FPakFile::Check, ReadHashFromPayload` |
| stored payload hash semantics | `FPakFile::Check` |

## Result

UE4 PAK is a trailer-indexed container. Its identifying `FPakInfo` record lives at EOF and has version-dependent size, so readers must probe supported trailer generations rather than search for a fixed header at byte zero.

UE4 4.27.2 supports PAK format versions 1 through 11. Versions 1-9 use the legacy filename/`FPakEntry` index model; version 10 introduces the primary index with compact entry data and path-hash/full-directory secondary indexes; version 11 changes path hashing to the corrected FNV64 implementation.

Each physical file payload is preceded by a versioned `FPakEntry` header, while the index contains corresponding metadata or a compact reconstruction of it. Index regions are SHA1-protected and must be validated before parsing.

Compression and encryption fields are structurally part of the PAK format, but their algorithms and processing rules are documented separately so this specification remains the authoritative container/layout contract.
