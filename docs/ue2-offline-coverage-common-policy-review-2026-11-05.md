# UE2 offline dependency / duplicate-version review — 5 November 2026

## Source authority and actual loader behavior

The January 2003 Unreal II source `Core/Src/UnObj.cpp`, `UObject::GetPackageLinker`, first reuses an existing `GObjLoaders` linker for an exact package root. Otherwise it calls `appFindPackageFile`, then constructs one `ULinkerLoad`. `Core/Src/UnMisc.cpp`, `appFindPackageFile`, tries the specified filename and then configured `GSys->Paths` in iteration order (including internationalization, cache GUID, CD and case-insensitive fallback). `Core/Src/UnLinker.cpp`, `ULinkerLoad::VerifyImport`, verifies imports against that *selected linker* using object name, class, package/parent and flags; it does not compare every candidate's export coverage and select a superset. UT2004 source `UT2004Src/UT2004SrcCmake/Core/Src/UnObj.cpp` has the same already-loaded-linker-before-physical-search principle and generation-level support. Explicit filename/root reset/relink is distinct from automatic simultaneous loading of multiple versions with the same root.

Source-relative path and package identity are not equivalent to proof that a historic installation mounted that path. Unreadable original mount context must not generate invented physical provider selections.

## Audit omission / unsound legacy common class

The earlier reader/UE2 source-serialization review verified parser and static source-gated VerifyImport branches, but accepted the pre-existing **user-configuration common package heuristic** without challenging its independence from Epic's VerifyImport. In the current PRD configuration `common_packages` includes `Core,Engine,Editor,Fire,IpDrv,UWindow,Botpack,UnrealShare,UnrealI,Gameplay,UnrealEd`. For Unreal II game #2, 39,295 real imports were automatically labelled common: Engine 33,737; Core 4,993; Gameplay 219; IpDrv 211; Editor 90; Fire 45. Gameplay has **zero** indexed provider candidates. This is a metadata/dashboard shortcut, not a source-verified dependency satisfaction event. The common bypass must be removed for UE2 in a separately versioned dependency-policy pass, and the dashboard must distinguish source-confirmed physical resolution from common/known native package metadata. **Not corrected by this coverage-only patch**.

## Read-only PRD evidence after 5 November Full Sync

- Full Sync root job #9525717 completed at 17:43:53: 1,217/1,217 source reimport children; 13/13 dependency batches; no failed jobs.
- All 1,217 metadata file records were republished during Full Sync, with 323 v126/lic0 packages using `ue2-unreal2-2003-01-07-package-v126`.
- 110,970 dependency references: 71,675 unresolved; 39,295 common; 0 source-verified resolved or confirmed missing.
- Among unresolved: 27,080 with one indexed package candidate; 44,592 with multiple; **3** with none (`MyStuff` in file #1253000). No authoritative runtime selection evidence was configured. An indexed candidate is **not** evidence of a mounted/selected original linker.
- Original package revisions in a single catalogue game scope are sometimes from distinct editions: Core.u in Unreal II base #111196 v110/lic2609 vs XMP #149515 v126/lic0; ParticleSystems.u base #111204 v110/lic2609 vs XMP #149523 v126/lic0. Their export sets must not be merged or treated as automatically substitutable.

## Confirmed Package Coverage defect and targeted repair

Before repair, `ue_package_coverage_cache` had 0 entries for Unreal II even though many package identities had multiple physically indexed copies. Source of defect: UEDB5 classic `required_object_identity` serializes `object_name`, `class_package`, `class_name`, and `outer_index`, **not a preassembled `object_path`**. `PdoPackageSupersetAnalyzer` only consumed `required_object_path`, so its per-game requirements were empty and multi-provider cache entries were pruned.

Repaired `PdoPackageSupersetAnalyzer` **for UE2 only**: source paths are reconstructed from the reader's existing `Uedb5ClassicDependencyResolver::importCoverageRows()` import-parent walk, by exact `source_index` and package root. Skip the Core.Package linker-root import itself; it is not an exported object. Source file #149516 `AutoTurretFX` now has exactly two required paths `ParticleSalamander2` and `ParticleSalamander3`. Original candidate #149589 matches 2/2; candidate #111224 matches 0/2. The regression `tests/tools/verify_ue2_package_coverage_original.php` uses MySQL READ ONLY and three genuine UEDB5 snapshots. No provider selected for original runtime; no dependencies or UEDB5 files were modified.

`PdoPackageObjectCoverageResolver` now accepts an explicit optional storage root, allowing isolated exact snapshot regressions without relying on DEV's missing full data directory. Other engine behaviour is unchanged. The Package Coverage website copy now states that its *export coverage* is NOT a claim of runtime provider selection, complete source VerifyImport acceptance or cross-edition universal compatibility.

**Needed to populate previously empty coverage cache:** a game-scoped run of existing CLI
`& "C:\php8.5\php.exe" "C:\Apache24\htdocs\unrealdb\catalog\bin\rebuild-package-coverage-cache.php" --game-id=2`
on PRD. This rebuild uses existing UEDB5 metadata; does not rescan original source files and does not update dependency classifications. Full Sync is unnecessary. The command was **not executed** in this audit. It writes only derived coverage cache entries.

## Remaining hard requirements before claiming UE2 dependency source parity

1. Remove UE2 `common_packages` shortcut in `Uedb5ClassicDependencyResolver` and other UE2 dependency paths; represent a known/common engine package as supplementary metadata, not an automatically satisfied dependency. Keep the other engines isolated.
2. Increment the UE2 dependency-policy version and republish UE2 Pass 2 and game counters under an atomic migration plan (avoid silently leaving PRD figures from the old policy while showing `current`). Scope Unreal II first; UT2003/UT2004 subsequent.
3. Distinguish offline indexed provider candidates, static object/class/outer/visibility matches, original-configuration mount selection, and engine-confirmed resolved load. Never union exports from different files to manufacture a single valid provider.
4. Treat distinct Unreal II / XMP installation variants as separate availability environments even though their original file records share catalogue game #2. Package Coverage compares unioned known consumer requirements and may report no universally complete candidate where each original edition was internally valid.

This audit adds an offline coverage fix and retains the existing UE2 Pass-2 policy; **it does not assert that common or physical load verification is fully source-parity complete**.
