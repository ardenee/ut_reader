# Package Summary and Version Encoding

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

## Purpose

The package summary is the dispatch point for all later parsing. Read it before interpreting name/import/export layouts.

## Common identification

Unreal package generations use `PACKAGE_FILE_TAG = 0x9E2A83C1`; the swapped representation indicates opposite byte order. After recognizing the tag, decode the exact generation's version representation.

## UE1/UE2/UE3

Earlier generations combine or serialize engine/licensee versions according to their source revision. UE3 uses the low/high portions of its version field for Epic/licensee semantics in the documented UE3 format. Counts, offsets, GUID/generation data and later compression metadata are version gated.

## UE4

UE4 4.27.2 starts with tag and a signed legacy version discriminator. Modern packages use negative legacy values; current 4.27.2 writes `-7`. The source documents -2 through -7 transitions, then serializes UE4 and licensee versions plus the applicable custom-version container. Unversioned packages are a distinct source-supported state, not version zero guessed by the reader.

## Conformance

Never select a table layout from extension alone. Dispatch from tag, version/licensee values and exact source-backed gates. Unknown future/unsupported version combinations must remain unsupported rather than being coerced into the nearest known version.

## Cross-generation conformance result

This semantic rule must be applied through the exact engine/revision reader selected by the package summary. The generation-specific package specs remain the final authority where serialized layouts differ.
