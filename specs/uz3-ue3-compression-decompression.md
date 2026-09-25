# UZ3 / UE3 Redirect Compression and Decompression

## 1. Scope

This specification distinguishes two different UE3-era compressed transport formats that both use the numeric value **5678**:

1. **UT3 commandlet `.uz3`** — confirmed by retail UT3 interoperability testing:
   - little-endian DWORD `5678`;
   - little-endian DWORD total uncompressed size;
   - one zlib stream containing the complete original file;
   - no serialized original filename.
2. **UE3 FCodec `5678` network transport path** — source-proven in `UnDownload.cpp`:
   - serialized INT `5678`;
   - serialized `FString OrigFilename`;
   - `RLE -> BWT -> MTF -> RLE -> Huffman` FCodec data.

These are different containers and must not be conflated merely because both begin with 5678.

Authoritative evidence:

- `ardenee/UE3src` source for the FCodec transport path.
- Retail UT3 v3809 `IpDrv.CompressCommandlet` / `Decompress` interoperability results recorded in `ardenee/UE3src/README.md` for the actual `.uz3` commandlet wrapper.

### Confirmed UT3 `.uz3` layout

```text
Offset  Size  Field
0x00    4     little-endian DWORD tag = 5678
0x04    4     little-endian DWORD total uncompressed size
0x08    ...   one zlib stream to EOF
```

Decoder requirements:

1. require at least 8 bytes;
2. require tag 5678;
3. read declared total uncompressed size;
4. zlib-decompress all remaining bytes as one stream;
5. require decoded size to match exactly;
6. reject trailing/non-zlib data.

The output filename is inferred by removing `.uz3`; there is no serialized filename field in this wrapper.

### Confirmed interoperability evidence

Retail UT3 v3809 generated a `.uz3` for `WarrenTemp.upk` with:
- original size 398643 bytes;
- header `2E 16 00 00 33 15 06 00`;
- zlib payload that reproduced the original byte-for-byte.

The reverse test also succeeded: UT3 `Decompress` accepted an UnrealDB-generated wrapper and reproduced the original MD5 exactly.

## 2. UE3 FCodec 5678 transport path

The supplied UE3 source also contains a separate network transport decoder using signature 5678, serialized `FString OrigFilename`, and the FCodec chain:

`RLE -> BWT -> MTF -> RLE -> Huffman`

This path is **not** the UT3 commandlet `.uz3` wrapper. The detailed FCodec sections below document this separate transport format and remain useful for source compatibility.

## 3. Cross-version result

The compressed-download reader was compared across multiple supplied UE3 revisions.

The reviewed snapshots retain the same essential container and decoder contract:

1. deserialize an `INT Signature`;
2. require `Signature == 5678`;
3. deserialize an `FString OrigFilename`;
4. construct `FCodecFull`;
5. register:
   - `FCodecRLE`
   - `FCodecBWT`
   - `FCodecMTF`
   - `FCodecRLE`
   - `FCodecHuffman`
6. call `Codec.Decode()`.

No later reviewed snapshot replaces this with the UT2003/UT2004 32 KiB zlib-block UZ2 algorithm.

This is an important engine-generation difference.

## 4. Container layout

The source-proven UE3 compressed stream begins:

| Field | Encoding | Meaning |
|---|---|---|
| Signature | Unreal serialized INT | must equal decimal 5678 |
| OrigFilename | Unreal serialized FString | original filename |
| CodecData | remainder of file | output of the fixed FCodecFull encoder chain |

Conceptually:

`[INT 5678][FString original filename][encoded stream to EOF]`

There is no additional source-proven version field between the filename and codec data.

## 5. Signature

The decoder explicitly checks:

`Signature != 5678`

and treats a mismatch as a download/size error.

Thus **5678 is source-proven for this UE3 compressed redirect container**.

This corrects the uncertainty recorded while documenting the earlier UZ source: the supplied UE3 source directly proves the 5678 wrapper on its own compressed-download path.

It does not by itself prove which earlier UE1 revisions used the same wrapper.

UnrealDB must therefore associate the proof with this UE3 path and only broaden it to UE1 where separate source/sample evidence supports doing so.

## 6. Original filename

Immediately after a valid 5678 signature, the engine deserializes:

`FString OrigFilename`

The runtime download decoder does not use that value to select the final cache filename; package metadata determines the destination.

Nevertheless it is a real serialized field and must be parsed to locate the codec payload correctly.

A decoder that assumes compressed data begins immediately after the four-byte signature is incorrect.

## 7. FString encoding

`OrigFilename` uses the engine's ordinary archive `FString` serialization for the relevant UE3 revision.

It is not a fixed-size C string and is not merely bytes terminated by zero without a serialized length.

UnrealDB must use the matching Unreal string serializer rather than scanning heuristically for a terminator.

The string serialization details belong to the shared primitive/string serialization specification; this document requires using that exact serializer.

## 8. Exact compression chain

The source decoder registers codecs in this order:

1. `FCodecRLE`
2. `FCodecBWT`
3. `FCodecMTF`
4. `FCodecRLE`
5. `FCodecHuffman`

`FCodecFull::Encode` applies codecs from first to last.

Therefore the source-defined compression transformation is:

`input -> RLE1 -> BWT -> MTF -> RLE2 -> Huffman -> output`

`FCodecFull::Decode` traverses the registered list backward.

Therefore decompression is:

`input -> Huffman -> RLE2 -> MTF -> BWT -> RLE1 -> output`

The duplicate RLE stages are intentional and must both be present.

## 9. BWT stage

`FCodecBWT` defines:

`MAX_BUFFER_SIZE = 0x40000`

or 262,144 bytes.

The encoder processes its stage input in blocks no larger than that value.

For each BWT block it serializes:

1. `CompressLength` as INT;
2. `First` as INT;
3. `Last` as INT;
4. `CompressLength + 1` transformed bytes.

The extra byte is part of the transform's sentinel construction.

## 10. BWT decoding

The decoder reads:

- `DecompressLength`
- `First`
- `Last`

It validates the length against the BWT buffer/source bounds through engine checks, increments the length, then reads exactly that many transformed bytes.

The position identified by `Last` is treated as synthetic symbol 256 while reconstructing the permutation.

Starting from `First`, the decoder follows the permutation and emits `DecompressLength - 1` original bytes.

UnrealDB must preserve the synthetic sentinel semantics and the encoded +1 byte.

## 11. First RLE stage

`FCodecRLE` defines:

`RLE_LEAD = 5`

Encoding emits up to five literal copies of a repeated byte.

For runs reaching at least five bytes, a following byte stores the run count.

Run count is byte-sized; the encoder starts a new run when the count reaches 255.

The first RLE pass operates on the original source before BWT.

## 12. Second RLE stage

The exact same RLE codec is registered again after MTF.

This second pass compresses the MTF output before Huffman coding.

There is no marker separating the two conceptual RLE stages in the final file because `FCodecFull` materializes intermediate data internally and only the final Huffman output reaches the container archive.

A decoder must reverse both stages.

## 13. RLE decoding

The decoder writes each literal byte.

When five equal consecutive bytes have been observed, it consumes the next byte as the encoded run count.

The source asserts that the count is at least 2, then emits the additional copies required by that count.

Malformed input that reaches the five-byte trigger without a count byte is truncated.

## 14. MTF stage

The move-to-front list is initialized as the identity sequence 0 through 255.

Encoding:

1. find the input byte's current position;
2. emit that position as one byte;
3. move the selected value to list position zero.

Decoding:

1. interpret the encoded byte as a list position;
2. emit the value at that position;
3. move that value to position zero.

The list is freshly initialized for each invocation of the MTF codec.

## 15. Huffman stage

The Huffman encoder:

1. records the current input position;
2. counts frequencies for all 256 byte values;
3. computes `Total`, the number of bytes to reconstruct;
4. seeks back to the original position;
5. serializes `Total` as INT;
6. builds the Huffman tree;
7. serializes the tree bitwise;
8. serializes encoded symbol bits.

Unused zero-frequency leaves are removed before tree construction.

## 16. Huffman tree serialization

For each node the bit writer stores one bit indicating whether the node has children.

For a branch, both children are serialized recursively.

For a leaf, the byte symbol is serialized.

The encoded symbol bitstream follows the serialized tree.

The complete Huffman stage consumes the remainder of the compressed container payload.

## 17. Huffman decoding

The decoder:

1. reads `Total`;
2. copies all remaining stage bytes into a bit reader;
3. reconstructs the Huffman tree recursively;
4. traverses the tree according to encoded bits;
5. emits symbols until exactly `Total` bytes have been reconstructed.

This output becomes the input to the second RLE decoder in the reverse chain.

## 18. WITH_UE3_NETWORKING

In the reviewed UE3 `FCodec.h`, Huffman table/bitstream implementation is guarded by `WITH_UE3_NETWORKING`.

The compressed redirect download path itself is part of UE3 networking.

For UnrealDB's static decoder, this compile-time engine feature flag does not become a byte-stream field. There is no serialized boolean indicating whether Huffman is present.

A stream produced for this source-defined network format uses the fixed codec chain above.

## 19. FCodecFull intermediate behavior

`FCodecFull` uses memory readers/writers for intermediate codec stages.

Only the first encoder reads the caller's input archive, and only the last encoder writes the caller's output archive.

During decoding, the last registered codec reads the compressed archive and the first registered codec writes the final uncompressed archive.

This explains why individual codec stages do not need wrapper boundaries in the final file.

## 20. Compression writer requirements

Although the reviewed source body exposes the authoritative decoder path more directly than a native commandlet implementation body, the inverse stream is fully defined by the codec classes and `FCodecFull::Encode`.

A source-compatible writer for this container must write:

1. serialized INT value 5678;
2. serialized FString containing the original filename;
3. FCodecFull output using the registered order:
   `RLE, BWT, MTF, RLE, Huffman`.

Do not substitute zlib, raw deflate, gzip, LZO, or package-level UE3 compression.

## 21. Decompression sequence

A source-compatible decoder must:

1. deserialize the signature;
2. require decimal 5678;
3. deserialize the original filename FString;
4. take the remaining bytes as FCodecFull input;
5. decode Huffman;
6. decode RLE;
7. decode MTF;
8. decode BWT;
9. decode RLE;
10. write the resulting original bytes.

Only after successful completion should UnrealDB attempt package identification/parsing.

## 22. Runtime post-decompression validation

After compressed download decoding, the UE3 runtime compares the resulting temporary file size with the expected package `FileSize`.

A mismatch becomes a network size error.

This expected size is supplied by package/network metadata; it is not serialized as a dedicated total-size field in the compressed wrapper shown here.

Therefore:

- codec/container validation and
- expected package-size validation

are distinct operations.

## 23. HTTP naming behavior

The UE3 HTTP downloader constructs the normal package filename from:

`PackageName + "." + Extension`

When compression is enabled, it appends `COMPRESSED_EXTENSION`.

In every reviewed supplied UE3 snapshot, that constant is `.uz2`.

This directly conflicts with assuming that UE3 source necessarily requests `.uz3`.

UnrealDB must keep the external suffix configurable/identifiable separately from the 5678 codec stream until authoritative UT3 native source establishes the `.uz3` convention.

## 24. Why extension alone cannot identify this byte format

The supplied evidence demonstrates that engine-generation labels and filename suffixes are not enough:

- UT2003/UT2004 source defines `.uz2` and uses 32 KiB independent zlib records.
- reviewed UE3 source also defines `.uz2`, but its compressed network download decoder expects 5678 + FString + the FCodec chain.

Thus two source lineages can use the same textual suffix while expecting materially different bytes.

UnrealDB identification must use structural evidence and game/engine context, not extension alone.

## 25. Relationship to the UZ2 spec

The previously documented UT2003/UT2004 UZ2 layout is:

`[ComSize][UncSize][zlib payload] ... EOF`

The UE3 compressed download format documented here is:

`[5678][FString filename][Huffman-wrapped reverse codec stream]`

They are not compatible.

A failure to parse one format must not automatically cause blind fallback to the other. Selection must be based on known game/source context or strong structural identification.

## 26. Relationship to legacy UZ

The codec primitives and chain belong to the long-lived Epic `FCodec` framework.

However this UE3 source provides direct proof of the 5678 wrapper and exact five-codec chain only for this UE3 download path.

It must not be used to retroactively claim that every UE1 `.uz` writer had the identical wrapper without UE1-specific proof.

Shared code is evidence of shared codec algorithms, not automatically evidence of identical outer container behavior in every revision.

## 27. UT3 commandlet `.uz3` versus UE3 FCodec transport

Retail UT3 interoperability testing now resolves the filename/container question for the commandlet path:

- actual UT3 commandlet `.uz3` = `[DWORD 5678][DWORD uncompressed size][single zlib stream]`;
- UE3 FCodec `5678` transport = `[INT 5678][FString original filename][FCodec payload]`.

They are separate formats despite the shared numeric tag.

The UE3 source's `.uz2` naming in the network-download path applies to that transport path and does not redefine the UT3 commandlet `.uz3` wrapper.

## 28. Structural validation

Before decoding, UnrealDB should require:

- enough bytes for the serialized INT;
- signature exactly 5678;
- a valid bounded FString;
- remaining codec payload;
- valid Huffman Total/tree/bitstream;
- valid RLE count structure;
- valid BWT block headers and bounds;
- BWT length within the source-defined maximum;
- enough bytes for each BWT transformed block;
- successful complete codec reconstruction.

Archive/check failures in the engine should become explicit parser errors rather than crashes/assertions.

## 29. Safety versus format rules

UnrealDB may impose operational limits to protect memory/CPU, particularly because BWT decompression and malformed Huffman metadata can be hostile inputs.

Such limits must be labeled UnrealDB safety policy.

They must not be presented as Epic format limits unless the source defines them.

The source-defined BWT maximum of 0x40000 bytes per BWT block is a genuine implementation/format constraint.

## 30. Filename handling

The embedded `OrigFilename` should be preserved as metadata.

It must not be trusted as an arbitrary filesystem destination path.

For catalogue processing, UnrealDB should decode to controlled temporary storage and treat the embedded name as serialized metadata, not as authority to escape the staging directory.

This is an UnrealDB security requirement, not an Epic format semantic.

## 31. Compression conformance

A generated stream should round-trip:

`source -> wrapper(5678, filename) -> RLE -> BWT -> MTF -> RLE -> Huffman -> reverse decode -> source`

The result must be byte-for-byte identical.

Where a matching engine build is available, an UnrealDB-generated stream should also be accepted by that engine's compressed-download decoder.

## 32. Decompression conformance

Test cases should include:

- valid small file;
- file larger than one 0x40000 BWT block after first-RLE transformation;
- repeated-byte-heavy data;
- all byte values;
- signature other than 5678;
- truncated FString;
- missing codec payload;
- malformed Huffman tree;
- Huffman Total larger than available bitstream can produce;
- truncated RLE count;
- BWT block over 0x40000;
- truncated BWT +1 sentinel payload;
- wrong First/Last values;
- decoded file whose size differs from external expected package size.

## 33. UnrealDB conformance requirements

UnrealDB must:

1. recognize the source-proven 5678 signature;
2. parse the following Unreal FString;
3. preserve the embedded original filename as metadata;
4. use exactly RLE -> BWT -> MTF -> RLE -> Huffman for encoding;
5. use exactly Huffman -> RLE -> MTF -> BWT -> RLE for decoding;
6. preserve BWT's 0x40000 maximum and +1 sentinel semantics;
7. preserve RLE_LEAD=5 behavior;
8. reset MTF to identity at invocation start;
9. honor Huffman Total;
10. reject malformed/truncated streams;
11. perform package parsing only after complete decompression;
12. keep post-decode expected-size/package validation separate;
13. never interpret this as UT2003/UT2004 zlib-block UZ2 solely because a supplied UE3 source constant says `.uz2`;
14. never claim the `.uz3` suffix itself is source-proven by the reviewed UE3 trees;
15. not invent a different UZ3 codec until source proves one.

## 34. Source-reference matrix

| Behavior | Source |
|---|---|
| compressed extension in reviewed UE3 trees is `.uz2` | each reviewed `Core/Inc/Core.h / COMPRESSED_EXTENSION` |
| HTTP appends compressed extension | `IpDrv/Src/HTTPDownload.cpp / UHTTPDownload::ReceiveFile` |
| signature field | `Engine/Src/UnDownload.cpp / UDownload::DownloadDone` |
| required signature 5678 | same |
| embedded OrigFilename FString | same |
| fixed codec registration order | same |
| reverse decode behavior | `Core/Inc/FCodec.h / FCodecFull::Decode` |
| forward encode behavior | `Core/Inc/FCodec.h / FCodecFull::Encode` |
| BWT 0x40000 maximum | `FCodecBWT::MAX_BUFFER_SIZE` |
| BWT header and +1 payload | `FCodecBWT::Encode/Decode` |
| RLE lead 5 | `FCodecRLE::RLE_LEAD` |
| MTF identity list | `FCodecMTF::Encode/Decode` |
| Huffman Total/tree/bitstream | `FCodecHuffman::Encode/Decode` |
| post-decode size validation | `Engine/Src/UnDownload.cpp / UDownload::DownloadDone` |

## 35. Revision evidence

The January 2008, March 2008, May 2009 and UDKUltimate source snapshots reviewed retain the same key decoder pattern: 5678 signature, FString filename, and the five-stage FCodec chain.

They also retain `COMPRESSED_EXTENSION ".uz2"`.

No reviewed later UE3 snapshot supplied evidence of a change to a native `.uz3` extension or to a different compressed redirect byte format.

This negative finding is important: UnrealDB should not manufacture a chronological “UZ2 became UZ3” format transition that the supplied source does not show.

## 36. Resolved UT3 `.uz3` boundary

The UT3 `.uz3` commandlet wrapper is no longer unresolved. Retail UT3 v3809 generation and reverse-decompression testing confirms the 8-byte header plus whole-file zlib stream described at the start of this document.

What remains separate is the UE3 FCodec network transport path, which also uses signature 5678 but serializes an original filename and uses the RLE/BWT/MTF/RLE/Huffman chain.

UnrealDB must identify these by their complete framing/context rather than by the 5678 value alone.
