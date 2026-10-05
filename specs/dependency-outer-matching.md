# Outer Matching

## Scope and authority

This is a dependency-operation specification for UnrealDB. It complements the engine-specific package/dependency specs and must never override their exact revision behavior.

Primary source families: UT99 retail v1.400 (`ardenee/UT99src`), Unreal II (`ardenee/unreal2src`), UE2.5 (`ardenee/UE2.5`), UT2003, UT2004, UE3 UDKUltimate (`ardenee/UE3src`), and UE4 4.27.2 (`ardenee/UnrealEngine4`).

Core principle: resolve from serialized package/import/export identity first. Runtime/configuration behavior is documented separately and is not guessed.

## Exact parent relation

After object/class identity matches, verify the expected outer.

UT99 retail proves the rule directly: when an import has a parent import, the verified parent `SourceIndex + 1` must equal the candidate source export's package/outer index. If the parent has no `SourceIndex`, the candidate must be provider-root (`PackageIndex==0`). UT99 also explicitly accepts a provider-root export when a resolved parent's `SourceIndex + 1` does not match.

The order matters: Epic checks object/class identity, then this parent/outer relation, then `RF_Public`. A private candidate rejected by outer matching must therefore not cause a private-import failure; a private candidate that passes identity and outer matching does.

UT99 constructs each `ExportHash` bucket by iterating `ExportMap` from low to high and prepending each export. Verification consequently traverses same-bucket candidates in descending export-index order. UnrealDB's per-provider UE1/UE2 verification preserves that ordering before applying outer and visibility checks.

Later engines use the same signed package-index graph with revision-specific cooked/export-outer behavior.

## Full chain

For static dependency analysis, compare the complete source-backed outer chain, not only the immediate parent string. Import and export outers can cross their respective maps in later revisions.

A matching leaf/class under a different group/outer is a distinct object and must be reported as outer mismatch.

## Multi-provider catalog boundary

Epic resolves one physical package/linker before import verification. UnrealDB can retain several historical, trimmed, conflicting, or poorly named physical files for the same logical package, but that catalogue state does not create a new source-valid selection rule.

When source/runtime provenance uniquely identifies one physical provider, run the exact profile's import verification against that provider only. When several valid physical providers remain and the original filesystem/mount/package-store ordering is unavailable, provider identity is unresolved. UnrealDB must not inspect candidate contents, count matches, rank coverage, or combine exports in order to choose one.

Coverage/superset reports may still compare package variants for diagnostics or manual repair analysis. They are not dependency semantics and must never feed authoritative provider selection.

## Source-reference matrix

| Concern | Authoritative symbols/files |
|---|---|
| UE1 retail import/export identity and verification | UT99 retail `Core/Src/UnLinker.h`: `FObjectImport`, `FObjectExport`, `GetImportFullName`, `GetExportFullName`, `ULinkerLoad::VerifyImport`, `FindExportIndex`, `ExportHash` construction |
| UE3 package-index/resource and verification model | UE3 `Core/Inc/UnLinker.h`, `Core/Src/UnLinker.cpp`: `FObjectResource`, `FObjectImport`, `FObjectExport`, `VerifyImport`, `VerifyImportInner`, path/class helpers |
| UE4 package-index/import/export model | UE4 `CoreUObject/Public/UObject/ObjectResource.h`, `LinkerLoad.h`, `Private/UObject/LinkerLoad.cpp`: `FPackageIndex`, `FObjectImport`, `FObjectExport`, `VerifyImport`, `FindExportIndex`, `BuildPathName` |

## UnrealDB conformance

Apply this operation only after the exact package reader has validated indices and tables. Preserve enough structured evidence to explain why a dependency resolved or failed; do not reduce resolution to a filename/name-only boolean.

For every profile, outer verification begins only after one physical provider has been established. Diagnostic multi-provider coverage may reuse source-backed matching for comparison, but it must not select the provider used by production dependency resolution.

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
