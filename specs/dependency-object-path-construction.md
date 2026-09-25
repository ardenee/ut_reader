# Object-Path Construction

## Scope and authority

This is a dependency-operation specification for UnrealDB. It complements the engine-specific package/dependency specs and must never override their exact revision behavior.

Primary source families: UT99 retail v1.400 (`ardenee/UT99src`), Unreal II (`ardenee/unreal2src`), UE2.5 (`ardenee/UE2.5`), UT2003, UT2004, UE3 UDKUltimate (`ardenee/UE3src`), and UE4 4.27.2 (`ardenee/UnrealEngine4`).

Core principle: resolve from serialized package/import/export identity first. Runtime/configuration behavior is documented separately and is not guessed.

## Construction

Build a resource path by walking `OuterIndex`/historical `PackageIndex` from leaf to root and prepending each `ObjectName`.

UT99 retail `GetImportFullName` follows negative import parents until zero. UE3 `GetImportPathName` demonstrates that cooked packages can have import outers represented by exports and that a subobject delimiter may be required when crossing package/object boundaries. UE4 likewise uses `FPackageIndex` outer traversal.

Therefore path construction must be engine/revision aware; it is not always "join every name with a dot".

## Safety

Validate each signed index before dereference, retain import/export kind, detect cycles, and stop only at the source-defined root sentinel. Never repair a broken chain by dropping invalid outers.

Store both the component chain and rendered path so matching does not depend on lossy string formatting.

## Source-reference matrix

| Concern | Authoritative symbols/files |
|---|---|
| UE1 retail import/export identity and verification | UT99 retail `Core/Src/UnLinker.h`: `FObjectImport`, `FObjectExport`, `GetImportFullName`, `GetExportFullName`, `ULinkerLoad::VerifyImport`, `FindExportIndex` |
| UE3 package-index/resource and verification model | UE3 `Core/Inc/UnLinker.h`, `Core/Src/UnLinker.cpp`: `FObjectResource`, `FObjectImport`, `FObjectExport`, `VerifyImport`, `VerifyImportInner`, path/class helpers |
| UE4 package-index/import/export model | UE4 `CoreUObject/Public/UObject/ObjectResource.h`, `LinkerLoad.h`, `Private/UObject/LinkerLoad.cpp`: `FPackageIndex`, `FObjectImport`, `FObjectExport`, `VerifyImport`, `FindExportIndex`, `BuildPathName` |

## UnrealDB conformance

Apply this operation only after the exact package reader has validated indices and tables. Preserve enough structured evidence to explain why a dependency resolved or failed; do not reduce resolution to a filename/name-only boolean.

## Later-generation verification changes

| Revision | Path behavior |
|---|---|
| Unreal II | Import paths follow negative parent imports; export paths follow positive export parents. |
| UE2.5 | Retains UE2 parent-chain path behavior. |
| UT2003 | Retains the same signed parent traversal. |
| UT2004 | Retains the same core UE2 path model. |
| UE3 | Cooked/seek-free outer graphs can mix import and export package indices; path rendering can distinguish subobject boundaries. |
| UE4 4.27.2 | Signed `FPackageIndex` traversal remains, with explicit package-name/external-package metadata and modern `BuildPathName` behavior. |

Later cooked formats therefore require per-hop package-index resolution rather than an import-only parent walk.
