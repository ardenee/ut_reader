# Import Class Identity

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

## Serialized identity

The import table carries class identity separately from object identity. UE4 `FObjectImport` serializes, in order, `ClassPackage`, `ClassName`, `OuterIndex`, `ObjectName`, plus version/editor-gated package-name data. UE3 uses the same core class-package/class-name concept.

`ClassPackage` + `ClassName` identify the class expected for the imported object. `ObjectName` + outer chain identify the imported object itself.

## Matching

Dependency verification must compare class/package identity according to the exact engine revision's linker rules. Do not replace class identity with filename extension, guessed asset type or export object name.

Runtime remaps/configuration are not serialized class identity. In particular, do not invent INI-driven `ClassRemap` behavior in UnrealDB.

## Conformance

Preserve all four core import identity fields and resolve their name references before matching. Invalid class name/package references invalidate the import record.

## Cross-generation conformance result

This semantic rule must be applied through the exact engine/revision reader selected by the package summary. The generation-specific package specs remain the final authority where serialized layouts differ.

## Later-generation source verification

| Revision | Source-confirmed class identity |
|---|---|
| Unreal II | Import table remains `ClassPackage, ClassName, PackageIndex, ObjectName`; active verification requires exact class package/name except explicit source fallbacks. |
| UE2.5 | Retains the same core serialized identity. No active generic ClassRemap/PackageRemap is proven. |
| UT2003 | Retains the four-field identity. Package remap exists elsewhere in runtime loading, not as import-table identity. |
| UT2004 | Retains the four-field identity; reviewed resolver has no active ClassRemap/PackageRemap. |
| UE3 | Core import class-package/class-name identity remains, but linker initialization can apply source-defined import fixups/remapping before resolution. |
| UE4 4.27.2 | Retains class identity while adding modern package-name/external-package and redirect/instancing runtime mechanisms. Those transformations are not raw serialized class identity. |

The serialized class tuple must be preserved before any source-backed runtime transformation is considered.
