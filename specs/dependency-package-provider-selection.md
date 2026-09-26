# Package-Provider Selection

## Scope and authority

This is a dependency-operation specification for UnrealDB. It complements the engine-specific package/dependency specs and must never override their exact revision behavior.

Primary source families: UT99 retail v1.400 (`ardenee/UT99src`), Unreal II (`ardenee/unreal2src`), UE2.5 (`ardenee/UE2.5`), UT2003, UT2004, UE3 UDKUltimate (`ardenee/UE3src`), and UE4 4.27.2 (`ardenee/UnrealEngine4`).

Core principle: resolve from serialized package/import/export identity first. Runtime/configuration behavior is documented separately and is not guessed.

## Provider derivation

Provider selection starts from the import's outer chain, not from filename extension or a global same-name search.

A top-level package import is the source-defined root case: its object name identifies the required package. A nested import recursively follows its outer import/package index until the top-level package resource is reached, then searches that package's linker/export map.

The provider's catalog filename may differ from the logical package name. Selection must therefore use parsed package identity/name/profile rules rather than "first file whose basename looks similar".

## Multiple candidates

When multiple catalog files can provide the same logical package, UnrealDB must not pick arbitrarily. Filter candidates by the applicable game/engine profile, valid package format/revision, package identity information available to that revision, and ability to satisfy the required export identity.

A provider that merely contains an object with the same leaf name is insufficient.

## UnrealDB catalog-level superset selection

A running Unreal installation will normally encounter one physical package for a logical package name according to its configured package search path. UnrealDB intentionally retains multiple historical, trimmed, duplicated, or poorly named physical package files, so it needs one additional catalog-level selection stage that does not exist as such in the engine loader.

That additional stage must not weaken or replace engine verification. The required order is:

1. derive requirements only from the consumer's serialized ImportMap;
2. evaluate each physical candidate independently using the selected target game's source-backed import-verification semantics;
3. record only imports that the candidate genuinely satisfies under those semantics;
4. compare the resulting verified coverage sets;
5. prefer a single candidate whose verified coverage contains the complete required set when one exists.

The coverage identity is therefore whatever the applicable engine revision accepts during import verification, not a path-string approximation. For UE1/UE2 this includes the source-defined object/class/package/outer/public rules and only documented revision-specific fallbacks. For UE3 it includes the corresponding `VerifyImportInner` identity and visibility checks. Later generations use their applicable source-backed resolver rules.

A package is a useful common/superset provider when its **verified coverage set** is a superset of the union of known required imports. It does not need to contain the most total exports, have the largest file size, or be the newest file. Several incomplete physical providers must never be combined to pretend that one package satisfies the complete requirement set.

Cross-game provider discovery follows the same rule: the source game only tells UnrealDB where another physical candidate can be found. Whether that candidate satisfies an import is decided by the **target game's** dependency semantics. `dependency-cross-examine.php` must therefore use the same target-game verification path as normal dependency rebuilding before presenting a candidate as a full match or allowing it to be queued.

## Runtime loading

Engine loaders can search configured paths, mounted packages, already-loaded packages and runtime package systems. UnrealDB does not possess that complete search environment. Catalog provider policy must remain explicit and separate from claims about what a running game would choose.

## Source-reference matrix

| Concern | Authoritative symbols/files |
|---|---|
| UE1 retail import/export identity and verification | UT99 retail `Core/Src/UnLinker.h`: `FObjectImport`, `FObjectExport`, `GetImportFullName`, `GetExportFullName`, `ULinkerLoad::VerifyImport`, `FindExportIndex` |
| UE3 package-index/resource and verification model | UE3 `Core/Inc/UnLinker.h`, `Core/Src/UnLinker.cpp`: `FObjectResource`, `FObjectImport`, `FObjectExport`, `VerifyImport`, `VerifyImportInner`, path/class helpers |
| UE4 package-index/import/export model | UE4 `CoreUObject/Public/UObject/ObjectResource.h`, `LinkerLoad.h`, `Private/UObject/LinkerLoad.cpp`: `FPackageIndex`, `FObjectImport`, `FObjectExport`, `VerifyImport`, `FindExportIndex`, `BuildPathName` |

## UnrealDB conformance

Apply this operation only after the exact package reader has validated indices and tables. Preserve enough structured evidence to explain why a dependency resolved or failed; do not reduce resolution to a filename/name-only boolean.

## Later-generation verification changes

| Revision | Provider-selection change |
|---|---|
| Unreal II | Root package import still selects provider by package name; `VerifyImport` passes no compatible GUID. Filesystem `appFindPackageFile` policy is runtime state. |
| UE2.5 | Same basic top-level package-import provider model. |
| UT2003 | Same model, with runtime package-remap behavior existing outside import verification. |
| UT2004 | Same serialized root-provider model; no active fuzzy/remap provider selection in the reviewed resolver. |
| UE3 | Provider selection is affected by cooked/seek-free and remapped-package behavior; import fixups can alter runtime resolution. |
| UE4 4.27.2 | Imports may carry/use explicit provider package information and external-package semantics; mounts, script packages, CoreRedirects and instancing can alter runtime selection. |

Catalog selection should preserve the serialized provider first, then annotate any reproducible revision-specific transformation.
