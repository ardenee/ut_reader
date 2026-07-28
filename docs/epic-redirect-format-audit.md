# Epic redirect format source audit

This document records the engine-defined redirect formats used by UnrealDB's production decoder. Format selection is based on the wrapper extension; numeric values such as `5678` are not sufficient because they have different meanings in historical UE1 `.uz` and UE3 `.uz3` files.

## UE1 `.uz`

Authoritative code reviewed:

- `ardenee/UT99src/Core/Inc/FCodec.h`
- UnrealDB: `catalog/lib/CatalogLegacyUz.php`

The normal UCC wrapper starts with little-endian signature `1234`, serializes the original filename, and decodes through Epic's FCodec stages in reverse order:

1. Huffman
2. Move-to-front
3. Burrows-Wheeler transform
4. Run-length encoding

A historical UE1 NewVer wrapper uses signature `5678` and adds an RLE stage between Huffman and MTF. It remains a `.uz` FCodec wrapper and must not be treated as UE3 `.uz3`.

## UE2 `.uz2`

Authoritative code reviewed:

- `ardenee/UT2004src/IpDrv/Src/UCompressCommandlet.cpp`
- `ardenee/UT2004src/Core/Inc/FFileManagerGeneric.h`
- UnrealDB: `catalog/src/Infrastructure/Jobs/CatalogRedirectArchiveStream.php`

The commandlet delegates to `FILECOPY_Compress`/`FILECOPY_Decompress`. The file manager uses:

- 32,768-byte source blocks
- maximum compressed record size 33,096 bytes
- little-endian compressed size
- little-endian uncompressed size
- one zlib `compress()` payload per record
- zlib `uncompress()` for every record

Raw deflate, gzip and verbatim equal-size records are not UZ2 formats. Equal compressed and uncompressed sizes still contain a zlib stream.

## UE3 `.uz3`

UnrealDB production layout:

- little-endian tag `5678` (`0x162E`)
- little-endian total uncompressed file size
- one zlib `compress()` stream containing the complete file
- zlib `uncompress()` with an exact output-size check

Unlike UE1 `.uz`, UZ3 has no serialized original filename and does not use the FCodec Huffman/RLE/MTF/BWT chain. The output name is the wrapper filename with `.uz3` removed.

The private `ardenee/UE3src` repository was supplied for the audit, but its code-search index was unavailable during this review. The layout above was checked against the established UE3 UZ3 format documentation and locked into executable regression tests. The direct UE3 commandlet source path should be added here when the private repository index becomes available.

## UE4

UE4 does not introduce a fourth redirect wrapper handled by this decoder. UE4/UE5 distribution in UnrealDB uses container/package handling rather than a `.uz4` format.

## Production rules

- `.uz` invokes only the UE1 FCodec decoder.
- `.uz2` invokes only the UE2 record-zlib decoder.
- `.uz3` invokes only the UE3 tagged whole-file-zlib decoder.
- Production dispatch does not guess another format after an exact decoder rejects a wrapper.
- Raw-deflate and gzip compatibility decoding is not permitted for UZ2 or UZ3.
