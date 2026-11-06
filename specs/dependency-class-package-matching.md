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

## Later-generation verification changes

| Revision | Class/package matching |
|---|---|
| Unreal II v69 (12-09-2000) | Exact object/class matching plus the `UnrealI` / `UnrealShare` compatibility exception, hash normalization and an unconditional `Mesh -> LodMesh` second pass. This December 2000 loader is source-backed for admitted versions 60–69, subject to the original source/licensee boundary. |
| Unreal II v126 (01-07-2003) | The **separate genuine Unreal II game source** has complete `ULinkerLoad::VerifyImport` and exact object/class/package matching in the selected SourceLinker. Unlike v69, it does not activate `UnrealI` / `UnrealShare` aliasing and its non-forgiving private-export exception is disabled. Exact source-emitted v126/licensee-0 is independently verified. |
| UT2004 v129 | Exact ObjectName/ClassName/ClassPackage matching from the latest complete `UT2004SrcCmake` linker. No UnrealI/UnrealShare class-package exception is active. `Mesh -> LodMesh` is an unconditional second hash pass. Its exact game-source verification profiles are v127/lic29, v128/lic29 and v129/lic29 (with separately observed v129/lic27 compatibility); other loadable versions do not automatically inherit that certification. |
| UT2003 v2107 | Exact class-package/name matching with no UnrealI/UnrealShare exception. `Mesh -> LodMesh` remains an unconditional Rehack; broader subclass matching belongs to FindExportIndex, not VerifyImport. Its exact game-source verification profile is v120/lic28; other loadable versions remain source-version-unverified until separately evidenced. |
| UE2.5 / Unreal Warfare | Remains its own separately documented engine profile; UT2004 behavior is not used as a substitute for that source. |
| UE3 | Import fixups/redirectors can transform runtime resolution, but raw ClassPackage/ClassName remain the serialized identity. |
| UE4 4.27.2 | CoreRedirects and instancing can alter runtime names/packages/classes; package privacy and explicit provider data also participate. These are transformations, not permission for fuzzy matching. |

Store both raw and resolved identity whenever a later-engine transformation is reproducible.
