# Export Indices

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

## Signed package-index model

An export reference is a positive package index:

```text
export_array_index = package_index - 1
```

This convention is explicit in UE3 `UnLinker.h` and UE4 `FPackageIndex`.

The disk integer encoding is generation-specific: early formats can use compact indices at applicable call sites; UE3/UE4 package indices are fixed 32-bit values.

## Zero

Zero is a sentinel, not ExportMap[0]. Its meaning depends on the field: root outer, class sentinel, no super, default archetype, or null.

## Validation

Resolve only after checking positivity and `index - 1 < ExportCount`. Preserve cycles/errors as malformed reference graphs rather than recursively following without a guard.

## Cross-generation conformance result

This semantic rule must be applied through the exact engine/revision reader selected by the package summary. The generation-specific package specs remain the final authority where serialized layouts differ.

## Later-generation source verification

| Revision | Source-confirmed representation |
|---|---|
| Unreal II | Export `ClassIndex`, `SuperIndex`, `SerialSize` and conditional `SerialOffset` are compact, but export `PackageIndex` is fixed INT. |
| UE2.5 | Retains that UE2 distinction. |
| UT2003 | Retains compact class/super/serial fields and fixed export parent `PackageIndex`. |
| UT2004 | Retains the UE2-era export index model for its revision. |
| UE3 | Object/package indices are fixed 32-bit; export records add `ArchetypeIndex`, export flags and other metadata. |
| UE4 4.27.2 | `FPackageIndex` is fixed int32 and export records include modern class/super/template/outer relationships plus versioned 32/64-bit serial ranges. |

The positive one-based export semantic survives, while the disk representation and surrounding export record evolve.
