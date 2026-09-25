# PAK Compression Handling

## Scope and authority

This specification documents compression of files stored inside Unreal Engine 4 PAK containers using the supplied Epic UE4 4.27.2 source.

Primary authority:

- repository: `ardenee/UnrealEngine4`
- branch: `UE4.27.2`
- `Engine/Source/Runtime/PakFile/Public/IPlatformFilePak.h`
- `Engine/Source/Runtime/PakFile/Private/IPlatformFilePak.cpp`
- `Engine/Source/Developer/PakFileUtilities/Private/PakFileUtilities.cpp`
- `Engine/Source/Runtime/Core/Public/Misc/Compression.h`
- `Engine/Source/Runtime/Core/Private/Misc/Compression.cpp`

This specification builds on `pak-format.md`. Encryption is deliberately separated into the next specification, but encryption-related alignment is noted where it changes how compressed block bytes are physically laid out.

## Compression is per PAK entry

PAK compression is not whole-container compression.

Each `FPakEntry` identifies its own compression method and, when compressed, contains a block map.

Relevant fields:

- `UncompressedSize`
- `Size`
- `CompressionMethodIndex`
- `CompressionBlocks`
- `CompressionBlockSize`
- `Flags`

`CompressionMethodIndex == 0` means uncompressed.

A nonzero compression method index means the entry is compressed and must be interpreted using the compression method table belonging to the enclosing `FPakInfo`.

Do not infer compression from filename extension, size ratio, entropy, or compressed-data signatures when valid entry metadata is available.

## Format history

Compression/encryption entry metadata was introduced with:

```text
PakFile_Version_CompressionEncryption = 3
```

Version 8 changed compression method representation:

```text
PakFile_Version_FNameBasedCompressionMethod = 8
```

Before version 8, the entry stores the deprecated integer compression flags.

From version 8 onward, the entry stores a compression-method index referring to the named method table in `FPakInfo`.

## Legacy compression methods: versions < 8

When loading an old PAK, `FPakInfo::Serialize` builds this in-memory method table:

| Index | Method |
|---:|---|
| 0 | None |
| 1 | Zlib |
| 2 | Gzip |
| 3 | Oodle |

The entry itself stores a legacy int32 value.

`FPakEntry::Serialize` maps it as follows:

- `COMPRESS_None` -> index 0
- any value containing `COMPRESS_ZLIB` -> index 1
- any value containing `COMPRESS_GZIP` -> index 2
- any value containing `COMPRESS_Custom` -> index 3
- otherwise the source treats it as an unknown compression type and fails

UE4 4.27.2 explicitly states that deprecated `COMPRESS_Custom` was used for Oodle and maps it to the name `Oodle`.

This is a legacy compatibility rule. It must not be generalized into "all custom compression is Oodle" outside the source-defined legacy PAK path.

## Named compression methods: version >= 8

Version 8 introduced named compression methods.

`FPakInfo` has:

- method index 0 permanently reserved for `None`;
- up to five serialized method-name slots;
- 32 ANSI bytes per slot.

The trailer therefore has 160 bytes reserved for compression names.

An entry stores `CompressionMethodIndex`, and the runtime resolves:

```text
CompressionMethod = PakInfo.GetCompressionMethod(Entry.CompressionMethodIndex)
```

The method name, not an assumed numeric codec constant, determines the decompressor.

A reader must reject an index outside the loaded method table rather than guessing a codec.

## Supported compression dispatch in UE4 4.27.2

`FCompression::UncompressMemory` has built-in paths for:

- `Zlib`
- `Gzip`
- `LZ4`

Other names are resolved through the engine's compression-format modular/plugin interface.

Therefore the PAK format is deliberately extensible. A valid PAK can name a compressor that a particular UnrealDB build does not implement.

That condition is **unsupported compression method**, not malformed PAK.

The 4.27.2 source also has Oodle support through the compression-format system when its module/plugin is available.

## No codec sniffing

For version >= 8, the trailer's compression method name is authoritative.

For legacy PAKs, the source-defined legacy compression field is authoritative.

UnrealDB must not:

- try Zlib, Gzip, LZ4, Oodle, etc. until one succeeds;
- use magic bytes to override a valid method field;
- silently reinterpret an unavailable named plugin codec as Zlib;
- treat failed decompression as evidence that another codec should be attempted.

A decompression failure using the identified codec is corruption, wrong encryption/key handling, unavailable/incompatible codec implementation, or another concrete processing failure.

## Compression blocks

A compressed `FPakEntry` contains:

```text
TArray<FPakCompressedBlock> CompressionBlocks
uint32 CompressionBlockSize
```

Each block is:

```text
int64 CompressedStart
int64 CompressedEnd
```

and its actual compressed byte count is:

```text
CompressedEnd - CompressedStart
```

The block descriptors do not store an uncompressed size per block.

The uncompressed block size is derived from the entry's `CompressionBlockSize`, `UncompressedSize`, and block number.

## Expected block count

The runtime checks:

```text
ceil(UncompressedSize / CompressionBlockSize)
    == CompressionBlocks.Num()
```

Equivalent integer form:

```text
(UncompressedSize + CompressionBlockSize - 1) / CompressionBlockSize
```

For a nonempty compressed entry:

- `CompressionBlockSize` must be nonzero;
- the block count must match this expression.

## Uncompressed size of each block

For block index `i`:

```text
logicalStart = i * CompressionBlockSize
uncompressedBlockSize =
    min(UncompressedSize - logicalStart, CompressionBlockSize)
```

All blocks except possibly the final block therefore produce exactly `CompressionBlockSize` bytes.

The final block produces the remaining bytes.

The async reader uses the same rule, with a full `CompressionBlockSize` result when the remainder is zero.

## Physical block offsets

PAK version 5 introduced relative compressed chunk offsets.

For version >= 5:

```text
physicalStart = Entry.Offset + Block.CompressedStart
physicalEnd   = Entry.Offset + Block.CompressedEnd
```

For older versions:

```text
physicalStart = Block.CompressedStart
physicalEnd   = Block.CompressedEnd
```

This is controlled solely by:

```text
FPakInfo::HasRelativeCompressedChunkOffsets()
```

Do not infer relative versus absolute addressing from the values themselves.

## Entry header and first compressed block

`Entry.Offset` points to the serialized payload-side `FPakEntry` header.

The compressed bytes follow that header.

When UnrealPak creates a compressed file, `FinalizeCopyCompressedFileToPak` converts compressor-relative block positions into entry-relative positions by adding:

```text
Entry.GetSerializedSize(PakVersion)
```

Consequently the first block normally begins after the entry header, not at `Entry.Offset` itself.

This distinction is essential for version >= 5 because block positions are relative to the entry offset.

## Compression block size written by UnrealPak

UE4 4.27.2 UnrealPak command parameters default:

```text
CompressionBlockSize = 64 * 1024
```

The command line can override it with:

```text
-compressionblocksize=
```

including KB/MB suffix handling.

Therefore 64 KiB is a **producer default**, not a PAK format constant.

A reader must use the serialized `FPakEntry.CompressionBlockSize`.

Do not reject otherwise valid entries merely because their block size differs from 64 KiB.

## UnrealPak compression process

`FCompressedFileBuffer::CompressFileToWorkingBuffer`:

1. reads the source file;
2. divides it into blocks of at most the requested compression block size;
3. compresses each block independently using the selected format;
4. records each compressed block's start and end;
5. concatenates the compressed block bytes;
6. records the largest source block as `FileCompressionBlockSize`;
7. later writes that value to `FPakEntry.CompressionBlockSize`;
8. writes total compressed stored bytes to `FPakEntry.Size`;
9. writes original source size to `FPakEntry.UncompressedSize`.

Blocks are independently decompressible.

There is no single continuous codec stream that must be fed all blocks together.

## Compression can increase a block's size

The runtime explicitly permits:

```text
CompressedBlockSize > UncompressedBlockSize
```

and merely logs it at verbose level.

Therefore UnrealDB must **not** enforce:

```text
compressed block size <= uncompressed block size
```

as a format rule.

UnrealPak normally applies producer heuristics to avoid storing poorly compressed files, but forced compression and codec behavior can produce larger compressed blocks.

## Producer heuristics are not format validation

UE4 4.27.2 UnrealPak commonly avoids compression when it is not worthwhile.

Examples from this source:

- files smaller than 1024 bytes are normally not attempted;
- Zlib must normally save at least 1024 bytes;
- Zlib may be rejected when its ratio is worse than 90% and it saves less than 65536 bytes;
- modern compressor decisions can be accepted differently;
- `-ForceCompress` can retain otherwise poor compression.

These are **creation heuristics**, not serialized-format restrictions.

A reader must not reject a compressed entry because:

- it is smaller than 1024 bytes;
- compression saves less than 1 KiB;
- compression ratio is poor;
- compressed size is equal to or greater than uncompressed size.

## Compression method selection is producer policy

UnrealPak's command-line/configuration path chooses which compressor to use. It can:

- accept `-compressionformats=` / `-compressionformat=`;
- fall back to Zlib unless disabled;
- force Zlib for files that must be readable before compression plugins load;
- disable compression for some memory-mapped bulk files.

These are packaging policies, not reader inference rules.

The reader consumes the method that was actually serialized.

## Reading compressed entries

The synchronous compressed reader:

1. resolves the method name from `CompressionMethodIndex`;
2. determines the first requested compression block;
3. reads the physical compressed block bytes;
4. decrypts the block first when the entry is encrypted;
5. calls `FCompression::UncompressMemory` with:
   - the resolved compression format;
   - exact expected uncompressed block size;
   - exact compressed block size;
6. copies the requested logical range from the decompressed block;
7. advances to the next block as necessary.

The async path follows the same logical model.

Thus the correct order for a compressed+encrypted block is:

```text
read -> decrypt -> decompress
```

The encryption mechanics are documented separately.

## Decompression output size

The caller knows the required uncompressed size for each block before decompression.

The decompressor is called with that exact destination size.

UnrealDB should likewise require the codec operation to produce the expected logical block data. It must not append an arbitrary amount of decompressed data and hope that the final file length matches.

## Logical file size

The logical file exposed to callers has size:

```text
FPakEntry.UncompressedSize
```

The physical stored compressed byte count is:

```text
FPakEntry.Size
```

These values are not interchangeable.

For uncompressed entries, `Size == UncompressedSize`.

For compressed entries, no format rule requires `Size < UncompressedSize`.

## Compression block physical size and encryption padding

Without encryption, a block's physical compressed byte count is exactly:

```text
CompressedEnd - CompressedStart
```

When compression and encryption are combined, UnrealPak pads each compressed block stream position to `FAES::AESBlockSize` before the next block.

The block descriptor's `CompressedEnd` identifies the end of the actual compressed data, while the next block may start after AES alignment padding.

The reader therefore:

- uses `CompressedEnd - CompressedStart` as the codec's compressed input size;
- may read an AES-aligned number of physical bytes for decryption;
- does not pass the alignment padding to the decompressor as compressed data.

This distinction is required even in the compression reader because otherwise encrypted compressed entries are decoded incorrectly.

## Compact index representation

Version 10+ can compactly encode `FPakEntry`.

The compact header contains:

- a 6-bit `CompressionMethodIndex`;
- a 16-bit compression block count;
- a compressed representation of `CompressionBlockSize`.

If the low six block-size bits are `0x3f`, an explicit uint32 block size follows.

Otherwise the decoder reconstructs:

```text
CompressionBlockSize = low6bits << 11
```

When there is exactly one compression block and the entry is not encrypted, the compact representation omits explicit block lengths and reconstructs the single block from entry header size and `Entry.Size`.

For multiple blocks or encrypted blocks, compressed block byte lengths are serialized and block starts/ends are reconstructed sequentially, including AES alignment where applicable.

These compact-index rules do not change the decompression algorithm; they only reconstruct the ordinary `FPakEntry` compression metadata.

## Compression method index width

The ordinary `FPakEntry.CompressionMethodIndex` is uint32.

The version-10 compact representation allocates six bits for it.

The PAK trailer itself permits only five serialized method names plus implicit None in this source, so normal UE4 4.27.2-generated PAKs remain well within the compact field.

UnrealDB should nevertheless validate all indexes before lookup and must not access a method-table element outside the table.

## Zlib

For method `Zlib`, UE4 routes decompression through:

```text
appUncompressMemoryZLIB
```

via `FCompression::UncompressMemory`.

The PAK block contains the codec output produced by UE's Zlib compression path; there is no additional PAK-specific inner framing around each compressed block.

The block boundaries and expected output size come from `FPakEntry`, not from a PAK compression header inside the block.

## Gzip

For method `Gzip`, UE4 routes through:

```text
appUncompressMemoryGZIP
```

Again, PAK itself adds no second compression header around the codec bytes beyond the ordinary Gzip stream generated by the compression implementation.

## LZ4

UE4 4.27.2 has a built-in `LZ4` compression dispatch path.

Decompression calls:

```text
LZ4_decompress_safe
```

with the entry-derived compressed and expected uncompressed block sizes.

Whether UnrealPak in a particular build/configuration chooses LZ4 is producer policy. If a PAK's named method table says LZ4, the generic compression layer defines how to decompress it.

## Oodle and plugin compression

Oodle is not encoded as a special PAK structural variant.

For named-method PAKs it is identified by its compression format name and routed through `ICompressionFormat`.

Other plugin compression names follow the same mechanism.

Therefore UnrealDB should architect PAK decompression as:

```text
serialized method name -> codec implementation
```

rather than hard-coding the entire PAK reader around one or two codecs.

If a named codec is unsupported locally, metadata/index parsing can still succeed; extraction of that entry must report the unsupported codec.

## Entry SHA1 and compression

The payload-side `FPakEntry.Hash` validates the stored bytes, not the reconstructed uncompressed file.

For compressed files, UnrealPak hashes the final compressed buffer that it writes.

If encryption is enabled, the source encrypts the compressed buffer before calculating that hash, so the hash covers the final stored encrypted bytes.

Accordingly:

- stored-byte integrity validation occurs against physical payload bytes;
- decompression correctness is a separate operation;
- do not compare `FPakEntry.Hash` with SHA1 of the decompressed output.

## Corruption checks

A robust source-compatible reader should reject or quarantine a compressed entry when any of these are true:

- compression method index is zero but compressed-block metadata is being required as compressed content;
- compression method index is outside the enclosing PAK method table;
- named compression format is empty/invalid where a nonzero method index is used;
- `CompressionBlockSize == 0` for a nonempty compressed file;
- block count does not equal `ceil(UncompressedSize / CompressionBlockSize)`;
- any `CompressedEnd < CompressedStart`;
- any resolved block range overflows integer arithmetic;
- any resolved block range lies outside the physical PAK;
- compact entry data is truncated;
- a compact block-length reconstruction overflows;
- decompression fails with the identified codec;
- decompressed output does not satisfy the expected logical block size;
- payload SHA1 fails when stored-byte verification is requested.

Do **not** reject solely because a compressed block is larger than its uncompressed counterpart.

## Zero-length files

An empty file has no useful compression work.

The runtime compression-reader setup only expects compression blocks when a non-None method is paired with nonzero `UncompressedSize`.

UnrealPak's producer path naturally leaves tiny/empty files uncompressed.

A catalogue reader should not invent a compressed zero-length representation as a requirement.

## Partial reads

PAK compression is designed for random logical reads.

Given logical offset `P`:

```text
blockIndex = P / CompressionBlockSize
offsetInBlock = P % CompressionBlockSize
```

Only the required block(s) need to be read and decompressed.

The runtime caches/decompresses blocks independently and can copy only the requested range.

UnrealDB does not need to decompress the entire contained file merely to serve a partial read, provided its API supports range extraction.

## Validation versus implementation safety limits

The source uses int32 sizes at the individual compression API boundary and int64 for PAK/file sizes.

UnrealPak explicitly notes that `CompressMemoryBound` truncates its size argument to 32 bits and therefore performs calculations per compression block rather than passing a potentially >32-bit whole file.

This is evidence for blockwise processing, **not** an Epic-defined maximum PAK entry/file size.

Any UnrealDB memory/allocation limits must be reported as implementation limits, not "invalid PAK format".

## UnrealDB implementation contract

1. determine compression solely from the versioned `FPakEntry`;
2. treat method index 0 as None;
3. use legacy compression-field mapping only for PAK versions < 8;
4. use the `FPakInfo` named method table for version >= 8;
5. validate method indexes before access;
6. support Zlib and Gzip exactly through their identified methods;
7. support LZ4 when named LZ4;
8. route Oodle/other names only to matching implementations, never guessed substitutes;
9. distinguish unsupported codec from corrupt PAK;
10. use serialized `CompressionBlockSize`, never assume 64 KiB;
11. validate block count against `UncompressedSize`;
12. derive each block's expected uncompressed output size from its index;
13. use version-aware relative/absolute compressed block offsets;
14. account for the payload-side `FPakEntry` header before the first data block;
15. use `CompressedEnd - CompressedStart` as actual codec input size;
16. permit compressed blocks larger than their uncompressed result;
17. when encrypted, read/decrypt AES-aligned bytes but pass only actual compressed bytes to the codec;
18. process compressed+encrypted data as decrypt then decompress;
19. expose logical file size as `UncompressedSize`;
20. treat `Size` as stored payload size;
21. validate stored-byte SHA1 separately from decompressed data;
22. never apply UnrealPak's compression-worthwhile heuristics as reader validity rules;
23. do not impose the 1024-byte producer threshold as a format rule;
24. do not impose 64 KiB as a format rule;
25. do not codec-sniff when metadata identifies the codec;
26. retain source-proven compression metadata even when extraction codec support is unavailable.

## Source-reference matrix

| Rule | Source/symbol |
|---|---|
| version 3 compression metadata | `FPakInfo::PakFile_Version_CompressionEncryption` |
| version 8 named methods | `PakFile_Version_FNameBasedCompressionMethod` |
| method table | `FPakInfo::Serialize, GetCompressionMethodIndex, GetCompressionMethod` |
| legacy method mapping | `FPakEntry::Serialize` |
| compressed block fields | `FPakCompressedBlock` |
| entry compression metadata | `FPakEntry` |
| relative block offsets | `FPakInfo::HasRelativeCompressedChunkOffsets` |
| runtime block-count check | `FPakAsyncReadFileHandle` constructor |
| block uncompressed size | `FPakCompressedReaderPolicy::Serialize`, async reader |
| codec dispatch | `FCompression::UncompressMemory` |
| legacy Custom -> Oodle | `FCompression::GetCompressionFormatFromDeprecatedFlags` |
| compression producer | `FCompressedFileBuffer::CompressFileToWorkingBuffer` |
| default 64 KiB producer block | `FPakCommandLineParameters` |
| block-size command option | `ProcessCommandLine` |
| poor-compression acceptance/rejection | `CreatePakFile::FAsyncCompressor::Compress` |
| compressed-entry construction | `PrepareCopyCompressedFileToPak` |
| block position finalization | `FinalizeCopyCompressedFileToPak` |
| encrypted compressed padding | `CompressFileToWorkingBuffer` |
| compact block reconstruction | `FPakFile::DecodePakEntry` |
| stored payload hash | `PrepareCopyCompressedFileToPak, FPakFile::Check` |

## Result

UE4 PAK compression is a per-entry, block-based system. The PAK entry provides the logical file size, stored size, compression method, block size, and compressed block ranges. Each block is decompressed independently to a size derived from its logical block position.

Versions before 8 identify codecs through legacy compression flags. Version 8 and later identify codecs by names stored in the PAK trailer. UE4 4.27.2 has built-in Zlib, Gzip and LZ4 dispatch and supports named plugin codecs such as Oodle.

The serialized block size is authoritative; UnrealPak's 64 KiB default and its compression-ratio/tiny-file heuristics are producer choices, not validity rules. A correct reader must also permit compressed data to be larger than its uncompressed result and must distinguish stored-byte SHA1 verification from decompression correctness.

For compressed+encrypted entries, physical AES padding is not part of the compressed codec stream: read/decrypt the aligned bytes, then pass only the descriptor-defined compressed byte count to the identified decompressor.
