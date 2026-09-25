# Runtime-Only Fallbacks UnrealDB Cannot Reproduce from Package Data

## Scope and authority

This is a dependency-operation specification for UnrealDB. It complements the engine-specific package/dependency specs and must never override their exact revision behavior.

Primary source families: UT99 retail v1.400 (`ardenee/UT99src`), Unreal II (`ardenee/unreal2src`), UE2.5 (`ardenee/UE2.5`), UT2003, UT2004, UE3 UDKUltimate (`ardenee/UE3src`), and UE4 4.27.2 (`ardenee/UnrealEngine4`).

Core principle: resolve from serialized package/import/export identity first. Runtime/configuration behavior is documented separately and is not guessed.

## Boundary

The engine loader has state that is not serialized in a package. UnrealDB must not claim to reproduce it from package bytes.

Examples across revisions include:

- objects/classes already loaded in memory;
- native public transient objects supplied by executable modules;
- runtime class flags such as safe-replace decisions when the class implementation is unavailable;
- configured package search paths and mount order;
- INI-driven remaps;
- active redirects/core redirects;
- `UObjectRedirector` targets requiring another loaded object/package;
- instancing/remapping contexts;
- editor/cooker-only missing-class allowances;
- platform/DLC/plugin package mounts;
- runtime delegates/custom loader behavior.

UE4's linker explicitly exposes active redirect maps and instancing context; UE3 verification can use loaded native objects and redirectors; UT99 verification can resolve native transient objects from runtime memory.

## UnrealDB outcome

When direct serialized matching fails and the only remaining source path requires such state, report `runtime_only_fallback_unavailable` with the applicable mechanism. Do not mark the dependency satisfied and do not mark the package structurally corrupt.

This is also why INI-based `ClassRemap` must not be implemented as a generic UnrealDB fallback: the required configuration is external to the package and cannot be known reliably.

## Source-reference matrix

| Concern | Authoritative symbols/files |
|---|---|
| UE1 retail import/export identity and verification | UT99 retail `Core/Src/UnLinker.h`: `FObjectImport`, `FObjectExport`, `GetImportFullName`, `GetExportFullName`, `ULinkerLoad::VerifyImport`, `FindExportIndex` |
| UE3 package-index/resource and verification model | UE3 `Core/Inc/UnLinker.h`, `Core/Src/UnLinker.cpp`: `FObjectResource`, `FObjectImport`, `FObjectExport`, `VerifyImport`, `VerifyImportInner`, path/class helpers |
| UE4 package-index/import/export model | UE4 `CoreUObject/Public/UObject/ObjectResource.h`, `LinkerLoad.h`, `Private/UObject/LinkerLoad.cpp`: `FPackageIndex`, `FObjectImport`, `FObjectExport`, `VerifyImport`, `FindExportIndex`, `BuildPathName` |

## UnrealDB conformance

Apply this operation only after the exact package reader has validated indices and tables. Preserve enough structured evidence to explain why a dependency resolved or failed; do not reduce resolution to a filename/name-only boolean.
