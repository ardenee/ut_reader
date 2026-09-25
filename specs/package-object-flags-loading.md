# Package and Object Flags Relevant to Loading

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

## Principle

Flags are revision-specific bitfields. Preserve unknown bits and interpret only flags defined by the package's engine/game revision.

## Package flags

UT99 defines loading-relevant package flags including `PKG_AllowDownload`, `PKG_ClientOptional`, `PKG_ServerSideOnly`, `PKG_BrokenLinks`, `PKG_Unsecure`, and `PKG_Need`. Later engines expand/change the flag set. UE3 adds package storage/compression semantics such as `PKG_StoreCompressed` and `PKG_StoreFullyCompressed`.

Do not apply UE3 flag meanings to the same numeric bit in UE1 without source proof.

## Object flags

Export tables serialize the subset of object flags relevant to persistent loading. UE4's `FObjectExport` explicitly masks with `RF_Load` on save/load. Runtime-only UObject state is not all persisted.

Flags can affect whether an object is loaded for client/server/editor and how exports are treated, but they do not override structural bounds.

## Conformance

Store raw flag values plus decoded names for the exact revision. Unknown bits are data, not automatic corruption. Never manufacture flags from observed behavior.

## Cross-generation conformance result

This semantic rule must be applied through the exact engine/revision reader selected by the package summary. The generation-specific package specs remain the final authority where serialized layouts differ.

## Later-generation source verification

| Revision | Source-confirmed evolution |
|---|---|
| Unreal II | Retains UE-era package/object flags and context filtering with revision-specific load consequences. |
| UE2.5 | Retains the UE2 flag model but its loader behavior must remain target-specific. |
| UT2003 | `RF_Public` is material to import resolution; serialized object/load flags participate in CreateExport. |
| UT2004 | Retains these concepts with its own revision-specific definitions and loading paths. |
| UE3 | Adds package storage flags including `PKG_StoreCompressed` and `PKG_StoreFullyCompressed`; export records add explicit ExportFlags. |
| UE4 4.27.2 | Package/object flags evolve further; persistent export flags use source-defined load masks, while package privacy/cooked/editor flags affect loading. |

Decode numeric bits from the exact revision only.
