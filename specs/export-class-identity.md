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

## Later-generation source verification

| Revision | Source-confirmed change |
|---|---|
| Unreal II | Negative ClassIndex derives class from an import; positive from a local export; zero means `Class`/`Core`. Missing nonzero export classes can cause export creation to be skipped. |
| UE2.5 | Retains class-index derivation but has its own missing-class/export-construction behavior. |
| UT2003 | A null resolved LoadClass is replaced with `UClass::StaticClass()`, unlike the reviewed UE2.5 nonzero-class guard. |
| UT2004 | Uses its own reviewed CreateExport/class behavior and must not inherit UT2003/UE2.5 construction policy blindly. |
| UE3 | ClassIndex remains package-index based; export records also add ArchetypeIndex and forced-export/cooked semantics. |
| UE4 4.27.2 | Modern export class identity remains ClassIndex/FPackageIndex based, alongside SuperIndex, TemplateIndex and outer relationships. |

Class identity and the runtime policy for what happens when the class cannot be created are separate concerns.
