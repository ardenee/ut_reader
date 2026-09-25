# Class and Package Matching

## Scope and authority

This is a dependency-operation specification for UnrealDB. It complements the engine-specific package/dependency specs and must never override their exact revision behavior.

Primary source families: UT99 retail v1.400 (`ardenee/UT99src`), Unreal II (`ardenee/unreal2src`), UE2.5 (`ardenee/UE2.5`), UT2003, UT2004, UE3 UDKUltimate (`ardenee/UE3src`), and UE4 4.27.2 (`ardenee/UnrealEngine4`).

Core principle: resolve from serialized package/import/export identity first. Runtime/configuration behavior is documented separately and is not guessed.

## Import side

`FObjectImport.ClassPackage` and `ClassName` describe the imported object's class. They are not the object's provider path.

## Export side

Derive export class identity through `ClassIndex` using the exact engine rules. UT99 and UE3 source expose `GetExportClassName` and `GetExportClassPackage`: negative class index resolves through an import; positive class index resolves locally; zero represents `Class` with `Core` package semantics.

Match the import's class package/name against this derived export class identity.

## Exceptions

Only explicit source exceptions may relax equality. UT99 retail contains the historical UnrealI/UnrealShare class-package compatibility and Mesh/LodMesh fallback. Such rules must be scoped to that revision.

Case/name semantics must follow `FName` behavior for the applicable engine; do not apply filesystem case rules as a substitute.

## Source-reference matrix

| Concern | Authoritative symbols/files |
|---|---|
| UE1 retail import/export identity and verification | UT99 retail `Core/Src/UnLinker.h`: `FObjectImport`, `FObjectExport`, `GetImportFullName`, `GetExportFullName`, `ULinkerLoad::VerifyImport`, `FindExportIndex` |
| UE3 package-index/resource and verification model | UE3 `Core/Inc/UnLinker.h`, `Core/Src/UnLinker.cpp`: `FObjectResource`, `FObjectImport`, `FObjectExport`, `VerifyImport`, `VerifyImportInner`, path/class helpers |
| UE4 package-index/import/export model | UE4 `CoreUObject/Public/UObject/ObjectResource.h`, `LinkerLoad.h`, `Private/UObject/LinkerLoad.cpp`: `FPackageIndex`, `FObjectImport`, `FObjectExport`, `VerifyImport`, `FindExportIndex`, `BuildPathName` |

## UnrealDB conformance

Apply this operation only after the exact package reader has validated indices and tables. Preserve enough structured evidence to explain why a dependency resolved or failed; do not reduce resolution to a filename/name-only boolean.
