# Export Class Identity

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

## ClassIndex

An export's class is represented by `ClassIndex`, a package index into an import or export, with a special zero meaning.

UE3 explicitly defines `UCLASS_INDEX = 0`: a zero `FObjectExport::ClassIndex` means the export itself is a `UClass` object. UE4 preserves the corresponding package-index class representation.

For nonzero ClassIndex, resolve by signed package-index rules and use the referenced resource's identity. Do not assume the class is always an import.

## Related indices

`SuperIndex`, UE4 `TemplateIndex`, and UE3 archetype-related indices are distinct relationships. They must not be substituted for ClassIndex.

## Conformance

Export class identity is obtained from ClassIndex resolution plus the referenced resource path/name. Filename extension or payload signature is not authoritative class identity.

## Cross-generation conformance result

This semantic rule must be applied through the exact engine/revision reader selected by the package summary. The generation-specific package specs remain the final authority where serialized layouts differ.
