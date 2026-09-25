# Name-Table Serialization and Version Changes

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

## Semantic model

A package name reference and a name-table entry are different structures. The name table stores strings plus revision-dependent metadata; object/import/export records store references into that table.

Validate every referenced name index before dereference.

## UE1/UE2 family

Use each revision's name-entry serializer. Early formats serialize the name text and name flags, with string representation and flag width/version branches defined by that engine source. Name references in object structures may use compact-index-era encoding.

## UE3

UE3 package names are table-backed `FName` values. Persistent references consist of the name-table index plus instance number according to UE3 linker serialization; do not collapse numbered names to only the base text.

## UE4

UE4 retains table-backed names and adds versioned name-table metadata. The package summary's name count/offset bounds the table, and later UE4 versions can include hashes associated with name entries. The exact package version controls those additions.

## Conformance

Preserve base text, number, flags/hashes where serialized, and original table index. Never deduplicate or reorder the on-disk name table while parsing; doing so changes references.

## Cross-generation conformance result

This semantic rule must be applied through the exact engine/revision reader selected by the package summary. The generation-specific package specs remain the final authority where serialized layouts differ.
