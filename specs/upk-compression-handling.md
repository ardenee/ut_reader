# UPK Compression Handling

## Scope and authority

This specification documents **UE3/UPK package compression handling** from the supplied Epic UE3 source. It is the compression companion to `specs/upk-format.md` and covers both source-defined package storage modes: mapped compression (`PKG_StoreCompressed`) and whole-package post-serialization compression (`PKG_StoreFullyCompressed`).

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
| package compression flags | `Core/Inc/UnObjBas.h: EPackageFlags` |
| generic framed compression implementation | `Core/Src/UnArchive.cpp: FArchive::SerializeCompressed` |
| compressed stream proxies | `Core/Src/UnArchive.cpp: FArchiveSaveCompressedProxy`, `FArchiveLoadCompressedProxy` |

## Package compression modes

UE3 defines two distinct package flags:

| Flag | Value | Source meaning |
|---|---:|---|
| `PKG_StoreCompressed` | `0x02000000` | package is stored compressed and requires archive compression-map support |
| `PKG_StoreFullyCompressed` | `0x04000000` | package is serialized normally and then fully compressed; it must be decompressed before `LoadPackage` |

They are not interchangeable.

### Mapped package compression: `PKG_StoreCompressed`

This mode has two related structures.

#### Package-level compression map

The package summary contains:

- `CompressionFlags`
- `TArray<FCompressedChunk> CompressedChunks`

Each `FCompressedChunk` contains:

1. `UncompressedOffset`
2. `UncompressedSize`
3. `CompressedOffset`
4. `CompressedSize`

These values map a logical region of the **uncompressed package address space** to a physical compressed region in the file.

#### Framing inside each compressed region

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

## Whole-package compression: `PKG_StoreFullyCompressed`

This flag describes a different processing order. The source comment is explicit: the package is **serialized normally, then fully compressed afterward, and must be decompressed before `LoadPackage` is called**.

Conceptually:

```text
normal serialized UE3 package
    -> whole-package compression
    -> physical fully-compressed representation

physical fully-compressed representation
    -> whole-package decompression
    -> normal serialized UE3 package
    -> LoadPackage / ULinkerLoad
```

Unlike `PKG_StoreCompressed`, `ULinkerLoad::SerializePackageFileSummary` has no branch that discovers this mode and installs it through `SetCompressionMap`. The flag is part of the already-serialized package summary, so the outer compressed representation must be dealt with before normal linker parsing.

### Framing and codec identification

UE3's generic `FArchive::SerializeCompressed` implementation uses the same framing already described above: package tag/chunk-size header, total-size summary, per-payload `FCompressedChunkInfo` records, and codec payloads. Its loading path also implements the same swapped-tag and repeated-package-tag compatibility rules.

However, `SerializeCompressed` receives `ECompressionFlags` from its **caller**. The framing does not contain a universal independent zlib/LZO/LZX selector. `FArchiveSaveCompressedProxy` and `FArchiveLoadCompressedProxy` likewise receive their compression flags from their caller.

Consequently, the generic UE3 Core code does **not** establish a universal way to identify the codec of an arbitrary fully-compressed UPK from the outer bytes alone. UnrealDB must not infer it from the `.upk` extension, platform defaults, entropy, or trial-decompression until package magic appears. A game/platform producer path must establish the outer representation and codec before this mode can be decoded source-compatibly.

Where a producer does establish zlib, LZO, or LZX, the codec rules earlier in this document apply unchanged, including LZX's four-byte serialized padding convention. PS3's 128-byte decompressor over-read allocation remains runtime memory padding, not serialized file data.

### Validation and handoff

For a positively identified fully-compressed representation, validate the framing, table bounds, compressed/uncompressed totals, per-chunk sizes, codec success, and exact output sizes using the same `SerializeCompressed` rules described above. After decompression, restart normal UE3 package identification on the output and validate its package tag, version and summary before parsing names/imports/exports.

Do not classify arbitrary data as fully compressed merely because normal UE package magic is absent or because one codec happens to produce plausible output.

The two package flags are independent bits, but their existence alone does not prove that setting both is a valid producer state or define a nested compression order. If an applicable game/platform source sets both, that producer's exact save/load path must establish the order.

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

### `PKG_StoreCompressed`

For a mapped-compressed UE3 package:

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

### `PKG_StoreFullyCompressed`

For a source-identified fully-compressed package:

1. identify the applicable outer representation and codec from source-backed game/platform context;
2. parse and validate the `SerializeCompressed` framing;
3. decompress all payload chunks using that established codec and the codec rules above;
4. require exact advertised uncompressed sizes;
5. validate the decompressed bytes as a normal UE3 package;
6. only then enter normal package/linker parsing.

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
20. do not conflate compression with encryption;
21. keep `PKG_StoreFullyCompressed` separate from the mapped `PKG_StoreCompressed` path;
22. decompress a fully-compressed package before normal `LoadPackage`/linker parsing;
23. do not infer a fully-compressed stream's codec from extension, platform defaults, or trial decompression;
24. require a source-backed producer/context to establish that outer codec;
25. validate the decompressed result as a UE3 package before parsing it;
26. do not invent nested-compression semantics merely because both package flag bits exist.

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
| fully-compressed flag/value and pre-LoadPackage requirement | `Core/Inc/UnObjBas.h: EPackageFlags`, `PKG_StoreFullyCompressed` comment |
| generic framed save/load implementation | `Core/Src/UnArchive.cpp: FArchive::SerializeCompressed` |
| caller-supplied codec for generic compressed streams | `FArchive::SerializeCompressed`, `FArchiveSaveCompressedProxy`, `FArchiveLoadCompressedProxy` |
| swapped framing | `FAsyncIOSystemBase::FulfillCompressedRead` |
| old repeated-tag compatibility | `FAsyncIOSystemBase::FulfillCompressedRead` |
| summary/size validation | `FAsyncIOSystemBase::FulfillCompressedRead` |

## Result

UE3 UPK compression has two distinct source-defined storage modes.

`PKG_StoreCompressed` is a **mapped logical-file compression system**. The readable package summary supplies `CompressionFlags` and `FCompressedChunk` records; each physical compressed region then uses the source-defined `SerializeCompressed` framing. Higher package parsing continues in logical uncompressed offsets.

`PKG_StoreFullyCompressed` is an **outer whole-package compression state**. The package is serialized normally, compressed afterward, and must be decompressed before normal `LoadPackage` processing. The generic UE3 framing is defined, but its codec comes from the caller rather than from a universal serialized codec field, so UnrealDB must only decode this form when the applicable producer/context establishes the codec.

Both modes share the same source-defined codec semantics and framed-chunk rules where applicable, but their activation and load order are fundamentally different. Neither compression mode is encryption.
