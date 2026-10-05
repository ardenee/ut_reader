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

## Section 1 â€” physical package/provider selection before import verification

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

**Classification:** **extra processing â€” non-compliant.** It could select a physical package Epic would not have selected in the original runtime environment.

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
- Section 1 originally moved Pass 2 from `uedb5-dependency-pass-v1` to `uedb5-dependency-pass-v2`. Section 2A now supersedes that one-off transition with the combined `transition-uedb5-source-identity-policy.php` v1/v2 -> v3 transition described below; the Section-1 ambiguity rule remains part of the v3 impact proof.
- UE5 Zen duplicates are treated the same way at the physical catalogue boundary; `PublicExportHash` remains an object lookup inside an already-established `FPackageId` provider and never selects a provider file.
- Cross-game repair candidate validation uses the target profile's UE1/UE2, UE3, or UE4 source-backed VerifyImport resolver. Unsupported profiles fail closed; generic path/class coverage cannot certify or queue a dependency-complete repair candidate.

## Section 2 — provider identity derivation and package-loading transformations

### Section 2A — UE1/UE2/UE3 classic identity and load-time transformations

**Status: complete and source-confirmed for the active UE1/UE2/UE3 profiles covered below.** UE4/UE5 remain explicitly outside this checkpoint and retain their pre-2A package-key normalization until Section 2B.

#### Local authority checked

- Unreal v1.200: `L:\Source\Games\Unreal\Unreal [v1.200] [1998-05-19]\Core\Src\UnObj.cpp` and `UnLinker.h`.
- UT99 v1.400: `L:\Source\Games\UT99\Unreal Tournament [v1.400] [1999-11-30]\Core\Src\UnObj.cpp` and `UnLinker.h`.
- Unreal II: `L:\Source\Games\Unreal II\Unreal II The Awakening [12-09-2000]\Unreal2_old\Core\Src\UnLinker.cpp`, `UnObj.cpp`, and `Core\Inc\UnLinker.h`.
- UT2003 v2107: `L:\Source\Games\UT2003\Unreal Tournament 2003 [v2107] [2002-10-01]\Core\Src\UnLinker.cpp`, `UnObj.cpp`, and `Core\Inc\UnLinker.h`.
- UT2004 v3369: `L:\Source\Games\UT2004\Unreal Tournament 2004 [v3369] [03-16-2004]\Core\Src\UnLinker.cpp`, `UnObj.cpp`, and `Core\Inc\UnLinker.h`.
- UE3 January 2008: `L:\Source\Engine\UE3\Unreal Engine [v3.0] [01-00-2008]\epic.jan2008\UnrealEngine3\Development\Src\Core\Src\UnLinker.cpp`, `Inc\UnLinker.h`, and `Inc\UnObjBas.h`.
- UE3 May 2011 was compared for later `RemapClasses`, GUID-map, and linker deltas.

The directory `L:\Source\Games\Unreal II\Unreal II The Awakening [01-07-2003]` was **not** accepted as Unreal-II authority: its linker files are modern `CoreUObject`-style source and do not match the UE2-era tree implied by the directory label. No Unreal-II rule in this checkpoint is derived from that mislabeled tree.

#### Source results and corrections

1. **Serialized FName identity is not trimmed.** UE1/UE2/UE3 import matching, root-package traversal, class identity, and the audited UE3 compatibility comparisons operate on FName identity. Leading/trailing whitespace is therefore identity data, not presentation noise. UnrealDB previously trimmed several of these fields in derived identity helpers. Those source-semantic paths now case-fold where FName comparison requires it but do not trim or clean the serialized text. Catalogue/search normalization remains a separate trimmed key.
2. **Package roots come from the serialized outer graph.** UE1/UE2 follow `PackageIndex`; UE3 follows the `OuterIndex` package-index graph. UnrealDB no longer substitutes a trimmed display path when deriving the source package root for these profiles.
3. **UE3 `GetImportPathName` delimiter semantics are preserved.** UE3 uses `SUBOBJECT_DELIMITER` (`:`) when the package/object boundary requires subobject notation; it does not universally join the graph with `.`. UEDB-derived UE3 identity paths now reproduce that rule.
4. **UE3 `FixupImportMap` comparisons are exact FName comparisons.** `SoundCueLocalized`/`SoundCue` and `SequenceObjects`/`Engine` compatibility remaps execute only for the source-defined names and relationships. A padded name must not accidentally trigger a compatibility remap.
5. **`RemapClasses` is revision/profile specific.** Later UE3 source performs the pre-`VER_FIXED_PREFAB_SEQUENCES` prefab sequence class correction, but the January-2008 UT3 linker does not contain that later step. UnrealDB therefore keeps the owning game/source policy boundary and does not infer the later rule from package version alone.
6. **GUID/generation are not ordinary ImportMap provider constraints.** The reviewed package loaders contain GUID/generation facilities, and early Unreal uses a heritage GUID list while later classic summaries use package GUID/generation tables. However, the audited `VerifyImport` paths obtain their package linker without passing a compatible GUID or generation-level restriction. UnrealDB must not invent those fields as normal dependency-provider filters.

#### V5 projection and migration boundary

UEDB5 now has a distinct SQL accelerator key kind for the source-exact classic FName package identity. It is used only when the staged import schema is UE1, UE2, or UE3. UE4/UE5 classic packages continue to use the existing normalized package-name key until Section 2B verifies their `PackageName` and redirect/instancing rules.

Pass 2 is therefore `uedb5-dependency-pass-v3`. `transition-uedb5-source-identity-policy.php` combines the prior v1->v2 duplicate-provider correction with the Section-2A exact-FName correction:

- the read-only impact proof starts from files that actually have a current v1/v2 dependency checkpoint; it does not scan the whole term dictionary or original package store;
- v1 files are rebuilt when either provider ambiguity or a source-FName correction can change the result;
- v2 files are rebuilt only for the new source-FName corrections because v2 already incorporated provider ambiguity;
- unaffected files are rolled forward and UE1/UE2/UE3 package keys are regenerated from retained SQL text through the same PHP FName-key function used by normal publication;
- impacted files use exact-file Pass-2 rebuilding from already-staged UEDB5 metadata; no original game-package reparse is required;
- UE4/UE5 package-key behavior is deliberately unchanged by this transition.

`diagnose-classic-source-identity-impact.php` exposes the same proof read-only. It requires the UEDB5 staging/status schema in the database to which the checkout is connected.

### Section 2B — queued

Audit UE4/UE5 `PackageName`, CoreRedirect/fixup and instancing boundaries, then UE5 Zen redirects/localization/package-store context. Do not extend the Section-2A UE1/UE2/UE3 FName package-key conclusion into UE4/UE5 without that source audit.
