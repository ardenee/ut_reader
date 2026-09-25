# UE3 Fully-Compressed Package Handling (`PKG_StoreFullyCompressed`)

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

## Next specification

The next target should be **UE3/UPK package flags and cooked-package semantics**: audit every serialized `EPackageFlags` bit that materially changes reading, validation, exports/imports, dependency behavior, or package classification, separating structural format behavior from editor/runtime-only state and from flags that must merely be preserved.
