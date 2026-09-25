# Required Package Identification

## Scope and authority

This is a dependency-operation specification for UnrealDB. It complements the engine-specific package/dependency specs and must never override their exact revision behavior.

Primary source families: UT99 retail v1.400 (`ardenee/UT99src`), Unreal II (`ardenee/unreal2src`), UE2.5 (`ardenee/UE2.5`), UT2003, UT2004, UE3 UDKUltimate (`ardenee/UE3src`), and UE4 4.27.2 (`ardenee/UnrealEngine4`).

Core principle: resolve from serialized package/import/export identity first. Runtime/configuration behavior is documented separately and is not guessed.

## Rule

A required external package is established by imports whose outer chain reaches a top-level package import. In the classic linker model, the top-level package import has root outer/package index zero and class identity `Core.Package` (subject to exact revision rules).

Nested imports do not independently name a different required package merely because their `ClassPackage` names one: `ClassPackage` describes the object's class, not the provider containing the imported object.

## Aggregation

UnrealDB should derive the top-level provider package for every external import, then deduplicate required packages by the revision-appropriate logical identity while retaining all imports that caused the requirement.

Native/runtime-resolved imports can still mention packages but may not correspond to a catalog file. Report those distinctly rather than manufacturing a missing-file dependency.

## Source-reference matrix

| Concern | Authoritative symbols/files |
|---|---|
| UE1 retail import/export identity and verification | UT99 retail `Core/Src/UnLinker.h`: `FObjectImport`, `FObjectExport`, `GetImportFullName`, `GetExportFullName`, `ULinkerLoad::VerifyImport`, `FindExportIndex` |
| UE3 package-index/resource and verification model | UE3 `Core/Inc/UnLinker.h`, `Core/Src/UnLinker.cpp`: `FObjectResource`, `FObjectImport`, `FObjectExport`, `VerifyImport`, `VerifyImportInner`, path/class helpers |
| UE4 package-index/import/export model | UE4 `CoreUObject/Public/UObject/ObjectResource.h`, `LinkerLoad.h`, `Private/UObject/LinkerLoad.cpp`: `FPackageIndex`, `FObjectImport`, `FObjectExport`, `VerifyImport`, `FindExportIndex`, `BuildPathName` |

## UnrealDB conformance

Apply this operation only after the exact package reader has validated indices and tables. Preserve enough structured evidence to explain why a dependency resolved or failed; do not reduce resolution to a filename/name-only boolean.

## Later-generation verification changes

| Revision | Required-package evidence |
|---|---|
| Unreal II | Root `Core.Package` import reached through the import parent chain is the serialized provider evidence. |
| UE2.5 | Retains that model. |
| UT2003 | Retains it; runtime package remap outside VerifyImport does not rewrite the raw dependency record. |
| UT2004 | Retains it without active resolver PackageRemap. |
| UE3 | Imports plus cooked/remapped outer structures and serialized dependency metadata must be considered; runtime fixups remain separately recorded. |
| UE4 4.27.2 | Explicit package-name/external-package information can identify a provider in addition to classic outer-chain package imports. |

Do not force the UE1/UE2 root-import-only rule onto UE4 packages that serialize additional provider information.
