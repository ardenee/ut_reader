# UE5-D035 — IoStore TOC trailing bytes rejected beyond Epic

## Difference

UnrealDB's `Uedb5IoStoreTocReader::parse()` rejected a structurally valid UE5.8.3 TOC whenever bytes remained after the known metadata table.

Epic UE5.8.3 does not impose that end-of-file condition. `FIoStoreTocResourceView::Read` consumes the sections defined by the current TOC version and then calls `FIoStoreTocResourceStorage::FinalizeRead()`; `FinalizeRead()` closes/releases the backing read handle and does not test that the cursor reached EOF.

Therefore the old UnrealDB rejection was an extra format gate not present in the pinned Epic loader.

## Pinned Epic source

- `Engine/Source/Runtime/Core/Internal/IO/IoStore.h` — SHA256 `58f0183013943732f7f948291edffe7113fc1e047dd72619874b56867c6a10c4`
- `Engine/Source/Runtime/Core/Private/IO/IoStore.cpp` — SHA256 `74746e575cfa0719d4fec38682a90d2dbc58d6f0c1c7877b161aafd4d57e38ed`
- `Engine/Source/Runtime/Core/Internal/IO/IoOffsetLength.h` — SHA256 `3fc4a22e30a854efed84a9f4ec25dea68d126927265369cdf0e01def7da431ca`
- `Engine/Source/Runtime/Core/Public/IO/IoChunkId.h` — SHA256 `64ebf25a5cf436e1cc353a8fefd16bbb231ef28ff914a4ef6296e27b20b30b1`

The relevant Epic read path is `FIoStoreTocResourceView::Read` followed by `FIoStoreTocResourceStorage::FinalizeRead`.

## UnrealDB correction

The terminal `$reader->remaining() !== 0` rejection was removed from:

- `catalog/src/Infrastructure/Metadata/Uedb5IoStoreTocReader.php`

All existing field, version, size, table-bound, compression-method, AES and chunk-reconstruction validation remains in place.

## Negative-before / positive-after

`catalog/bin/verify-uedb5-ue5-iostore-foundation.php` now appends `EXT` after an otherwise valid synthetic version-8 TOC metadata table and verifies that:

- the TOC still parses as version 8;
- all three chunk records remain intact; and
- the main package chunk still reconstructs exactly.

Before the reader correction, this single new assertion failed while the other 33 foundation assertions passed. After the correction, the foundation suite passes **34/34**.

The affected B001/B002 implementation fingerprints were refreshed only after their operation-level acceptance drivers passed. B006's source/header prerequisite now incorporates the 34-check foundation fixture.

## Scope boundary

This correction establishes Epic-equivalent treatment of unconsumed bytes after the known UE5.8.3 TOC sections. It does not claim semantic support for an unknown future TOC version: UnrealDB still enforces Epic's UE5.8.3 `DirectoryIndex <= Version <= Latest` gate. It also does not certify all IoStore semantics; the finite UE5.8.3 source denominator tracks the remaining TOC/chunk semantic review separately.
