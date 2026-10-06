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
| Unreal / Unreal Gold UE1 | `L:\Source\Games\Unreal\Unreal [v1.200] [1998-05-19]\Core\Src\UnLinker.h`, `UnObj.cpp`; v1.227 headers also inspected | **partial source coverage** | Every sibling Unreal revision under `L:\Source\Games\Unreal` (v0.82, v0.83, v0.84a, v0.86x, v0.867, v1.200, v1.224 incomplete, v1.227) was searched for the missing linker/name/object implementations. v1.200 remains the newest complete Unreal-only implementation body; v227 exposes declarations/header logic only. Do not substitute UT99 behavior for the missing v227 bodies. |
| UT99 v1.400 | `L:\Source\Games\UT99\Unreal Tournament [v1.400] [1999-11-30]\Core\Src\UnLinker.h`, `UnObj.cpp` | **source-confirmed** | One `GetPackageLinker`; child imports inherit parent `SourceLinker`. |
| Unreal II / UE2 | Unreal-II-specific: `L:\Source\Games\Unreal II\Unreal II The Awakening [12-09-2000]\Unreal2_old\Core\Src\UnLinker.cpp`; generic UE2 supplement: `L:\Source\Engine\UE2\Unreal Engine [v2.5]_ Unreal Warfare [09-29-2007]\Core\Src\UnLinker.cpp` | **partial source coverage** | v69/min60 is still the latest complete **Unreal-II-specific** linker. The Warfare tree is a complete generic UE2 linker at package v126/min60 and is valid supplemental evidence for generic UE2 serialization/preprocessing, but it does not prove Unreal-II game-branch `VerifyImport` deltas for v70-126. The folder labelled `Unreal II The Awakening [01-07-2003]` is actually UE4 4.0.2 source and is rejected as Unreal-II authority. |
| UT2003 v2107 | `L:\Source\Games\UT2003\...\Core\Src\UnLinker.cpp`, `UnObj.cpp` | **source-confirmed** | One package linker; no catalogue coverage ranking. |
| UT2004 v129 / UE2.5 game profile | `L:\Source\Games\UT2004\UT2004Src\UT2004SrcCmake\Core\Src\UnLinker.cpp`, `UnObj.cpp`, `Core\Inc\UnObjVer.h` | **source-confirmed** | Latest complete local source; package v129/min v60. v3369/v128 was cross-checked. One package linker precedes exact import verification. |
| UT3 v512 / early-2008 UE3 | `L:\Source\Engine\UE3\Unreal Engine [v3.0] [01-00-2008]\...\Core\Src\UnLinker.cpp`; March-2008 copy hash-identical | **source-confirmed** | Active game profile is v512/licensee 0. The checkout itself reaches engine v530/min491; that wider engine range is not inherited as UT3 policy. `VerifyImportInner` selects one package linker, then verifies imports. |
| UDKUltimate / later UE3 | `L:\Source\Engine\UE3\Unreal Engine [v3.0] UDKUltimate [05-11-17]\UDKUltimate\Development\Src\Core` (engine 8364) and `L:\Source\Engine\UE3\Unreal Engine 3 (10897)\Development\Src\Core` (engine 10897 / changelist 1532151); `CodeRedModding/UnrealEngine3` build 10897 | **source-confirmed** | Full Core linker/name/version implementations are present locally. The local 10897 `UnObjVer.cpp`, `UnObjVer.h`, and `UnLinker.cpp` Git blob hashes exactly match the CodeRedModding 10897 repository at commit `601d6a1f50a0a4a67e3ee0c352333783408d1ba7`. Later generic UE3 source is therefore available; game-specific UT3 authority remains separate. |
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

## Section 2 ï¿½ provider identity derivation and package-loading transformations

### Section 2A ï¿½ UE1/UE2/UE3 classic identity and load-time transformations

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

### Section 2B1 ï¿½ UE4/UE5 classic PackageName and load-time identity transformations

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

All classic LinkerLoad schemasï¿½UE1, UE2, UE3, UE4, and UE5 classicï¿½now use package-key kind `3`, generated through the shared PHP exact-FName key function. UE5 Zen/IoStore remains keyed by `FPackageId` and is explicitly excluded from this transition.

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

- **Unreal II:** the directory labelled `Unreal II The Awakening [01-07-2003]` is actually UE4 4.0.2 source (`ENGINE_MAJOR_VERSION 4`, `ENGINE_MINOR_VERSION 0`, `ENGINE_PATCH_VERSION 2`) and is rejected as Unreal-II authority. The latest complete **Unreal-II-specific** `VerifyImport` body remains `L:\Source\Games\Unreal II\Unreal II The Awakening [12-09-2000]\Unreal2_old\Core\Src\UnLinker.cpp`; its `UnObjVer.h` declares package v69/min60/licensee `0x7F`, so UnrealDB keeps that profile at versions 60-69. Section 4C separately confirms the complete generic UE2/Warfare v126 linker as serialization/preprocessing authority through v126, without assigning its `VerifyImport` semantics to Unreal II.
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

Section 3B1 advanced Pass 2 to `uedb5-dependency-pass-v8`, accepting v1-v7; Section 3C below now supersedes that checkpoint with v9.

- A v7 file is reconsidered only for the new `ue3_ut3_*` reasons; completed UE1/UE2/UT2004 corrections are not replayed.
- UT3 package-only rows and already-resolved deterministic public object edges roll forward without opening UEDB/package containers.
- UT3 v512 files rebuild from already-staged UEDB5 metadata only when staged object edges contain outcomes that can change under the full source outcome model.
- UT3 package versions other than 512 or nonzero licensee versions with object edges are rebuilt only to become explicitly source-implementation-unavailable; they do not inherit v512 behavior.
- No Pass-1/package-byte reparse is required for 3B1.
- Optional V4 repair remains exact-file only for the same impacted consumers.

### Section 3C - UT4 / UE4 4.27.2 VerifyImportInner and VerifyImport wrapper

**Status: complete for the UT4 game profile against the local UE4 4.27.2 release source. Other UE4 profiles do not inherit this policy automatically.**

#### Source authority and profile boundary

- Authority: `L:\Source\Engine\UE4\UE 4.27.2\Engine\Source\Runtime\CoreUObject\Private\UObject\LinkerLoad.cpp`, especially `FLinkerLoad::VerifyImportInner()` and `FLinkerLoad::VerifyImport()`.
- Source tree/branch: local UE4 4.27.2 release (`d94b38ae3446da52224bedd2568c078f828b4039`).
- V5 is gated by `ue4.ut4.*` plus source policy `ue4-4.27.2-release-classic-package`; V4 is gated by the stable `ut4` game source key. Other UE4 catalogues fail closed for object verification instead of inheriting the UT4 contract.
- Section 2B1 remains authoritative for `FObjectImport::PackageName`, instancing/CoreRedirect boundaries and the V4 metadata-loss boundary. 3C does not invent missing PackageName state.

#### Source results and corrections

1. **Direct `NAME_None` is an early return.** `ClassPackage`, `ClassName`, or `ObjectName == NAME_None` makes that direct import not relevant to `VerifyImportInner`; UnrealDB no longer treats the literal FName `None` as an ordinary object requirement.
2. **One provider linker still precedes object verification.** Top-level `Package` imports and assigned `PackageName` load/establish a package before export lookup. Provider content never chooses a different physical package.
3. **File-backed matching remains exact and ordered.** `ObjectName` and `ClassName` are exact FName matches. UE4 first determines whether any full `ClassPackage` match exists; only if none exists may `FPackageName::GetShortFName` participate in the package-name-transition fallback. Outer matching follows the serialized import/export graph, including the separate-linker outer-import identity check.
4. **Private export handling is compile/runtime-context dependent.** `IsPrivateImportAllowed()` is compiled only under `WITH_EDITOR` and permits the three source graph predicates `ImportIsInAnyExport`, `AnyExportIsInImport`, and `AnyExportShareOuterWithImport`. Static UnrealDB therefore does not turn such a private match into unconditional resolution. Outside that allowance, source initializes SafeReplace from `GIsEditor && !IsRunningCommandlet()` and then forces it false when the import is referenced as an export super/class/outer or another import's outer. A hard-referenced private candidate is a deterministic source rejection; otherwise the result is runtime/editor-context unresolved.
5. **An object miss inside an established provider is not hard missing.** After export lookup, 4.27.2 can resolve memory-only/instanced packages, use `LOAD_FindIfFail`, bind public native/transient objects or CDOs, find moved script structs, or suppress failure through class/SafeReplace state. UnrealDB now records this residual branch as `runtime_native_transient_findif_fail_or_missing_class_context`/unresolved. A genuinely absent physical provider remains hard missing.
6. **The wrapper retries `Core.ObjectRedirector`.** If the original object is absent, `VerifyImport()` retries as `Core.ObjectRedirector`, creates/preloads the redirector, reads `DestinationObject`, and validates the destination's class/superclass chain (with the CDO exception) before rewriting runtime import state. Static table evidence can prove that a redirector candidate exists, but not its serialized runtime destination; UnrealDB therefore records redirector and redirector-descendant cases as unresolved rather than fabricated success or hard missing.
7. **All authoritative UT4 surfaces now share the source outcome contract.** V4 rebuilding, UEDB5 Pass 2, parsed local/self-provider publication, physical-provider evaluation, and cross-game certification use the 4.27.2 profile and accept only deterministic public source outcomes as static resolution. The older generic local full-path success path is no longer allowed to certify a UT4 import.
8. **Legacy unverified staging fails closed where its schema is insufficient.** `unverified-staging-v1` does not retain enough UE4 provider class-index/class-package graph to rerun source-exact local VerifyImport, so a same-path local export is reported metadata-unresolved instead of being promoted to exact resolution.

#### Bounded migration boundary

Section 3C advanced Pass 2 to `uedb5-dependency-pass-v9`, accepting v1-v8; Section 3D below supersedes that checkpoint with v10.

- A v8 row is reconsidered only for the new UE4/UT4 delta; completed UE1/UE2/UE3 corrections are not replayed.
- UT4 object edges already persisted as missing/unresolved are rebuilt because 4.27.2 runtime fallback semantics can change their classification.
- Previously resolved UT4 edges are rebuilt only when the selected provider export is private. The transition uses the still-live pre-cutover V4 export-flag projection solely as an indexed **impact-discovery accelerator**; the actual dependency rebuild remains V5-only and reads the staged UEDB5 snapshot/provider data.
- Deterministic public resolved edges and package-only rows roll forward without opening UEDB/package containers.
- Unprofiled UE4 object edges are rebuilt only to become explicitly source-implementation-unavailable.
- No Pass-1/package-byte reparse is required for 3C.
- Optional V4 repair remains exact-file only for the same impacted consumers.

### Section 3D - UE5 5.8.3 classic VerifyImportInner and VerifyImport wrapper

**Status: complete for the dedicated UE5 5.8.3 classic LinkerLoad source policy. This audit was performed independently from UE4 4.27.2; shared-looking branches were re-proven rather than inherited.**

#### Source authority

- Authority: `L:\Source\Engine\UE5\UE 5.8.3\Engine\Source\Runtime\CoreUObject\Private\UObject\LinkerLoad.cpp`, especially `FLinkerLoad::VerifyImportInner()`, `FLinkerLoad::VerifyImport()`, `IsPackageReferenceAllowed()`, `TryCreatePlaceholderClassImport()` and create-time `ImportsToVerifyOnCreate` handling.
- Local UE5 source revision: `396c9f059903aed5fec78ecd3d437a40c6415368`.
- Resolver/profile: `Uedb5Ue5ClassicVerifyImportResolver` under source policy `ue5-5.8.3-classic-linkerload` only. Zen/IoStore remains a separate package/dependency model and does not inherit this classic LinkerLoad policy.

#### Source results and corrections

1. **Direct `NAME_None` returns immediately.** Raw staged FName number/text is now used to reproduce `FName::IsNone()`; literal `None` with number zero is not treated as an ordinary dependency merely because its rendered text is non-empty.
2. **Provider selection remains package-first, including effective `PackageName`.** Package relocation, instancing/remapping and filtered package-name behavior are kept separate from deterministic serialized provider identity; runtime configuration is not guessed.
3. **Initial UE5 export matching is not UE4 class-tuple matching.** UE5 5.8.3 hashes/searches by `ObjectName` and requires only redirector/non-redirector class parity at this stage. Class name/package mismatch is accepted as a file-backed match and queued in `ImportsToVerifyOnCreate`; create time later warns when the resolved object's class is not serialization-compatible. UnrealDB therefore keeps the dependency resolved while recording deferred class verification.
4. **Different-linker outer class identity can also be deferred.** When the source-linker outer import has the same object name but a different class name/package, UE5 adds the outer import to `ImportsToVerifyOnCreate` rather than rejecting the candidate immediately. The resolver preserves this as `deferred_outer_class_verification`.
5. **The three private-import graph allowances are unconditional in UE5 5.8.3.** Unlike UE4 4.27.2, `IsPrivateImportAllowed()` is not wrapped in `WITH_EDITOR`; `ImportIsInAnyExport`, `AnyExportIsInImport`, or `AnyExportShareOuterWithImport` directly permit the matching private export.
6. **Private matches outside those allowances are still context-sensitive.** Source initializes SafeReplace from `GIsEditor && !IsRunningCommandlet()` and then forces it false when the import is referenced as a consumer export super/class/outer or another import's outer. UnrealDB now reports unreferenced private matches as runtime/editor-context unresolved and only hard-referenced private matches as deterministic `private_export` rejection. The same distinction is retained when the wrapper is considering a private `ObjectRedirector` candidate.
7. **A file-backed object miss is not hard missing.** UE5 can still recover through memory-only/instanced packages, dynamic-import linker substitution, `LOAD_FindIfFail`, native/transient objects and CDOs, moved script structs, placeholder type creation while deserializing redirector destinations, and SafeReplace/class runtime state. Provider-table exhaustion is therefore `runtime_only`; only physical provider absence remains hard missing.
8. **`bImportOptional` is metadata, not a classic VerifyImport result.** The audited UE5 source serializes/authors the flag but does not consult it in `VerifyImportInner()` or `VerifyImport()`. The previous `optional_missing` classic outcome has been removed. Optional-resource semantics remain preserved for the UE5 systems that actually consume the flag.
9. **`IsPackageReferenceAllowed()` is deterministic from staged package flags.** `PKG_NotExternallyReferenceable` (`0x00000800`) makes a provider `Private`; a cross-mount reference is rejected, while same-mount reference is allowed. `PKG_AccessSpecifierEpicInternal` (`0x00001000`) is not rejected by this helper. UEDB5 retains the required flags and package names, so UnrealDB reproduces this gate instead of calling it runtime-only.
10. **`ObjectRedirector` remains payload-dependent.** The wrapper retries the same object name as `CoreUObject.ObjectRedirector`, creates/preloads it, reads `DestinationObject`, then validates the destination class/superclass chain (with the CDO exception). Static table evidence proves only the redirector candidate; the destination remains runtime/payload unresolved.
11. **Dynamic imports and placeholder objects are not fabricated.** `DynamicImportsIndex` rows are runtime-added and editor-only placeholder type creation depends on live serialization/property-bag state. Serialized evidence such as `RF_HasDynamicImports` does not authorize UnrealDB to invent those objects or linkers.

#### Bounded migration boundary

Section 3D advanced Pass 2 to `uedb5-dependency-pass-v10`, accepting v1-v9; Section 3E below supersedes that checkpoint with v11.

- v9 UE1-UE4 rows do not replay their completed source deltas.
- Only UE5 classic rows under the 5.8.3 source policy are candidates for the new 3D transition.
- Existing UE5 classic missing/unresolved object edges are rebuilt because their source outcome may now become runtime-derived unresolved under the corrected post-file-miss and private SafeReplace rules.
- Existing resolved/package-only UE5 classic provider relations are re-opened once to apply `IsPackageReferenceAllowed()`. Provider `package_flags` are authoritative in staged UEDB5 but intentionally are not duplicated in the dependency SQL accelerator, so SQL alone cannot prove that an old resolved relation is not a cross-mount private-package reference.
- Common/script-only rows with no affected provider relation roll forward.
- No Pass-1 reparse is required: raw FName number/text, package flags, PackageName, import/export graphs and optional bits are already present in UEDB5.
- UE5 classic is not currently a registered game migration target, so this v10 rule does not create a current full-game replay; it prevents future staged UE5 rows from carrying a semantically stale v9 checkpoint.

### Section 3E - UE5 5.8.3 Zen/IoStore dependency resolution and package-store/runtime boundaries

**Status: complete against the local UE5 5.8.3 package-store and AsyncLoading2 source. Zen remains independent from classic LinkerLoad/VerifyImport.**

#### Source authority

- `Runtime/PakFile/Private/FilePackageStore.cpp` - mounted-container ordering, package entries, optional segments, redirects/localization and soft references.
- `Runtime/CoreUObject/Private/Serialization/PackageStore.cpp` - backend-priority arbitration.
- `Runtime/CoreUObject/Private/Serialization/AsyncLoading2.cpp` / `Public/Serialization/AsyncLoading2.h` - PackageImport keys, global import store, public-export lookup, redirector handling, imported-package rewrites, optional headers and runtime status/filter behavior.
- Local UE5 source revision remains `396c9f059903aed5fec78ecd3d437a40c6415368`.

#### Source results and corrections

1. **The exact Zen object key is `(FPackageId, PublicExportHash)`.** `FPublicExportKey::FromPackageImport()` uses the package ID from the active header's `ImportedPackageIds` and the serialized imported public-export hash. There is no package-name/object-path fallback.
2. **Imported package identity can be rewritten before object lookup.** AsyncLoading2 applies enabled CoreRedirect package-name rewriting, instancing remap and loose-file localization before consulting the package store. These transformations can rewrite `ImportedPackageId`, `PackageIdToLoad`, or both, and they depend on live redirect/instancing/culture state. Static UnrealDB therefore requires an explicit authoritative pre-store identity context. With none, every serialized Zen `PackageImport` remains `package_identity_runtime_context_required`; an authoritative empty rewrite set explicitly proves that the serialized identity survives those stages unchanged. When a caller has the real aggregate result, `package_identity_rewrites` records source ID, effective import ID and package ID to load without replacing the serialized dependency identity.
3. **Single-container package-store state is never proof of the effective global store.** `FFilePackageStoreBackend` orders mounted containers by descending mount `Order`, then later `Sequence`; `FindOrAdd` makes the first effective redirect/localization row win. `FPackageStore` separately arbitrates mounted backends by priority, and hybrid/editor or already-loaded-package state can alter which loader/provider is used. Therefore absence of a redirect/localization row from the consumer's own ContainerHeader does **not** prove that no mounted backend redirects it. After pre-store identity is proven, static UnrealDB requires an authoritative effective package-store context for every Zen `PackageImport`; otherwise the result is `package_store_mount_context_required`. A selected-container row is evidence only, never a global winner by itself.
4. **Explicit package-store redirects precede localization.** Under authoritative context, explicit source->target ID redirects apply first. Localization is skipped in editor and otherwise requires active culture plus proof that the localized target package exists. Serialized required/source package identity remains unchanged while an effective provider lookup ID is recorded separately.
5. **Duplicate public hashes are runtime/order ambiguous for dependency lookup.** `ConditionalCreateImport()` checks the global import store first. If absent, `GetPublicExportIndex()` scans the main header low-to-high and, under `WITH_EDITOR`, then the optional header; cell fallback scans the main cell map. But every later `StoreGlobalObject()`/`StoreGlobalCell()` for the same `(PackageId, PublicExportHash)` overwrites the same global slot. Thus the first conditional-create fallback and the object ultimately visible after later export construction need not be the same duplicate. UnrealDB does not invent a stable winner: duplicate ordinary/cell hashes remain unresolved with all candidate indices preserved.
6. **`WITH_EDITOR` and runtime `GIsEditor` are different source inputs.** `FPackageImportStore::GetImportObject()` follows `UObjectRedirector::DestinationObject` only when compiled `WITH_EDITOR`; optional Zen headers/exports are likewise editor-build state. By contrast, `FFilePackageStoreBackend::GetPackageRedirectInfo()` uses runtime `GIsEditor` only for localized-package redirection. UnrealDB now carries `with_editor` and `is_editor` separately and does not substitute one for the other. Epic's exact ScriptImport hash for `/Script/CoreUObject.ObjectRedirector` is `1D39669A89BAECB6`; editor-build lookup remains payload-dependent, while a known non-editor build returns the redirector object itself.
7. **Optional segments augment the ordinary package; they are not alternative packages.** The same `FPackageId` can own one ordinary store entry and one optional-segment store entry. Main ExportBundleData is chunk index `0`; optional data is chunk index `1`. The previous builder incorrectly rejected this valid source shape and `findPackageChunk()` ignored chunk index. UEDB5 now preserves the main header plus a separate nested optional header/store entry. Optional consumer imports/load-order rows stay distinct from main source indices; optional ordinary provider exports can satisfy imports only under `WITH_EDITOR`, after main exports. Cell fallback remains main `CellExportMap` only.
8. **Export filter flags follow the exact build/runtime predicate.** Under `WITH_EDITOR`, `AsyncLoading2_ShouldSkipLoadingExport()` always returns false. A `UE_SERVER` build applies `NotForServer`; a build without server code applies `NotForClient`; otherwise dedicated-server/client-only runtime state decides those two bits, while mixed/listen state keeps the export. UnrealDB resolves the predicate when those inputs are authoritative and reports `export_filter_runtime_state_required` only when the necessary state is genuinely unknown. A deterministic filtered export is retained as unresolved provenance (`export_filtered_for_runtime_build`) rather than fabricated as a different object.
9. **`Missing`, `NotInstalled`, and `Pending` are package-store environment states.** The static catalogue indexes physically available staged providers and cannot manufacture on-demand backend status. A provider absent from the catalogue remains a catalogue missing result; runtime `NotInstalled`/`Pending` behavior is explicitly outside the standalone package snapshot until authoritative backend state is supplied.
10. **Soft references retain raw package IDs.** `FFilePackageStoreBackend::GetSoftReferences()` does not apply package-store redirect lookup during enumeration, so soft references remain source IDs and are not rewritten just because a redirect row exists.

#### Bounded migration boundary

Pass 2 is now `uedb5-dependency-pass-v11`; the transition accepts v1-v10.

- Completed UE1/UE2/UE3/UE4/UE5-classic deltas are not replayed by later-policy rows; the transition suppression table now explicitly carries those completed boundaries through v10.
- The v11 Zen impact query is SQL-bounded to staged UE5 Zen files that actually have PackageImport/cell/import-load-order dependency edges keyed by Zen `FPackageId`. Those rows are reopened because v3 now requires explicit pre-store identity proof and effective package-store proof before static resolution, in addition to the optional/filter/redirector corrections. Script imports, soft references, main-local export-only load-order rows, UE5 classic and non-UE5 files roll forward.
- Normal production Pass 2 does not possess live CoreRedirect/instancing/culture, already-loaded-package, hybrid-loader, backend-priority or mounted-container state. It therefore preserves serialized Zen identities but leaves affected package imports unresolved instead of asserting an environment-specific provider. Tests or future runtime-aware callers may supply explicit authoritative identity/store contexts; an empty context is meaningful proof, not an implicit default.
- The optional-segment source-shape correction requires Pass-1 restaging only for Zen packages that actually have an optional segment, because the previous builder could not represent the valid main+optional entry pair. No registered UE5 game migration target currently exists, so this does not trigger a present catalogue-wide scan.
- No V4 dependency metadata is used to make Zen decisions.

## Section 4A - Unreal / UE1 package serialization and pre-dependency preprocessing

**Status: complete for the supplied Unreal source surface; latest-v227 serializer/linker implementation bodies are explicitly unresolved rather than inherited from UT99.**

### Source authority and availability

- Latest Unreal tree: `L:\Source\Games\Unreal\Unreal [v1.227]`, revision `2bd1ce95a78bfd95fb83e7834abbb878b40b3cb6`.
- v227 defines engine 227, package version 69, licensee version 227 and minimum package version 60.
- The public v227 checkout contains the relevant Core headers but no `Core\Src` implementation bodies. Git history also contains no missing Core linker/name/object source bodies.
- Every sibling Unreal revision under `L:\Source\Games\Unreal` was searched for those implementations: v0.82, v0.83, v0.84a, v0.86x, v0.867, v1.200, v1.224 incomplete, and v1.227. The older v0.x trees contain earlier implementations; none is newer than v1.200.
- `L:\Source\Games\Unreal\Unreal [v1.224] [1999-05-01] [INCOMPLETE]` provides v68/min60 constants but likewise not the required linker implementation.
- `L:\Source\Games\Unreal\Unreal [v1.200] [1998-05-19]` is therefore confirmed as the latest complete Unreal-only Core implementation available locally for historical serialization branches. No Section-4A rule is sourced from UT99.

### Source results and corrections

1. **v227 summary generation count is clamped on load.** The inline `FPackageFileSummary` serializer clamps the serialized generation count to 0..64 before reading generation records. UnrealDB's game-aware `unrealgold` UEDB5 reader path now reproduces that behavior while preserving both serialized and effective counts. The rule is not projected onto UT99 or UE2.
2. **Pre-v50 Unreal exports omit PackageIndex.** Complete v1.200 `FObjectExport::operator<<` serializes the fixed-width PackageIndex only for version >=50 and initializes it to zero otherwise. UnrealDB previously consumed four bytes unconditionally and could misalign old exports. The UE1 pre-v50 path is corrected and UEDB5 records the outer as not serialized.
3. **SerialOffset follows nonzero SerialSize.** v1.200 uses `if (E.SerialSize)`, not `> 0`. The UE1 reader now consumes the compact SerialOffset for any nonzero serialized size. This correction is intentionally not projected onto UE2 by this checkpoint.
4. **v227 exposes a persistent ArchetypeIndex but not its serializer body.** `FObjectExport` declares `ArchetypeIndex`; `RF_HasArchtype` is part of `RF_Load`. Without the friend `operator<<` body, the byte order and gate cannot be asserted. UnrealDB therefore does not claim full v227 export-layout parity.
5. **v227 current serializers are demonstrably used.** `UWebAdmin/Src/WebAdminFile.cpp` reads the current summary, name entries and imports and identifies root package imports via `PackageIndex == 0 && ClassName == Package`. This proves current-type use but does not reveal the missing serializer implementations.
6. **Historical pre-dependency preprocessing is source-proven only where the body exists.** v1.200 loads summary/heritage/names/imports/exports, applies runtime name-context mapping, derives export ClassPackage/ClassName from ClassIndex, then begins VerifyImport. v227 declares related linker APIs and context state, but its missing body prevents silently promoting the v1.200 algorithm to a complete v227 claim.
7. **Unreal source-policy attribution is corrected.** Versions 60..68 are marked `ue1-unreal-v224-v60-68-public-source-partial`; v69 is `ue1-unreal-v227-v69-public-source-partial`; profile-admitted versions above 69 are `ue1-unreal-post-v69-profile-admitted-unresolved`. The previous Unreal v69 attribution to a UT432 shared serializer has been removed.

### UEDB5 and regression boundary

- Pre-v50 Unreal exports use `ue1.unreal.object-export.v2` and explicitly record that outer PackageIndex was not serialized.
- v227-summary snapshots retain `generation_count` plus `effective_generation_count`.
- `verify-ue1-pre50-import-layout.php` now covers the corrected export layout and nonzero SerialOffset condition.
- `verify-unreal-v227-summary-contract.php` proves the 65->64 and negative->0 generation-count behavior.
- Existing UE2 serialization and legacy-game persistence tests remain green.
- The database currently connected to this checkout does not contain the Step-5 UEDB5 tables, so no catalogue impact count is fabricated here. Required restaging is narrowly bounded to pre-v50 Unreal files plus any v68+ Unreal file whose serialized generation count lies outside 0..64; 60+ source-policy labels must also be refreshed before cutover.
- Full source notes and the unresolved-body matrix are in `specs/unreal-v227-package-format.md`.

**Section 4A does not certify the missing v227 Core implementation. The next Unreal checkpoint must not claim full v227 serializer/preprocessing parity unless those implementation bodies are obtained or another first-party source artifact proves the missing branches.**

### Post-4A source-gap recovery

The previously listed source-availability gaps were re-audited before Section 4B:

1. **Unreal v227:** every sibling Unreal tree under `L:\Source\Games\Unreal` was searched. No v1.224/v1.227 Core serializer/linker implementation body exists there; v1.200 remains the newest complete Unreal-only implementation. The v227 unresolved boundary is therefore confirmed rather than merely unsearched.
2. **Unreal II / later UE2:** `Unreal II The Awakening [01-07-2003]` is UE4 4.0.2 source and cannot be used. The separate UE2/Warfare tree is complete at package v126/min60; Section 4C now audits it as generic UE2 serialization/preprocessing authority through v126. It still does **not** prove Unreal-II-specific `VerifyImport` behavior after v69, so the fail-closed object-verification boundary remains until matching game-branch evidence is found.
3. **Later UE3:** both the local UDKUltimate engine-8364 tree and `L:\Source\Engine\UE3\Unreal Engine 3 (10897)` contain full Core linker sources. The 10897 checkout is the `CodeRedModding/UnrealEngine3` repository at commit `601d6a1f50a0a4a67e3ee0c352333783408d1ba7`; its `UnObjVer.cpp` (`27efc4042ec0e93dd98ad898950140904bbc398a`), `UnObjVer.h` (`a6d3f58762b7dc2fa5fef5f72dcc3faf9ceb9b13`), `UnLinker.cpp` (`0e8e58f0c31d5223093d5d4b716273b1d6a31457`) and `UnLinker.h` (`d7f59456fb37e1e63172268a0b4e93fec23c78f9`) are hash-identical to the older local 2013 copy. The earlier "source unavailable" classification is withdrawn.

This recovery checkpoint changes source availability only. It does not widen a game-specific resolver profile until the corresponding source-conformance section audits and tests that behavior.

## Section 4B - UT99 / UE1 package serialization and pre-dependency preprocessing

**Status: complete for retail v1.400/package v68; v430/package v69 is source-confirmed for summary/import/export serialization but remains partial for the missing `FNameEntry` implementation body.**

### Source authority and version boundary

- Complete retail authority: `L:\Source\Games\UT99\Unreal Tournament [v1.400] [1999-11-30]`, engine 400, package v68, minimum v60.
- Later public authority: `L:\Source\Games\UT99\Unreal Tournament v432` plus `C:\Users\arden\source\repos\UT99src-ext`; the headers report engine 430, package v69, licensee 0, minimum v60.
- `L:\src\Repos\UT99src` contains the same retail v400 complete source. `C:\Users\arden\source\repos\UnrealTournament` is not used as Core serializer authority.
- The v432 public tree and `UT99src-ext` contain the inline `FPackageFileSummary`, `FObjectImport`, and `FObjectExport` serializers. Neither contains `Core/Src/UnName.cpp`; the complete Git history of `UT99src-ext` also contains no hidden name/linker implementation body.

### Source results and corrections

1. **Retail v400 table serialization is fully source-backed.** Names branch at package v64; summary branches at v68; Import `PackageIndex` and Export `PackageIndex` are fixed-width INT; Class/Super/SerialSize/SerialOffset use compact indices; SerialOffset is present for any nonzero SerialSize.
2. **v430/v69 is more complete than the old audit wording said.** Its headers directly prove the low/high 16-bit Epic/licensee split and the complete summary/import/export byte serializers. Those rules no longer need to be described merely as generic “layout evidence.”
3. **The exact v69 name-entry serializer remains unresolved.** `FNameEntry::operator<<` is declared in the v430 header but its body is absent from the public distribution. UnrealDB does not claim that the retail v400 `FString` branch is first-party proof for v69.
4. **Epic preprocesses the NameMap before reading Imports/Exports.** Retail `ULinker` builds `_ContextFlags` from edit/client/server load flags; `LoadNames` inserts either the serialized FName or `NAME_None`. Import/export FName references then resolve through that effective map before `VerifyImport`.
5. **UnrealDB now uses an explicit source-backed catalogue context instead of raw-name semantics.** The catalogue mask is the UCC/UnrealEd all-context state `RF_LoadForClient | RF_LoadForServer | RF_LoadForEdit = 0x00070000`. Raw serialized names and flags remain stored; Imports/Exports/dependency identity use the effective map. This is not claimed as the only runtime mode.
6. **The low-level UE1 reader no longer applies runtime import assertions to raw FName text.** The previous `Core.Package`/positive-parent validation could reject a row that Epic would first map to `NAME_None` and ignore. Those assertions belong after NameMap preprocessing/VerifyImport, not in byte parsing.
7. **Both current and V5 metadata paths apply the same preprocessing.** `CatalogParsedPackageMetadataSnapshotBuilder` applies the UT99 all-context NameMap before current-format paths/dependencies are derived. `Uedb5ClassicDependencyResolver` reconstructs the same effective map from UEDB5 Names before UE1 VerifyImport while leaving the source-shaped UEDB5 Names section unchanged.
8. **UT99 source policies now expose the real boundary.** v69/licensee-era snapshots use `ue1-ut99-v430-public-source-partial`; versions above 69 use `ue1-ut99-post-v69-profile-admitted-unresolved`. The former `supplemental-v430` and `forward-loader-compatible` labels were too strong.

### Regression and migration boundary

- `verify-ue1-root-import-integrity.php` proves that raw imports are preserved and that zero load-context name flags map to `NAME_None` only in preprocessing.
- `verify-uedb5-unreal-classic-dependency-resolution.php` proves the same mapping happens before V5 VerifyImport and yields the source-irrelevant `NAME_None` result.
- The UE1 VerifyImport profile, UT99 persistence, cross-game legacy persistence, parity-contract and physical-provider tests remain green.
- Existing staged/current UT99 metadata can be affected only where a referenced Name row has no bit in `0x00070000`, plus rows whose old v69+ source-policy labels need refresh. Name flags are not projected into the Step-5 SQL candidate tables, so exact impact discovery scans compact UEDB5 metadata, **not original package bytes**. Run `D:\php8.5\php.exe catalog\bin\diagnose-ut99-name-map-impact.php --summary` against the real staging DB for the bounded count, then rerun without `--summary` for exact file IDs. Only those IDs require current-format rebuild or Pass-2 refresh; no game-wide package reparse is justified by 4B. The diagnostic fails closed if `ue_uedb5_files` is absent.
- The next package-serialization checkpoint should continue independently with the next engine/game profile rather than inheriting UT99 preprocessing into UE2 without its own source comparison.

## Section 4C - Unreal II / UE2 package serialization and pre-dependency preprocessing

**Status: complete for the supplied Unreal-II v69 source and generic UE2/Warfare v126 serialization/preprocessing surface. Unreal-II-specific VerifyImport remains intentionally bounded to package versions 60-69.**

### Source authority and availability

- Unreal-II-specific authority: `L:\Source\Games\Unreal II\Unreal II The Awakening [12-09-2000]\Unreal2_old`, engine 411, package v69, licensee `0x7F`, minimum v60.
- Later generic UE2 authority: `L:\Source\Engine\UE2\Unreal Engine [v2.5]_ Unreal Warfare [09-29-2007]`, engine 1226, package v126, licensee 0, minimum v60.
- The directory labelled `Unreal II The Awakening [01-07-2003]` is UE4 4.0.2 in the supplied archive and contains no `U2XMP_all` subtree. It is rejected as UE2/Unreal-II authority.
- Both accepted trees contain complete `UnLinker.cpp`, `UnName.cpp`, `UnObj.cpp`, and version headers, so the serialization/preprocessing comparison has no missing implementation-body gap.

### Source results and corrections

1. **The core package-table byte layout is shared across v69 and v126.** Both serialize the same summary fields and v68 generation/heritage branch, the same Import fields, and the same Export fields. Import/Export `PackageIndex` is fixed-width INT; FName references, class/super indices and serial size/offset are compact.
2. **UE2 SerialOffset uses truthiness, not positivity.** Both `FObjectExport::operator<<` bodies use `if (E.SerialSize)`. UnrealDB previously consumed UE2 SerialOffset only for `SerialSize > 0`; the reader now consumes it for every nonzero value, matching the source.
3. **Both sources preprocess Names before Imports/Exports.** `ULinker` builds `_ContextFlags` from editor/client/server state and `LoadNames` maps nonmatching rows to `NAME_None`. UnrealDB now applies the explicit source-backed UCC/editor all-context mask `0x00070000` to Unreal II before deriving current-format or UEDB5 dependency identity while preserving raw serialized Names/flags.
4. **v126 has a real runtime-name-length delta.** Unreal-II v69 copies the FString into the FName buffer directly; Warfare v126 copies `Str.Left(NAME_SIZE-1)`. UnrealDB therefore keeps raw serialized text but caps effective v70-126 catalogue FNames at 63 characters. Versions 60-69 retain the v69 behavior.
5. **The v126 rule stops at 126.** UEDB5/current preprocessing does not apply v126 truncation or NameMap authority to admitted versions above 126.
6. **Export hashing is built from effective Names/class identity.** Both linkers create the export hash only after Names, Imports, and Exports are loaded. The existing source-profile VerifyImport resolvers continue to own game-specific hash traversal/matching; no v126 Unreal-II VerifyImport profile was added.
7. **Warfare QuickMD5 is non-transforming and generic-only.** v126 hashes two structural raw-byte ranges after table loading and before export-hash construction. v69 has no corresponding block. QuickMD5 does not change table/dependency identity, so it is documented rather than substituted with another hash.
8. **The old payload-mismatch documentation was wrong.** Both v69 and v126 `Preload` call fatal `appErrorf` when consumed bytes differ from `SerialSize`; the previous “Unreal II warning” claim was removed from both Unreal-II and UE2.5 specs.
9. **Runtime export-creation differences stay revision-specific.** v69 falls back to `UClass::StaticClass()` for a missing resolved class and skips `Camera`; v126 returns null for a missing nonzero class reference and skips both `Camera` and `PlayerInput`. These are not projected into static dependency identity.
10. **Source-policy attribution now states the actual proof.** Versions 60-69 use `ue2-unreal2-2000-12-09-package-v69`; versions 70-126 use `ue2-warfare-v126-serialization`; admitted versions above 126 use `ue2-unreal2-post-v126-profile-admitted-unresolved`. The generic v126 policy does not activate Unreal-II v69 VerifyImport semantics.

### Regression and migration boundary

- `verify-legacy-ue2-serialization-contract.php` now proves that a negative/nonzero compact SerialSize still carries a serialized SerialOffset.
- `verify-unreal2-name-map-preprocessing.php` proves v69 effective names are not v126-truncated, v126 raw text is preserved while effective text is capped at 63 characters, zero-context Names become `NAME_None`, V5 uses the same rules, and v127 does not inherit v126 preprocessing.
- The existing UE2 VerifyImport profile regression remains green and still proves Unreal-II VerifyImport stops at v69.
- The UE2 transition query recognizes both historical `ue2-unreal2-*` policies and the new generic v126 serialization policy, so migration impact cannot disappear merely because attribution changed.
- Run `D:\php8.5\php.exe catalog\bin\diagnose-unreal2-name-map-impact.php --summary` against the real staging DB for a bounded metadata-only impact count; rerun without `--summary` for exact file IDs.
- That diagnostic reads staged UEDB5 only. Context-filtered Names, v70-126 >63-character effective names, and source-policy changes do **not** require original Unreal package reads. A staged export with negative `serial_size` is separately reported because the old reader would have skipped its serialized offset; only that exact file set requires Pass-1 reparsing from original bytes.
- This checkout's connected database still lacks `ue_uedb5_files`, so the diagnostic correctly exits before scanning and no impact count is fabricated.

## Section 4D - UT2003 / UE2 package serialization and pre-dependency preprocessing

**Status: complete against the sole supplied UT2003 v2107 source tree, independently from Unreal II, generic Warfare v126, and UT2004.**

### Source authority

- `L:\Source\Games\UT2003\Unreal Tournament 2003 [v2107] [2002-10-01]`
- `ENGINE_VERSION = 1107 + DEMO_VERSION_OFFSET`, package v120, licensee `0x1C`, minimum v60.
- The tree contains complete `UnLinker.cpp`, `UnName.cpp`, `UnObj.cpp`, and headers; there is no missing serializer/preprocessing implementation gap for this profile.

### Source results and corrections

1. **UT2003 retains the classic UE2 table layout independently.** Summary, Import, and Export fields match the audited source: fixed INT PackageIndex/outer fields, compact FName/class/super/SerialSize/SerialOffset, and SerialOffset present whenever SerialSize is nonzero.
2. **The summary has a strict tag guard.** On load, `FPackageFileSummary` reads Tag first and does not consume the remaining summary if Tag is wrong. UnrealDB already rejects unsupported tag immediately after four bytes, so no code change was needed.
3. **Names are preprocessed before Imports/Exports.** `ULinker` constructs `_ContextFlags`, then `LoadNames` maps nonmatching rows to `NAME_None`. UCC and UnrealEd prove an all-context state with edit/client/server all enabled; UnrealDB now uses that explicit `0x00070000` catalogue context for UT2003 v60-120.
4. **UT2003 does not inherit Warfare's 63-character runtime-name truncation.** Its v64+ `FNameEntry` path copies the loaded FString directly with `appStrcpy`. Raw and effective long names therefore remain intact for v60-120.
5. **Later admitted versions fail closed.** The old `ue2-ut2003-forward-loader-compatible` label is replaced by `ue2-ut2003-post-v120-profile-admitted-unresolved`; post-v120 rows do not inherit v2107 NameMap or VerifyImport behavior.
6. **The existing UE2 nonzero SerialOffset fix covers UT2003 too.** The source uses `if (E.SerialSize)`, matching the reader correction made in 4C.

### Regression and migration boundary

- `verify-ut2003-name-map-preprocessing.php` proves v120 preserves raw/full long names, filters zero-context names to `NAME_None`, and that v121 inherits neither transformation in current nor V5 paths.
- `verify-legacy-ue2-serialization-contract.php` retains the nonzero/negative SerialSize -> SerialOffset regression.
- Existing UE2 VerifyImport, UEDB5 classic dependency, persistence, and transition tests remain green.
- `diagnose-ut2003-name-map-impact.php` performs bounded staged-metadata discovery only. Run with `--summary` for counts and without it for exact file IDs. Only a negative-`serial_size` file requires Pass-1 original-byte reparse; context/policy changes are metadata/Pass-2 scope.
- This checkout's connected DB lacks `ue_uedb5_files`, so the diagnostic fails closed before scanning and no impact count is invented.

## Section 4E - UT2004 / UE2.5 package serialization and pre-dependency preprocessing

**Status: complete against all supplied UT2004 source revisions, with v129 as latest authority and v127/v128 independently cross-checked.**

### Source authority

- v3186 / package v127: `L:\Source\Games\UT2004\Unreal Tournament 2004 - v3186`.
- v3369 / package v128: `L:\Source\Games\UT2004\Unreal Tournament 2004 [v3369] [03-16-2004]`.
- latest package v129: `L:\Source\Games\UT2004\UT2004Src\UT2004SrcCmake`.
- All three declare minimum package v60 and licensee `0x1D`; all contain complete Core linker/name/object implementations.

### Source results and corrections

1. **The serialized table contract is stable across v127-v129.** v128/v129 serializer differences are formatting/build-only for summary/import/export; v127 independently confirms the same field layout.
2. **Strict bad-tag handling is source-proven.** Summary serialization stops immediately after Tag on mismatch; UnrealDB already rejects bad magic after four bytes.
3. **NameMap preprocessing is mandatory.** All audited revisions construct `_ContextFlags` before tables and map nonmatching Names to `NAME_None` before Imports/Exports. UCC/UnrealEd prove an all-context `0x00070000` mode.
4. **UT2004's name truncation is version-gated.** v60-63 uses the legacy ANSI branch without the later explicit cap; v64-129 loads FString and copies `Str.Left(NAME_SIZE-1)`, so effective catalogue names cap at 63 characters while raw serialized text remains preserved.
5. **SerialOffset follows every nonzero SerialSize.** All audited revisions use `if (E.SerialSize)`; the shared UE2 reader correction from 4C therefore applies directly.
6. **Package 130+ no longer masquerades as v129 authority.** The old builder assigned `POLICY_V129` to every version >=129. It now uses `ue2-ut2004-post-v129-profile-admitted-unresolved` above 129, matching the existing VerifyImport fail-closed boundary.

### Regression and migration boundary

- `verify-ut2004-name-map-preprocessing.php` proves v63 filtering without truncation, v64/v129 63-character effective names, raw-name preservation, and no v129 inheritance at v130 in both current and V5 paths.
- Existing UE2 serialization, UT2004 VerifyImport, V5 dependency, persistence, and transition regressions remain green.
- `diagnose-ut2004-name-map-impact.php` provides staged-metadata-only counts/IDs for context filtering, runtime truncation, policy refresh, and negative-size reparsing.
- Only exact negative-`serial_size` files require original package bytes; all other 4E remediation is metadata/Pass-2 scope.
- This checkout's connected DB lacks `ue_uedb5_files`, so the diagnostic fails closed and no impact count is fabricated.

**Next checkpoint: Section 4F - UT3 / UE3 package serialization and pre-dependency preprocessing, audited independently from the UT3 source before using later UE3 build 10897 as supplemental comparison.**
