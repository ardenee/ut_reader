# PAK Encryption Handling

## Scope and authority

This specification documents encryption of Unreal Engine 4 PAK containers and PAK entries using the supplied Epic UE4 4.27.2 source.

Primary authority:

- repository: `ardenee/UnrealEngine4`
- branch: `UE4.27.2`
- `Engine/Source/Runtime/PakFile/Public/IPlatformFilePak.h`
- `Engine/Source/Runtime/PakFile/Private/IPlatformFilePak.cpp`
- `Engine/Source/Developer/PakFileUtilities/Private/PakFileUtilities.cpp`
- `Engine/Source/Runtime/Core/Public/Misc/AES.h`
- `Engine/Source/Runtime/Core/Private/Misc/AES.cpp`

This specification builds on `pak-format.md` and `pak-compression-handling.md`.

PAK signing (`.sig` files / RSA signatures) is distinct from PAK encryption. Signing code appears in the same subsystem but must not be confused with AES payload/index encryption.

## Encryption layers

UE4 PAK has two independent encryption decisions:

1. **index encryption**, identified by `FPakInfo.bEncryptedIndex`;
2. **individual entry payload encryption**, identified by `FPakEntry::IsEncrypted()`.

A PAK can therefore have:

- neither encrypted;
- encrypted index but unencrypted entries;
- unencrypted index but encrypted entries;
- both encrypted index and encrypted entries.

Do not infer one from the other.

## Format history

Relevant PAK versions are:

```text
PakFile_Version_CompressionEncryption = 3
PakFile_Version_IndexEncryption       = 4
PakFile_Version_EncryptionKeyGuid     = 7
```

Version 3 introduced the entry encryption flag alongside compression metadata.

Version 4 made `bEncryptedIndex` meaningful.

Version 7 added `EncryptionKeyGuid` to `FPakInfo`.

When loading a version older than 4, the source forces:

```text
bEncryptedIndex = false
```

When loading a version older than 7, the source invalidates the key GUID.

These version rules are authoritative; a reader must not reinterpret bytes from older trailers as newer encryption metadata.

## Entry encryption flag

For PAK version >= 3, `FPakEntry` serializes one byte of flags after the hash.

The relevant bit is:

```text
Flag_Encrypted = 0x01
```

`FPakEntry::IsEncrypted()` tests that bit.

The encryption flag describes the contained file's payload bytes. It does not mean the PAK index itself is encrypted.

For PAK versions before 3, no entry encryption flag is serialized and the entry is not encrypted through this generic mechanism.

## Index encryption flag

`FPakInfo.bEncryptedIndex` is a uint8 serialized in the PAK trailer.

It controls decryption of the PAK index data.

The trailer itself is not encrypted; it must remain readable so the engine can obtain:

- magic;
- version;
- index offset;
- index size;
- index hash;
- index-encrypted flag;
- encryption key GUID where supported;
- compression method metadata.

Thus an encrypted-index PAK is still structurally identifiable from its plaintext trailer.

## Encryption key GUID

From version 7 onward, `FPakInfo` serializes:

```text
FGuid EncryptionKeyGuid
```

The source comment defines an empty GUID as meaning the embedded/legacy key path.

The GUID identifies which registered key should be used. The GUID is not the key itself.

UnrealDB must never treat the GUID bytes as AES key material.

## Key lookup

UE4's generic key lookup is:

```text
FPakPlatformFile::GetPakEncryptionKey
```

It first asks the registered encryption-key collection for the exact `EncryptionKeyGuid`.

If no registered key is found and the GUID is invalid/empty, the runtime can call the legacy:

```text
FCoreDelegates::GetPakEncryptionKeyDelegate()
```

If a valid GUID was requested but no matching key is registered, the runtime reports failure rather than trying unrelated keys.

This is an important conformance rule:

**a valid key GUID selects a key; it is not permission to try every known key.**

## Deferred mounting when a key is unavailable

The PAK platform layer can encounter an encrypted PAK whose required key has not yet been registered.

Such PAKs can remain in `PendingEncryptedPakFiles`.

`RegisterEncryptionKey(Guid, Key)`:

1. adds the key to the registered key set;
2. finds pending PAKs whose `EncryptionKeyGuid` equals that GUID;
3. attempts to mount those PAKs;
4. removes successfully handled matching pending records.

Therefore "required key unavailable" is a distinct state from "invalid PAK".

UnrealDB should likewise distinguish:

- valid encrypted PAK, key unavailable;
- key supplied but decryption/integrity failed;
- structurally corrupt PAK.

## Default cipher implementation

Unless a custom PAK encryption delegate is bound, the runtime uses:

```text
FAES::DecryptData
```

and UnrealPak writes using:

```text
FAES::EncryptData
```

The implementation is Rijndael/AES with:

```text
AES_KEYBITS = 256
AESBlockSize = 16
```

The `FAESKey` used by this path is 32 bytes.

The implementation processes each 16-byte block independently with the AES block primitive. No IV is serialized by the PAK format and this source does not apply CBC/CTR/GCM framing to generic PAK AES data.

Consequently the source-backed generic PAK algorithm is effectively AES-256 ECB-style block processing.

UnrealDB must not invent:

- an IV;
- nonce;
- salt;
- authentication tag;
- CBC chaining;
- CTR counter;
- GCM metadata;
- password-derived key scheme.

## AES alignment requirement

`FAES::EncryptData` and `FAES::DecryptData` require the byte count to be a multiple of:

```text
FAES::AESBlockSize = 16
```

The PAK writer therefore pads encrypted physical data to 16-byte boundaries.

The padding is physical storage padding. It does not change the logical contained-file size.

## Custom encryption delegate

Before using `FAES::DecryptData`, the runtime checks:

```text
FPakPlatformFile::GetPakCustomEncryptionDelegate()
```

If bound, the custom delegate receives:

- data pointer;
- data size;
- encryption key GUID.

That delegate replaces the default AES decryption operation for this path.

This is runtime/project-specific behavior.

A PAK does **not** serialize a field identifying which custom encryption algorithm a game bound at runtime.

Therefore UnrealDB cannot reconstruct a custom project encryption scheme from generic PAK bytes alone.

If exact game/project source proves a custom delegate implementation, that implementation may be supported specifically for that game/revision. Otherwise it must be reported as unresolved/unsupported, not guessed.

## Index encryption

When UnrealPak is creating a PAK:

```text
Info.bEncryptedIndex =
    MasterEncryptionKey && EncryptIndex
```

The command-line path recognizes:

```text
-encryptindex
```

Index encryption is therefore explicit producer policy.

The runtime reads `IndexSize` bytes from `IndexOffset`.

If `bEncryptedIndex` is set, it decrypts that buffer before parsing it.

## Index encryption padding

Because AES requires 16-byte blocks, the serialized encrypted index region is AES aligned.

The logical serialized index content can be shorter than its aligned encrypted storage buffer depending on writer construction, but the physical encrypted buffer passed to AES must be block aligned.

A reader must use the source-defined stored index size/layout and must not decrypt an arbitrary unaligned substring.

## Index hash ordering

`FPakFile::DecryptAndValidateIndex` does:

```text
if encrypted:
    decrypt index buffer

SHA1(decrypted index buffer)
compare with FPakInfo.IndexHash
```

Thus `FPakInfo.IndexHash` validates the **decrypted/plain index data**, not the encrypted ciphertext.

Correct index processing order is:

```text
read encrypted index
-> decrypt
-> SHA1 validate against IndexHash
-> parse index
```

Do not hash ciphertext and compare it with `IndexHash`.

## Wrong-key detection for the index

Generic AES encryption has no authentication tag.

For an encrypted index, the primary source-backed validation after decryption is the index SHA1 comparison.

A wrong key will normally produce plaintext whose SHA1 does not match `FPakInfo.IndexHash`.

Therefore UnrealDB should report a concrete state such as:

```text
index decryption/integrity failed
```

rather than automatically declaring the PAK structurally invalid before distinguishing key availability.

## Uncompressed encrypted entry creation

For an uncompressed encrypted file, UnrealPak:

1. obtains the original file size;
2. computes:
   ```text
   Align(FileSize, 16)
   ```
3. fills trailing bytes up to that aligned size;
4. encrypts the aligned buffer;
5. writes the aligned encrypted bytes;
6. retains the logical `FPakEntry.Size` / `UncompressedSize` semantics from the original entry metadata;
7. sets `Flag_Encrypted`.

The fill bytes are deterministic bytes copied from the file buffer rather than a standardized PKCS#7 padding structure.

Therefore do not implement PKCS#7 unpadding.

The logical file length comes from the PAK entry, not from padding bytes.

## Empty encrypted files

The writer's deterministic padding logic uses original file data as its source.

Normal producer policy does not need to create an encrypted physical payload for a zero-length file.

A reader should rely on serialized entry size/flags and must not invent a special padding convention for empty files.

## Reading an uncompressed encrypted entry

The runtime aligns encrypted reads to AES block boundaries.

Conceptually, for a requested logical range it:

1. expands the physical read start downward to a 16-byte boundary;
2. expands the physical read end upward to a 16-byte boundary;
3. reads those aligned ciphertext bytes;
4. decrypts the complete aligned buffer;
5. returns only the requested logical byte range.

This is necessary because AES operates on complete 16-byte blocks.

For whole-file extraction the simpler equivalent is:

1. locate the payload after the payload-side `FPakEntry`;
2. read `Align(Entry.Size, 16)` ciphertext bytes;
3. decrypt them;
4. retain exactly `Entry.Size` logical bytes.

Do not expose alignment padding as part of the contained file.

## Compressed encrypted entry creation

For a compressed+encrypted file, UnrealPak compresses independently by compression block.

After each compressed block, it advances the physical stream to AES alignment before the next block.

After compression has produced the complete padded block stream, UnrealPak encrypts the compressed buffer.

The entry records:

- compressed block start;
- compressed block end;
- compressed logical byte count;
- entry encrypted flag.

The compression block's `CompressedEnd` identifies the end of the actual compressed bytes, not necessarily the end of AES padding before the next block.

## Reading compressed encrypted entries

The source-backed processing order is:

```text
read AES-aligned ciphertext
-> decrypt
-> take the actual compressed bytes described by the block
-> decompress
```

Never decompress ciphertext.

Never pass AES padding after `CompressedEnd` to the codec as though it were compressed stream data.

For a block whose actual compressed size is:

```text
CompressedEnd - CompressedStart
```

the runtime may need to read/decrypt:

```text
Align(actual compressed size, 16)
```

bytes depending on block position/alignment, but the decompressor receives only the actual compressed byte count.

## Relative compressed offsets still apply

Encryption does not alter the PAK version rule for compressed-block offsets.

For version >= 5, compressed block offsets are relative to the entry offset.

For older versions they are absolute.

Encryption alignment must be applied after resolving the correct physical block location. It must not be used to guess whether offsets are relative.

## Entry payload SHA1

UnrealPak's uncompressed-file writer computes the entry hash after optional encryption has modified the stored buffer.

The compressed-file writer explicitly:

1. encrypts the compressed buffer when requested;
2. hashes the final buffer written to the PAK.

Therefore `FPakEntry.Hash` is a stored-payload integrity hash.

For encrypted entries it covers ciphertext/stored bytes, not plaintext.

This is distinct from `FPakInfo.IndexHash`, which is checked after index decryption.

The distinction is critical:

| Hash | Encrypted case |
|---|---|
| `FPakInfo.IndexHash` | SHA1 of decrypted/plain index buffer |
| `FPakEntry.Hash` | SHA1 of final stored payload bytes |

Do not apply one rule to the other.

## Entry hash and physical padding

For compressed encrypted entries, the compressed writer's total stored compressed buffer includes AES-alignment expansion performed between blocks, and the final hash is calculated over that final buffer.

For uncompressed encrypted entries, the source's hash call uses the original file-size argument even though an aligned encrypted buffer is written. This behavior must be reproduced exactly when validating against source-produced entry hashes; do not silently redefine the hash domain based on what seems more intuitive.

This is a source-level difference worth preserving rather than flattening into a generic "always hash all ciphertext bytes" rule.

## Payload-side entry header

`Entry.Offset` points to the plaintext serialized `FPakEntry` header stored before the file payload.

The generic encryption process encrypts the contained file data, not that payload-side `FPakEntry` metadata header.

A reader must first parse the entry metadata, then locate and decrypt the payload bytes.

Do not attempt to AES-decrypt the `FPakEntry` header itself.

## Compact index encrypted flag

For version 10 compact entries, encryption is represented in the compact bitfield.

`DecodePakEntry` reconstructs ordinary `FPakEntry.Flags`, including `Flag_Encrypted`.

Compact index encoding changes metadata representation only. It does not define a different encryption algorithm.

After decoding, the same AES/custom-delegate rules apply.

## Key material is external

The PAK contains an encryption key GUID, not the encryption key.

The actual key comes from runtime/project configuration/delegates/registered key data.

Consequently UnrealDB can determine from the PAK:

- that the index is encrypted;
- that an entry is encrypted;
- which GUID is requested from version 7 onward;
- physical alignment and decryption ordering.

It cannot derive the AES key from the PAK itself.

Missing key material is not parser failure.

## Legacy key behavior

For PAKs before version 7, no key GUID is serialized.

The runtime invalidates the GUID and can obtain the key through the legacy `GetPakEncryptionKeyDelegate`.

This means an old encrypted PAK cannot identify its key by GUID from its own bytes.

UnrealDB must represent this explicitly, for example:

```text
encrypted; legacy/no serialized key GUID; external key required
```

It must not manufacture a GUID or infer one from filename/game name.

## Encryption is not signing

UE4 also supports signed PAKs and `.sig` files using RSA-related logic.

That mechanism protects/authenticates PAK chunks/signature tables and is separate from AES PAK encryption.

Do not:

- use RSA signature keys as AES encryption keys;
- interpret a `.sig` file as encryption metadata;
- require signing merely because a PAK is encrypted;
- require encryption merely because a PAK is signed.

## Encryption is not compression

Entry encryption and compression are independent flags/metadata.

Correct generic entry order is:

### Uncompressed + unencrypted

```text
read -> logical bytes
```

### Uncompressed + encrypted

```text
read aligned ciphertext -> decrypt -> trim to logical size
```

### Compressed + unencrypted

```text
read compressed block -> decompress
```

### Compressed + encrypted

```text
read aligned ciphertext -> decrypt -> trim to compressed block bytes -> decompress
```

No other ordering is source-backed.

## Custom encryption and UnrealDB

The custom delegate means UE4 permits game/project code to replace the generic decrypt operation.

Because the delegate binding is not serialized in the PAK, generic byte inspection cannot prove which custom algorithm was intended.

UnrealDB's generic reader should therefore expose an explicit state such as:

```text
encrypted PAK/entry; generic AES metadata present; decryption requires key and may require project-specific custom delegate
```

when exact producer context is unavailable.

It must not trial-decrypt with unrelated algorithms.

## Validation rules

A source-compatible reader should validate:

- PAK version before interpreting encryption fields;
- `bEncryptedIndex` only according to version >= 4;
- `EncryptionKeyGuid` only according to version >= 7;
- entry encrypted flag only according to version >= 3;
- encrypted physical read sizes are AES-block aligned before calling default `FAES`;
- key GUID lookup is exact when a valid GUID is present;
- index SHA1 after index decryption;
- payload hash according to the source-defined payload hash domain;
- compressed encrypted block boundaries before decompression;
- all aligned-size calculations for overflow/out-of-file conditions.

## Conditions that are not corruption

Do not classify these alone as malformed PAK:

- index is encrypted and the key is unavailable;
- entry is encrypted and the key is unavailable;
- valid encryption GUID is unknown to UnrealDB;
- old encrypted PAK has no serialized key GUID;
- a project may have used a custom encryption delegate;
- encrypted payload physical storage is larger than logical size due to 16-byte alignment;
- compressed encrypted blocks have gaps caused by AES alignment;
- the PAK is unsigned.

These conditions require appropriate capability/key reporting, not invented format rejection.

## Conditions that indicate failure

Once the correct required key/decryption implementation is available, failures include:

- encrypted index byte range is invalid;
- encrypted buffer cannot be represented as complete AES blocks for the generic AES path;
- decrypted index SHA1 does not equal `FPakInfo.IndexHash`;
- entry payload ranges exceed the PAK;
- compressed encrypted block alignment/ranges are inconsistent;
- stored payload hash fails where verification is possible;
- decrypted compressed data cannot be decompressed with the serialized compression method;
- arithmetic overflows while aligning or resolving physical ranges.

## No trial-key heuristics

If a valid `EncryptionKeyGuid` is present, use the matching key only.

For legacy no-GUID PAKs, the source expects an externally supplied legacy key. It does not define a "try every known key until the index parses" format rule.

UnrealDB must not turn trial-key success into authoritative format identification.

If an operator explicitly supplies candidate keys as an external recovery workflow, that is outside the PAK format specification and must remain clearly separated from normal parser behavior.

## UnrealDB implementation contract

1. parse encryption metadata only according to the PAK version;
2. keep index encryption separate from entry encryption;
3. treat `Flag_Encrypted` as entry-payload encryption;
4. treat `bEncryptedIndex` as index encryption;
5. retain `EncryptionKeyGuid` exactly when version >= 7;
6. represent pre-v7 encrypted PAKs as having no serialized key GUID;
7. never derive key material from the GUID;
8. obtain key material externally;
9. use exact GUID key lookup when a valid GUID exists;
10. distinguish missing key from corrupt PAK;
11. default to source-compatible AES-256 16-byte block processing when no proven custom encryption implementation applies;
12. do not invent IV/nonce/salt/tag fields;
13. do not use PKCS#7 unpadding;
14. align encrypted physical reads to 16 bytes;
15. preserve logical entry size independently of encryption padding;
16. leave the payload-side `FPakEntry` header plaintext;
17. decrypt the index before validating `IndexHash`;
18. validate `IndexHash` over decrypted index bytes;
19. preserve the source-defined `FPakEntry.Hash` domain rather than assuming it matches index-hash semantics;
20. for compressed encrypted entries, decrypt before decompressing;
21. do not pass AES padding to the decompressor;
22. honor relative compressed-block offset rules independently of encryption;
23. decode compact entry encryption metadata before payload processing;
24. distinguish custom-delegate-required behavior from generic AES behavior;
25. never guess a custom encryption algorithm from ciphertext;
26. keep RSA PAK signing separate from AES PAK encryption;
27. do not impose arbitrary encrypted-file size limits as format constraints;
28. report implementation/resource limits separately from format validity.

## Source-reference matrix

| Rule | Source/symbol |
|---|---|
| entry encryption introduction | `FPakInfo::PakFile_Version_CompressionEncryption` |
| index encryption introduction | `PakFile_Version_IndexEncryption` |
| key GUID introduction | `PakFile_Version_EncryptionKeyGuid` |
| index encrypted flag | `FPakInfo::bEncryptedIndex` |
| key identifier | `FPakInfo::EncryptionKeyGuid` |
| old-version clearing | `FPakInfo::Serialize` |
| entry encrypted flag | `FPakEntry::Flag_Encrypted, IsEncrypted, SetEncrypted` |
| key lookup | `FPakPlatformFile::GetPakEncryptionKey` |
| deferred key registration/mount | `FPakPlatformFile::RegisterEncryptionKey` |
| custom decryption hook | `FPakCustomEncryptionDelegate`, `DecryptData` |
| default decryption | `DecryptData -> FAES::DecryptData` |
| AES block requirement | `FAES::EncryptData, FAES::DecryptData` |
| AES block size | `FAES::AESBlockSize` |
| AES implementation | `AES.cpp` Rijndael setup/encrypt/decrypt |
| index decryption/hash ordering | `FPakFile::DecryptAndValidateIndex` |
| index creation encryption flag | `CreatePakFile` |
| uncompressed payload encryption | `PrepareCopyFileToPak` |
| compressed payload encryption | `PrepareCopyCompressedFileToPak` |
| compressed AES padding | `FCompressedFileBuffer::CompressFileToWorkingBuffer` |
| aligned encrypted async reads | encrypted PAK read request classes in `IPlatformFilePak.cpp` |
| compact entry encrypted bit | `FPakFile::EncodePakEntry, DecodePakEntry` |
| signing separation | `FPakSignatureFile`, signature-file loading |

## Result

UE4 4.27.2 PAK encryption is metadata-driven and key-dependent. Entry payload encryption, index encryption, and PAK signing are separate mechanisms.

The generic encryption path uses 32-byte AES keys and 16-byte independent AES blocks, with no serialized IV, nonce, salt, or authentication tag. Encrypted physical storage is aligned to 16 bytes while logical file sizes remain defined by the PAK entry. Version 7 adds a GUID that identifies externally supplied key material; it does not embed the key.

Encrypted indexes are decrypted before their SHA1 is validated. Encrypted compressed payloads are decrypted before decompression, and AES alignment bytes are excluded from the codec input. Runtime custom encryption delegates can replace generic decryption, but that choice is not serialized in the PAK and therefore cannot be guessed by a generic UnrealDB reader.
