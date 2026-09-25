# Outer Matching

## Scope and authority

This is a dependency-operation specification for UnrealDB. It complements the engine-specific package/dependency specs and must never override their exact revision behavior.

Primary source families: UT99 retail v1.400 (`ardenee/UT99src`), Unreal II (`ardenee/unreal2src`), UE2.5 (`ardenee/UE2.5`), UT2003, UT2004, UE3 UDKUltimate (`ardenee/UE3src`), and UE4 4.27.2 (`ardenee/UnrealEngine4`).

Core principle: resolve from serialized package/import/export identity first. Runtime/configuration behavior is documented separately and is not guessed.

## Exact parent relation

After object/class identity matches, verify the expected outer.

UT99 retail proves the rule directly: when an import has a parent import, the verified parent `SourceIndex + 1` must equal the candidate source export's package/outer index. A source export at root can be accepted only where the source code explicitly permits that case.

Later engines use the same signed package-index graph with revision-specific cooked/export-outer behavior.

## Full chain

For static dependency analysis, compare the complete source-backed outer chain, not only the immediate parent string. Import and export outers can cross their respective maps in later revisions.

A matching leaf/class under a different group/outer is a distinct object and must be reported as outer mismatch.

## Source-reference matrix

| Concern | Authoritative symbols/files |
|---|---|
| UE1 retail import/export identity and verification | UT99 retail `Core/Src/UnLinker.h`: `FObjectImport`, `FObjectExport`, `GetImportFullName`, `GetExportFullName`, `ULinkerLoad::VerifyImport`, `FindExportIndex` |
| UE3 package-index/resource and verification model | UE3 `Core/Inc/UnLinker.h`, `Core/Src/UnLinker.cpp`: `FObjectResource`, `FObjectImport`, `FObjectExport`, `VerifyImport`, `VerifyImportInner`, path/class helpers |
| UE4 package-index/import/export model | UE4 `CoreUObject/Public/UObject/ObjectResource.h`, `LinkerLoad.h`, `Private/UObject/LinkerLoad.cpp`: `FPackageIndex`, `FObjectImport`, `FObjectExport`, `VerifyImport`, `FindExportIndex`, `BuildPathName` |

## UnrealDB conformance

Apply this operation only after the exact package reader has validated indices and tables. Preserve enough structured evidence to explain why a dependency resolved or failed; do not reduce resolution to a filename/name-only boolean.

## Later-generation verification changes

| Revision | Outer rule |
|---|---|
| Unreal II | Candidate normally matches verified parent SourceIndex+1, but a provider-root export (`PackageIndex==0`) is explicitly accepted when parent matching does not line up. |
| UE2.5 | Retains this root-export acceptance. |
| UT2003 | Retains it. |
| UT2004 | Retains it. |
| UE3 | Cooked seek-free outer graphs can mix imports and exports; verification must resolve the actual signed package index. |
| UE4 4.27.2 | Modern FPackageIndex outer matching includes dynamic/external-package and instancing cases; exact raw and transformed outers should be kept separate. |

The UT99 parent rule therefore cannot simply be copied unchanged into UE3/UE4 cooked resolution.
