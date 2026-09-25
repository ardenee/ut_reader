# UPK Compression Handling

## Scope and authority

This specification documents **UE3/UPK package compression handling** from the supplied Epic UE3 source. It is the compression companion to `specs/upk-format.md`.

Primary authority:

- Repository: `ardenee/UE3src`
- Latest inspected UE3 tree: `Unreal Engine [v3.0] UDKUltimate [05-11-17]/UDKUltimate`
- Core source: `Development/Src/Core`

The package format and compression implementation are treated as one UE3 revision. Behavior is not borrowed from UE2, UE2.5, UE4, or another game merely because a similarly named flag or helper exists there.

The supplied `ardenee/UT3src-comunity` tree does not contain the native Core compression/linker implementation required to supersede this UE3 source. It is therefore not used to invent a UT3-specific binary compression variant.

UE4 4.27.2 retains legacy compression APIs and historical chunk-size compatibility, but UE4 is **not** used to redefine the UE3 UPK format. Its later compression API evolution belongs to the UE4 package/PAK specifications.

## Authoritative references

| Area | UE3 source |
|---|---|
| compression flags, masks, chunk sizes | `Core/Inc/UnFile.h: ECompressionFlags` |
| archive compression interface | `Core/Inc/UnArc.h: FArchive::SerializeCompressed`, `SetCompressionMap`, `FCompressedChunkInfo` |
| codec implementations/dispatch | `Core/Src/UnMisc.cpp: appCompressMemory*`, `appUncompressMemory*` |
| package chunk descriptors | `Core/Inc/UnLinker.h: FCompressedChunk` |
| package compression activation | `Core/Src/UnLinker.cpp: ULinkerLoad::SerializePackageFileSummary` |
| compressed logical-file mapping | `Core/Src/UnAsyncLoading.cpp: FArchiveAsync::SetCompressionMap` |
| compressed read/framing | `Core/Src/UnAsyncLoading.cpp: FAsyncIOSystemBase::FulfillCompressedRead` |
| async compressed package reads | `Core/Src/UnAsyncLoading.cpp: FArchiveAsync::PrecacheCompressedChunk`, `LoadCompressedData` |

## Two compression layers must not be confused

A compressed UE3 package has two related structures.

### 1. Package-level compression map

The package summary contains:

- `CompressionFlags`
- `TArray<FCompressedChunk> CompressedChunks`

Each `FCompressedChunk` contains:

1. `UncompressedOffset`
2. `UncompressedSize`
3. `CompressedOffset`
4. `CompressedSize`

These values map a logical region of the **uncompressed package address space** to a physical compressed region in the file.

### 2. Framing inside each compressed region

The bytes at each package `CompressedOffset` are not merely one raw zlib/LZO/LZX stream.

`FArchive::SerializeCompressed` writes a framed compressed stream, and `FAsyncIOSystemBase::FulfillCompressedRead` reads that framing before dispatching individual compressed blocks to the selected codec.

A conforming UPK reader therefore must:

1. read the package summary compression map;
2. find the package `FCompressedChunk` covering the requested logical offset;
3. seek to that chunk's physical `CompressedOffset`;
4. parse the inner `SerializeCompressed` framing;
5. decompress its subchunks with the package `CompressionFlags`;
6. expose the result at the package chunk's `UncompressedOffset`.

Treating `FCompressedChunk.CompressedSize` bytes as one raw codec stream is not source-compatible.

## Compression flag values

The latest supplied UE3 source defines:

| Flag | Value | Meaning |
|---|---:|---|
| `COMPRESS_None` | `0x00` | no compression |
| `COMPRESS_ZLIB` | `0x01` | zlib |
| `COMPRESS_LZO` | `0x02` | LZO |
| `COMPRESS_LZX` | `0x04` | LZX |
| `COMPRESS_BiasMemory` | `0x10` | compression-time preference for smaller output |
| `COMPRESS_BiasSpeed` | `0x20` | compression-time preference for speed |
| `COMPRESS_ForcePPUDecompressZLib` | `0x80` | PS3 zlib decompression routing option |

Masks:

- `COMPRESSION_FLAGS_TYPE_MASK = 0x0F`
- `COMPRESSION_FLAGS_OPTIONS_MASK = 0xF0`

Codec dispatch uses `Flags & COMPRESSION_FLAGS_TYPE_MASK`.

The option bits are not additional codecs.

## Platform defaults are not file identification

The source defines platform defaults:

- Xbox 360: LZX
- PS3: zlib
- PC: LZO when `WITH_LZO` is compiled, otherwise zlib
- `GBaseCompressionMethod = COMPRESS_Default`

These defaults influence saving/cooking and some runtime operations. They do **not** authorize a reader to guess a package codec from the platform or filename.

For a package compression map, use the serialized `CompressionFlags`.

## Codec dispatch

`appCompressMemory` and `appUncompressMemory` require one of the codec type bits and switch on `Flags & COMPRESSION_FLAGS_TYPE_MASK`.

Dispatch is:

- `COMPRESS_ZLIB` -> zlib implementation;
- `COMPRESS_LZO` -> LZO implementation only when `WITH_LZO` is compiled;
- `COMPRESS_LZX` -> Xbox/XDK LZX implementation only when `WITH_XDK` is compiled.

If the selected implementation is not compiled into that engine build, the native function reports the compression type as unsupported.

This compile-time availability is an engine-build limitation, not evidence that the serialized codec value is invalid.

UnrealDB, as an offline reader, should support every source-defined serialized codec it claims to accept rather than reproducing a particular Epic build's missing optional library.

## ZLIB semantics

The UE3 implementation uses zlib's ordinary `compress` and `uncompress` APIs.

On decompression the engine supplies the expected uncompressed size and checks that zlib produced exactly that amount.

Therefore:

- the inner block is a zlib stream produced by `compress`;
- expected uncompressed size comes from `FCompressedChunkInfo.UncompressedSize`;
- successful inflation to a different size is not source-equivalent success.

## LZO semantics

When `WITH_LZO` is available, the supplied source uses the LZO1X family.

Compression variants are selected only while **compressing**:

- normal: `lzopro_lzo1x_1_14_compress`;
- `COMPRESS_BiasSpeed`: `lzopro_lzo1x_1_08_compress`;
- `COMPRESS_BiasMemory`: `lzopro_lzo1x_99_compress`.

All are deliberately kept in the same LZO1X family so the corresponding LZO1X decompressor can read them.

On Windows the source selects `lzopro_lzo1x_decompress_safe`; other builds shown use `lzopro_lzo1x_decompress`.

The bias bits therefore do not change the on-disk codec identity from LZO to another algorithm.

## LZX semantics

When `WITH_XDK` is available, UE3 uses the Xbox XMem LZX codec:

- compression context: `XMEMCODEC_LZX`;
- decompression context: `XMEMCODEC_LZX`.

The source reserves **four padding bytes** at the end of compressed LZX output because the XMem LZX decompressor may read past the logical compressed data.

Saving:

- compressor capacity is reduced by 4;
- after compression, reported `CompressedSize` is increased by 4.

Loading:

- the serialized/reported compressed size includes those four bytes;
- the native LZX decompressor subtracts 4 before calling `XMemDecompress`.

A compatible non-XDK implementation must preserve this serialized size convention. It must not feed the four padding bytes to an LZX decoder as if they were compressed payload.

The engine also verifies that LZX produced exactly the expected uncompressed byte count.

## Generic compression chunk sizes

The source defines:

- `LOADING_COMPRESSION_CHUNK_SIZE_PRE_369 = 32768`
- `LOADING_COMPRESSION_CHUNK_SIZE = 131072`
- `SAVING_COMPRESSION_CHUNK_SIZE = LOADING_COMPRESSION_CHUNK_SIZE`
- `MaxUncompressedSize = 256 * 1024`

The 32 KiB constant is a historical compatibility value. The current serialized framing carries the compression subchunk size in its header, so a reader must read that value rather than blindly assume 128 KiB.

Do not turn `MaxUncompressedSize` into a package-size or export-size limit. It is the source helper's maximum buffer size for one memory compression operation.

## FCompressedChunkInfo

The inner compression framing uses:

`FCompressedChunkInfo`

with two signed 32-bit integer fields in declaration/serialization order:

1. `CompressedSize`
2. `UncompressedSize`

The first entry in the chunk-info table is a **summary entry**. It is not compressed payload chunk 0.

Payload subchunks begin at table index 1.

## Inner compressed-stream layout

For the UE3 framing consumed by `FulfillCompressedRead`, the physical bytes are:

```text
int32 PackageTagOrSwappedTag
int32 CompressionChunkSize

FCompressedChunkInfo Summary
FCompressedChunkInfo Chunk[0]
FCompressedChunkInfo Chunk[1]
...
FCompressedChunkInfo Chunk[N-1]

byte CompressedPayloadForChunk0[Chunk[0].CompressedSize]
byte CompressedPayloadForChunk1[Chunk[1].CompressedSize]
...
```

where each `FCompressedChunkInfo` is:

```text
int32 CompressedSize
int32 UncompressedSize
```

and:

`N = ceil(ExpectedUncompressedSize / CompressionChunkSize)`

The in-memory table count used by the engine is therefore:

`TotalChunkCount = N + 1`

because entry 0 is the summary.

## Header magic and byte swapping

The first inner 32-bit integer must be:

- `PACKAGE_FILE_TAG = 0x9E2A83C1`, or
- `PACKAGE_FILE_TAG_SWAPPED = 0xC1832A9E`.

Anything else is treated by the source as compressed-data corruption.

When the swapped tag is present:

- the serialized compression chunk size is byte-swapped;
- every `FCompressedChunkInfo.CompressedSize` is byte-swapped;
- every `FCompressedChunkInfo.UncompressedSize` is byte-swapped.

The compressed codec payload bytes themselves are then supplied to the codec; they are not integer-byte-swapped by this table logic.

## Historical header compatibility

After reading the second header integer as `CompressionChunkSize`, the loader contains this compatibility rule:

- if `CompressionChunkSize == PACKAGE_FILE_TAG`, use `LOADING_COMPRESSION_CHUNK_SIZE`.

The source comment identifies this as handling old packages that do not store the chunk size in the header.

A reader must reproduce the source branch. It must not reject the repeated package tag as an impossible chunk size.

The separate `LOADING_COMPRESSION_CHUNK_SIZE_PRE_369 = 32768` constant exists in the same compression contract and records the older historical chunk-size era. It must not be projected onto every old file without the corresponding source path proving that branch.

## Summary entry semantics

`CompressionChunks[0]` summarizes the entire framed compressed region.

The loader verifies:

`CompressionChunks[0].UncompressedSize == sum(payloadChunk[i].UncompressedSize)`

The saving path accumulates compressed payload sizes into the summary's `CompressedSize`.

The summary entry is metadata only; decompression starts with entry 1.

## Payload subchunk rules

For each payload entry:

- `CompressedSize` is the exact number of physical bytes to read for that subchunk;
- `UncompressedSize` is the exact output size expected from the codec;
- every non-final payload chunk must have the configured full `CompressionChunkSize`;
- only the final payload chunk may be shorter;
- no payload chunk may have `UncompressedSize > CompressionChunkSize`.

The loader computes the largest compressed subchunk and allocates buffers accordingly.

## Framed-region validation

`FulfillCompressedRead` performs source-defined consistency checks before/during decompression.

A conforming reader should equivalently reject:

1. inner header tag is neither normal nor swapped package tag;
2. summary uncompressed size differs from the sum of payload uncompressed sizes;
3. framed header + chunk-info table + summary compressed payload size exceeds the outer compressed region size;
4. requested outer `FCompressedChunk.UncompressedSize` differs from the calculated inner uncompressed total;
5. a non-final inner chunk is short;
6. an inner chunk's uncompressed size exceeds the declared compression chunk size;
7. codec decompression fails;
8. a codec that verifies output size does not produce the expected bytes.

Bounds arithmetic should be performed safely; integer overflow must not be allowed to turn an invalid file into an in-bounds read.

## Relationship between outer and inner sizes

For an outer package `FCompressedChunk C`:

- `C.CompressedOffset` points to the beginning of the inner compression header;
- `C.CompressedSize` is the physical framed-region size passed to `LoadCompressedData`;
- `C.UncompressedSize` is the expected logical output size;
- `C.UncompressedOffset` is where that output belongs in the logical package address space.

The inner summary's uncompressed total must agree with `C.UncompressedSize`.

The outer compressed size includes framing overhead; it is not merely the sum of raw codec bytes.

## Package compression activation

The UPK summary's compression data is acted on when the package is marked for stored compression.

The linker passes:

- the summary `CompressedChunks` array;
- the summary `CompressionFlags`;

to `SetCompressionMap`.

If the original loader archive does not support a compression map, the UE3 linker creates an `FArchiveAsync`, preserves its position and byte-swapping state, and installs the map there.

This means all subsequent seeks/reads continue to use **logical uncompressed package offsets**.

Code parsing names, imports, exports, dependency data, or export payloads must not manually reinterpret those logical offsets as physical compressed-file offsets.

## Logical offset lookup

`FArchiveAsync::FindCompressedChunkIndex` selects an outer package chunk when:

`Chunk.UncompressedOffset <= RequestOffset < Chunk.UncompressedOffset + Chunk.UncompressedSize`

The archive then precaches/decompresses that compressed chunk and serves reads from its uncompressed buffer.

The archive's logical total size is updated to:

`lastChunk.UncompressedOffset + lastChunk.UncompressedSize`

after the compression map is installed.

## Reads crossing package-compression boundaries

The source archive precaches compressed chunks into uncompressed buffers and presents a logical file abstraction.

A metadata reader should preserve that abstraction. Parser code should be able to request bytes by logical package offset without knowing whether the backing range was physically compressed.

Do not bake package-compression handling independently into the name/import/export readers.

## PS3 decompression detail

For zlib on PS3, the source may route decompression to the SPU implementation unless `COMPRESS_ForcePPUDecompressZLib` is set.

The async compressed-read path may add 128 bytes of source padding on PS3 so decompression code can safely over-read/cache-line/DMA access.

These are runtime/platform implementation details. They do not add bytes to the serialized UPK compression format beyond the compressed sizes already recorded.

An offline reader does not need to emulate SPU scheduling, but it must not reinterpret the flag as a different codec.

## Compression options versus decompression

`COMPRESS_BiasMemory` and `COMPRESS_BiasSpeed` control compression choices. The decompression dispatch is determined by the codec type mask.

A reader should therefore:

- retain/report the raw flags;
- choose the codec from the low type bits;
- not require the same encoder bias behavior to decompress existing data.

## Unsupported or contradictory flag combinations

The engine asserts that at least one of ZLIB/LZO/LZX is present and dispatches on the masked type value.

The source does not establish a valid fourth combined codec made by OR-ing multiple codec type bits. A robust reader should accept only source-defined codec selector values after masking:

- `0x01`
- `0x02`
- `0x04`

rather than invent semantics for `0x03`, `0x05`, `0x06`, or `0x07`.

Preserve the original raw flags for diagnostics.

## Compression is not encryption

None of:

- `CompressionFlags`;
- outer `FCompressedChunk`;
- inner `FCompressedChunkInfo`;
- zlib/LZO/LZX dispatch;

defines encryption.

Do not XOR, decrypt, strip keys, or otherwise transform compressed bytes unless a separate game/platform source proves an encryption layer.

## Cross-revision findings

The UE3 source line contains explicit backward-compatibility in the compression reader rather than one timeless assumed layout:

- a historical compression chunk-size era is represented by `LOADING_COMPRESSION_CHUNK_SIZE_PRE_369 = 32768`;
- the normal loading/saving compression chunk size is 131072;
- old framed data that lacks an explicit chunk-size value is detected by the repeated package-tag compatibility case;
- byte-swapped compressed framing is explicitly supported;
- codec availability is build/platform dependent while serialized codec identity remains in `CompressionFlags`.

These branches must remain data-driven. Do not identify one UE3 revision by applying another engine's constants.

UE4's later source retains legacy `SerializeCompressed` compatibility and the same historical 32 KiB/128 KiB chunk-size constants, but later UE4 adds newer compression APIs/formats. Those later additions are not valid evidence for adding codecs or fields to UE3 UPK.

## UnrealDB reader algorithm

For a compressed UE3 package:

1. parse the UE3 summary normally;
2. retain raw `PackageFlags`, `CompressionFlags`, and all outer `FCompressedChunk` records;
3. validate the compression codec selector from `CompressionFlags & 0x0F`;
4. install a logical compression map;
5. for a logical read, locate the covering outer chunk;
6. bounds-check its physical `CompressedOffset` and `CompressedSize`;
7. read the inner two-int header;
8. validate normal/swapped package tag;
9. derive `CompressionChunkSize`, including the repeated-tag compatibility branch;
10. calculate the expected payload-subchunk count from the outer uncompressed size;
11. read the summary + payload `FCompressedChunkInfo` entries;
12. byte-swap table integers when required;
13. validate summary totals, region bounds, per-chunk sizes, and final-chunk rules;
14. read each compressed payload using its exact `CompressedSize`;
15. dispatch zlib, LZO, or LZX from the serialized codec selector;
16. for LZX, account for the source-defined four-byte serialized padding convention;
17. require the exact expected uncompressed size;
18. concatenate inner outputs;
19. require the total to equal the outer `FCompressedChunk.UncompressedSize`;
20. expose those bytes at the outer `UncompressedOffset`;
21. let all higher package parsing continue in logical uncompressed offsets.

## Conformance requirements

1. preserve the outer `FCompressedChunk` map exactly;
2. preserve raw `CompressionFlags`;
3. select only source-defined UE3 codecs from the type mask;
4. support zlib;
5. support LZO1X for serialized LZO packages;
6. support the UE3/XMem LZX bitstream semantics needed for serialized LZX packages;
7. implement LZX's four-byte serialized padding-size convention;
8. parse the inner compression header and chunk-info table;
9. never treat an outer compressed chunk as one raw codec stream;
10. support normal and swapped inner package tags;
11. reproduce the repeated-package-tag old-header compatibility branch;
12. use the serialized compression subchunk size;
13. validate the inner summary against payload entries;
14. validate the inner total against the outer uncompressed size;
15. allow only the final inner payload chunk to be shorter than the configured chunk size;
16. keep all package table/export offsets in the logical uncompressed address space;
17. do not infer codec from extension, platform, or current engine default;
18. do not convert helper buffer limits into arbitrary file-format limits;
19. do not invent semantics for combined codec selector bits;
20. do not conflate compression with encryption.

## Source-reference matrix

| Rule | Proving source/symbol |
|---|---|
| codec flag values/masks | `Core/Inc/UnFile.h: ECompressionFlags`, compression masks |
| 32 KiB/128 KiB chunk constants | `Core/Inc/UnFile.h` |
| codec dispatch | `Core/Src/UnMisc.cpp: appCompressMemory`, `appUncompressMemory` |
| zlib stream behavior | `appCompressMemoryZLIB`, `appUncompressMemoryZLIB` |
| LZO1X variants | `appCompressMemoryLZO`, `appUncompressMemoryLZO` |
| LZX XMem + four-byte padding | `appCompressMemoryLZX`, `appUncompressMemoryLZX` |
| archive compression contract | `Core/Inc/UnArc.h: SerializeCompressed`, `FCompressedChunkInfo` |
| outer package chunk layout | `Core/Inc/UnLinker.h: FCompressedChunk` |
| package compression map installation | `Core/Src/UnLinker.cpp: SerializePackageFileSummary` |
| logical/physical map | `Core/Src/UnAsyncLoading.cpp: FArchiveAsync::SetCompressionMap` |
| outer chunk lookup | `FArchiveAsync::FindCompressedChunkIndex` |
| compressed read dispatch | `FArchiveAsync::PrecacheCompressedChunk`, `FAsyncIOSystemBase::LoadCompressedData` |
| inner framing/header/table | `FAsyncIOSystemBase::FulfillCompressedRead` |
| swapped framing | `FAsyncIOSystemBase::FulfillCompressedRead` |
| old repeated-tag compatibility | `FAsyncIOSystemBase::FulfillCompressedRead` |
| summary/size validation | `FAsyncIOSystemBase::FulfillCompressedRead` |

## Result

UE3 UPK compression is a **mapped logical-file compression system**. The package summary identifies compressed logical ranges with `FCompressedChunk`; each physical range contains a second, source-defined `SerializeCompressed` framing layer with a package tag, compression subchunk size, summary entry, per-subchunk size table, and codec payloads.

The serialized codec selector supports zlib, LZO, and LZX. LZX has a source-defined four-byte padding convention. Old and byte-swapped compressed framing have explicit compatibility paths.

A correct UPK reader must reproduce those layers and validations before parsing package structures through logical uncompressed offsets.


## Fully-compressed package handling (`PKG_StoreFullyCompressed`)

## Scope and authority

This specification documents what the supplied UE3 source actually establishes for the package flag:

`PKG_StoreFullyCompressed = 0x04000000`

Primary authority:

- Repository: `ardenee/UE3src`
- Latest inspected UE3 tree: `Unreal Engine [v3.0] UDKUltimate [05-11-17]/UDKUltimate`
- Core source: `Development/Src/Core`

This specification is deliberately separate from `specs/upk-compression-handling.md`.

The source explicitly distinguishes:

- `PKG_StoreCompressed`: package compression supported transparently through the package's `CompressionFlags` and `CompressedChunks` map;
- `PKG_StoreFullyCompressed`: the package is serialized normally and then fully compressed, and **must be decompressed before `LoadPackage` is called**.

The distinction is part of the format contract and must not be collapsed into one reader path.

## Authoritative references

| Area | Source |
|---|---|
| package flag definition | `Core/Inc/UnObjBas.h: EPackageFlags` |
| generic compressed-stream framing | `Core/Inc/UnArc.h: FCompressedChunkInfo, SerializeCompressed` |
| compressed-stream implementation | `Core/Src/UnArchive.cpp: FArchive::SerializeCompressed` |
| streaming compressed proxy | `Core/Src/UnArchive.cpp: FArchiveSaveCompressedProxy, FArchiveLoadCompressedProxy` |
| compression flags/codecs | `Core/Inc/UnFile.h: ECompressionFlags` |
| codec implementations | `Core/Src/UnMisc.cpp: appCompressMemory, appUncompressMemory` |
| normal package loader | `Core/Src/UnLinker.cpp: ULinkerLoad::SerializePackageFileSummary` |
| mapped package compression | `Core/Src/UnLinker.cpp: ULinkerLoad::SerializePackageFileSummary` |
| async compressed framing validation | `Core/Src/UnAsyncLoading.cpp: FAsyncIOSystemBase::FulfillCompressedRead` |

The supplied `ardenee/UT3src-comunity` tree does not provide a native Core implementation that overrides this behavior.

## Flag definition

UE3 defines:

```text
PKG_StoreCompressed      = 0x02000000
PKG_StoreFullyCompressed = 0x04000000
```

The source comments are materially different.

`PKG_StoreCompressed`:

> Package is being stored compressed, requires archive support for compression

`PKG_StoreFullyCompressed`:

> Package is serialized normally, and then fully compressed after (must be decompressed before LoadPackage is called)

Therefore `PKG_StoreFullyCompressed` describes an **outer transformation of an already serialized package**.

It is not the ordinary package compression-map mechanism.

## Fundamental processing model

The source-defined conceptual order for a fully compressed package is:

```text
normal serialized UE3 package
        |
        v
whole-package compression step
        |
        v
fully-compressed physical representation
```

Loading reverses this before the normal linker is invoked:

```text
fully-compressed physical representation
        |
        v
decompress outer representation
        |
        v
normal serialized UE3 package
        |
        v
LoadPackage / ULinkerLoad
```

This is different from `PKG_StoreCompressed`, where `ULinkerLoad` reads the package summary first and then installs a logical compression map.

## Why the normal linker cannot discover this mode from the outer bytes

The source comment explicitly says fully compressed packages must be decompressed **before** `LoadPackage`.

That has an important consequence.

The `PKG_StoreFullyCompressed` bit belongs to the package flags of the **uncompressed package summary**. It is therefore not a generic outer-file signature that the normal package linker can inspect before decompression.

A generic reader must not assume that byte 0 of a fully compressed physical file contains the normal UE3 package tag or directly readable package flags.

The outer representation has to be identified by the applicable producer/container context before the normal package summary is available.

## No ULinkerLoad fully-compressed branch

`ULinkerLoad::SerializePackageFileSummary` contains explicit runtime handling for:

`Summary.PackageFlags & PKG_StoreCompressed`

That path installs:

- `Summary.CompressedChunks`;
- `Summary.CompressionFlags`;

through `Loader->SetCompressionMap(...)`.

There is no equivalent branch in the inspected generic package loader that sees `PKG_StoreFullyCompressed` and transparently decompresses the physical file.

This matches the flag comment: fully compressed data is expected to have been decompressed before package loading reaches this code.

Therefore UnrealDB must not route `PKG_StoreFullyCompressed` through `SetCompressionMap`.

## Generic UE3 SerializeCompressed framing

UE3 provides a generic framed compression representation through:

`FArchive::SerializeCompressed`

This is the same fundamental framing family used by the compression infrastructure documented for compressed package chunks.

### FCompressedChunkInfo

Each entry serializes exactly:

1. `CompressedSize`
2. `UncompressedSize`

Both are 32-bit integers.

The serializer requires the serialized order to match the structure memory layout because async I/O may read these records in bulk.

## SerializeCompressed outer header

When saving, `SerializeCompressed` first writes an `FCompressedChunkInfo` used as a header:

- `CompressedSize = PACKAGE_FILE_TAG`
- `UncompressedSize = GSavingCompressionChunkSize`

Thus the first two serialized 32-bit values are:

1. package tag;
2. compression chunk size.

The package tag here is compression-framing magic. It does not mean that the compressed stream itself is already a directly parseable package summary.

## Byte order

When loading, the first `FCompressedChunkInfo` is read as the package-file-tag header.

If its first integer is not `PACKAGE_FILE_TAG`, the source treats the stream as potentially byte swapped and requires:

`PACKAGE_FILE_TAG_SWAPPED`

It then byte-swaps:

- summary compressed size;
- summary uncompressed size;
- header chunk-size integer;
- every per-chunk `CompressedSize`;
- every per-chunk `UncompressedSize`.

Therefore the generic compressed-stream framing supports normal and swapped integer byte order.

This is not encryption.

## Historical compression-chunk-size compatibility

The second integer of the framing header is the loading compression chunk size.

The loader contains the compatibility rule:

```text
if LoadingCompressionChunkSize == PACKAGE_FILE_TAG:
    LoadingCompressionChunkSize = LOADING_COMPRESSION_CHUNK_SIZE
```

This is the old-header compatibility form where the current explicit chunk-size field was not available in the same way.

The latest source defines:

- `LOADING_COMPRESSION_CHUNK_SIZE_PRE_369 = 32768`;
- `LOADING_COMPRESSION_CHUNK_SIZE = 131072`;
- `SAVING_COMPRESSION_CHUNK_SIZE = LOADING_COMPRESSION_CHUNK_SIZE`.

As with ordinary UPK compression, UnrealDB must reproduce only the compatibility branch actually established by the applicable source. It must not guess 32 KiB merely from age.

## Compression summary

After the two-int framing header, loading reads another `FCompressedChunkInfo` as the stream summary.

Its important value is:

`Summary.UncompressedSize`

which determines how many payload chunks are expected.

Saving initializes the first table entry so that:

- `CompressionChunks[0].UncompressedSize = Length`;
- `CompressionChunks[0].CompressedSize` accumulates the total compressed payload bytes.

The first table entry is summary metadata, not payload.

## Payload chunk count

Saving calculates:

```text
payloadChunks = ceil(Length / GSavingCompressionChunkSize)
tableEntries  = payloadChunks + 1
```

where table entry zero is the summary.

Loading has already consumed the summary separately and calculates the number of following payload records from:

```text
ceil(Summary.UncompressedSize / LoadingCompressionChunkSize)
```

These are two views of the same serialized structure.

## Serialized layout

The generic `SerializeCompressed` representation is therefore:

```text
int32 PACKAGE_FILE_TAG or swapped tag
int32 CompressionChunkSize

int32 TotalCompressedPayloadSize
int32 TotalUncompressedSize

repeat ceil(TotalUncompressedSize / CompressionChunkSize):
    int32 ChunkCompressedSize
    int32 ChunkUncompressedSize

compressed payload chunk 0
compressed payload chunk 1
...
```

The summary's compressed-size value describes the total compressed payload bytes. The individual payload entries describe each codec stream.

## Payload decompression

Loading:

1. finds the largest serialized compressed payload size;
2. allocates a temporary compressed buffer;
3. reads each compressed payload using its exact `CompressedSize`;
4. calls `appUncompressMemory` with the supplied compression flags;
5. writes exactly `Chunk.UncompressedSize` bytes into the destination;
6. advances the destination by that uncompressed amount.

The codec is therefore external to the framing itself: `SerializeCompressed` receives `ECompressionFlags Flags`.

The framing does **not** serialize an independent codec identifier.

## Critical codec-identification limitation

Because `FArchive::SerializeCompressed` receives the codec flags from its caller, the generic framing alone does not contain a self-describing zlib/LZO/LZX selector.

This is especially important for fully compressed packages.

The `PKG_StoreFullyCompressed` flag tells us that an outer whole-package compression operation exists, but the inspected Core flag definition and generic linker path do not by themselves define a universal rule for recovering the compression codec from an arbitrary fully-compressed physical UPK.

Therefore UnrealDB must not:

- infer the codec from `.upk`;
- infer it from the framing tag;
- assume PC means LZO;
- assume Xbox 360 means LZX;
- assume PS3 means zlib;
- try codecs until one yields package magic.

The platform defaults in `ECompressionFlags` are runtime/cooker defaults, not a serialized universal identification mechanism.

A concrete fully-compressed producer path must establish the codec for that file family.

## Compression codecs

Where the applicable producer/source establishes the flags, the generic UE3 codec implementation supports the source-defined types already documented in `specs/upk-compression-handling.md`:

- `COMPRESS_ZLIB = 0x01`;
- `COMPRESS_LZO = 0x02`;
- `COMPRESS_LZX = 0x04`.

Their exact codec semantics, including the LZX four-byte padding convention, remain those documented in the UPK compression specification.

This specification does not invent a new fully-compressed codec.

## LZX padding still applies when LZX is established

UE3's LZX compressor adds four bytes to the reported compressed size because the XMem LZX decoder can read past the actual compressed bitstream.

The LZX decompressor subtracts those four bytes before passing the stream to XMem.

Therefore, if a source-backed fully-compressed package path establishes `COMPRESS_LZX`, the same serialized four-byte size/padding convention must be honored.

It is a codec rule, not a `PKG_StoreCompressed`-only rule.

## PS3 source-buffer padding is not serialized data

On PS3, the generic decompression implementation may allocate 128 bytes of extra memory after compressed input so SPU/cache/DMA reads can safely over-read.

That is runtime memory padding.

It must not be interpreted as an additional 128 serialized bytes in the fully-compressed format.

## FArchiveLoadCompressedProxy behavior

UE3 also supplies `FArchiveLoadCompressedProxy`.

It wraps compressed data and exposes an uncompressed archive view by repeatedly invoking `SerializeCompressed`.

Important source behavior:

- it is a loading archive;
- it uses caller-supplied `CompressionFlags`;
- it allocates a temporary `LOADING_COMPRESSION_CHUNK_SIZE` buffer;
- when the buffer is exhausted it decompresses the next framed block;
- `Tell()` reports the uncompressed/raw position;
- seeking is forward-only;
- forward seeking is implemented by decompressing and discarding bytes.

This reinforces that the compression format itself does not magically discover its codec.

## FArchiveSaveCompressedProxy behavior

`FArchiveSaveCompressedProxy`:

- receives caller-supplied compression flags;
- buffers raw bytes in `LOADING_COMPRESSION_CHUNK_SIZE` blocks;
- calls `SerializeCompressed` when a buffer is full or flushed;
- writes compressed data to its backing byte array;
- reports raw/uncompressed position for normal serialization.

This is generic compression infrastructure. Its existence does not prove that every `PKG_StoreFullyCompressed` file is produced by this exact proxy or that every game uses one universal codec.

## Validation required for a known fully-compressed stream

Once the outer representation and codec have been positively established from applicable source/context, a robust reader should validate:

1. enough bytes exist for the two-int framing header;
2. tag is normal or swapped `PACKAGE_FILE_TAG`;
3. compression chunk size is valid before division/count calculations;
4. enough bytes exist for the summary;
5. summary sizes are non-negative and safely representable;
6. calculated payload count cannot overflow;
7. the entire payload-info table is within the physical input;
8. every compressed and uncompressed chunk size is valid;
9. only the final uncompressed chunk may be shorter than the configured chunk size;
10. the sum of payload uncompressed sizes equals the summary uncompressed size;
11. the sum of payload compressed sizes equals the summary compressed payload size where applying the saved representation;
12. all compressed payloads remain inside the physical input;
13. each codec operation succeeds;
14. each codec produces exactly the advertised uncompressed size;
15. total output equals the advertised total uncompressed size;
16. decompressed output is then independently validated as the expected UE3 package/container.

Engine assertions are not a reason for an offline catalogue reader to perform unsafe allocations or reads. Arithmetic must be bounds checked before allocation or I/O.

## Distinction from PKG_StoreCompressed

### PKG_StoreCompressed

The normal package summary is readable first.

The summary contains:

- `CompressionFlags`;
- `CompressedChunks`.

`ULinkerLoad` installs that map and continues reading a logical uncompressed address space.

Physical and logical offsets coexist.

### PKG_StoreFullyCompressed

The package is serialized normally **first** and the resulting package is compressed afterward.

It must be decompressed before normal package loading.

There is no package-summary compression map available to bootstrap that outer decompression.

After successful outer decompression, the result is treated as a normal serialized package.

These paths must remain separate.

## Interaction between the two flags

The inspected definitions assign independent bits to `PKG_StoreCompressed` and `PKG_StoreFullyCompressed`.

However, the existence of two bit values is not sufficient evidence that every combination is a valid producer state or defines a universal nested-compression order.

UnrealDB must not invent:

```text
fully compressed -> mapped compressed
```

or the reverse merely because both bits can mathematically be set.

If a real source-backed producer sets both, its exact save/load sequence must define the order.

## Detection rules for UnrealDB

A generic file scanner must distinguish **recognition** from **decoding capability**.

### Safe recognition

If a source-defined outer fully-compressed family is known, recognize it using that family's proven framing/context.

### Unsafe recognition

Do not classify arbitrary unknown data as `PKG_StoreFullyCompressed` merely because:

- normal UE package magic is absent;
- bytes have high entropy;
- zlib/LZO/LZX happens to decode;
- trial decompression yields `PACKAGE_FILE_TAG`;
- the extension is `.upk`;
- the file came from a UE3 game.

The package flag itself is inside the decompressed package and therefore cannot serve as the generic pre-decompression detector.

## Decompressed package validation

After a known fully-compressed outer representation is decoded, UnrealDB should restart normal UE3 package identification against the resulting bytes.

The output should satisfy the applicable UE3 package rules, including:

- normal or swapped package tag;
- valid package version/licensee version;
- structurally valid summary;
- bounded name/import/export/dependency metadata;
- any source-defined inner `PKG_StoreCompressed` handling only if that state is independently valid for the applicable producer.

Successful codec decompression alone is not sufficient package validation.

## Error classification

For a positively identified fully-compressed representation, useful failures include:

- invalid fully-compressed framing tag;
- truncated fully-compressed header;
- invalid compression chunk size;
- truncated compression chunk table;
- invalid compressed/uncompressed chunk size;
- compressed payload outside file bounds;
- compressed-size total mismatch;
- uncompressed-size total mismatch;
- unsupported source-defined codec;
- decompression failed;
- decompressed output is not a valid UE3 package.

For an arbitrary file where the outer fully-compressed family has **not** been established, report the actual identification failure instead of claiming it is fully compressed.

## UnrealDB implementation contract

1. keep `PKG_StoreFullyCompressed` distinct from `PKG_StoreCompressed`;
2. preserve the exact flag value `0x04000000`;
3. treat the source comment "decompressed before LoadPackage" as a hard ordering requirement;
4. never expect `ULinkerLoad::SetCompressionMap` to decode this outer representation;
5. do not assume the outer file begins with the ordinary uncompressed package summary;
6. use the source-defined `SerializeCompressed` framing when, and only when, the applicable producer establishes it;
7. parse its tag/chunk-size header exactly;
8. support swapped framing exactly;
9. reproduce the repeated-package-tag historical compatibility branch;
10. parse the summary and per-payload `FCompressedChunkInfo` records;
11. validate all sizes/counts before allocation or reads;
12. use only a source-established codec;
13. do not infer codec from platform defaults;
14. do not trial-decompress with several codecs as identification;
15. preserve codec-specific rules such as LZX four-byte padding;
16. do not serialize PS3 runtime over-read padding into the format;
17. require exact advertised decompressed sizes;
18. validate the decompressed result as a UE3 package;
19. do not infer nested compression semantics from both flag bits alone;
20. do not call compression encryption.

## Source-reference matrix

| Rule | Proving source/symbol |
|---|---|
| fully compressed flag/value | `Core/Inc/UnObjBas.h: EPackageFlags` |
| decompress before LoadPackage requirement | `PKG_StoreFullyCompressed` source comment |
| mapped compression is separate | `PKG_StoreCompressed` source comment and `ULinkerLoad::SerializePackageFileSummary` |
| no generic linker fully-compressed branch | `Core/Src/UnLinker.cpp: ULinkerLoad::SerializePackageFileSummary` |
| compressed info record layout | `Core/Src/UnArchive.cpp: operator<<(FCompressedChunkInfo&)` |
| generic framed compression | `Core/Src/UnArchive.cpp: FArchive::SerializeCompressed` |
| header tag + saving chunk size | `FArchive::SerializeCompressed` saving path |
| swapped framing | `FArchive::SerializeCompressed` loading path |
| historical repeated-tag compatibility | `FArchive::SerializeCompressed` loading path |
| summary and payload chunk table | `FArchive::SerializeCompressed` |
| caller supplies codec flags | `FArchive::SerializeCompressed(..., ECompressionFlags Flags, ...)` |
| compressed proxy also requires caller flags | `FArchiveSaveCompressedProxy`, `FArchiveLoadCompressedProxy` |
| codec definitions | `Core/Inc/UnFile.h: ECompressionFlags` |
| codec implementations | `Core/Src/UnMisc.cpp: appCompressMemory, appUncompressMemory` |
| async equivalent framing | `Core/Src/UnAsyncLoading.cpp: FAsyncIOSystemBase::FulfillCompressedRead` |

## Result

UE3 `PKG_StoreFullyCompressed` is explicitly an **outer whole-package compression state**: the package is serialized normally, compressed afterward, and must be decompressed before normal `LoadPackage` processing.

It is not the `PKG_StoreCompressed` logical chunk-map mechanism.

UE3 Core provides a concrete generic `SerializeCompressed` framing consisting of a package-tag/chunk-size header, total-size summary, per-payload chunk table, and compressed payloads. However, that framing receives its compression codec from the caller; it does not serialize a universal self-identifying codec selector.

Therefore a generic UnrealDB reader must not claim it can decode arbitrary fully-compressed UPKs from the flag or extension alone. The applicable game/platform producer path must establish how the outer representation is identified and which codec is used. Once established, the framing and codec must be decoded exactly and the resulting bytes validated as a normal UE3 package.
