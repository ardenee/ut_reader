# UPK Encryption Handling / Verification

## Scope and conclusion

This specification records the result of checking the supplied **UE3/UPK package implementation for a generic package-encryption layer**.

Primary authority:

- Repository: `ardenee/UE3src`
- Latest inspected UE3 tree: `Unreal Engine [v3.0] UDKUltimate [05-11-17]/UDKUltimate`
- Core source: `Development/Src/Core`

The source-backed conclusion is:

> **The inspected UE3 UPK package format does not define a generic UPK encryption field, encryption package flag, encryption algorithm selector, key field, IV field, encrypted-chunk table, or decrypt-before-linker stage.**

UPK package loading in this source is ordinary Unreal package serialization plus the separately documented compression mapping. Compression is not encryption.

This document is intentionally a **negative specification**: it defines what UnrealDB must *not invent* when handling UE3 UPK files.

It does not claim that no UE3 licensee, platform, distribution system, or individual game ever wrapped or transformed package data. Such behavior is only part of a supported format when the corresponding source proves it.

## Authoritative references

| Area | UE3 source |
|---|---|
| package flags | `Core/Inc/UnObjBas.h: EPackageFlags` |
| package summary structure | `Core/Inc/UnLinker.h: FPackageFileSummary` |
| package summary serialization | `Core/Src/UnLinker.cpp: operator<<(FPackageFileSummary&)` |
| package loading | `Core/Src/UnLinker.cpp: ULinkerLoad::SerializePackageFileSummary` |
| package compression activation | `Core/Src/UnLinker.cpp: ULinkerLoad::SerializePackageFileSummary` |
| archive interface | `Core/Inc/UnArc.h` |
| compression flags/codecs | `Core/Inc/UnFile.h`, `Core/Src/UnMisc.cpp` |
| compressed logical-file reader | `Core/Src/UnAsyncLoading.cpp: FArchiveAsync` |

The supplied `ardenee/UT3src-comunity` tree does not contain a native Core linker/package implementation that establishes a different UT3 UPK encryption format. It therefore cannot be used to add undocumented encryption behavior to the UE3 package reader.

## Package summary contains no generic encryption metadata

The inspected `FPackageFileSummary` contains the normal UE3 package metadata, including:

- package tag/version;
- header size;
- folder name;
- package flags;
- name/import/export/depends locations and counts;
- GUID/generation information;
- engine/cooked-content versions;
- `CompressionFlags`;
- `CompressedChunks`;
- `PackageSource`;
- version-dependent additional-package and texture-allocation information.

The package-summary serializer reads these fields directly.

There is no corresponding generic field for:

- encryption flags;
- encryption method;
- cipher identifier;
- key identifier;
- encryption key;
- IV/nonce;
- authentication tag;
- encrypted block/chunk descriptors;
- encrypted offset/size map.

A reader must not reinterpret an unrelated summary value as encryption metadata.

## EPackageFlags contains compression flags, not encryption flags

The latest inspected UE3 `EPackageFlags` includes, among others:

- `PKG_Cooked = 0x00000008`;
- `PKG_Unsecure = 0x00000010`;
- `PKG_StoreCompressed = 0x02000000`;
- `PKG_StoreFullyCompressed = 0x04000000`;
- `PKG_NoExportAllowed = 0x20000000`;
- `PKG_StrippedSource = 0x40000000`.

It does **not** define `PKG_Encrypted` or an equivalent generic package-encryption flag.

### PKG_Unsecure is not encryption

`PKG_Unsecure` is described by the source as:

> Not trusted.

It is not an encrypted-package marker and must not trigger decryption.

### PKG_NoExportAllowed is not encryption

`PKG_NoExportAllowed` identifies a package as not created by a modder and marks internal data as not for export.

It does not alter the package byte stream and must not trigger decryption.

### PKG_StrippedSource is not encryption

`PKG_StrippedSource` records that source has been removed to reduce package size.

It is not a cryptographic transform.

### PKG_Cooked is not encryption

Cooked packages may differ structurally/content-wise according to the target and cooking process, but `PKG_Cooked` itself does not specify encrypted bytes.

## Compression is the source-defined byte transformation

For `PKG_StoreCompressed`, `ULinkerLoad::SerializePackageFileSummary` explicitly installs the package's:

- `CompressedChunks`;
- `CompressionFlags`;

using `SetCompressionMap`.

If the original archive cannot perform that mapping, the loader replaces it with `FArchiveAsync`, which understands compressed package ranges.

The source does not perform an analogous:

- `SetEncryptionMap`;
- `SetDecryptionKey`;
- decrypting archive replacement;
- cipher dispatch;

before reading the package tables.

Therefore compressed UPKs must be handled according to `specs/upk-compression-handling.md`, not through an inferred encryption stage.

## Normal package loading expects readable package bytes

`FPackageFileSummary` begins by serializing `Sum.Tag`.

It proceeds only when the value is:

- `PACKAGE_FILE_TAG = 0x9E2A83C1`, or
- `PACKAGE_FILE_TAG_SWAPPED = 0xC1832A9E`.

The swapped value is byte-order support, not encryption.

After summary parsing, `ULinkerLoad::SerializePackageFileSummary` validates the normalized package tag and then continues normal package parsing.

There is no generic source path that receives arbitrary encrypted initial bytes, discovers a key/cipher, decrypts them, and then exposes a package tag.

Accordingly, a file whose first bytes do not satisfy the applicable package/container format must not be labelled "encrypted UPK" merely because its magic cannot be read.

## Byte swapping is not encryption

The source explicitly supports opposite-endian packages.

When `PACKAGE_FILE_TAG_SWAPPED` is encountered, the archive toggles forced byte swapping and normalizes the tag.

This is deterministic integer byte-order conversion.

It must never be described or implemented as decryption.

## PackageSource is not an encryption selector

`FPackageFileSummary::PackageSource` is documented as a value used to determine whether the package was saved by Epic/licensee or a modder/etc.

It follows `CompressedChunks` in summary serialization.

There is no source evidence that `PackageSource` selects a cipher, supplies a key, or signals encrypted payload.

UnrealDB must preserve/report it according to the package specification but must not derive cryptographic behavior from it.

## SHA verification is not encryption

The Core source includes SHA/hash verification facilities.

Hashing and verification can establish integrity/authenticity expectations, but they do not make the UPK byte stream encrypted and they do not provide a decryption algorithm.

A package failing an integrity check is not thereby evidence of encrypted package data.

## Fully compressed packages are not encrypted packages

`PKG_StoreFullyCompressed` is source-described as a package serialized normally and then fully compressed, requiring decompression before `LoadPackage` is called.

This is a compression/container-state distinction.

It does not define encryption.

A reader encountering this mode must investigate and implement its source-defined **compression** wrapper separately. It must not route it to an encryption handler.

## No generic cipher can be inferred from later engines

UE4/UT4 and their PAK/container ecosystem must not be used to retrofit encryption into UE3 UPK.

If a later container supports AES or another encryption system, that proves only the applicable later container/version unless the UE3/UT3 source independently establishes the same behavior.

Similarly, a UE3 game/licensee implementation cannot automatically be applied to every UE3 package.

## Game/platform-specific extensions

A game or platform may introduce behavior outside the generic Core package format.

For UnrealDB, such a transform is admissible only when there is source-backed evidence establishing all necessary identification and decoding rules, including as applicable:

1. the exact game/engine revision;
2. the exact file/container type;
3. how the transformed file is identified;
4. whether the package header itself is transformed;
5. which byte ranges are transformed;
6. algorithm and mode;
7. key derivation or key selection;
8. IV/nonce rules;
9. block alignment/padding;
10. order relative to compression;
11. integrity/authentication rules;
12. version/platform conditions.

Without those facts, automatic decryption would be heuristic rather than format parsing.

## Identification rule

For the generic UE3 UPK reader:

1. inspect the package/container according to its applicable source-defined format;
2. recognize normal or swapped UE package magic where that format requires it;
3. parse the UE3 package summary;
4. apply source-defined compression when the package flags and compression map require it;
5. do **not** run a decryption probe when parsing fails;
6. report the actual structural failure.

Examples of valid structural outcomes include:

- invalid package magic;
- unsupported/newer package version;
- truncated summary;
- invalid table offset/count;
- invalid compression map;
- unsupported compression codec;
- compressed-data corruption.

"Encrypted" is not a generic fallback classification for an otherwise unrecognized UPK.

## No trial-decryption heuristics

The reader must not:

- XOR bytes looking for `0x9E2A83C1`;
- try common AES keys;
- try zero keys/zero IVs;
- derive keys from filenames or GUIDs;
- brute-force short keys;
- try game keys merely because the extension is `.upk`;
- decrypt until a plausible package magic appears;
- treat high entropy as proof of encryption.

Such techniques can create false-positive package identification and depart from the official format.

## Extension does not prove format or encryption

A `.upk` filename is not sufficient evidence that arbitrary bytes are a generic UE3 package, nor that unreadable bytes are an encrypted UE3 package.

Identification must come from the source-defined structure.

This is especially important for UnrealDB because it catalogs files from multiple games and engine revisions. The reader must not repair a failed identification by borrowing assumptions from another engine/game.

## Relationship to compression

The correct generic processing order is:

```text
physical UE3 package
    |
    +-- read source-defined package summary
    |
    +-- if PKG_StoreCompressed:
    |      install CompressionFlags + CompressedChunks mapping
    |      decompress requested logical ranges
    |
    +-- parse names/imports/exports/dependencies in logical package space
```

There is no generic source-backed UPK stage:

```text
decrypt -> package summary
```

and no generic source-backed stage:

```text
package summary -> decrypt exports
```

in the inspected UE3 Core package loader.

## UnrealDB implementation contract

The generic UE3/UPK implementation should therefore:

1. have **no generic UPK decryption step**;
2. have **no generic UPK encryption flag**;
3. have **no guessed UPK encryption key registry**;
4. not treat `PKG_Unsecure` as encrypted;
5. not treat `PKG_NoExportAllowed` as encrypted;
6. not treat `PKG_StrippedSource` as encrypted;
7. not treat `PKG_Cooked` as encrypted;
8. not treat `PKG_StoreCompressed` or `PKG_StoreFullyCompressed` as encrypted;
9. not treat byte swapping as encryption;
10. not treat `PackageSource` as a cipher selector;
11. not treat SHA/hash verification as encryption;
12. not infer encryption from missing/invalid magic;
13. not trial-decrypt unknown data;
14. keep game/platform-specific transforms outside the generic UE3 reader unless their applicable source proves them;
15. preserve an explicit distinction between **unsupported/unknown wrapper** and **encrypted**, when the latter has not been proven.

## Error/reporting requirements

When the generic reader cannot parse a file, diagnostics should describe the proven condition.

Prefer:

- `invalid UE3 package tag`;
- `truncated UE3 package summary`;
- `unsupported UE3 package version`;
- `invalid compressed chunk map`;
- `unsupported UE3 compression codec`;
- `compressed chunk framing invalid`;
- `unknown outer/container format`.

Do not report:

- `encrypted package`;
- `encryption unsupported`;
- `wrong encryption key`;

unless a source-defined encrypted format was positively identified first.

## Source-reference matrix

| Rule | Proving source/symbol |
|---|---|
| package flags have no generic encrypted flag | `Core/Inc/UnObjBas.h: EPackageFlags` |
| `PKG_Unsecure` means not trusted | `Core/Inc/UnObjBas.h: EPackageFlags` |
| compressed and fully-compressed flags | `Core/Inc/UnObjBas.h: EPackageFlags` |
| summary contains compression metadata | `Core/Inc/UnLinker.h: FPackageFileSummary` |
| exact summary serialization | `Core/Src/UnLinker.cpp: operator<<(FPackageFileSummary&)` |
| package starts from normal/swapped magic | `Core/Src/UnLinker.cpp: operator<<(FPackageFileSummary&)` |
| byte swapping behavior | `Core/Src/UnLinker.cpp: operator<<(FPackageFileSummary&)` |
| compressed packages install compression map | `Core/Src/UnLinker.cpp: ULinkerLoad::SerializePackageFileSummary` |
| archive exposes compression-map hook, not generic encryption-map hook | `Core/Inc/UnArc.h: FArchive::SetCompressionMap` |
| compression algorithms are separate | `Core/Inc/UnFile.h: ECompressionFlags` |
| package compressed reads | `Core/Src/UnAsyncLoading.cpp: FArchiveAsync` |

## Result

The inspected official UE3 package implementation provides **compression but no generic UPK encryption format**.

That absence is itself part of the reader contract. UnrealDB must not invent encryption semantics to explain malformed, wrapped, unsupported, or game-specific data.

If an applicable game/platform source later proves an encrypted UE3-derived package or wrapper, it must receive its own narrowly scoped specification and identification path rather than changing the generic UPK parser.

## Next specification

The next target should be **UE3 fully-compressed package handling (`PKG_StoreFullyCompressed`)**. It is explicitly distinct from `PKG_StoreCompressed`: the source describes the package as being serialized normally and then fully compressed before loading, so its outer framing/decompression path should be documented independently before implementing or classifying such files.
