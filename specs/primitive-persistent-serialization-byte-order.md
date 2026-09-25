# Primitive Persistent Serialization and Byte Order

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

## Persistent primitive rule

Persistent archives serialize multibyte primitive values through byte-order-aware archive operations. UT99 `FArchive` routes 16/32/64-bit integers and floating values through `ByteOrderSerialize`; one-byte values are serialized directly. UE4 retains the same principle: multibyte primitive archive operators call `ByteOrderSerialize`.

The normal on-disk representation is little-endian. A package whose magic is `PACKAGE_FILE_TAG_SWAPPED` activates byte swapping for subsequent persistent values. The tag is therefore both identification and byte-order evidence.

Do not byte-swap strings or byte arrays wholesale. Swap the serialized numeric units according to their archive operator.

## Widths

Use the width required by the exact serialized field. Do not equate C++ host widths with package widths. Important examples include fixed 32-bit package indices, version-dependent 32/64-bit export serial sizes, and byte-sized flags in some container formats.

UE4 `bool` archive serialization retains legacy 32-bit UBOOL representation in the binary archive path; a C++ `bool` in source is not proof of a one-byte disk field.

## Strings

Strings are serialized by the engine's `FString` archive rules, not as raw C strings. Generation/version-specific ANSI/Unicode count semantics belong to the exact package spec and must be honored before table parsing.

## Conformance

UnrealDB must carry a byte-order state from the package tag, use exact field widths, distinguish byte arrays from numeric scalars, and reject truncated primitive reads. Host endianness must never leak into the file model.

## Cross-generation conformance result

This semantic rule must be applied through the exact engine/revision reader selected by the package summary. The generation-specific package specs remain the final authority where serialized layouts differ.
