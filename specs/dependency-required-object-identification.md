# Required Object Identification

## Scope and authority

This is a dependency-operation specification for UnrealDB. It complements the engine-specific package/dependency specs and must never override their exact revision behavior.

Primary source families: UT99 retail v1.400 (`ardenee/UT99src`), Unreal II (`ardenee/unreal2src`), UE2.5 (`ardenee/UE2.5`), UT2003, UT2004, UE3 UDKUltimate (`ardenee/UE3src`), and UE4 4.27.2 (`ardenee/UnrealEngine4`).

Core principle: resolve from serialized package/import/export identity first. Runtime/configuration behavior is documented separately and is not guessed.

## Rule

A required object is the imported resource identified by:

- provider/top-level package;
- complete outer chain;
- `ObjectName`;
- `ClassName`;
- `ClassPackage`;
- any revision-specific serialized identity fields.

Leaf object name alone is not sufficient because groups/outers can contain equal names and classes can differ.

## Resolution

Resolve against candidate provider exports using the engine revision's export class derivation and outer matching. UT99's hash search is an optimization over the same identity tuple; it still checks object name, class name, class package and parent relationship.

Keep package-level requirements and object-level requirements separate so a present package with a missing export is reported as "provider present, object missing", not "package missing".

## Source-reference matrix

| Concern | Authoritative symbols/files |
|---|---|
| UE1 retail import/export identity and verification | UT99 retail `Core/Src/UnLinker.h`: `FObjectImport`, `FObjectExport`, `GetImportFullName`, `GetExportFullName`, `ULinkerLoad::VerifyImport`, `FindExportIndex` |
| UE3 package-index/resource and verification model | UE3 `Core/Inc/UnLinker.h`, `Core/Src/UnLinker.cpp`: `FObjectResource`, `FObjectImport`, `FObjectExport`, `VerifyImport`, `VerifyImportInner`, path/class helpers |
| UE4 package-index/import/export model | UE4 `CoreUObject/Public/UObject/ObjectResource.h`, `LinkerLoad.h`, `Private/UObject/LinkerLoad.cpp`: `FPackageIndex`, `FObjectImport`, `FObjectExport`, `VerifyImport`, `FindExportIndex`, `BuildPathName` |

## UnrealDB conformance

Apply this operation only after the exact package reader has validated indices and tables. Preserve enough structured evidence to explain why a dependency resolved or failed; do not reduce resolution to a filename/name-only boolean.

## Later-generation verification changes

| Revision | Required-object identity |
|---|---|
| Unreal II | Exact provider export match uses ObjectName + ClassName + ClassPackage plus parent/outer qualification; Mesh->LodMesh is the explicit compatibility retry. |
| UE2.5 | Same core exact identity and explicit Mesh fallback. |
| UT2003 | Same core identity; FindExportIndex subclass matching is separate from VerifyImport. |
| UT2004 | Same distinction between VerifyImport identity and broader FindExportIndex behavior. |
| UE3 | Adds mixed outer graphs, redirector/runtime paths and serialized DependsMap relationships. |
| UE4 4.27.2 | Adds explicit package/external-package cases, privacy, redirects/instancing and preload/dependency tables; raw import identity must remain distinguishable from resolved identity. |

A leaf-name match alone is never sufficient in any reviewed generation.
