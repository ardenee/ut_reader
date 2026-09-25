# Outer and Package-Index Traversal

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

## Outer chain

`OuterIndex` identifies the containing object/package. In UE3 a zero `FObjectResource::OuterIndex` identifies a top-level package associated with the linker root; UE4 preserves the signed `FPackageIndex` model.

To build an object path:

1. start at the target import/export resource;
2. read its `ObjectName`;
3. resolve `OuterIndex`;
4. prepend each resolved outer name;
5. stop at the source-defined zero/root condition.

## Safety

Each hop can cross import/export maps. Validate the signed index before every dereference. Detect cycles and impossible depth; a cycle is corruption, not permission to truncate the path and pretend success.

## Package identification

For imports, the outer chain is also part of determining the referenced top-level package. Do not assume `ClassPackage` is the package containing the imported object; it identifies the import's class package.

## Cross-generation conformance result

This semantic rule must be applied through the exact engine/revision reader selected by the package summary. The generation-specific package specs remain the final authority where serialized layouts differ.

## Later-generation source verification

| Revision | Source-confirmed traversal change |
|---|---|
| Unreal II | Import full paths follow negative parent imports to zero; export full paths follow positive export parents to zero. |
| UE2.5 | Retains the corresponding UE2 parent-chain model. |
| UT2003 | Retains negative import-parent and positive export-parent traversal. |
| UT2004 | Retains that UE2-era path structure. |
| UE3 | Cooked/seek-free packages can mix import and export indices in outer graphs; path traversal must therefore resolve the signed package index at every hop rather than assuming import-only outers. |
| UE4 4.27.2 | `FPackageIndex` traversal remains signed and explicit package-name/external-package behavior can supplement the outer chain. |

A UE1/UE2 assumption that every import outer is another import is not safe for later cooked package models.
