# Epic/Game Source Conformance Audit

## Governing rule

For every supported UE/game profile, UnrealDB must perform the semantic processing present in that profile's authoritative Epic/game source: nothing extra and nothing omitted.

Implementation architecture may differ for storage, indexing, caching, batching and SQL acceleration only when those changes do not alter semantic results. Any semantic difference must be recorded here with:

- authoritative source behavior;
- UnrealDB behavior;
- whether UnrealDB has extra or missing processing;
- why the difference exists;
- whether the difference is acceptable, unresolved, or requires code correction.

A runtime/configuration state that was not retained is **not** permission to guess. If that state can change the result, the static result must remain unresolved.

## Section 1 — physical package/provider selection before import verification

### Source result

The reviewed local source establishes the same sequencing principle across the active profiles:

- classic UE1/UE2/UE3: root package import obtains one package linker; nested imports inherit that linker's `SourceLinker`;
- UE4 4.27.2 and UE5 5.8.3 classic: one package/linker is loaded or found first (including source-defined `PackageName` cases), then `VerifyImportInner` searches that linker's exports;
- UE5 Zen/IoStore: a PackageImport first names an exact `FPackageId`; the global package/import store establishes the PackageId-to-package relationship, then `PublicExportHash` is searched inside that provider package.

No reviewed source path tries every same-package physical file and chooses the file whose exports satisfy the greatest number of imports.

### Profile ledger

| Profile | Local authority checked | Section-1 status | Notes |
|---|---|---|---|
| Unreal / Unreal Gold UE1 | `L:\Source\Games\Unreal\Unreal [v1.200] [1998-05-19]\Core\Src\UnLinker.h`, `UnObj.cpp`; v1.227 header also inspected | **partial source coverage** | v1.200 implementation proves one-linker-first selection. The supplied v1.227 subtree exposes `VerifyImport` declarations but no implementation body was found, so 227-specific provider-selection deltas remain open. Do not substitute UT99 behavior for that missing body. |
| UT99 v1.400 | `L:\Source\Games\UT99\Unreal Tournament [v1.400] [1999-11-30]\Core\Src\UnLinker.h`, `UnObj.cpp` | **source-confirmed** | One `GetPackageLinker`; child imports inherit parent `SourceLinker`. |
| Unreal II / UE2 | `L:\Source\Games\Unreal II\...\Core\Src\UnLinker.cpp`, `UnObj.cpp` | **source-confirmed** | One package linker precedes export matching. |
| UT2003 v2107 | `L:\Source\Games\UT2003\...\Core\Src\UnLinker.cpp`, `UnObj.cpp` | **source-confirmed** | One package linker; no catalogue coverage ranking. |
| UT2004 v3369 / UE2.5 | `L:\Source\Games\UT2004\...\Core\Src\UnLinker.cpp`, `UnObj.cpp` | **source-confirmed** | One package linker; generation/file lookup is runtime environment state. |
| UT3 Jan-2008 / UE3 | `L:\Source\Engine\UE3\Unreal Engine [v3.0] [01-00-2008]\...\Core\Src\UnLinker.cpp` | **source-confirmed** | `VerifyImportInner` selects one package linker, then verifies imports. |
| UDKUltimate / later UE3 | Current `L:\Source\Engine\UDK` contains a game sample, not the UDKUltimate C++ linker tree named by the existing spec | **not re-verified locally** | No active catalogue game profile currently depends on this tree. Restore the authoritative source before new conformance claims. |
| UT4 / UE4 4.27.2 | `L:\Source\Engine\UE4\UE 4.27.2\Engine\Source\Runtime\CoreUObject\Private\UObject\LinkerLoad.cpp` | **source-confirmed** | `LoadImportPackage`/`GetPackageLinker` establishes provider before export verification. |
| UE5 5.8.3 classic | `L:\Source\Engine\UE5\UE 5.8.3\Engine\Source\Runtime\CoreUObject\Private\UObject\LinkerLoad.cpp` | **source-confirmed** | One provider linker, with UE5-specific PackageName/runtime branches. |
| UE5 5.8.3 Zen/IoStore | `L:\Source\Engine\UE5\UE 5.8.3\Engine\Source\Runtime\CoreUObject\Private\Serialization\AsyncLoading2.cpp` and package-store types | **source-confirmed** | Exact PackageId provider relationship precedes public-export-hash lookup. |

### UnrealDB difference found

**Previous UnrealDB behavior:** V4 and V5 enumerated duplicate physical providers, executed import/object resolution against each one, counted successful matches (and in UE4 redirector evidence), and chose the best/complete provider.

**Classification:** **extra processing — non-compliant.** It could select a physical package Epic would not have selected in the original runtime environment.

**Correction:** authoritative dependency resolution no longer uses provider-content coverage to choose a physical file. Coverage tooling remains diagnostic only.

### Unavoidable environment difference retained

**Epic behavior:** runtime filesystem search paths, mounts, loaded-package state, package-store/container state and source-defined redirects determine the physical package visible for a package identity.

**UnrealDB limitation:** the aggregate catalogue does not retain enough provenance to reconstruct that historical runtime order for every duplicate file.

**Correct static behavior:**

- zero valid physical candidates: provider unavailable/missing;
- exactly one valid physical candidate: select it, then run the exact profile resolver;
- more than one valid physical candidate with no authoritative runtime-order evidence: `unresolved` with reason `provider_environment_ambiguous` and candidate file IDs preserved.

**Classification:** missing runtime/environment state, explicitly represented as unresolved. No heuristic substitution is permitted.

### Implementation points

- V4: `PdoDependencyResolver` detects duplicate physical candidates before UE1/UE2/UE3/UE4 verification and emits `provider_environment_ambiguous` rather than scoring candidate contents.
- V5: `PdoUedb5PhysicalProviderSelector` returns either one selected provider or an ambiguity record. `Uedb5DependencyRebuilder` converts dependencies for that package identity to canonical `unresolved` while preserving candidate IDs.
- Pass-2 policy is bumped from `uedb5-dependency-pass-v1` to `uedb5-dependency-pass-v2`. `transition-uedb5-provider-selection-policy.php` proves impact from indexed V5 dependency/package-provider keys: current v1 payloads that do not reference a package identity with multiple valid physical providers are rolled forward without rewriting package metadata; only impacted consumers require an exact-file dependency rebuild.
- UE5 Zen duplicates are treated the same way at the physical catalogue boundary; `PublicExportHash` remains an object lookup inside an already-established `FPackageId` provider and never selects a provider file.
- Cross-game repair candidate validation uses the target profile's UE1/UE2, UE3, or UE4 source-backed VerifyImport resolver. Unsupported profiles fail closed; generic path/class coverage cannot certify or queue a dependency-complete repair candidate.

## Next audit section

Section 2 must audit **provider identity derivation and package-loading transformations** profile by profile: package root/outer traversal, compatible GUID/generation inputs, UE3 fixups/remaps, UE4/UE5 `PackageName`, redirects/instancing boundaries, and Zen redirects/localization/package-store context. No existing documentation is presumed correct until checked against the local authoritative source.
