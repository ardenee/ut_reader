# Engine-Specific Source-Backed Fallbacks

## Scope and authority

This is a dependency-operation specification for UnrealDB. It complements the engine-specific package/dependency specs and must never override their exact revision behavior.

Primary source families: UT99 retail v1.400 (`ardenee/UT99src`), Unreal II (`ardenee/unreal2src`), UE2.5 (`ardenee/UE2.5`), UT2003, UT2004, UE3 UDKUltimate (`ardenee/UE3src`), and UE4 4.27.2 (`ardenee/UnrealEngine4`).

Core principle: resolve from serialized package/import/export identity first. Runtime/configuration behavior is documented separately and is not guessed.

## Rule

A fallback is valid only when it is explicitly present in the exact engine/game source being modeled.

## Proven UT99 retail fallbacks

The supplied v1.400 linker contains:

- `HashNames`: historical `UnrealShare` class package is normalized to `UnrealI` for hashing;
- import verification permits the corresponding UnrealI/UnrealShare class-package compatibility;
- package loading can retry `UnrealI` as `UnrealShare`;
- class lookup retries `Mesh` as `LodMesh`;
- a depth-specific UnrealI/UnrealShare shareware import construction path;
- `CLASS_SafeReplace` handling for a missing object when the runtime class is available.

These must not become global fallbacks.

## Later revisions

UE2/2.5/UT2003/UT2004 must use only fallbacks retained or added in their exact source. UE3/UE4 contain redirect/deprecation mechanisms, but many depend on runtime registries/configuration.

## Prohibition

Do not implement speculative basename matching, extension substitution, case-folded filesystem hunting, nearest-class matching, arbitrary parent dropping, or INI `ClassRemap` behavior unless the exact target source and available serialized/config data prove it.

## Source-reference matrix

| Concern | Authoritative symbols/files |
|---|---|
| UE1 retail import/export identity and verification | UT99 retail `Core/Src/UnLinker.h`: `FObjectImport`, `FObjectExport`, `GetImportFullName`, `GetExportFullName`, `ULinkerLoad::VerifyImport`, `FindExportIndex` |
| UE3 package-index/resource and verification model | UE3 `Core/Inc/UnLinker.h`, `Core/Src/UnLinker.cpp`: `FObjectResource`, `FObjectImport`, `FObjectExport`, `VerifyImport`, `VerifyImportInner`, path/class helpers |
| UE4 package-index/import/export model | UE4 `CoreUObject/Public/UObject/ObjectResource.h`, `LinkerLoad.h`, `Private/UObject/LinkerLoad.cpp`: `FPackageIndex`, `FObjectImport`, `FObjectExport`, `VerifyImport`, `FindExportIndex`, `BuildPathName` |

## UnrealDB conformance

Apply this operation only after the exact package reader has validated indices and tables. Preserve enough structured evidence to explain why a dependency resolved or failed; do not reduce resolution to a filename/name-only boolean.

## Later-generation fallback inventory

The UT99 baseline fallbacks remain documented above, but later source changes the inventory:

| Revision | Source-backed fallback/compatibility notes |
|---|---|
| Unreal II v69 (12-09-2000) | Retains the historical `UnrealI` -> `UnrealShare` package-load retry, UnrealShare-to-UnrealI hash normalization and the UnrealI/UnrealShare class-package match. Retains provider-root outer acceptance, runtime native/transient binding, broad SafeReplace and forgiving broken-link handling. `Mesh -> LodMesh` is an unconditional second hash pass. This profile is limited to package versions 60-69; later Unreal II versions are source-unverified. |
| UT2004 v129 | Latest complete `UT2004SrcCmake` source retains root-export outer acceptance, strict private-export rejection, runtime native/transient binding, broad SafeReplace/forgiving behavior, and an unconditional `Mesh -> LodMesh` Rehack. It has no active UnrealI/UnrealShare compatibility and no active ClassRemap/PackageRemap in `VerifyImport`. Source-backed for package versions 60-129. |
| UT2003 v2107 | No UnrealI/UnrealShare compatibility. Retains root-outer acceptance, runtime native/transient binding, broad SafeReplace/forgiving behavior, and an unconditional `Mesh -> LodMesh` Rehack. A runtime `GObjPackageRemap` retry exists in `StaticLoadObject` **outside VerifyImport**. This VerifyImport profile is bounded to package versions 60-120. |
| UE2.5 / Unreal Warfare | Remains a separate source profile. Do not infer its fallbacks from UT2004. |
| UE3 | Adds source-defined import fixups/remapping, UObjectRedirector fallback and cooked/seek-free/runtime conditions. |
| UE4 4.27.2 | Adds CoreRedirects, instancing/package remapping, script/native/in-memory handling and external-package cases. No arbitrary fuzzy fallback is source-backed. |

Fallbacks are therefore a revision matrix, not an accumulating list inherited from UT99.
