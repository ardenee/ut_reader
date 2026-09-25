# Compression Dispatch

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

## Dispatch layers

Compression must be selected from serialized/source-defined metadata, not by trying codecs until one succeeds.

### UE1/UE2 redirect formats

UZ/UZ2 handling follows their dedicated specs and game revisions. Do not reuse UE3 package compression framing merely because both use compression.

### UE3 packages

UE3 package compression uses summary `CompressionFlags` and `CompressedChunks` for `PKG_StoreCompressed`. The compression type is selected by the type-mask bits: ZLIB, LZO or LZX as documented in `upk-compression-handling.md`. `PKG_StoreFullyCompressed` is a distinct producer/context-dependent outer transformation.

### UE4 packages

UE4 package/container compression must follow its exact serialized metadata. PAK compression is entry/block based and uses the PAK compression method table; see `pak-compression-handling.md`. Do not route a PAK through the UObject-package compression-map reader.

## Order

Dispatch package/container decompression before parsing logical data that lives behind that layer. For encrypted+compressed PAK entries, decrypt stored block bytes before decompression.

## Conformance

Reject contradictory/unsupported serialized codec identifiers. Platform defaults are producer defaults, not permission to guess a missing codec. Resource limits may protect the implementation but must not be reported as format limits.

## Cross-generation conformance result

This semantic rule must be applied through the exact engine/revision reader selected by the package summary. The generation-specific package specs remain the final authority where serialized layouts differ.
