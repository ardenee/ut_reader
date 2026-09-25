# UZ2 Redirect Compression and Decompression

## 1. Scope and exact revisions

This specification documents the `.uz2` redirect/file-copy compression format from the supplied Unreal Tournament 2003 v2107 and Unreal Tournament 2004 source trees.

Authoritative revisions:

- `ardenee/Unreal_Tournament_2003_v2107`
- `ardenee/UT2004src`

The two supplied game revisions both explicitly define `COMPRESSED_EXTENSION` as `.uz2` and both implement redirect compression in `FFileManagerGeneric::Copy` with zlib block records.

This specification does not treat UZ, UZ2, and UZ3 as interchangeable. It documents only behavior directly proved for UZ2 by these source trees.

## 2. Authoritative source references

Primary format implementation:

- `Core/Inc/Core.h`
  - `ECopyCompress`
  - `ECopyResult`
  - `COMPRESSED_EXTENSION`
- `Core/Inc/FFileManagerGeneric.h`
  - `COPYBLOCKSIZE`
  - `MAXCOMPSIZE`
  - `FFileManagerGeneric::Copy`
- `IpDrv/Src/UCompressCommandlet.cpp`
  - `UCompressCommandlet::Main`
  - `UDecompressCommandlet::Main`

Runtime redirect/download integration:

- `Engine/Src/UnDownload.cpp`
  - compressed-download completion/decompression path
- `IpDrv/Src/HTTPDownload.cpp`
  - `UHTTPDownload::ReceiveFile`

The UT2003 and UT2004 implementations were reviewed separately. Differences are identified below instead of being flattened.

## 3. File identity and extension

Both supplied source revisions define:

`COMPRESSED_EXTENSION = ".uz2"`

Compression does not replace the original extension. The file manager appends `.uz2` to the destination filename.

For example:

`Example.ut2 -> Example.ut2.uz2`

Decompression performs the inverse naming operation: `FILECOPY_Decompress` appends `.uz2` to the source-side filename internally while writing the uncompressed destination filename.

The commandlet requires its decompression input token to end in `.uz2`; it strips that suffix to obtain the destination name.

## 4. No UZ2 global magic/header

The authoritative `FFileManagerGeneric::Copy` implementation writes no global file signature, magic value, version, total uncompressed length, block count, checksum, or footer.

The UZ2 file is a concatenation of independently zlib-compressed block records until EOF.

Therefore the first four bytes of a normal UZ2 file are the first record's compressed-size DWORD, not a UZ2 magic.

UnrealDB must not require an invented magic value.

## 5. Block constants

Both source revisions define:

`COPYBLOCKSIZE = 32768`

and:

`MAXCOMPSIZE = 33096`

Thus each uncompressed input block is at most 32,768 bytes.

The compression buffer is 33,096 bytes.

These values are source format/implementation constraints for this UZ2 path, not arbitrary UnrealDB safety limits.

## 6. UZ2 record layout

A UZ2 stream consists of zero or more records:

| Field | Size | Meaning |
|---|---:|---|
| ComSize | 4 bytes | compressed zlib payload length |
| UncSize | 4 bytes | expected/maximum uncompressed block length |
| ComData | ComSize bytes | zlib-compressed block |

There is no per-file record count. Records continue until physical EOF.

Conceptually:

`[ComSize][UncSize][zlib data][ComSize][UncSize][zlib data] ... EOF`

Each source block is compressed independently. zlib state does not carry from one record into the next.

## 7. Compression algorithm

For each source block, the engine:

1. chooses `UncSize = min(remaining source bytes, 32768)`;
2. reads exactly `UncSize` bytes;
3. initializes the output capacity to `MAXCOMPSIZE`;
4. calls zlib `compress()`;
5. obtains the resulting `ComSize`;
6. writes `ComSize`;
7. writes `UncSize`;
8. writes exactly `ComSize` compressed bytes.

If zlib `compress()` does not return `Z_OK`, the copy result becomes `COPY_CompFail`.

If source reading fails, the result is `COPY_ReadFail`.

If output writing fails, the result is `COPY_WriteFail`.

On a failed copy, the partially produced destination is deleted.

## 8. Compression level

The source calls zlib `compress()`, not `compress2()`.

Consequently the format-producing path uses the compression behavior selected by that zlib convenience function rather than serializing a custom Unreal compression-level field.

There is no compression-level byte in the UZ2 format.

A source-compatible reader must accept valid zlib streams regardless of whether another conforming producer happens to choose a different legal zlib compression level. Exact engine-byte reproduction for generated files should use behavior equivalent to the engine's `compress()` call.

## 9. Decompression algorithm

The file manager processes records while the compressed source archive is not at EOF.

For each record it:

1. reads `ComSize`;
2. reads `UncSize`;
3. validates the sizes against the implementation maxima;
4. reads exactly `ComSize` bytes;
5. calls zlib `uncompress()` using `UncSize` as the output-size input;
6. receives the actual decoded size back through that length argument;
7. writes the resulting decoded bytes;
8. continues with the next record until EOF.

A zlib result other than `Z_OK` produces `COPY_DecompFail`.

## 10. Required size validation

The source rejects a record when:

`ComSize > 33096`

or:

`UncSize > 32768`.

These checks occur before reading/decompressing the block payload.

The source also detects archive read errors while reading the compressed payload.

UnrealDB must perform equivalent bounds validation before allocating/reading based on untrusted UZ2 record values.

A record whose declared payload extends beyond EOF is truncated and invalid.

## 11. UT2003 integer representation

The supplied UT2003 v2107 implementation writes the two DWORD record sizes directly:

- `Dest->Serialize(&ComSize, sizeof(ComSize))`
- `Dest->Serialize(&UncSize, sizeof(UncSize))`

Its decompressor likewise reads them directly.

For the target PC/x86 game format this produces the normal little-endian DWORD representation.

The UT2003 source shown does not explicitly normalize these record values through `INTEL_ORDER32`.

That distinction matters when documenting source behavior and must not be silently rewritten as if the UT2003 code contained the later conversion.

## 12. UT2004 integer representation

The supplied UT2004 implementation explicitly normalizes record sizes to Intel byte order.

Compression calculates:

- `OutComSize = INTEL_ORDER32(ComSize)`
- `OutUncSize = INTEL_ORDER32(UncSize)`

and serializes those values.

Decompression reads the DWORDs and applies:

- `ComSize = INTEL_ORDER32(ComSize)`
- `UncSize = INTEL_ORDER32(UncSize)`

before validation and decompression.

Thus UT2004 explicitly defines the on-disk UZ2 record sizes as Intel/little-endian values.

On the ordinary little-endian PC targets, the resulting byte representation agrees with the UT2003 files.

UnrealDB should therefore read/write UZ2 size fields explicitly as little-endian uint32 values.

## 13. Last block

All complete non-final blocks are 32,768 uncompressed bytes.

The final block may contain fewer than 32,768 bytes.

No special final-record flag exists. EOF after the final compressed payload terminates the stream.

A final block whose uncompressed length happens to equal 32,768 is also valid; EOF still determines that no further record follows.

## 14. Empty input

The compressor loops while `Total < Size`.

For a zero-byte source, no block is emitted.

Therefore the direct file-copy algorithm can produce an empty compressed output for an empty source. There is no mandatory global UZ2 header to emit.

Whether an empty file is meaningful as a game package is a separate package-format question and must not be confused with UZ2 container parsing.

## 15. No stored original filename

UZ2 does not serialize the original filename.

The filename is supplied externally by the filesystem/redirect URL and inferred by removing the appended `.uz2` suffix.

UnrealDB must not expect a filename field inside the compressed byte stream.

## 16. No stored original total size

UZ2 does not contain a file-level uncompressed-size field.

The complete uncompressed size is the sum of the actual decoded lengths of all records.

At runtime the engine already has package metadata containing the expected package size. That information is external to UZ2.

## 17. No UZ2 checksum

The wrapper does not add an Unreal-specific CRC, MD5, GUID, or checksum to each block or to the complete UZ2 stream.

Integrity checking inside each compressed record is provided by the zlib stream format and zlib decoder.

The game download path performs additional validation after decompression, including checking the resulting file size and package GUID. Those checks belong to the package/download workflow, not the UZ2 byte format.

## 18. Commandlet compression

`UCompressCommandlet::Main` accepts one or more file/wildcard tokens.

For each source it invokes:

`GFileManager->Copy(Src, Src, ..., FILECOPY_Compress, ...)`

The file manager appends `.uz2`, so the source itself is not overwritten.

After success, the commandlet obtains source and compressed sizes and logs the compression percentage.

UT2004 additionally skips command-line tokens beginning with `-` in this loop, with the source comment referring to options such as `-nohomedir`.

That command-line parsing difference does not change the UZ2 byte format.

## 19. Commandlet decompression

`UDecompressCommandlet::Main`:

1. requires a source token;
2. requires that token to end in `.uz2`;
3. removes `.uz2` to derive `Dest`;
4. calls the file manager with `FILECOPY_Decompress`;
5. reports read/write/decompression failures distinctly;
6. logs the decompressed source and destination names on success.

The file manager's decompression mode internally opens `Dest + ".uz2"` as the compressed source.

## 20. HTTP redirect behavior

In the HTTP downloader, when compression is enabled:

`File = File + COMPRESSED_EXTENSION`

Therefore an HTTP redirect request asks for the ordinary package filename with `.uz2` appended.

The redirect URL substitution logic can use placeholders including file and extension values, but those are URL/configuration behaviors, not fields in the UZ2 stream.

UnrealDB should not encode redirect-server URL rules into the UZ2 parser.

## 21. Runtime post-download decompression

The game download path records whether the incoming file is compressed.

After a compressed download completes, the engine moves/renames the temporary compressed file so the file manager can process it using `FILECOPY_Decompress`.

After decompression, the runtime verifies that the resulting file size equals the expected package `FileSize`.

It then validates the package GUID using the package-file GUID check.

Only after those checks does it move the package into its final/cache destination.

This proves an important separation:

- UZ2 decompression validates the compressed record structure and zlib payloads.
- package size/GUID validation happens after decompression.

UnrealDB should preserve that separation.

## 22. zlib stream expectations

Each `ComData` field is the direct output of zlib `compress()` and is consumed by zlib `uncompress()`.

It is therefore a zlib-wrapped deflate stream, not raw deflate and not gzip.

A decoder that invokes a raw-deflate-only API without zlib wrapper handling is not source-compatible.

There is one complete zlib stream per UZ2 record.

## 23. No cross-block dictionary

Because each source block is passed independently to `compress()`, no compression dictionary or sliding-window history is shared between UZ2 records.

A decoder must reset zlib state for each record.

A compressor must likewise produce each block independently to match the engine structure.

## 24. Exact parsing procedure

A robust UZ2 parser should implement the following source-equivalent procedure:

1. set offset = 0;
2. while offset is less than file length:
   - require at least 8 bytes for the next record header;
   - read little-endian uint32 ComSize;
   - read little-endian uint32 UncSize;
   - reject ComSize > 33096;
   - reject UncSize > 32768;
   - require at least ComSize remaining bytes;
   - pass exactly those ComSize bytes to a zlib decoder;
   - provide UncSize as the expected output capacity;
   - require successful zlib completion;
   - append the actual decoded bytes;
   - advance to the next record;
3. require termination exactly at EOF.

The engine's source uses archive error state for some truncation detection. UnrealDB should convert that into a clear structural failure rather than accepting a partial final record.

## 25. Strictness around decoded size

The source initializes the zlib output-size variable from the serialized `UncSize` and then lets `uncompress()` update it to the actual output size.

The subsequent write uses that resulting value.

Therefore `UncSize` functions as the supplied output capacity and the normal engine-produced record contains the original source-block length.

For UnrealDB validation, a mismatch between the serialized original-block length and actual decoded length is evidence of a noncanonical/corrupt record and should be reported rather than silently rewriting the metadata.

Engine-generated files should always round-trip with equality.

## 26. Compression procedure for UnrealDB

To produce source-compatible UZ2:

1. read the original file in chunks of at most 32768 bytes;
2. compress each chunk independently using zlib format;
3. ensure the compressed result does not exceed 33096 bytes;
4. write compressed size as little-endian uint32;
5. write original chunk size as little-endian uint32;
6. write the zlib stream bytes;
7. repeat without a global header/footer;
8. append `.uz2` to the original filename.

Do not prepend a package magic, UZ marker, block count, total size, or filename.

## 27. Decompression procedure for UnrealDB

To decompress:

1. identify/select UZ2 independently from UZ and UZ3;
2. remove only the terminal `.uz2` suffix when deriving a conventional output filename;
3. parse records until EOF;
4. enforce the source block maxima;
5. zlib-decompress every record independently;
6. concatenate decoded blocks in record order;
7. only after complete successful decompression hand the result to package/archive identification;
8. if package metadata is available, validate the resulting package normally.

A decompression error must not leave a partially decoded file eligible for import as if it were valid.

## 28. Distinction from legacy UZ

UZ2 is not the legacy FCodec BWT/RLE/MTF/Huffman stream documented for the UE1 UZ lineage.

Although `FCodec.h` remains in the UT2003/UT2004 source trees, the actual `UCompressCommandlet` calls `FILECOPY_Compress`, and `FFileManagerGeneric::Copy` implements that path with zlib block records.

Therefore presence of `FCodec.h` is not evidence that a `.uz2` file should be decoded with `FCodecFull`.

UnrealDB must never fall back from failed UZ2 zlib parsing to UE1 FCodec parsing merely because both implementations exist in the engine source tree.

## 29. Distinction from UZ3

Nothing in these UT2003/UT2004 UZ2 source paths proves the UT3 `.uz3` format.

UZ3 must be documented independently from the supplied UT3/UE3 source.

Do not infer UZ3 framing, codec, limits, or byte order from UZ2.

## 30. Error classification

The source distinguishes:

- `COPY_ReadFail`
- `COPY_WriteFail`
- `COPY_CompFail`
- `COPY_DecompFail`
- `COPY_Canceled`
- miscellaneous failure

For UnrealDB, malformed input should similarly preserve the useful cause where possible:

- truncated record header;
- compressed size over 33096;
- uncompressed size over 32768;
- compressed payload overrun/truncation;
- zlib decode failure;
- decoded-size mismatch;
- trailing/incomplete bytes;
- output write/storage failure.

These diagnostics should report actual numeric values and offsets where available.

## 31. Conformance tests

Minimum format tests should include:

- one-byte source;
- source shorter than 32768;
- source exactly 32768;
- source 32769 bytes;
- several full blocks plus a partial final block;
- incompressible/random-like block;
- highly compressible block;
- truncated 8-byte record header;
- ComSize = 33097 rejection;
- UncSize = 32769 rejection;
- payload shorter than ComSize;
- corrupt zlib stream;
- concatenated valid blocks;
- valid final full-size block ending exactly at EOF.

Round trip:

`original -> UnrealDB UZ2 compressor -> UnrealDB UZ2 decompressor -> original`

must be byte-identical.

Engine-produced UZ2 files must decode byte-identically in UnrealDB, and UnrealDB-produced UZ2 files should be accepted by the matching game commandlet/runtime.

## 32. UnrealDB conformance requirements

UnrealDB must:

1. treat `.uz2` as a distinct format;
2. parse no invented global header;
3. read/write record size fields as little-endian uint32;
4. enforce ComSize <= 33096;
5. enforce UncSize <= 32768;
6. require the complete declared compressed payload;
7. use zlib-wrapped streams, not gzip or raw deflate;
8. reset zlib for every record;
9. concatenate decoded records in order;
10. reject malformed/truncated records;
11. not use UE1 FCodec decoding as a UZ2 fallback;
12. not infer UZ3 behavior from UZ2;
13. distinguish UZ2 structural validation from subsequent Unreal package validation;
14. support compression as well as decompression;
15. append/remove `.uz2` only as the external filename convention;
16. preserve exact engine limits rather than replacing them with guessed limits.

## 33. Source-reference matrix

| Rule | UT2003 source | UT2004 source |
|---|---|---|
| `.uz2` extension | `Core/Inc/Core.h / COMPRESSED_EXTENSION` | `Core/Inc/Core.h / COMPRESSED_EXTENSION` |
| compression/decompression modes | `Core/Inc/Core.h / ECopyCompress` | same |
| result codes | `Core/Inc/Core.h / ECopyResult` | same |
| 32768 source block | `Core/Inc/FFileManagerGeneric.h / COPYBLOCKSIZE` | same |
| 33096 compressed buffer/max | `Core/Inc/FFileManagerGeneric.h / MAXCOMPSIZE` | same |
| destination suffix append | `FFileManagerGeneric::Copy` | same |
| record compression | `FFileManagerGeneric::Copy / FILECOPY_Compress` | same |
| record decompression | `FFileManagerGeneric::Copy / FILECOPY_Decompress` | same |
| direct DWORD serialization | `FFileManagerGeneric::Copy` | n/a |
| explicit Intel-order DWORD serialization | n/a | `FFileManagerGeneric::Copy` |
| zlib `compress()` | `FFileManagerGeneric::Copy` | same |
| zlib `uncompress()` | `FFileManagerGeneric::Copy` | same |
| commandlet compression | `IpDrv/Src/UCompressCommandlet.cpp` | same |
| commandlet decompression | `IpDrv/Src/UCompressCommandlet.cpp` | same |
| HTTP appends compressed extension | `IpDrv/Src/HTTPDownload.cpp` | same |
| post-download decompression | `Engine/Src/UnDownload.cpp` | same |
| post-decode size/GUID validation | `Engine/Src/UnDownload.cpp` | same |

## 34. Revision conclusion

For the supplied PC-oriented UT2003 v2107 and UT2004 sources, UZ2 has the same practical on-disk record format:

`little-endian ComSize + little-endian UncSize + independent zlib stream`

repeated until EOF, with 32 KiB maximum uncompressed blocks.

The notable source-level revision difference is that UT2004 explicitly applies `INTEL_ORDER32` around the size fields, while the supplied UT2003 implementation serializes its DWORDs directly. This does not justify creating two different PC UZ2 parsers; it does require preserving the source distinction in the specification.
