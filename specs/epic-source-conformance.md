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
| Unreal II / UE2 | `L:\Source\Games\Unreal II\Unreal II The Awakening [12-09-2000]\Unreal2_old\Core\Src\UnLinker.cpp`, `UnObj.cpp`, `Core\Inc\UnObjVer.h` | **partial source coverage** | Latest complete local linker is package v69 (loadable v60-69 for this audit). The newer/final v70-128 packages do not have a complete local UE2 `VerifyImport` implementation and therefore fail closed for object verification. |
| UT2003 v2107 | `L:\Source\Games\UT2003\...\Core\Src\UnLinker.cpp`, `UnObj.cpp` | **source-confirmed** | One package linker; no catalogue coverage ranking. |
| UT2004 v129 / UE2.5 game profile | `L:\Source\Games\UT2004\UT2004Src\UT2004SrcCmake\Core\Src\UnLinker.cpp`, `UnObj.cpp`, `Core\Inc\UnObjVer.h` | **source-confirmed** | Latest complete local source; package v129/min v60. v3369/v128 was cross-checked. One package linker precedes exact import verification. |
| UT3 v512 / early-2008 UE3 | `L:\Source\Engine\UE3\Unreal Engine [v3.0] [01-00-2008]\...\Core\Src\UnLinker.cpp`; March-2008 copy hash-identical | **source-confirmed** | Active game profile is v512/licensee 0. The checkout itself reaches engine v530/min491; that wider engine range is not inherited as UT3 policy. `VerifyImportInner` selects one package linker, then verifies imports. |
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
- Section 1 originally moved Pass 2 from `uedb5-dependency-pass-v1` to `uedb5-dependency-pass-v2`. Section 2A now supersedes that one-off transition with the combined `transition-uedb5-source-identity-policy.php` v1/v2 -> v3 transition described below; the Section-1 ambiguity rule remains part of the v3 impact proof.
- UE5 Zen duplicates are treated the same way at the physical catalogue boundary; `PublicExportHash` remains an object lookup inside an already-established `FPackageId` provider and never selects a provider file.
- Cross-game repair candidate validation uses the target profile's UE1/UE2, UE3, or UE4 source-backed VerifyImport resolver. Unsupported profiles fail closed; generic path/class coverage cannot certify or queue a dependency-complete repair candidate.

## Section 2 � provider identity derivation and package-loading transformations

### Section 2A � UE1/UE2/UE3 classic identity and load-time transformations

**Status: complete and source-confirmed for the active UE1/UE2/UE3 profiles covered below.** This subsection records the 2A checkpoint; Section 2B1 below extends the same exact-FName conclusion to UE4/UE5 classic only after their own source audit.

#### Local authority checked

- Unreal v1.200: `L:\Source\Games\Unreal\Unreal [v1.200] [1998-05-19]\Core\Src\UnObj.cpp` and `UnLinker.h`.
- UT99 v1.400: `L:\Source\Games\UT99\Unreal Tournament [v1.400] [1999-11-30]\Core\Src\UnObj.cpp` and `UnLinker.h`.
- Unreal II: `L:\Source\Games\Unreal II\Unreal II The Awakening [12-09-2000]\Unreal2_old\Core\Src\UnLinker.cpp`, `UnObj.cpp`, and `Core\Inc\UnLinker.h`.
- UT2003 v2107: `L:\Source\Games\UT2003\Unreal Tournament 2003 [v2107] [2002-10-01]\Core\Src\UnLinker.cpp`, `UnObj.cpp`, and `Core\Inc\UnLinker.h`.
- UT2004 v129: `L:\Source\Games\UT2004\UT2004Src\UT2004SrcCmake\Core\Src\UnLinker.cpp`, `UnObj.cpp`, and `Core\Inc\UnObjVer.h`; v3369/v128 was independently cross-checked.
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

At the 2A checkpoint, UEDB5 introduced package-key kind `3` for exact-text, case-insensitive classic FName identity and limited it to UE1/UE2/UE3 until modern classic source could be audited. That historical v3 boundary is superseded by 2B1 below.

`diagnose-classic-source-identity-impact.php` exposes the bounded proof read-only. It requires the UEDB5 staging/status schema in the database to which the checkout is connected.

### Section 2B1 � UE4/UE5 classic PackageName and load-time identity transformations

**Status: complete and source-confirmed for UE4 4.27.2 and UE5 5.8.3 classic LinkerLoad packages.** Zen/IoStore remains Section 2B2 and is not changed by this checkpoint.

#### Local authority checked

- UE4 4.27.2: `L:\Source\Engine\UE4\UE 4.27.2`, branch `4.27.2-release`, commit `d94b38ae3446da52224bedd2568c078f828b4039`, primarily `Engine/Source/Runtime/CoreUObject/Private/UObject/LinkerLoad.cpp`, `Linker.cpp`, and `ObjectResource.*`.
- UE5 5.8.3: `L:\Source\Engine\UE5\UE 5.8.3`, release commit `396c9f059903aed5fec78ecd3d437a40c6415368`, primarily `LinkerLoad.cpp`, `Linker.cpp`, `ObjectResource.*`, and `PackageRelocation.cpp`.

#### Source results and corrections

1. **UE4/UE5 classic FName identity is also exact text, not trimmed.** `ObjectName`, `ClassName`, `ClassPackage`, top-level package imports, explicit `FObjectImport::PackageName`, provider package identity, and class-package traversal are compared as FNames. UnrealDB no longer applies `trim()` on those source-semantic paths. Search/display normalization remains separate.
2. **UE4 UEDB4 derives package roots from the raw serialized outer graph whenever that graph is reconstructible.** For version 520+ mixed/export-outer imports, UEDB4 discarded serialized `PackageName`; those cases remain explicitly metadata-unresolved instead of guessing. Pre-520 mixed/export-outer cases retain the existing provider-environment unresolved boundary.
3. **UE4 script-package commonness is recomputed from the raw source root.** An old normalized `is_common` value cannot turn a whitespace-padded serialized FName into `/Script/...`.
4. **UE5 classic retains explicit `PackageName` losslessly.** The UEDB5 resolver uses the serialized/effective package FName exactly and can establish a different source linker where UE4 UEDB4 cannot.
5. **UE5 class mismatch remains deferred by source.** After ObjectName/redirector/outer/private checks succeed, 5.8.3 assigns `SourceIndex` and queues class verification for create time; UnrealDB must not tighten this into a static class rejection.
6. **CoreRedirects and instancing are real transformations but require runtime/config context.** UE4 and UE5 execute `FixupImportMap()` before `PopulateInstancingContext()`. Active CoreRedirect data and linker instancing mappings are not intrinsic package bytes, so static UnrealDB must preserve the serialized identity and mark those branches runtime/config-dependent rather than fabricate remaps.
7. **UE5 package relocation is separately source-bounded.** 5.8.3 runs `RelocateReferences()` after instancing-context population. `Package.Relocation=1` may rewrite same-mount top-level package imports for packages at `EUnrealEngineObjectUE5Version::ADD_SOFTOBJECTPATH_LIST` (1008) or newer when the loaded parent path differs from the serialized original parent path. `FPathViews::GetMountPointNameFromPath` excludes `/Classes_<Mount>/...` paths through `bHasClassesPrefix`. The static resolver reproduces those eligibility gates and reports an eligible rewrite runtime/config-dependent, including the provider name mode 1 would produce; pre-1008 and `Classes_`-prefixed packages are not put behind that boundary.
8. **UE4 4.27.2 does not have the UE5 relocation stages.** Its audited linker sequence is `FixupImportMap()` -> `PopulateInstancingContext()` -> `FixupExportMap()`; the earlier package-format wording that listed `RelocateReferences`/`ApplyInstancingContext` for UE4 was incorrect and is corrected in this checkpoint.

#### V5 projection and bounded migration boundary

All classic LinkerLoad schemas�UE1, UE2, UE3, UE4, and UE5 classic�now use package-key kind `3`, generated through the shared PHP exact-FName key function. UE5 Zen/IoStore remains keyed by `FPackageId` and is explicitly excluded from this transition.

Pass 2 is therefore `uedb5-dependency-pass-v4`. `transition-uedb5-source-identity-policy.php` accepts v1, v2, or v3 checkpoints and remains bounded:

- impact discovery starts from the exact old-policy file IDs and their file-leading dependency/provider indexes; it does not scan the entire term dictionary or original package store;
- v1 files still reconsider provider ambiguity plus source-identity changes; v2 files reconsider source-identity changes; v3 files rebuild only when a UE4/UE5-classic source-identity change can affect them;
- for modern classic packages where UEDB4 discarded explicit `PackageName`, the impact proof conservatively uses retained NameMap evidence rather than assuming the old derived root preserved that FName;
- unaffected classic rows are rolled forward and re-keyed from retained SQL text with the same PHP FName-key function used by normal publication;
- impacted V5 files rebuild by exact file from already-staged UEDB5 metadata; no full-game reparse is required;
- `--rebuild-v4-impacted` optionally refreshes only the exact live UE1/UE2/UE3/UE4 files whose source identity can change, then refreshes statistics for touched games.

### Section 2B2 - UE5 Zen/IoStore package-store redirects and localization

**Status: complete and source-confirmed for the audited UE5 5.8.3 package-store path.**

The cooked package-store audit establishes a separate Zen identity pipeline from classic `LinkerLoad`:

- serialized PackageImport `FPackageId` remains the dependency source identity;
- explicit package-store redirects are container-global mappings and rewrite provider lookup identity before package lookup;
- `PublicExportHash` is then resolved inside the redirect target package, not against the source package ID;
- localized-package redirects are a distinct second-stage mapping and depend on runtime editor/culture state, so static UnrealDB does not guess a culture;
- mounted containers are ordered by mount `Order` and then later mount `Sequence`; the first effective redirect/localization mapping wins;
- `GetSoftReferences()` enumerates raw package IDs and does not eagerly apply package-store redirects;
- raw 64-bit package IDs and public-export hashes are never used as unprefixed PHP array keys, avoiding numeric-string coercion;
- redirect/localization UEDB5 section schemas are v2 because they preserve the complete container-global context rather than rows filtered to the current package.

Commit checkpoint: `c9adfc35` (`Align Zen package-store redirects with source`).

### Section 3A1 - UE1 VerifyImport: Unreal and UT99

**Status: complete for the latest complete local VerifyImport implementations available for each UE1 profile. Later UE1 revisions with no local implementation body are deliberately not inferred.**

#### Source authority and version boundary

- Unreal: the newest local tree is `L:\Source\Games\Unreal\Unreal [v1.227]`, but it does not contain the `Core\Src` linker implementation. The latest complete local `VerifyImport` implementation is `L:\Source\Games\Unreal\Unreal [v1.200] [1998-05-19]\Core\Src\UnLinker.h`. UnrealDB therefore applies the v1.200 VerifyImport contract only to its source-backed early policy (`ue1-unreal-v120-loadable-v34-59`).
- UT99: the newest local tree is `L:\Source\Games\UT99\Unreal Tournament v432`, but its local tree exposes linker declarations rather than the implementation body. The latest complete local implementation is `L:\Source\Games\UT99\Unreal Tournament [v1.400] [1999-11-30]\Core\Src\UnLinker.h`. UnrealDB therefore applies the v1.400 VerifyImport contract only to `ue1-ut99-retail-v1400-1999-11-30`.
- `UT99src-ext`/the local v432 tree may prove later serialization layout, but it is not treated as proof that the v1.400 VerifyImport body remained unchanged.

#### Source results and corrections

1. **Pre-50 Unreal ImportMap layout is different.** Unreal v1.200 serializes `_ObjectPackage` as an `FName` when `Ar.Ver() < 50`; `PackageIndex` is not serialized and is set to zero on load. The legacy reader, V4 parsed snapshot, unverified snapshot and UEDB5 legacy snapshot now preserve this exact field boundary instead of decoding the third import field as an `INT OuterIndex`.
2. **Direct `NAME_None` and `NAME_None` ancestry are different operations.** `VerifyImport()` returns immediately for a direct import whose `ClassPackage`, `ClassName` or `ObjectName` is `NAME_None`. A child of such an import is not itself source-irrelevant: recursive verification returns without establishing the parent `SourceLinker`, so the child fails the parent-linker requirement. The old generic `source_irrelevant_name_none_ancestor` UE1 rule is removed.
3. **UT99 v1.400 preserves ExportHash visit order.** Matching uses the source hash traversal, which visits later prepended matching export indices first. Unreal v1.200 uses its own source scan order; UnrealDB no longer shares a generic UE1/UE2 candidate-order rule.
4. **UT99 v1.400 compatibility is profile-specific.** v1.400 supports the `UnrealI`/`UnrealShare` class-package hash compatibility and retries a failed `UnrealI` package load as `UnrealShare`. Its `Mesh` branch is an **unconditional Rehack pass**: after the Mesh hash pass, `ClassName` is changed to `LodMesh` and the hash lookup runs again. A LodMesh match can replace an earlier public Mesh match, a private LodMesh match can still fail the import, and an earlier public Mesh match is retained only when the LodMesh pass finds nothing. Unreal v1.200 does not inherit these rules. UE2 profiles are audited separately rather than inheriting UT99 behavior.
5. **Unreal v1.200 keeps its own old-version fallback.** The source retry for texture/sound-family classes can bind by object/class identity without reapplying the normal outer/public checks in that legacy branch. That rule is confined to the v1.200 profile.
6. **Private exports remain source failures.** A direct identity/outer match that is not `RF_Public` is rejected before runtime fallback; UnrealDB does not continue searching for a different public duplicate.
7. **A file-backed miss is not automatically proven missing.** After direct source matching/fallbacks fail, both audited UE1 implementations can consult runtime-loaded/native/transient class/object state and SafeReplace behavior. Static UnrealDB now reports that remaining path as unresolved/runtime unavailable rather than inventing a hard missing result.
8. **Administrator ClassRemap is not UE1 VerifyImport behavior.** The authoritative UE1 path no longer consumes the generic catalogue ClassRemap map.
9. **Later UE1 source policies fail closed.** v224/v227-era Unreal, UT99 v432/v69 serializer policies and other forward-compatible UE1 packages are not assigned v1.200/v1.400 VerifyImport semantics without a complete local implementation proving them. Object verification is explicitly source-unresolved for those profiles.

#### Bounded migration boundary

Section 3A1 introduced Pass 2 `uedb5-dependency-pass-v5`; Section 3A2 now supersedes it with `uedb5-dependency-pass-v6`. The 3A1 transition accepted v1-v4 and moved them to v5:

- previous provider/source-FName transition rules remain intact;
- audited UE1 files are rebuilt only when their staged dependency edges contain `missing` or `unresolved` outcomes that can change under the source-exact verifier;
- later source-unverified UE1 files are rebuilt only when they actually contain import dependency edges, so those object decisions become explicitly unresolved rather than inherited;
- any pre-50 Unreal file is flagged for exact Pass-1 restage before Pass 2, because its serialized ImportMap interpretation changed; `migrate-uedb5-game.php --file-id=<id> --apply` provides that exact-file path;
- successful Pass-1 restage clears the old dependency checkpoint automatically;
- unaffected files roll forward without UEDB/package container reads;
- optional V4 dependency refresh remains exact-file only. A pre-50 V4 file would additionally require its package-owned metadata to be reparsed, not merely its dependencies rebuilt.

The connected development catalogue currently contains no verified Unreal file below package version 50, so the pre-50 repair path is a guarded source-correct boundary rather than a current bulk reparse requirement.

A follow-up source-order review during 3A2 found that UT99's `Mesh -> LodMesh` branch is unconditional even after a public Mesh match. That delta is carried by Pass-2 policy v6 and is targeted through indexed Mesh-import consumers; the rest of the 3A1 v5 transition remains valid.

### Section 3A2 - UE2 VerifyImport: Unreal II and UT2003

**Status: complete for 3A2: Unreal II v69 and UT2003 v2107. UT2004 was intentionally excluded from 3A2 and is completed independently in Section 3A3 below.**

#### Source authority and version boundary

- **Unreal II:** the newer local `Unreal II The Awakening [01-07-2003]` tree does not contain a usable UE2 linker implementation and is not accepted as dependency authority. The latest complete local UE2 `VerifyImport` body is `L:\Source\Games\Unreal II\Unreal II The Awakening [12-09-2000]\Unreal2_old\Core\Src\UnLinker.cpp`; its `UnObjVer.h` declares `PACKAGE_FILE_VERSION 69`, `PACKAGE_MIN_VERSION 60`, and licensee version `0x7F`. UnrealDB applies this VerifyImport profile only to package versions 60-69. Later Unreal II package versions are not assigned v69 semantics.
- **UT2003:** `L:\Source\Games\UT2003\Unreal Tournament 2003 [v2107] [2002-10-01]\Core\Src\UnLinker.cpp` is complete; `UnObjVer.h` declares `PACKAGE_FILE_VERSION 120`, `PACKAGE_MIN_VERSION 60`, and licensee version `0x1C`. UnrealDB applies the v2107 VerifyImport contract only to package versions 60-120. Later packages do not inherit it.
- **UT2004/UE2.5:** no 3A2 rule was derived from either Unreal II or UT2003. Section 3A3 below audits the latest UT2004 source directly and supersedes the former isolated legacy path.

#### Source results and corrections

1. **The profiles have separate parent-linker behavior.** Both recursively verify a negative `PackageIndex` parent, but Unreal II v69 asserts that the inherited parent `SourceLinker` exists. UT2003 v2107 has that assertion commented out and guards the subsequent export lookup with `if (Import.SourceLinker)`, so a source-linker-less parent is tolerated rather than treated as the Unreal II assertion boundary.
2. **Direct `NAME_None` still returns immediately.** This does not justify a generic ancestor exemption. The descendant outcome follows each profile's real parent-linker behavior above.
3. **Both audited profiles use `ExportHash` prepend order.** Later matching export indices are visited before earlier entries in the same hash chain; catalogue order must not replace this source order.
4. **Unreal II v69 retains old UnrealI/UnrealShare compatibility.** Its `HashNames` maps `UnrealShare` class-package hashing to `UnrealI`, its class comparison accepts `UnrealShare` for an `UnrealI` import, and a failed top-level `UnrealI` package load retries `UnrealShare`. UT2003 v2107 contains none of these compatibility branches.
5. **Mesh -> LodMesh is an unconditional Rehack in both audited UE2 profiles.** The source performs the Mesh hash pass, then changes `ClassName` to `LodMesh` and jumps back through lookup regardless of whether Mesh matched. A LodMesh result can replace a public Mesh result; a private LodMesh can fail after a public Mesh match; if LodMesh does not match, the earlier public Mesh source index remains.
6. **Private exports are rejected by both audited profiles.** The earlier UnrealDB assumption that Unreal II accepted a private export was incorrect for the latest complete Unreal II v69 source. A matching non-`RF_Public` export enters the source failure/forgiving path before runtime recovery.
7. **A file miss is runtime-dependent, not automatically hard-missing.** Both sources can search public native transient objects/classes and execute SafeReplace/forgiving behavior. Static UnrealDB therefore reports the residual branch unresolved/runtime-unavailable instead of fabricating a definite miss.
8. **Catalogue ClassRemap is not part of these audited VerifyImport paths.** Unreal II/UT2003 no longer fetch or apply the administrator ClassRemap map. Section 3A3 independently confirms the same prohibition for UT2004.
9. **All production paths use the same profile boundary.** V4 dependency rebuilding, UEDB5 Pass 2, physical provider fallback, local/self-provider publication, and cross-game dependency certification dispatch through the exact Unreal II-v69/UT2003-v2107 profiles. Source-unverified later versions fail closed rather than falling back to the shared legacy UE2 resolver.

#### Bounded migration boundary

Section 3A2 advanced Pass 2 to `uedb5-dependency-pass-v6`, accepting v1-v5. Section 3A3 now supersedes that checkpoint with v7:

- v5 UE1 rows retain the completed 3A1 corrections and are rebuilt only for the newly identified UT99 `Mesh` Rehack delta;
- UE2 impact is selected from staged SQL by exact file ID and only when an Unreal II/UT2003 file has object dependency edges whose VerifyImport semantics can change;
- the three currently catalogued Unreal II files at package versions 60/68/69 require exact Pass-1 restage so their snapshot `source_policy` records the v69 source profile; this is an exact-file operation, not a game-wide reparse;
- later Unreal II and UT2003 packages require Pass-2 dependency rebuild only; their package serialization is not reparsed solely to mark unsupported VerifyImport behavior;
- unaffected rows roll forward without opening UEDB/package containers;
- optional V4 repair remains exact-file only for the same impacted consumer IDs.

The v6 transition deliberately excluded UT2004; Section 3A3 below adds only the independently source-proven UT2004 delta.

### Section 3A3 - UT2004 / UE2.5 game-profile VerifyImport

**Status: complete against the latest complete local UT2004 source. No Unreal II, UT2003, or generic UE2 behavior is used to fill gaps.**

#### Source authority and version boundary

- Latest complete authority: `L:\Source\Games\UT2004\UT2004Src\UT2004SrcCmake\Core\Src\UnLinker.cpp` and `Core\Inc\UnObjVer.h`.
- This tree declares `PACKAGE_FILE_VERSION 129`, `PACKAGE_MIN_VERSION 60`, and licensee version `0x1D`. UnrealDB therefore applies the UT2004 v129 VerifyImport profile only to package versions 60-129.
- The earlier v3369/v128 `Core\Src\UnLinker.cpp` was independently cross-checked. Its relevant VerifyImport flow matches the v129 implementation, but v129 remains the latest authority.
- Package version 130+ is not assigned v129 object-verification semantics without later source proof.

#### Source results and corrections

1. **Parent-linker absence is tolerated.** Nested imports recursively verify their parent, copy its `SourceLinker`, and the source has `//check(Import.SourceLinker);` followed by `if (Import.SourceLinker)`. A child whose parent establishes no linker is unresolved, not an assertion failure and not source-irrelevant ancestry.
2. **Direct `NAME_None` imports return immediately.** That early return does not authorize the former generic `source_irrelevant_name_none_ancestor` rule; descendant behavior follows the parent-linker rule above.
3. **Object/class/provider matching is exact and hash-ordered.** `ExportHash` candidates require exact `ObjectName`, `GetExportClassName`, and `GetExportClassPackage`; later prepended hash entries are visited first. No UnrealI/UnrealShare compatibility exists in this source.
4. **Outer matching keeps the source root-export exception.** When a parent SourceIndex exists, the expected outer is `Parent.SourceIndex + 1`; a candidate with `Source.PackageIndex == 0` is also accepted exactly as in source.
5. **Private exports are hard source failures outside forgiving mode.** A matching export without `RF_Public` enters broken-link handling under `LOAD_Forgiving`; otherwise the source throws `FailedImportPrivate`. UnrealDB records this deterministic static rejection as missing/source-rejected.
6. **`Mesh -> LodMesh` is an unconditional Rehack.** After the Mesh hash pass the source mutates `ClassName` to `LodMesh` and jumps back to lookup regardless of whether Mesh already matched. LodMesh can replace an earlier public Mesh match; a private LodMesh can fail after a public Mesh; the earlier Mesh SourceIndex survives only if the LodMesh pass finds nothing.
7. **The remaining miss is runtime-dependent.** The source searches runtime public/native/transient objects/classes and sets `SafeReplace` when the class exists but the object cannot bind; forgiving/failure behavior also depends on runtime flags. Static UnrealDB therefore leaves this residual branch unresolved/runtime-derived.
8. **No active ClassRemap/PackageRemap belongs in UT2004 VerifyImport.** The latest v129 linker contains no ClassRemap path in VerifyImport, and the documented package-remap retry outside it is inactive/commented. The old UnrealDB administrator ClassRemap lookup and shared legacy resolver were extra processing and have been removed from all authoritative dependency paths.
9. **All resolution surfaces now share the same source profile.** V4 rebuilding, UEDB5 Pass 2, local/self-provider publication, provider selection, and cross-game certification use `PROFILE_UT2004_V129` for versions 60-129 and fail closed outside that range.

#### Bounded migration boundary

Section 3A3 advanced Pass 2 to `uedb5-dependency-pass-v7`, accepting v1-v6; Section 3B1 below now supersedes that checkpoint with v8.

- A v6 file is reconsidered only for the new `ue2_ut2004_*` reasons; earlier UE1/UE2 corrections are not replayed.
- UT2004 package-only files roll forward without opening UEDB/package containers.
- UT2004 files with object dependency edges are recomputed from already-staged UEDB5 metadata. This deliberately includes previously resolved object edges because the old v6 result did not persist which administrator ClassRemap configuration, if any, influenced it; claiming those rows unaffected would be unprovable.
- No UT2004 Pass-1/package-byte reparse is required for 3A3.
- Optional V4 repair remains exact-file only for the same impacted consumers.

### Section 3B1 - UT3 / UE3 VerifyImportInner and VerifyImport wrapper

**Status: complete for the active UT3 package-version-512/licensee-0 profile. No later UE3/UDK behavior is inherited.**

#### Source authority and profile boundary

- Primary authority: `L:\Source\Engine\UE3\Unreal Engine [v3.0] [01-00-2008]\epic.jan2008\UnrealEngine3\Development\Src\Core\Src\UnLinker.cpp`.
- `L:\Source\Engine\UE3\Unreal Engine [v3.0] [03-00-2008]\UnrealEngine3\Development\Src\Core\Src\UnLinker.cpp` is byte-identical to the January copy (SHA-256 `E19F04113B7634656AF145F8C7BC363E9492A19B819B007328D2A3036407E603`); the two `UnObjVer.h` files are also byte-identical (SHA-256 `91A7FCBB65E40A8C6931424E1A5FCC2199DF70E3A6A3FD652957F56EFA029650`).
- The early-2008 source defines `VER_FULL_VERSION_OF_UT3_BUMP = 512`, but its `VER_LATEST_ENGINE` is 530 and `GPackageFileMinVersion` is 491. Therefore 512 is the UT3 content/game-profile boundary, not the checkout's final engine version.
- UnrealDB deliberately registers `PROFILE_UT3_V512` only for game `ut3`, package version 512, licensee 0. v513+, nonzero licensee versions, and other UE3 profiles fail closed rather than inheriting this implementation.

#### Source results and corrections

1. **Direct `NAME_None` returns immediately.** `ClassPackage == NAME_None`, `ClassName == NAME_None`, or `ObjectName == NAME_None` makes the direct import irrelevant to `VerifyImportInner`. Descendants are different: if their parent never establishes a `SourceLinker`, the source tolerates that missing linker; UnrealDB records the descendant runtime/source-linker dependency as unresolved rather than treating the entire ancestry as ignored.
2. **Root imports are exactly `Core.Package`.** A top-level import establishes the one package linker used by descendants. Non-root imports recurse through their serialized negative `OuterIndex`; cooked import-to-export outers hit the source TODO/return and remain unresolved.
3. **Provider matching is exact.** File-backed candidates require exact FName `ObjectName`, export class name, export class package, and the resolved outer relationship. Derived catalogue paths are not VerifyImport identity.
4. **UT3 `RF_Public` is 64-bit.** The audited flag is `0x0000000400000000`; low-32-bit truncation is not equivalent.
5. **Private exports are context-sensitive in this UE3 source.** If the import is referenced by a consumer export's super/class/outer/archetype or by another import's outer, the source forces `SafeReplace = FALSE` and the private match is a deterministic rejection. Otherwise editor state (`GIsEditor && !GIsUCC`) can permit the SafeReplace path, so static UnrealDB reports runtime-unresolved rather than a hard missing dependency.
6. **A file-backed miss is not hard-missing.** After no export match, source can still use public/native/transient runtime objects/classes, `LOAD_FindIfFail`, SafeReplace, and class-presence state. UnrealDB now records the residual branch as `runtime_native_transient_findif_fail_or_missing_class_context`/unresolved.
7. **`VerifyImport()` retries `Core.ObjectRedirector`.** A matching serialized redirector is real source evidence, but the dependency resolver does not deserialize/preload `DestinationObject`; it therefore records `object_redirector_target_unavailable` rather than guessing the target.
8. **All authoritative UT3 resolution surfaces share the same profile/outcome resolver.** V4 dependency rebuilding, UEDB5 Pass 2, local/self-provider publication, physical-provider evaluation, and cross-game certification call the profiled UE3 outcome API. No production caller remains on the old match-only UE3 API.
9. **Later UE3 remains separate.** Later `RemapClasses`/export-class-package rules and UDK behavior are not back-ported merely because the engine can deserialize older packages.

#### Bounded migration boundary

Pass 2 is now `uedb5-dependency-pass-v8`; the transition accepts v1-v7.

- A v7 file is reconsidered only for the new `ue3_ut3_*` reasons; completed UE1/UE2/UT2004 corrections are not replayed.
- UT3 package-only rows and already-resolved deterministic public object edges roll forward without opening UEDB/package containers.
- UT3 v512 files rebuild from already-staged UEDB5 metadata only when staged object edges contain outcomes that can change under the full source outcome model.
- UT3 package versions other than 512 or nonzero licensee versions with object edges are rebuilt only to become explicitly source-implementation-unavailable; they do not inherit v512 behavior.
- No Pass-1/package-byte reparse is required for 3B1.
- Optional V4 repair remains exact-file only for the same impacted consumers.

**Next audit checkpoint: Section 3C - UT4 / UE4 4.27.2 VerifyImportInner and redirector/runtime branches, against the local 4.27.2 source.**
