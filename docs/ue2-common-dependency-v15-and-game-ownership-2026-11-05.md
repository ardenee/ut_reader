# UE2 dependency source parity: common shortcut removal and ownership audit

Date: 5 November 2026. Production game identity and metadata are authoritative only within their registered game context; the original game-source loader defines the import/load algorithm.

## Why the earlier Epic path audit missed this

Previous Graphify/Graphviz/Ghidra/Doxygen/Xdebug and reader acceptance verified *reachable engine source paths*, serialized table data, version selection and conditional source-shaped `VerifyImport`. But it did not require an explicit **negative test** for UnrealDB-only status shortcuts *before* those paths were reached. `Uedb5ClassicDependencyResolver::resolve()` used the configured `common_packages` list to return `common` before it consulted package/linker or `VerifyImport`. This was not an Epic exception; the earlier review and 7/7 UE2 bounded-operation closure therefore made an overbroad claim. Code-path reachability without a verified source-semantic assertion is insufficient. The new regression explicitly exercises the forbidden bypass on exact source files.

## Original UE2 loader semantics

January 2003 Unreal II `Core/Src/UnObj.cpp`, `UObject::GetPackageLinker`: an existing `GObjLoaders` linker is looked up by its root package object; otherwise `appFindPackageFile` resolves a physical archive and creates a linker. `Core/Src/UnMisc.cpp`: specified path, then ordered `GSys->Paths` (with conditional language, CD and cache handling). `Core/Src/UnLinker.cpp`, `ULinkerLoad::VerifyImport`: object/class/outer/export/public rules against the **selected** linker, not union of all versions. The UT2004 `GetPackageLinker` retains the same same-root reuse principle and adds generation-level variants.

A second file with the same filename does not displace an already loaded linker just because its export list is bigger. The engine generally uses one physical linker for a given root object in a loading context, so two different copies with the *same logical package root* do not act as independently combined providers. Distinct roots/explicit loading contexts are a separate case. Static UnrealDB may evaluate **all independent candidates** for coverage but must never state that the original engine automatically chose the largest superset. Source path order is installation evidence, not a property encoded in every package.

## UE2 v15 change — no automatic common satisfaction

- In the UE2 engine branch, `Uedb5ClassicDependencyResolver` now refuses configured `common_packages` input. `Uedb5GameDependencyPassService::resolverOptions` independently supplies an empty list for UE2; other engine branches are intentionally unchanged.
- Source policy is now `uedb5-dependency-pass-ue2-mount-no-common-v15`. Old v14 snapshots are **stale** until republished from existing originals' UEDB5 metadata. No original-file Full Sync is required.
- Original, hash-pinned UEDB5 `U2.u` #111205 previously had 80 Core imports labelled common; with the new UE2 code and no selected provider, all 80 are unresolved and **zero** imports are common. `UnrealGame.u` #1360642 previously had 27 GamePlay references labelled common; they are likewise no longer automatically satisfied. Test: `tests/tools/verify_ue2_no_common_source_bypass.php`.
- This change does not yet make an arbitrary static catalogue provider into an Epic-selected runtime linker. The offline candidate/export compatibility report is separate and already implemented in Package Coverage; a future per-candidate offline compatibility view may expose satisfiable alternatives without claiming runtime selection.
- Production deployment must be followed by UE2 Pass 2 v15 republication for game IDs 2 (Unreal II), 4 (UT2003), 5 (UT2004) and a game-stat refresh. Until then the Games page correctly treats all three old v14 aggregates as stale/unavailable. Run locally on PRD using `catalog/bin/publish-ue2-no-common-v15.ps1 -GameIds 2,4,5 -Workers 2 -Apply`. This is resumable, scoped to those games and no full rescan of original bytes.
- UE1, UE3 and UE4 retain their own existing policies and `common_packages` paths pending **their own** source-specific audit. Their public counts are not silently reclassified via this UE2 patch.

## Gameplay 219 reference investigation

Read-only actual PRD MySQL: 219 GamePlay references across **25 package files assigned to Unreal II game #2**, with source files such as `UnrealGame.u`, `UT2k4Assault.u`, `Onslaught.u`, `XGame.u`. Their retained source paths show UT2003/UT2004, rather than Unreal II base/XMP. `GamePlay.u` is already present and verified under the **proper** UT2003 game #4 and UT2004 game #5; it is absent within #2. Thus the common shortcut concealed an apparent **game-ownership problem**, not necessarily absent real base-game files.

Initial broad substring discovery reported 393 suspects, but this included two filename-only matches. One is genuine Unreal II community archive `Unreal 2/MapPacks/.../ut2003barrensarc.utx` #1252898 (v110/licensee2609) and must **not** be reassigned based on its filename. The other is top-level `UT2004Weapons.utx` #149679 (v127/licensee29), exact same verified bytes also exist under UT2004 #5; without a directory root it remains a separate cross-game ownership lead.

The precise directory-segment audit `catalog/bin/diagnose-ue2-game-ownership.php --db-host=192.168.0.55` found **391** high-confidence cross-game duplicates: 88 with UT2003 source directory evidence and exact verified same bytes already under #4, 303 with UT2004 directory evidence and exact verified same bytes already under #5. With the top-level `UT2004Weapons` fingerprint lead, **392** files have clear cross-game exact-byte counterparts, but the rigorous script deliberately reports the 391 with directory evidence separately. No production record was moved, marked duplicate, deleted or reimported by this investigation. A dedicated identity/metadata/dependency migration, or safe demotion procedure, is required rather than directly changing `ue_files.game_id`.

## Already executed Package Coverage cache rebuild

The user ran the scoped PRD rebuild for game #2: 211 package names considered, 197 cached, 14 pruned. It uses existing UEDB5. The original `AutoTurretFX` consumer #149516 requires two exports; provider #149589 matches 2/2, #111224 matches 0/2. Package Coverage is a static multi-provider compatibility comparison, not a mounted-provider selection. Its cache is intentionally separate from source-classified dependency results.

## Follow-up hard acceptance requirements

1. Repair wrong game ownership using supported identity/metadata workflows, verifying exact hashes and source-path provenance for each candidate. Confirm no false positives from package filenames, and reconcile game counts, coverage, providers and published dependencies.
2. Republish current UE2 v15 dependencies for all three UE2 games via scoped PRD CLI and rebuild cached statistics. Until complete, stale badges must remain Unavailable, not obsolete v14 numbers.
3. Implement a separately labelled per-candidate offline import/export compatibility assessment, version-specific and source-shaped, including object name, class, outer and visibility. Never combine exports from two versions. Distinguish static candidates from the engine's single session-selected physical linker.
4. Cross-verify the UE1/UE3/UE4 legacy common heuristic independently before editing their siloed policies.
