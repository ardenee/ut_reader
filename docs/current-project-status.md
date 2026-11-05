# UnrealDB current project status

## 5 November 2026 — Unreal II wrong-game ownership repaired in PRD (409 files)

The earlier UE2 Full Sync faithfully regenerated original packages but did not audit source game ownership, leaving Unreal II with UT2003/UT2004 content and 219 false common `GamePlay` dependencies. **Actual PRD repair completed:** 391 wrong-game files with original UT2003/UT2004 directory provenance and exact byte-identical verified counterparts removed; a second provenance/UE2-version check identified 15 more exact UT2004 duplicates removed and **3 distinct UT2004 originals imported/verified into game #5 first**, then retired from game #2. Overall **406 canonical duplicate deletions + 3 canonical verified moves** = **409 wrong-game records removed**. A total of 391+18 original source UEDB5 containers were SHA-256 backed up to PRD `L:\\UnrealDB\\maintenance-audit\\ue2-owner-fix-20261105`, together with complete preflight manifests, a backup of the old game2 coverage cache and durable action logs; three unique moved package bytes have a separate backup. Queued mirror jobs #88 and #453 were redirected to their valid surviving game5 files and retained.

Source counts: Unreal II game #2 **1,217 → 808 verified**, UT2003 #4 **5,082 unchanged**, UT2004 #5 **72,880 → 72,883**. Final postflights found 0 deleted-source file identities/UEDB5/provider projections, 0 old source physical packages/UEDB5 containers, all 409 correct-game targets verified present and backups intact. The original Unreal II map-pack `ut2003barrensarc.utx` and the genuine Unreal II files whose bytes also occur in UT2004 are **retained**; package-name or cross-game hash alone cannot prove incorrect game ownership. UnrealDB's `source_relative_path` is original catalogue acquisition provenance, **not a serialized UE package field**.

UE2 v15 Pass 2 for game #2 **808/808 complete**; independently rebuilt its cached `games.php` stats, freshness gate PASS, and dependency references **110,970 → 74,556** with no `common` shortcut. UT2003 #4 **5,082/5,082 v15 complete**; UT2004 #5 v15 publication is ongoing and its old cached aggregate is stale until full Pass 2 completion. Additional UT2004 affected-dependency and projection reconciliations were queued by canonical imports and must be executed by the PRD worker. The obsolete game2 Package Coverage cache (197 groups) was backed up and replaced via a completed source-current rebuild: **84 groups inspected, 70 cached, 14 pruned**.

Evidence and restoration details: [UE2 production 409-file ownership correction](ue2-409-game-ownership-production-reconciliation-2026-11-05.md).

---

## Historical (superseded): 5 November 2026 — UE2 v15 shortcut and ownership investigation, before the 409-file PRD repair

Epic UE2's source `VerifyImport` does not contain the configured UnrealDB `common_packages` dependency exemption. The earlier UE2 7/7 path closure was overbroad: tracing executable source paths did not assert that the UnrealDB-only shortcut was absent. UE2 v15 now ignores this shortcut while leaving other engine policies isolated. SHA-pinned genuine Unreal II files #111205 and #1360642 demonstrate 80 Core and 27 GamePlay imports, respectively, no longer incorrectly classified `common`. Production UE2 game IDs 2/4/5 need Pass 2 republication and cached statistic rebuild after deployment; old v14 figures must fail freshness until that occurs. The PRD local resumable scoped wrapper is `catalog/bin/publish-ue2-no-common-v15.ps1`; it requires explicit `-Apply`.

PRD read-only ownership investigation: all 219 GamePlay-labelled references under Unreal II game #2 originate from 25 UT2003/UT2004 files wrongly placed in that game. The correct GamePlay.u providers exist under UT2003/UT2004. An exact-hash + source-directory audit identified 391 wrongly scoped duplicates (88 UT2003, 303 UT2004) that are already present in their correct games. A top-level `UT2004Weapons.utx` adds one separate exact cross-game match; filename-only `ut2003barrensarc.utx` is actually from an Unreal II map pack and is **not** an ownership candidate. Nothing has been migrated or deleted pending a source-safe game-ownership reconciliation. The user separately completed the Unreal II Package Coverage rebuild (211 examined, 197 cached, 14 pruned).

Epic source-specific notes, test evidence and next steps: [UE2 v15 and game ownership](ue2-common-dependency-v15-and-game-ownership-2026-11-05.md).

---

## 5 November 2026 — UE2 offline package coverage corrected; dependency common-policy gap reopened

The completed Unreal II Full Sync #9525717 rebuilt 1,217/1,217 UEDB5 sources, 13/13 dependency batches and current game statistics, but unchanged counts are expected under current provider-selection rules: 71,675 unresolved and 39,295 config-labelled common; 0 source-confirmed physical resolves. Read-only original-source review found that `common_packages` is an UnrealDB heuristic, **not** a UE2 `VerifyImport` exception: the earlier 7/7 bounded-operation closure does **not** close offline common-versus-resolved semantics. A separately versioned UE2 dependency-policy migration/republication remains outstanding; no policy change or PRD Pass-2 writes in this patch.

Package Coverage had zero Unreal II cached package rows because the UE2 snapshot preserves source import name/parent fields rather than a preconstructed `required_object_path`. The catalogue coverage analyser now derives this path from the existing source-backed UE2 import-parent walk and skips the package-root linker import, without changing other engine logic. Original Unreal II file #149516 requires two `AutoTurretFX` exports; physical candidate #149589 matches 2/2 and #111224 matches 0/2. Coverage evidence is offline **candidate compatibility**, never an engine-selected runtime provider. The independent scoped coverage-cache rebuild CLI can repopulate the PRD display from existing UEDB5, with no full resync. See [UE2 coverage and common-policy review](ue2-offline-coverage-common-policy-review-2026-11-05.md).

---

## 5 November 2026 — Hide package-only badges in file listings

Removed the internal package_only status badge from both catalog/game-files.php and the older catalog/game-page.php; also removed the visible Package only dependency filter option. The underlying publication outcome remains in UEDB5, existing bookmarked dep_filter=package_only URLs remain accepted, and downstream dependency/download closure logic remains untouched. This is a display-only change: do not promote a package-level presence check to an exact export or ScriptObjects match.

**Verified example:** Satisfactory file **1390232** (/Engine/BasicShapes/Sphere) retains **resolved: 10** (8 exact cooked ScriptObjects in global.utoc, 2 indexed export references). Its additional indexed package-level reference to /Engine/EngineMaterials/DefaultMaterial (#1372197) is no longer shown as package_only: 1, since it was not reporting an error. Its source-backed dependency records and file-examine.php are unchanged.

**Acceptance:** 7/7 real DEV HTTP GET routes on PRD read-only metadata, including Sphere, UE5/UE4 general lists, UE4 resolved and UE5 fully resolved filters, legacy game view and unchanged UE5 package examiner. Rendered assertions confirm exact resolution badges and the tooltip survive while package_only badges/selection are absent. No database writes or engine tests needed; fixed UE1–UE5 acceptance register remains 32/32 PASS. Evidence: [package-only display cleanup](../tests/evidence/game-file-package-only-badge-removed-20261105.json).

---

## 5 November 2026 — Games drilldown and consistent numbered pagination

**Games public header:** removed duplicate Search and admin-only Library action buttons; global nav remains unchanged. All four current dependency counters are links: **Unresolved** and **Unresolved Files** open the `unresolved` game-file filter; **Resolved** opens files containing at least one satisfied dependency; **Resolved Files** opens `fully_resolved` (every dependency met). Reference totals and distinct matching file counts are explicitly shown with their different units. UE5 now shares the Games page's exact indexed-target exception-set; Satisfactory drilldowns match **244 unresolved references / 51 unresolved files**, **1,847,626 satisfied references**, and **27,189 fully satisfied files**. The 26 external-to-current-index package identities remain unresolved, not source-confirmed missing. Native source-policy projections are unchanged.

**Style delivery:** the parent `catalog-ui.css` URL now has a file-mtime cache key and its nested `catalog-ui-components.css` import has a content-hash cache key, preventing browsers from continuing to use an older pagination layout after deployment.

**Pagination:** one responsive, compact shared component provides numbered nearby pages, ellipses, first/previous/next/last and a validated Go-to-page field preserving filters and sort. The Game Files browser uses bounded SQL offset **only for direct page selection**, retaining HMAC/context-bound keyset cursors for fast adjacent navigation. Other existing offset-paginated views updated to the same controls: original game page, file-examine package tables, archive directory, PAK/UPK package lists, duplicate manager and unverified queue. No rewrite of the separate cursor-only confirmed-missing view is claimed.

**Acceptance:** 71 named rendered Games/pagination assertions PASS; 15/15 HTTP 200 game/list GETs plus expected 303 historical game-page redirect; 5/5 direct regression GETs and 6/6 archive/PAK public GETs pass on DEV with PRD source metadata. UT4 page 200→signed next 201 and signed previous 199 pass; out-of-range selections clamp correctly. Direct legacy game-page and file-examine pagination both present numeric links and a Go form. Admin-only views were syntax/static checked, not bypassed for authenticated browser tests. No PRD SQL writes, native engine reruns or original corpus changes. Existing **32/32 fixed engine contracts remain PASS**. Evidence: [Games links and pagination](../tests/evidence/games-links-and-shared-pagination-20261105.json).

---

## 5 November 2026 — Games Resolved correction and UE5 exact catalogue coverage

**Corrected definition:** `Resolved` on `catalog/games.php` is **total dependency references − current unresolved references**. The former field `resolved_dependency_count` only counted uniquely engine-selected physical providers and therefore wrongly displayed zero for every game. For UE1–UE4, the fresh per-game source counts now yield the expected known/common/package-only satisfied totals; e.g. UT99 4,091,838, UT2004 3,260,406, UT3 1,079,968, UT4 413,683. The `Resolved Files` count remains dependency-bearing files with no outstanding source-unresolved/missing references.

**Satisfactory UE5:** Old `823,481 unresolved` was based on source-unknown package summaries, ignoring Zen ScriptImports, already source-resolved load-order edges and independently indexed IoStore targets. Read-only PRD SQL verifies **1,847,870 total Zen dependency edges**, **234,662/234,662 exact ScriptImport matches in pinned `global.utoc`**, **699,069/699,247 exact imported provider/export hashes**, and **124,168/124,234 package-only indexed package IDs**. The remaining **244 references on 51 files** name **26 unique FPackageIds not indexed** in the imported `FactoryGame-WindowsServer.utoc`; the other **1,847,626 references** are catalogue-satisfied and **27,189 of 27,240 files** have no outstanding indexed reference. There are **zero Satisfactory base-game registry rows** in PRD currently. These 26 source IDs may belong to additional, unimported game/base-game containers; do **not** label them missing. There is one FactoryGame container plus companion global.utoc, not a guaranteed all-dependencies-complete native mount.

**Safety/scope:** The site calculates UE5 catalogue coverage from current indexed ready/published package IDs, exact provider public-export hash, and SHA-pinned ScriptObjects identities. Original Epic-source v14 unresolved outcomes stay unchanged and unknown native mount context is not falsified. Cache invalidates on game statistics publication and on indexed container, published-package or ScriptObjects changes. Real DEV PHP HTTP cold/warm and all eight game rows now match read-only PRD data. Existing source-only Game Files filter is not linked for the UE5 adjusted counters, to avoid a misleading subset. No PRD SQL/package writes; 32/32 fixed engine acceptance cases unchanged. Evidence: [Resolved and UE5 exact indexed coverage](../tests/evidence/games-page-resolved-and-ue5-indexed-coverage-20261105.json). The previous Games+Graphify status block below is historical and superseded for the dependency totals.

---

## Historical (superseded): 5 November 2026 Games dependency coverage, Graphify-only dashboard

**Public Games table** now shows `Unresolved` (source-published reference count), `Unresolved Files` (distinct files with one or more unresolved references), `Resolved` (explicit source provider-resolved reference count), and `Resolved Files` (distinct dependency-bearing files with no source-unresolved or source-missing references). The two confirmed-missing columns and the explanatory/admin-link footer are removed. **Maintenance → Update All Game Dependencies** was already registered in the global admin navigation; the duplicate page-bottom link is not repeated.

**Important outcome distinction:** common and package-only references count as *met* for the Resolved Files completeness criterion, even though they do not contribute to the explicit Resolved physical-provider references column. Files with zero references do not inflate Resolved Files. Unknown native mounts remain unresolved. All four columns show **Unavailable**, not zero, until current engine-policy publication and cached statistics are source-fresh.

**Real PRD source data, DEV GET test:** eight game rows displayed with exact source-count equality. UT4 shows 334,462 unresolved references / 43,777 unresolved files / 0 explicit resolved references / 20,461 files with no outstanding source reference; UT3 1,511,557 / 4,264 / 0 / 2,458; Satisfactory (Zen script import edges included) 823,481 / 27,240 / 0 / 0. Cold GET ~5.96 s and cached-metric GET ~1.48 s. File counts use a private, timestamp-invalidated file cache; no database schema migration or production metadata rewrite. Isolated SQLite positive/negative cases passed including mixed multi-reference files, Zen script-only entries, missing and zero-reference exclusions, and update-epoch invalidation. Public response cache is independent and remains protected.

**Graphify DEV:** removed the whole UnrealDB/Epic compliance section, acceptance panel and injected API client from the generated Graphify landing page. The live `http://127.0.0.1:8765/` responds HTTP 200 with the Source Graph Library, project graph metrics, viewer limits and local scanner, without any compliance panel. Versioned builder, installer and documentation updated; local host/server and indexed project data preserved.

**Acceptance scope:** fixed UE1–UE5 contract register stays at **32/32 PASS**. This is a user-facing presentation improvement, not a new native engine parity claim or a new acceptance contract. Evidence: [Games and Graphify HTTP/SQL verification](../tests/evidence/games-page-graphify-only-20261105.json).

---

## UE4 closeout — 5 November 2026

**UE4 6/6 PASS; fixed UnrealDB contract register 32 PASS / 0 BLOCKED.** This is closure of the defined file/header/table/VerifyImport-classic/provider/dependency/website operations, not a claim to all possible native Unreal Engine runtime behavior.

**UT4 game #7 production:** 64,247 verified files, 64,247 staged, 64,247 source-current UE4-mount-v14 dependency results; zero stale, zero invalid staged, zero missing primary provider keys. Cached game statistics refreshed 5 November 2026 09:02:27. Source references 748,145 total, of which 413,683 common and 334,462 unresolved; zero source-confirmed missing and zero authoritative physical mounted provider links. Unknown native mounts are not classified as missing.

**UE4 F17/F22 website:** 18 actual PHP HTTP 200 DEV routes using PRD read-only original metadata and 26 exact source/UI assertions PASS; actual missing and cross-game query services source-current. Original UT4 files include v511 industrial_asset_zoo.umap #1353254 (440 unresolved, 87 common), v510 UT4_Base_1stP_AnimBP.uasset #1340389 (28, 175), v493 WeaponTutorialMap.umap #1355626 (104, 71), and v383 EditorHelp.uasset #1335845 (4, 6). Games, unresolved vs confirmed missing filter, legacy Game, File Info, Imports/Uses, dependency JSON and Download Info checked; UE1, UE2, UE3 controls still accurate.

**Original-source scope:** UT4 clean-master source authority matches all four pinned Epic source SHA-256 anchors; original independent nine-file original names/imports/exports and conditional provider/isolated publisher acceptance reused rather than rerun. UE4 4.0.2 and UE4 4.27.2 provide release-boundary context only. No new production PHP changes, native runtime session, PRD SQL writes or authenticated PRD browser run. Evidence [current UT4 website acceptance](../tests/evidence/ue4/ue4-prd-v14-website-source-closure-20261105.json), [previous original UT4 source parity](../tests/evidence/ue4/ue4-original-version383-511-first-latest-source-six-codepaths-20261102.json), [fixed register](../tests/contracts.json).

---

## UE3 fully closed — 5 November 2026

**UE3 6/6 PASS, zero remaining UE3 blockers** in the fixed UnrealDB operation register. All engines now show **31 PASS / 1 BLOCKED**; UE4 website dependency remains open. Existing Jan-2008 Epic UE3 original UT3 v512 licensee0 reader, complete table/VerifyImport/provider-conditional evidence reused without native reruns.

**UT3 game #6 production current:** 6,722/6,722 verified and current under independent UE3-mount-v14 policy, 0 stale, 0 invalid staged, 0 missing primary provider keys; game statistics are current. Original UEDB5 source publication has **2,591,525 references: 1,511,557 unresolved and 1,079,968 common**, zero source-confirmed missing. Unresolved is not an authoritative missing or mounted-provider-resolution verdict.

**Final website contract F17/F22:** 18 PHP HTTP 200 GET responses on DEV against original PRD metadata and 22 exact source/UI assertions passed. Seven original UT3 files confirmed source counters; #149701 UTGameContent.u (2,700 unresolved, 434 common), #1275854 UTResize.u (1,322, 439) and #1292076 HauntedGame.u (1,269, 1,043) displayed exact values. Both physical UTGameContent files were shown but never spuriously linked without an actual native mount. Games, Game Files filters, legacy Game page, File Info, Uses/Imports, JSON, download-info and read-only missing/cross-game services were covered. UE1/UE2 website controls passed.

**Scope:** No code changes or new test IDs were necessary. Source-selected native evidence reused; no authenticated PRD browser or new native mounted session tested; no PRD SQL or package changes. Evidence [UE3 current PRD website closure](../tests/evidence/ue3/ue3-prd-v14-website-source-closure-20261105.json), [previous original UE3 parity](../tests/evidence/ue3/ue3-original-ut3-v512-all-six-metadata-codepaths-20261102.json), [operation register](../tests/contracts.json).

---

## UE2 fully closed — 5 November 2026

**UE2 7/7 PASS, zero blocked within the seven defined UnrealDB operations.** The fixed all-engine register is now **30 PASS / 2 BLOCKED**; only the distinct UE3 and UE4 website dependency contracts remain open. Accepted UE2 reader/table/native-source and UE2-owned UT2004 v99 compatibility evidence were reused, not rerun.

**Original PRD source-pinned data:** Unreal II 1,217/1,217, UT2003 5,082/5,082, UT2004 72,880/72,880. **79,179/79,179** verified and current under UE2-mount-v14 with zero stale and current game stats. Production has 7,719,112 unresolved references and zero source-confirmed missing. Zero missing is NOT proof that all dependencies are resolved; no native mounted provider was invented.

**UE2 website F17/F22:** original source files #111205 U2.u (197 unresolved, 1,117 common), #149124 XGame.u (1,138, 530) and #111557 Construction_S.utx (47, 18) matched source SQL values and rendered counts. UE2-owned UT2004 legacy file #111505 AW-Labels.utx remained source-correct (five common). Games, filtered Game Files, old Game page, File Info, Uses examiner, source-gated JSON and missing/cross-game read models were verified using real DEV PHP HTTP and/or PRD SELECT-only MySQL against original retained files. 23 named UI assertions passed. Corrected the old Game page omission of unresolved and clarified zero physical links versus source references in File Info. UE1 and UE5 regressions passed.

**Scope:** Earlier original native/source/conditional-provider evidence was reused without reruns. UE2 unmounted provider lookups remain unresolved rather than missing/resolved. An authenticated PRD browser session was not independently tested; DEV HTML against actual PRD source/MySQL and PRD-deployed source SHA checks provide the website acceptance. No original package/metadata/PRD SQL modifications were made by this batch.

Evidence: [UE2 PRD v14 website closure](../tests/evidence/ue2/ue2-prd-v14-website-source-closure-20261105.json), [accepted UE2 original source parity](../tests/evidence/ue2/ue2-real-original-metadata-and-dependency-parity-20261102.json), [single contract register](../tests/contracts.json).

---

## 4 November 2026 — all-game dependency updater and unresolved catalogue totals

The current source-pinned UE1 v14 publications contain **4,045,795 unresolved references for UT99** and **711,776 for Unreal Gold**, with **zero source-confirmed missing references**. The previous public Games page showed 0/0 because it only presented missing and official-base-game missing aggregates. `catalog/games.php` now displays a separate **Unresolved dependencies** column, leaving **Missing (confirmed)** and **Missing base-game (confirmed)** explicitly distinct. Totals are derived from source-published, fresh cached `dependency_count - resolved - missing - package_only - common` (no new SQL schema/migration). Stale game policies continue to display **Unavailable**, never fabricated zeros.

`catalog/game-files.php` now filters by **Unresolved**, retains **Missing (confirmed)**, supports Zen script-only source references in filtering, and sorts the UE1–UE4 dependency column by unresolved source counts instead of confirmed missing. Zen per-file catalogue matches are computed on the page, so its misleading source-only keyset dependency sort is disabled. UI tests against PRD read-only MySQL found UT99 `_AphexSnow.utx` (2 unresolved) under Unresolved and excluded it under Missing (confirmed), while UE5 ScriptImport source rows remain discoverable.

New admin navigation **Maintenance → Update All Game Dependencies** points to `catalog/engine-dependency-update.php`. It enumerates all currently configured games dynamically (no hard-coded IDs), shows policy and statistics freshness, and after admin CSRF + explicit backup/UPDATE confirmation atomically queues one low-priority, durable **source-specific UEDB5 v14 Pass 2 job per outdated game**. `CatalogEngineDependencyUpdateJobHandler` is restricted to the Lelly-pc CLI worker; it uses the original `Uedb5GameDependencyPassService` with bounded resumable claims, exact source policy/metadata hashes and statistics rebuild on completion. This is **not** the old generic dependency refresh path. The new resource job uses the same per-game dependency concurrency key, and active queue dedupe prevents duplicate work.

Read-only actual production preflight on all pending games passed: Unreal II 1,217; UT2003 5,082; UT2004 72,880; UT3 6,722; UT4 64,247; total **150,148 verified and staged**, with zero missing primary-provider keys, zero invalid staged identities and zero current v14 publications. These five game updates have **not been started**: queueing requires the administrator's backup acknowledgement in the new PRD page. UE1 and UE5 current games are skipped when their cached stats are also fresh. Current UE1/UE5 accepted parity statuses are unchanged.

---

## Verified checkpoint: UE1 published, statistics current (4 November 2026)

**UE1 fully closed within the seven defined UnrealDB operations: 7/7 PASS, 0 BLOCKED.** Current fixed contract register: **29 PASS / 3 BLOCKED** across five engines; only UE2, UE3, UE4 website dependency source epochs are pending.

**PRD-local UE1 cutover completed:** the user supplied the Lelly-pc production log C:\Temp\UnrealDB\UE1\epic-policy-cutover\cutover-20261104-132552.log. Both resumable workers exited zero. Independent read-only PRD SQL and source-policy verifier confirmed **UT99 74,303/74,303** and **Unreal Gold 10,788/10,788** verified files current under UE1-mount-v14, 0 stale, 0 old v13 rows. UE2/UE3/UE4 epochs remain separate; UE5 stays current. Accepted native UE1 reader/serialization evidence was reused, not rerun.

**games.php Unavailable fixed:** the cutover advanced last UE1 dependency publication to November 4, but cached game stats were from October 5. Game-list freshness correctly hid outdated totals. Rebuilt existing scoped game statistics for IDs 3 and 12 against PRD; both rows were rebuilt November 4 at 15:41:47. They now satisfy the source policy, payload SHA and aggregate-after-publication gates. Public Missing and Base-game Missing badges are numeric (both 0 *confirmed missing*); this does **not** imply all UE1 imports are resolved, as source-unknown mounted providers remain unresolved.

**Future cutovers protected:** the PRD-only UE1 orchestrator now refreshes both game statistics after dependency republication and **before** final cutover verification. The full verifier includes game_stats_current for each game; stale cache rejects a false success. Seven isolated SQLite negative/positive freshness cases and a PowerShell AST/order check passed. DEV PHP HTTP against real PRD read-only MySQL returned HTTP 200 on games.php, both dependency JSON endpoints, Uses/Used By, File Info and both game file lists. Source data, not old v13 records, drives the results; no game runtime was invented. Authenticated PRD browser was not independently exercised.

**Source scope:** the seven implemented UE1 library/website paths are closed, not every possible historical UE1 binary or mounted game session. Three remaining blockages are UE2, UE3 and UE4 website dependency publication, not UE1. Evidence: [UE1 production and games.php closure](../tests/evidence/ue1/ue1-prd-v14-published-stats-website-closure-20261104.json) and [operation register](../tests/contracts.json).

---

## Historical UE1 pre-cutover checkpoint (superseded 4 November 2026)

**UE1 website dependency remains BLOCKED, not PASS.** Genuine production read-only preflight on both UE1 games shows **85,091/85,091** verified files with staged current-format UEDB5 and primary provider keys: UT99 game #3 has 74,303 and Unreal Gold game #12 has 10,788. Both have zero invalid identities and complete staging, but **0/85,091 currently published under uedb5-dependency-pass-ue1-mount-v14**. Original source/native parser evidence was reused, not rerun.

The current PHP website examiner and File Info rendered HTTP 200 on DEV using read-only PRD MySQL for original file IDs #113256 and #1246569, with stale-data notices; JSON dependency endpoints return HTTP 409 and hide old v13 links. Admin-only Missing pages cannot be verified unauthenticated. Existing production canary, migration and policy verifier scripts are SHA-identical across DEV and PRD; no original UE1 file or production database was modified.

**Operational blocker:** PRD Lelly-pc is not connected to Desktop Commander. WinRM, WMI and remote Task Scheduler from DEV are unavailable. The scoped migration script enforces local PRD execution; the separate production rollout policy additionally requires a verified full database/metadata backup and restore point before writes. The script does not itself verify a full database backup, and no restore point has been independently verified. Do not bypass the guard by migrating 85,091 files from DEV over UNC storage and remote MySQL.

**PRD-local execution after a verified backup/restore point:** run the already-staged PowerShell script C:\Apache24\htdocs\unrealdb\catalog\bin\apply-ue1-epic-policy-cutover.ps1 with -Apply -CanaryOnly; after both canaries pass, run it with -Apply -Workers 2 -Limit 100, then C:\php8.5\php.exe C:\Apache24\htdocs\unrealdb\catalog\bin\verify-ue1-epic-policy-cutover.php. The jobs are resumable, scoped to UE1 games 3 and 12, and preserve UE2-UE5 independent epochs.

Evidence: [genuine UE1 preflight and website read-path status](../tests/evidence/ue1/ue1-prd-v14-readiness-and-local-execution-gate-20261104.json). The [single contract register](../tests/contracts.json) retains ue1-website-dependency=BLOCKED until actual source-correct production regeneration and website outcomes are verified.

---

## Verified checkpoint: 4 November 2026 - seven UE5 operations closed

**Current fixed operation-path register: 28 PASS / 4 BLOCKED across five Unreal engines. UE5 is 7/7 PASS in the implemented file/header/table/IoStore/dependency/website scope.** The four remaining blocked cases are UE1-UE4 website dependency views; their historical v13 database projections have not been republished under per-engine v14.

**PRD Satisfactory game 14:** FactoryGame-WindowsServer.utoc retained and indexed 27,240 original Zen packages. Durable source-backed dependency pass job #9525707 completed all 27,240/27,240, leaving 0 stale UE5 v14 policy publications. Current missing-dependency SQL projections contain 0 explicitly source-proven missing objects; source-unknown mounted references remain unresolved rather than inferred missing. Historical Zen SQL-examination backfill job #9525708 completed separately; new package publication writes examination rows atomically.

**Upload lifecycle fixed and deployed:** commit [9ba48789](https://github.com/ardenee/ut_reader/commit/9ba48789) schedules name indexing, member publication and a durable dependency follow-up from initial IoStore upload/backup restore. The dependency job defers until publication completes, reports failed name indexing and reuses active dedupe keys on retry. 13 named handoff checks passed. Metadata-only global.utoc is not misclassified as package-bearing.

**UE5 web and dependency closure:** commit [89e776e8](https://github.com/ardenee/ut_reader/commit/89e776e8) corrects File Info, Uses, Used By and the JSON endpoint for verified Zen packages. Real exact FPackageId references from current UEDB5 summary records produce 1 consumer for Ramp #1363210, 212 for BC_Foundations #1379013, and 202 for ACLAnimBoneCompressionSettings #1386377. Pagination, negative provider and duplicate physical provider cases are checked without claiming native runtime mount resolution. Local PHP HTTP views ran against production MySQL read-only with unavailable .uedb5 storage to verify SQL-only browsing. Ten changed worker/web source files were deployed DEV to GitHub to PRD, with SHA-256 comparisons.

**Closure boundary:** these results verify seven implemented UnrealDB UE5 operations, reusing accepted Epic 5.8.3 native comparisons and current Satisfactory 5.6.1 selected-source evidence, not every historical UE5 cooked format. UE5.0.0 first-release 44-byte Zen summary has not been independently validated from genuine 5.0 cooked bytes. Direct unauthenticated PRD HTTPS requests from DEV returned 403; authenticated production browser UI is not independently proven, although DEV HTTP plus PRD read-only MySQL and PRD code hashes are. No Epic source, original PRD package bytes or PRD SQL changed in this closure audit.

Accepted evidence: [UE5 auto dependency and website F17/F22 closeout](../tests/evidence/ue5/ue5-upload-dependency-and-website-final-closure-20261104.json) and [single operation register](../tests/contracts.json).

---

## Historical checkpoint: 3 November 2026 - superseded by UE5 live publication

The 3 November source-parity summary described 32 implemented code paths, but the live end-to-end contract register still had five website blockers then. This does not represent 32 end-to-end passes. Original UE5.0.0
FZenPackageSummary is **44 bytes** (GraphDataOffset), versus 5.8.3
**52 bytes** (dependency bundles/imported names). The source-pinned 5.8.3
Zen reader must not be advertised as native-verified for UE5.0.0 cooked bytes;
no genuine 5.0.0 cooked fixture was tested in this batch.

**PRD 235,239 verified/staged source files remain intact; their dependency
projections still carry the historical v13 marker.** All five engine
resolvers now use distinct source-correct v14 publication epochs. Old v13
missing/resolved outcomes are withheld from user-facing missing/Requires/
Required By and cross-game dependency reads until actual source-correct
per-engine Pass 2 regeneration; no policy is mass relabeled. UE1 85,091
pending, UE2 79,179, UE3 6,722, UE4 64,247; UE5 Satisfactory
game14 has 0 imported/staged IoStore packages in PRD. Read-only checks
against original UT4 file #1353254 retain 527 original SQL edges but
reject stale dependency interpretation. Duplicate verified import with
intact V5 and stale-only dependency status remains a duplicate, **not**
a forced source reimport or metadata repair.

**No production SQL, original source, IoStore imports or UEDB5 metadata
writes** occurred in this verification phase. Epic source and DEV-native
fixture inventories remain untouched. See
[UE5 actual first/latest source and engine-epoch evidence](../tests/evidence/ue5/ue5-500-vs-583-zen-struct-and-all-engine-policy-epoch-20261103.json)
and the [per-engine production rollout gate](../tests/ENGINE-DEPENDENCY-EPOCH-CUTOVER-20261103.md).
The earlier October checkpoints below are historical descriptions.

**Verified checkpoint: 15 October 2026.** This document is the navigation entry point for **current** production state. Dated migration and format design reports remain historical source/provenance records, even where their original text uses present tense.

## UE5.8.3 audit update — 19 October 2026

This section supersedes the historical UE5/IoStore limitation statement below, but does not change the seven-game production checkpoint. The strict pinned Epic verifier passed 18/18 registered operations (41 source hashes, 91 source symbols, 312 contract and 120 reachability assertions, zero issues). Isolated DEV Satisfactory Zen V5 publication, dependency Pass 2 and validation passed 27,240/27,240, and PAK v11 extraction passed 4,761/4,761. A new independently executed Epic UE5.8.3 UnrealPak differential also matched 4,761/4,761 per-file entries and decoded SHA-256 results (212,783,532 bytes). A pinned engine-content real-file sample also accepted 475 genuine UE5 classic .uasset readers across ten UE5 version values; 119 genuine UE5 classic assets also passed the V5 staging writer/reader roundtrip (25 older pre-UE5-summary assets recognized as legacy). The independent Epic Zen retained-TOC comparison also passes **27,240/27,240 reconstructed package hashes and lengths (1,398,535,898 bytes)**. All 16 UE5-subset workflows are now mapped in the recovery register but remain unaccepted pending complete reachability and integration evidence. Production DEV/PRD IoStore schema migrations have both been applied. No Satisfactory production import has been started. **UE5.8.3 compliance certification remains open** pending exhaustive production workflow, branch inventory and independent Epic differential. See [UE5 certification status](../catalog/compliance/UE583-CERTIFICATION-STATUS.md) and [recovery register](../catalog/compliance/RECOVERY.json).

## Current production baseline

- Production is **UEDB5-only**. All **235,239** verified files across Unreal Gold, UT99, Unreal II, UT2003, UT2004, UT3, and UT4 have format-5 registration and `validated` migration status.
- The bounded post-cutover verifier passed. It checks current registration/status, missing/incorrect registrations, retired table absence, runtime fallback and **14** representative V5 container hashes. It is **not** an exhaustive fresh reparse of 235,239 source packages.
- The live `metadata` tree contains exactly **235,239 canonical `.uedb5` containers**, no V1-V4 containers or other artifacts. **48** obsolete/stale files (21,344,662 bytes) were moved to a separately retained SHA-256-journaled quarantine: `C:\Temp\uedb5-legacy-quarantine-20261015`.
- The checked historical V1-V4 metadata SQL tables are absent; the retired-table runtime reference scanner returns **0** references. **Do not drop** `ue_uedb5_compact_name_candidates`, `ue_uedb5_compact_object_candidates` or their V5 dictionary: they are active search/publication data.
- The production schema inventory estimates **88,854,872,064 bytes** for 72 base tables, with both live compact candidate tables together estimated at **74,387,734,528 bytes**. These are information-schema estimates, not a measured disk-reclamation promise.
- Existing production MySQL 8.4, Apache 2.4 and PHP 8.5 remain the target runtime. Production MySQL stays on its own service; isolated integration MySQL is independently hosted on local loopback port 3339 and is shut down after testing.

## Lifecycle and operational validation

- **Seven-game:** real scanner upload/duplicate handling, stable-ID reimport, game-backup restore with durable parent/child jobs, and non-deferred physical/catalog deletion with completed dependency reconciliation each passed bounded isolated MySQL tests on all seven games.
- **Workers:** two concurrent real PHP processes completed 40/40 persisted jobs exactly once; explicit dead-letter retry, corrupted-backup rejection and interrupted two-file backup restoration were tested. The recovered backup parent 115, entries 116/117 and dependency job 118 completed without replaying an already successful entry.
- **UT3 correction:** `PdoPackageSupersetAnalyzer` now uses the worker-provided storage configuration, avoiding accidental global-production-storage reads during isolated reconciliation; job 71 recovered successfully.
- **Regressions:** the final current post-cleanup regression set passed **17/17**. Seven representative source packages also passed current parser/V5 SQL publication/rollback fixtures.
- No full 235,239-file rescan was run for this checkpoint. All seven-game lifecycle fixtures were isolated from production SQL and stored packages.

## Known coverage limits

- **UE5 Zen/IoStore:** source-backed readers/resolvers and fixtures exist, but the seven current production games do not establish production `.utoc/.ucas` ingestion or live Zen package-store coverage.
- The tested package samples and 14 bounded production hash checks are not exhaustive engine-policy parity or source-data validation over all records. Source-specific conformance exceptions remain documented in the relevant specs.
- Candidate SQL size is a performance concern, **not** evidence that candidate tables are obsolete. Any redesign needs query-plan, search/provider parity and staged rollback evidence.
- Fixture setup and destructive integration scripts are **not idempotent**; recreate isolated database/storage fixtures before replaying imports or deletions. Never point them at the production MySQL service.

## Current commands (bounded/read-only on production)

```powershell
C:\php8.5\php.exe catalog/bin/verify-uedb5-post-cutover-bounded.php
C:\php8.5\php.exe catalog/bin/verify-uedb5-candidate-cutover-readonly.php
C:\php8.5\php.exe catalog/bin/audit-legacy-runtime-references.php
C:\php8.5\php.exe catalog/bin/audit-uedb5-obsolete-schema-readonly.php
C:\php8.5\php.exe catalog/bin/migrate.php status
```

**Do not rerun** historical V3/V4 migration/retirement scripts, destructive quarantine actions or all-file migration commands as a routine health check.

## Authoritative documentation and evidence

- [V5 lifecycle test results, job IDs, quarantine and isolated restart instructions](../catalog/docs/UEDB5-V5-LIFECYCLE-INTEGRATION.md)
- [V5 format specification](../specs/uedb5-format.md) (normative format sections; dated migration discussion is historical)
- [Epic-source conformance ledger](../specs/epic-source-conformance.md)
- [Game-profile version admission](../specs/game-profile-version-admission-2026-10-10.md) (no arbitrary version/licensee range rejection)
- [Production deployment](production-deployment.md), [installation](installation.md), [database migration guide](database-migrations.md)
- [Earlier production closeout (historical checkpoint)](../specs/uedb5-production-closeout-2026-10-12.md)

## UE3 / UT3 v512 specification conformance review (2026-11-05)

- Compared the source-selected UE3 metadata reader and UT3 snapshot builder against the independent UE3 format specification and UT3 addendum. Seven original SHA-256-pinned UT3 v512/licensee-0 package fixtures remain readable (35,593 names; 17,904 imports; 34,635 exports).
- Corrected three isolated source-boundary defects: export payload range beyond logical EOF accepted, early/unsupported UE3 versions parsed under the later summary layout, and non-v512/licensee-0 files labelled with UT3's v512 policy. Disposable negative cases now reject; the genuine UT3 snapshot positive case passes.
- No native UObject loading, production data modification or UEDB5 regeneration. Current evidence does not prove native package mount precedence or compressed UT3 package parity. Older UE3 source hashes in several downstream contract records require future scope-aware validation, not automatic wholesale recertification.
- Details: [UE3 UT3 code compliance audit](ue3-v512-spec-conformance-audit-2026-11-05.md) and [new bounded acceptance evidence](../tests/evidence/ue3/ue3-v512-spec-boundaries-20261105.json).
