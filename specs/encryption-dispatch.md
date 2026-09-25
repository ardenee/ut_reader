# Encryption Dispatch

## Scope and authority

This is a cross-generation UnrealDB semantic specification. It does **not** flatten UE1, UE2/2.5, UE3 and UE4 into one binary layout. The exact per-generation package specifications remain authoritative for serialized order and version gates.

Authoritative source families used by this specification:

- UT99 retail v1.400: `ardenee/UT99src`, especially `Core/Inc/UnArc.h`, `UnObjBas.h` and package/linker source.
- Unreal II / UE2 and UE2.5: `ardenee/unreal2src` and `ardenee/UE2.5`; behavior is used only where present in those source trees.
- UT2003/UT2004: their supplied game source trees for revision-specific UE2 branches.
- UE3 UDKUltimate: `ardenee/UE3src`, especially `Development/Src/Core/Inc/UnLinker.h`, `UnFile.h`, `UnObjBas.h`, and `Core/Src/UnLinker.cpp`.
- UE4 4.27.2: `ardenee/UnrealEngine4`, especially `Core/Public/Serialization/Archive.h`, `CoreUObject/Public/UObject/PackageFileSummary.h`, `ObjectResource.h`, and their implementations.

Rules:

1. use the package's own engine/revision rules;
2. never use a later generation's field layout to repair an earlier package;
3. version/licensee/custom-version gates are format rules, not optional hints;
4. byte ranges and counts must be checked against the actual file without imposing arbitrary UnrealDB format limits;
5. runtime/config behavior not serialized in the file must not be guessed.

## Generic UObject packages

The supplied generic UE1/UE2/UE3 package formats do not define a universal package-encryption dispatch layer equivalent to PAK encryption. UE3 generic UPK behavior is documented in `upk-encryption-handling.md`: no generic encrypted-package flag/key/IV/decrypt stage should be invented.

If a game/platform revision proves a custom encrypted package wrapper, dispatch it only for that proven producer/revision.

## UE4 PAK

PAK has explicit encryption metadata:

- `FPakInfo.bEncryptedIndex` for index encryption;
- `FPakEntry::Flag_Encrypted` for entry payload encryption;
- version >= 7 `EncryptionKeyGuid` for external key selection.

Generic runtime decryption uses the registered exact GUID key, falling back to the legacy no-GUID key delegate only when the GUID is invalid. A custom PAK encryption delegate may replace generic AES behavior but that runtime binding is not serialized.

See `pak-encryption-handling.md`.

## Dispatch order

For an encrypted PAK index: read -> decrypt -> validate index SHA1 -> parse.

For compressed+encrypted PAK payload: read aligned ciphertext -> decrypt -> trim to actual compressed block bytes -> decompress.

## Conformance

Missing key is not corruption. Never derive a key from GUID/name/hash, never trial unrelated ciphers as format detection, and keep RSA PAK signing separate from encryption.

## Cross-generation conformance result

This semantic rule must be applied through the exact engine/revision reader selected by the package summary. The generation-specific package specs remain the final authority where serialized layouts differ.

## Later-generation source verification

| Revision | Source-confirmed dispatch |
|---|---|
| Unreal II | No generic package encryption descriptor/decrypt stage in the reviewed linker. |
| UE2.5 | No generic package encryption layer established by the reviewed reader. |
| UT2003 | No generic package encryption descriptor in the reviewed package path. |
| UT2004 | No generic UObject-package encryption dispatch in the reviewed package path. |
| UE3 | Generic UPK still has no universal encrypted-package flag/key/IV/decrypt stage; exact game/platform source is required for wrappers. |
| UE4 4.27.2 | PAK explicitly supports independent index/payload encryption, key GUID selection, AES-block alignment and an optional custom runtime encryption delegate. |

PAK encryption must not be projected backward onto UPK or UE2 packages.
