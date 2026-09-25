# UZ Redirect Compression and Decompression

## Scope

This specification documents the Unreal Engine redirect/file-copy compression format whose conventional UE1 extension is `.uz`.

The format is not documented here by assuming that every Unreal generation used the same redirect encoding. The supplied source revisions were compared independently.

The most important result of that comparison is that the later UE2 redirect implementation is **not the same format** as the older codec framework exposed by the UE1 source. UT2003/UT2004 use the separately named `.uz2` format and are documented separately.

Authoritative source reviewed for this specification:

- `ardenee/UT99src` — Unreal Tournament retail v1.400 source tree.
- `ardenee/UT99src-ext` — later Unreal Tournament public-source lineage (repository description identifies v432).
  - `Core/Inc/FCodec.h`
- `ardenee/Unreal_Tournament_2003_v2107` — used only as a cross-generation boundary check.
  - `Core/Inc/FCodec.h`
  - `IpDrv/Src/UCompressCommandlet.cpp`
- `ardenee/UT2004src` — used only as a cross-generation boundary check.
  - `Core/Inc/FCodec.h`
  - `Core/Inc/FFileManagerGeneric.h`
  - `Core/Inc/Core.h`
  - `IpDrv/Src/UCompressCommandlet.cpp`

## Critical format boundary

Do not identify `.uz` and `.uz2` as the same wire format.

The UT2004 source explicitly defines:

`COMPRESSED_EXTENSION = ".uz2"`

and its file manager compresses independent 32 KiB blocks using zlib, each preceded by compressed-size and uncompressed-size DWORDs.

That is source proof for **UZ2**, not for UE1 UZ.

The existence of the same legacy `FCodec` classes in the UT2003/UT2004 trees does not prove that `.uz2` uses those codecs: the actual `FILECOPY_Compress` implementation proves that UZ2 uses zlib block records.

Accordingly UnrealDB must route `.uz` and `.uz2` independently.

## UE1 codec pipeline

The later supplied UT99 public-source lineage contains Epic's `FCodec.h` compression framework.

It defines these reversible codecs:

- `FCodecBWT`
- `FCodecRLE`
- `FCodecHuffman`
- `FCodecMTF`
- `FCodecFull`

`FCodecFull` is a codec chain. Encoding applies registered codecs from first to last. Decoding applies the exact same registered codecs in reverse order.

Therefore any UE1 UZ implementation using this framework must reverse the encoder pipeline exactly. A decoder may not independently reorder stages.

## BWT stage

`FCodecBWT` uses a maximum source block size of:

`0x40000 = 262144 bytes`

For each source block, the encoder writes:

1. CompressLength — INT
2. First — INT
3. Last — INT
4. exactly CompressLength + 1 transformed bytes

The encoder sorts suffix positions from 0 through CompressLength inclusive. `First` records the sorted position whose source position is 1; `Last` records the sorted position whose source position is 0.

The transformed byte emitted for a sorted source position is the byte immediately preceding that position, with the position-zero sentinel case represented through the separately recorded Last index.

### BWT decode

The decoder reads:

- DecompressLength
- First
- Last

It checks the block against its maximum and available input, then reads `DecompressLength + 1` transformed bytes.

The Last position is treated as synthetic symbol 256 while reconstructing the sorted mapping. The decoder follows the resulting permutation beginning at First and emits exactly the original DecompressLength bytes.

UnrealDB must preserve the +1 sentinel behavior. Treating the transformed payload as exactly DecompressLength bytes is incorrect.

## RLE stage

`FCodecRLE` uses:

`RLE_LEAD = 5`

The encoder writes up to five literal copies of a repeated byte.

When the run reaches at least five bytes, a following byte stores the run count.

Runs are capped at 255 before a new run is emitted.

### RLE decode

The decoder copies input bytes directly.

When five consecutive equal bytes have been observed, it reads one additional count byte.

The source asserts that this count is at least 2 and then emits enough additional copies to reach the encoded run length.

There is no independent RLE file header.

## Move-to-front stage

`FCodecMTF` initializes a 256-byte list with values 0 through 255.

For each input byte during encoding:

1. find its current list position;
2. output that position as one byte;
3. move the selected value to position zero.

Decoding performs the inverse:

1. read a byte as a list index;
2. output the value currently at that index;
3. move that value to position zero.

There is no independent MTF header.

## Huffman stage

`FCodecHuffman::Encode` first counts frequencies for all 256 byte values.

It writes the total uncompressed byte count as an INT before the Huffman bitstream.

Unused symbols are removed from the active tree.

The tree is built by repeatedly combining the least-frequent active nodes into a binary parent and reinserting that parent according to count.

### Huffman tree serialization

The tree is serialized bitwise:

- one bit indicates whether the node has children;
- a branch node recursively writes its two children;
- a leaf writes its byte value.

The encoded data bits follow the serialized tree.

The bitstream allocation is based on the calculated exact bit count, while the archive writes the resulting whole bytes.

### Huffman decode

The decoder first reads Total.

It then treats the entire remaining input as a bit reader, reconstructs the Huffman tree recursively, and walks the tree for each symbol until exactly Total output bytes have been produced.

This makes the Huffman stage naturally consume the remainder of the stage input.

## FCodecFull ordering

`FCodecFull::Encode` invokes codec 0, codec 1, and so on.

Intermediate results are stored in memory buffers.

`FCodecFull::Decode` begins at the last codec and walks backward.

For a registered encoder chain:

`A -> B -> C -> D`

the only source-compatible decoder chain is:

`D -> C -> B -> A`.

This is important for UnrealDB because detecting the individual transforms is not sufficient; their registered order is part of the UZ format behavior.

## Return-value convention

The legacy codec interface has an unusual return convention in the supplied source:

- the Encode methods generally return 0;
- Decode methods generally return 1.

Do not interpret those values as conventional false=failure / true=success without examining the caller. The format implementation should determine errors from the actual archive/validation contract used by the source path.

## No magic proven by FCodec.h

`FCodec.h` itself does **not** define a UZ magic signature or standalone wrapper header.

Its encoded stream consists of the structures emitted by the configured codec stages.

Therefore UnrealDB must not invent a UZ magic number based solely on this codec source.

If another exact UE1 compression caller writes a prefix/header before invoking the codec chain, that wrapper is authoritative. Until that writer is present/proven in supplied source, it must remain separately documented rather than inferred.

## The reported "5678" form

UnrealDB has encountered files historically associated with a `5678` UZ marker/form.

The reviewed `FCodec.h` source does not itself establish that marker or define its semantics.

Consequently this specification does **not** claim that `5678` is universally present, universally absent, or equivalent to the raw codec stream.

It must remain a separately verified UZ variant until an authoritative supplied source path proves the writer/reader behavior.

A production decoder may preserve an already source/sample-proven compatibility path, but that behavior must not be attributed to this source document without proof.

## Cross-version verification: UT2003

The supplied UT2003 v2107 tree retains substantially the same legacy codec classes:

- BWT maximum block 0x40000;
- RLE lead 5;
- Huffman format;
- MTF algorithm;
- FCodecFull forward encode/reverse decode.

However UT2003's redirect compression commandlet routes compression through `GFileManager->Copy(... FILECOPY_Compress ...)`.

This establishes that merely finding FCodec.h in a later engine does not prove the redirect format uses FCodecFull.

The UT2003 redirect format belongs under UZ2 and must be documented from its file-manager implementation.

## Cross-version verification: UT2004

UT2004 likewise retains the old codec framework, with small implementation-level changes that do not redefine its basic codec stream.

But the actual redirect/file-copy compressor in `FFileManagerGeneric.h` is zlib-based.

It defines:

- COPYBLOCKSIZE = 32768
- MAXCOMPSIZE = 33096
- COMPRESSED_EXTENSION = ".uz2"

For each block it writes:

1. ComSize as an Intel-order 32-bit value
2. UncSize as an Intel-order 32-bit value
3. ComSize bytes from zlib `compress()`

Decompression reverses that with zlib `uncompress()`.

This is conclusive evidence of a new redirect format generation and is the reason it must not be folded into UZ.

## Compression requirements for UE1 UZ

For the legacy UZ codec path, compression must reproduce the exact configured source codec sequence.

Within the individual codecs:

- BWT source blocks may be at most 0x40000 bytes;
- BWT writes three INTs plus length+1 transformed bytes per block;
- RLE uses a five-byte lead and one-byte run count;
- MTF starts with identity list 0..255 for each codec invocation;
- Huffman writes total decoded length, tree, then encoded symbols;
- FCodecFull resets intermediate buffers between stages.

A compressor that merely produces data decompressible by a different algorithm is not source-compatible UZ.

## Decompression requirements for UE1 UZ

Decompression must:

1. establish the exact UZ wrapper/variant before selecting a codec pipeline;
2. apply the source codec chain in reverse order;
3. honor Huffman's declared Total output count;
4. reset the MTF list to 0..255 at the start of the MTF decode;
5. interpret RLE's fifth repeated byte as the trigger for the following run-count byte;
6. parse each BWT block as three INTs plus DecompressLength+1 bytes;
7. honor the BWT synthetic sentinel at Last;
8. reject structurally impossible block lengths instead of reading beyond the input;
9. return the decoded bytes unchanged to the normal Unreal package/archive reader.

The decompressor must not attempt to interpret Unreal package metadata until redirect decompression has completed.

## File naming

The conventional UE1 redirect representation appends `.uz` to the original filename, e.g. a package filename is represented as the original name plus the redirect suffix.

The exact writer-side extension constant was not located in the supplied UT99 source body reviewed for this document. Therefore this naming rule should be treated as the known UE1 redirect convention/project input, while the byte-level codec statements above are directly source-proven.

UT2003/UT2004 source explicitly proves `.uz2`, which is separate.

## Validation

Source-compatible validation should be structural, not heuristic.

For the codec stream this includes:

- sufficient bytes for every required integer/header;
- BWT length not exceeding the source maximum;
- BWT transformed payload containing length+1 bytes;
- valid BWT First/Last traversal;
- RLE count byte available when the five-byte lead is reached;
- valid MTF indices, inherently 0..255;
- a complete Huffman tree;
- enough Huffman bits to produce Total bytes;
- archive errors propagated rather than silently accepting truncation.

Do not impose unrelated package-size or output-size limits and call them UZ format rules.

Operational resource limits can exist for UnrealDB, but they must be explicitly distinguished from Epic format validation.

## Compression/decompression symmetry

A useful conformance test is exact round-trip behavior:

`source -> UE1 UZ encoder -> UE1 UZ decoder -> source`

The decoded byte stream must be byte-for-byte identical to the input.

For validation against real engine behavior, an UnrealDB-produced compressed file should additionally be accepted by the matching engine's decompressor where that exact compression entry point is available.

The reverse should also hold: engine-produced UZ files must decode byte-for-byte in UnrealDB.

## UnrealDB conformance requirements

UnrealDB must:

1. keep UZ, UZ2 and UZ3 as distinct formats;
2. identify UZ from proven wrapper/variant evidence rather than from UE package version;
3. implement BWT exactly, including 0x40000 blocks and the +1 sentinel byte;
4. implement RLE with RLE_LEAD=5;
5. implement MTF with an identity 256-byte starting list;
6. implement the serialized Huffman tree and Total count exactly;
7. reverse the registered encoder order for decoding;
8. reject truncated/impossible codec structures;
9. not reinterpret the decoded bytes until decompression succeeds;
10. not use UT2003/UT2004 zlib UZ2 behavior as a fallback for UZ;
11. not use a legacy FCodec stream as a fallback for UZ2 merely because FCodec.h still exists in those trees;
12. keep the 5678 form explicitly variant/unresolved until its exact source writer/reader is proven;
13. separate engine format limits from UnrealDB operational safety limits;
14. support compression as well as decompression using the same source-defined transforms.

## Source-reference matrix

| Behavior | Source |
|---|---|
| codec abstraction | UT99src-ext / Core/Inc/FCodec.h / FCodec |
| BWT block size | UT99src-ext / FCodecBWT::MAX_BUFFER_SIZE |
| BWT encoding layout | UT99src-ext / FCodecBWT::Encode |
| BWT inverse/sentinel | UT99src-ext / FCodecBWT::Decode |
| RLE lead and run representation | UT99src-ext / FCodecRLE |
| Huffman Total/tree/bitstream | UT99src-ext / FCodecHuffman |
| MTF transform | UT99src-ext / FCodecMTF |
| forward/reverse chain | UT99src-ext / FCodecFull |
| legacy codec retained in UE2-era source | UT2003 v2107 and UT2004 / Core/Inc/FCodec.h |
| UT2003 compression command routing | UT2003 / IpDrv/Src/UCompressCommandlet.cpp |
| UT2004 command routing | UT2004 / IpDrv/Src/UCompressCommandlet.cpp |
| UZ2 extension | UT2004 / Core/Inc/Core.h |
| UZ2 zlib block implementation | UT2004 / Core/Inc/FFileManagerGeneric.h |

## Unresolved source boundary

The supplied later UT99 public-source tree proves the codec implementations, but the exact UE1 command/file-manager caller that binds a particular codec registration order and any optional UZ wrapper was not present in the reviewed source paths.

That missing evidence is deliberately not filled from memory or third-party descriptions.

Before UnrealDB replaces an existing production UZ path solely from this specification, the exact wrapper and codec-registration order must be tied either to an authoritative supplied source body or to a separately documented, byte-verified compatibility corpus.

This restriction does not apply to UZ2: the supplied UT2003/UT2004 source exposes its compression command path, and UT2004 exposes the exact zlib block implementation.
