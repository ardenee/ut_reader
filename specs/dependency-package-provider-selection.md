# Package-Provider Selection

## Scope and authority

This is a dependency-operation specification for UnrealDB. The applicable Epic/game source revision is authoritative. Catalogue convenience must not add, remove, reorder, or weaken semantic loader processing.

Authoritative review now uses the local source trees under `L:\Source\Engine` and `L:\Source\Games`. See `epic-source-conformance.md` for the profile-by-profile audit ledger.

## Source invariant

Epic selects or establishes **one package/linker/package-store provider first**, then verifies or resolves imports/exports inside that selected provider.

UnrealDB must preserve that order:

1. derive the required provider identity exactly as the applicable source revision does;
2. identify the physical provider from source/runtime environment evidence when that evidence is available;
3. only after a single provider is established, apply that revision's import/export verification rules to it;
4. never inspect several same-identity physical providers and choose the one whose contents resolve more imports.

The following are not source-backed provider-selection rules and are forbidden in authoritative dependency resolution:

- greatest import/export coverage;
- complete/superset coverage preference;
- largest file;
- newest upload;
- most exports;
- closest filename;
- redirector count;
- trying each provider until one works.

Coverage analysis may remain a diagnostic/catalog-comparison tool, but it cannot decide authoritative dependency outcomes.

## Provider derivation

For classic UE1/UE2/UE3 packages, the top-level package import establishes the source package/linker. Nested imports recursively resolve their parent and inherit that parent's `SourceLinker`. Exact revision-specific rules remain in the engine/game specifications.

UE4/UE5 classic can additionally use serialized/effective `FObjectImport::PackageName` where the source version permits it. That field selects a provider independently of an import-only outer walk when the source does so.

UE5 Zen/IoStore uses exact package-store identity. A PackageImport identifies `ImportedPackageIds[ImportedPackageIndex]`; object resolution then uses the corresponding public-export hash **inside that selected package**. `FPackageId + PublicExportHash` is an object lookup key, not permission to select a different physical file based on export coverage.

## Multiple physical catalogue candidates

A running Unreal installation has filesystem paths, mounts, already-loaded packages, package-store/container order, redirects and other runtime state that determine which physical provider is visible for a logical package identity. UnrealDB's aggregate catalogue can contain several historical or duplicated physical files while lacking that original runtime ordering.

When exactly one valid physical candidate exists for the source-derived provider identity, UnrealDB may use it and then run the source resolver.

When more than one valid physical candidate exists and no retained source/runtime provenance uniquely establishes which provider Epic would have selected, UnrealDB must return **unresolved provider-environment ambiguity**. It must preserve the candidate file IDs as diagnostic evidence. Database ordering may be used for display only; it must not turn ambiguity into a semantic resolution.

When no candidate exists, the source-derived provider is unavailable in the catalogue. This remains distinct from the multiple-provider ambiguity above.

## Runtime/configuration boundary

UnrealDB cannot reconstruct runtime state that was never retained. That limitation is a documented difference, not a license to substitute heuristics. The correct static outcome is unresolved when the missing runtime/environment state can change the result.

## Source-reference matrix

| Profile | Source-confirmed provider step |
|---|---|
| Unreal UE1 (available full v1.200 implementation) | `Core/Src/UnLinker.h::ULinkerLoad::VerifyImport` calls one `GetPackageLinker`; `Core/Src/UnObj.cpp` resolves it with `appFindPackageFile`. The supplied v1.227 subtree exposes the API but not the implementation body, so later Unreal-specific deltas remain separately auditable. |
| UT99 v1.400 | `Core/Src/UnLinker.h::ULinkerLoad::VerifyImport` establishes one package linker; nested imports inherit the parent's linker. |
| Unreal II / UE2 | `Core/Src/UnLinker.cpp::ULinkerLoad::VerifyImport` establishes one `GetPackageLinker` result; nested imports inherit it. |
| UT2003 v2107 | Same source sequence in its own `Core/Src/UnLinker.cpp`; runtime file lookup remains `appFindPackageFile`. |
| UT2004 v3369 / UE2.5 | Same one-linker sequence in its own `Core/Src/UnLinker.cpp`; `GetPackageLinker`/`appFindPackageFile` include that revision's generation/file lookup state. |
| UT3 January 2008 / UE3 | `Core/Src/UnLinker.cpp::VerifyImportInner` obtains one package linker for a root package and descendants inherit it. |
| UDKUltimate / later UE3 | Full Core linker source is present under `L:\Source\Engine\UE3\Unreal Engine [v3.0] UDKUltimate [05-11-17]` (engine 8364) and the later `L:\Source\Engine\UE3\Unreal Engine 3 (10897)` tree (engine 10897 / changelist 1532151). The audited 10897 linker/version files are byte-identical to `CodeRedModding/UnrealEngine3` build 10897. This closes the source-availability gap but does not widen a game-specific provider policy without its own audit. |
| UT4 / UE4 clean-master v511 | `CoreUObject/Private/UObject/LinkerLoad.cpp::FLinkerLoad::VerifyImportInner` reuses the provider linker established by the top-level Package import before export verification. Final UE4.27.2 remains supplemental only. |
| UE5 5.8.3 classic | `CoreUObject/Private/UObject/LinkerLoad.cpp::FLinkerLoad::VerifyImportInner` follows the same one-provider-first sequence, with UE5-specific PackageName/runtime rules. |
| UE5 5.8.3 Zen/IoStore | `AsyncLoading2.cpp` resolves PackageImports through ordered `ImportedPackageIds` and the global import/package store; the loader maintains a 1:1 PackageId-to-package relationship before public-export-hash lookup. |

## UnrealDB conformance

Authoritative dependency resolution must never merge providers or use their contents to select among duplicates. Any unavoidable environment difference must be listed in `epic-source-conformance.md` with its source behavior, UnrealDB behavior, missing/extra processing status, and reason.
