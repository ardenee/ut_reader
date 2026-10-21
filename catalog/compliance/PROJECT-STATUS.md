# UnrealDB — master source-compatibility status and recovery point

Updated: **2026-10-21**. This is the canonical recovery/handover file for this project; use it instead of depending on any chat history. The project aims at real production behaviour, not expanding audit counts.

## Current checkpoint

| Item | State |
|---|---|
| DEV | `game-amd`, `192.168.0.51`; Desktop Commander account `aaron@arden.ie` |
| Repo | `C:\Users\arden\source\repos\ut_reader` (branch `main`) |
| Reference sources | `L:\Source\Engine` and `L:\Source\Games` |
| Deployment | `Z:\Apache24\htdocs\unrealdb` on DEV is PRD `Lelly-pc` |
| GitHub | `https://github.com/ardenee/ut_reader` |
| Active engine / operation | **UE1 / O02 — original/standard package summary and metadata** |
| O01 status | **ACCEPTED — 6/6 fixed identification/dispatch criteria passed**; see `UE1-ACCEPTANCE.md`. Original full readers are O02 work. |
| Primary acceptance metric | **UE1: 1 accepted operation (O01); O02 implementing.** Ten source-dependent categories currently listed; UE1 overall NOT accepted. |
| Latest completed work batch | **UE1 O01 — completed original resource v9/v10 EOF identification and error-safe production dispatch** |
| Latest verified code checkpoint | `4dad9618` — production code and 36-check original-format verifier committed/pushed; **7/7 DEV–PRD SHA-256 parity**. DEV 20/20 regressions, PRD 9/9 targeted scripts. |

## Engine sequence

| Phase | Engine | New-process status |
|---|---|---|
| 1 | UE1 | IN PROGRESS |
| 2 | UE2/2.5 | NOT STARTED |
| 3 | UE3/UDK | NOT STARTED |
| 4 | UE4 | NOT STARTED; historical work preserved |
| 5 | UE5 | NOT STARTED; historical work preserved |

Do not move to UE2 before UE1 acceptance. Earlier VERIFIED work cannot be substituted for the new acceptance requirements.

## Provisional UE1 operation categories (denominator not frozen)

| ID | Category | Status |
|---|---|---|
| O01 | File identification and version dispatch | **ACCEPTED (6/6 source-backed scoped requirements)** |
| O02 | Package summary and metadata | **IMPLEMENTING** — build original v9/v10 resource and v23 Unrealfile summary/table readers from supplied Epic code |
| O03 | Names | NOT STARTED |
| O04 | Imports/exports | NOT STARTED |
| O05 | Dependency resolution | NOT STARTED |
| O06 | Object and property serialization | NOT STARTED |
| O07 | Bulk/external data | NOT STARTED |
| O08 | Cooked and game-specific formats | NOT STARTED |
| O09 | Production data integration | NOT STARTED |
| O10 | Complete production workflows | NOT STARTED |

These ten categories are the current operation register; an operation is accepted only against its **own stated responsibility**. Do not enlarge one operation to require later operations. The categories are provisional until source inspection verifies relevance to UE1; do not calculate an engine-wide completion percentage from them.

## Mandatory source-first execution and acceptance discipline (2026-10-21)

**Policy correction after repeated source-coverage misses and O01 scope expansion:**

1. **Inventory source before interpreting or editing a format.** For each engine, recursively enumerate ALL supplied engine/game source roots and relevant C++ load/save code; search format tags, headers AND EOF trailers, version gates, serializers, loader functions, and all source revisions. Register the exact source file, symbol/line and branch difference. The absent `UnObjVer.h` is NOT evidence of absent source: original Unreal v0.82–0.84a use `UnRes.cpp` and trailer metadata, not the later `ULinker`.
2. **No unsupported "source gap" assumptions.** Before calling information unavailable, search alternative file names, legacy subsystems, adjacent versions, engine and game trees, actual loader/writer implementations, and compiled artifacts where useful; record searches and distinguish (a) source genuinely absent for a particular fork, (b) UnrealDB reader not yet built, and (c) independent test fixture not yet available. Treat source definitions as implementation authority; do not delay source-driven work pending an optional old executable.
3. **Freeze a narrow operation contract BEFORE coding.** O01 is file-format identification, version/licensee extraction, selected-game dispatch and honest unsupported/invalid results. Full name/import/export decoding is O02–O04; dependencies O05; payloads O06/O07; production SQL/search O09; complete workflow acceptance O10. Later operation failures must not block earlier acceptance unless they actually change its inputs or outputs.
4. **Work each operation in one bounded source→diff→fix→accept sequence:** exhaustive source mapping once, real production caller coverage, minimal correction, source-anchored positive/negative tests, standard regressions, evidence of observed differences, Git commit/push and immediate PRD SHA-256 deployment. Never add arbitrary B-number cycles or new acceptance conditions after work begins without a demonstrated scope error.
5. **Publish measurable results:** per-operation PASS/FAIL and exact open acceptance checks; genuine corpus and regression counts separately; source findings with true status (source present, code absent, or test missing). Do not conflate tests passed with engine accepted. After closing O01, **advance to O02** without reopening O01 for unrelated improvements.

The source-first rule is mandatory for UE1 and repeats unchanged for UE2/2.5, UE3/UDK, UE4 and UE5. Review all supplied code branches before stating that any engine information is missing.

## UE1 source branch matrix — discovered, not assumed equivalent

| Source tree | Mechanically observed file version / minimum | Distinction and remaining work |
|---|---|---|
| `Unreal [v0.82]` | `RES_FILE_VERSION=0x0009` | SOURCE FOUND: `Src/Engine/UnRes.cpp::FGlobalResourceManager::AddFile`, `Src/Inc/UnResTyp.h::FResourceFileTrailer`, `Src/Inc/UnBuild.h`; **trailer at EOF** tagged `Unreal Resource\x1A`, not a later `.u` package header; reader NOT implemented |
| `Unreal [v0.83]` | `RES_FILE_VERSION=0x0009` | SOURCE FOUND: same resource-trailer format family and native read/write paths in `Src/Engine/UnRes.cpp`, `Src/Inc/UnResTyp.h`; reader NOT implemented |
| `Unreal [v0.84a]` | `RES_FILE_VERSION=0x000A` | SOURCE FOUND: `Src/Engine/UnRes.cpp`, `Src/Inc/UnResTyp.h`, `Src/Inc/UnBuild.h`; trailer format version changes to 10; reader NOT implemented |
| `Unreal [v0.86x]` | `UNREAL_FILE_VERSION=23`, min 22 | Early 32-byte `Unrealfile\x1A` header; separate format |
| `Unreal [v0.867]` | Version 23, min 22 | `FUnrealfileSummary`, not modern UE1 package summary |
| `Unreal [v1.200]` | `PACKAGE_FILE_VERSION=61`, min 34 | Classic numeric tag; different pre-v68 summary serializer; original complete source |
| `Unreal [v1.224]` | Version 68, min 60 | Tree labelled INCOMPLETE; provenance and source gaps |
| `Unreal [v1.227]` | Version 69, min 60 | Source includes licencee/Legend changes; header/linker bodies incomplete; git HEAD previously `2bd1ce9` |
| `UT99 [v1.400]` | Version 68, min 60 | Complete matching C++ `ULinkerLoad` source inspected |
| `UT99 v432` | Version 69, min 60 | Folder labelled v432 but `ENGINE_VERSION=430` in header; pin actual source config; git HEAD previously `db43a2e` |
| `Engine\UE1` | Version 61, min 34 | Community-maintained version-200-derived baseline; git HEAD previously `403fe5e` |

All paths above are under `L:\Source\Games\Unreal`, `L:\Source\Games\UT99`, or `L:\Source\Engine\UE1`. Each relevant fork must be traced under its correct file-format family. UE2 and newer game roots are reserved for later phases.

**Source coverage clarification (2026-10-21):** Source-code absence is **not** a blocker for the early v0.82/0.83/0.84a resource formats or v0.86x/v0.867 Unrealfile format. Both loading and serialization structures are present, but corresponding UnrealDB readers have not been implemented. The v0.82/0.83 resource versions are **9** and v0.84a is **10**, distinct from later classic package versions 35–69. Unreal v1.224 directory genuinely contains mostly headers (8 C++ files observed); v1.227 contains the file summary/layout headers, while some specific linker implementations are absent. Nearby complete source branches can inform shared behaviour but must not automatically be treated as identical. Genuine old-format binary fixtures and native runtime runs improve *independent validation*; **neither is a prerequisite to read/implement the available documented source**. The remaining production MySQL/search work is an **UnrealDB implementation test**, not an Epic-source gap. See `catalog/compliance/UE1-IMPLEMENTATION.md`.

## Actual completed findings, code, and evidence

| Finding | Status | Production path | Evidence |
|---|---|---|---|
| UE1-D001: shared legacy reader admitted UE2-only Killing Floor tag into UE1 | CORRECTED in B01 | `catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php` | 6/6 source-anchored checks; earlier 8 regression scripts passed |
| UE1-D002: source-defined minimum version prompt vs noninteractive product policy | OPEN investigation | `catalog/lib/GameProfiles.php`, UE1 reader | Minimum is 22 (old v0.867), 34 (v1.200), or 60 (UT99 v400), dependent on branch |
| UE1-D003: original 32-byte Unrealfile header incorrectly treated as generic unknown magic | CORRECTED classification; reader MISSING | `catalog/lib/GameProfiles.php`, `catalog/src/Application/Catalog/CatalogPackageHeaderInspector.php` | 9/9 new checks after reproducing five baseline failures |
| UE1-D004: file-examine header inspector still admitted UE2 tag as UE1 | CORRECTED for engine-specific inspection | `catalog/src/Application/Catalog/CatalogPackageHeaderInspector.php` | Same 9-check test; preserves UE2 tag |
| UE1-D005: production header-only helper returned fallback blank headers after reader failures | CORRECTED for UE1 only | `catalog/lib/CatalogParser.php` | New 4-check real-entry-point test (three failed before fix, 4/4 pass after); 15/15 selected regressions passed |
| UE1-D006: pre-68 inspector assumed heritage GUID always inline at byte 44 | CORRECTED; reader already source-aligned | `catalog/src/Application/Catalog/CatalogPackageHeaderInspector.php` | 4 of 5 source-anchored variant checks failed before fix; 5/5 pass after; genuine 61/61 corpus and 15/15 regression scripts pass |
| UE1-D007: preliminary UE1 game admission still accepted UE2-only Killing Floor tag | CORRECTED in B05; UE2 still admitted | `catalog/lib/GameProfiles.php::gp_classify_file()` | Real profile/classifier/V5 test reproduced failure; two negative path checks pass after fix; 92/92 genuine source/V5 disk roundtrips and 19/19 selected scripts pass |

The early `Unrealfile` header now returns an explicit **unsupported format** result with UE1 identity/version at classification. It is **not** deserialized or accepted as a usable package. This is a genuine unresolved feature gap.

**Genuine legacy corpus discovered 2026-10-21:** `L:\Source\Engine\UE1\Source\Unreal\Textures` contains 61 standard-magic `.utx` packages: 59 are versions 35–60, and two are version 61; all licensee versions are zero. The other 85 files are PCX images. Per-file SHA-256/version inventory at `catalog/compliance/inventory/ue1-textures-v35-v61.csv`, explanation at `catalog/compliance/inventory/UE1-TEXTURES-CORPUS.md`. **B04 VERIFIED (limited):** all 61/61 SHA-verified files parse via the canonical UE1 reader without reported issues, including matching name/import/export counts and actual production header helper; an independent direct-byte source-defined 44-byte summary read matches all 11 serialized header fields in 61/61 files, and file-examine GUIDs agree in 61/61. These files all have heritageCount=1, heritageOffset=44; they do **not** cover other heritage arrangements. Original compiled Epic engine/runtime comparison and full workflow acceptance remain OPEN.

**B05 verified (bounded):** new installed genuine UE1 v68/v69 corpus 31/31; aggregate genuine-file profile/reader/source-specific V5 container write/read 92/92, including version69/licensee227. The UE1 preliminary classifier no longer admits the UE2-only Killing Floor tag (UE1-D007); UE2-only tag remains admitted under UE2. Two negative controls passed, 19/19 selected regression scripts passed on DEV, and six relevant executable checks passed on deployed PRD. Actual production MySQL publishing and search, early string-tagged binary reader, missing source fork coverage and native Epic executable parity remain **OPEN**.

B02 checks passed: `verify-ue1-early-format-identification.php` **9/9**, plus **12/12** selected UE1/UE2/UE4/UE5 regression scripts, PHP syntax and source-anchored tests. B03 checked the live header-only helper: 4/4 assertions passed after three baseline failures, plus **15/15** selected regressions. Synthetic fixtures isolate branch decisions; native engine and genuine-package corpus equivalence are **not** established. The `.u` files sampled in the v0.86x/v0.867 source directories are script text, not binary package corpus.

## Durable reference files

- This page: `catalog/compliance/PROJECT-STATUS.md`
- Defects and production locations: `catalog/compliance/UE1-IMPLEMENTATION.md`
- O01 evidence and acceptance checklist: `catalog/compliance/UE1-ACCEPTANCE.md`
- UT99 source: `specs/epic-source/ue1/ut99-v1400/01-package-entry-tag.md`
- Original Unreal 0.867 source: `specs/epic-source/ue1/unreal-v0867/01-unrealfile-entry.md`
- Primary test B01: `catalog/bin/verify-ue1-package-tag-source.php`
- Primary test B02: `catalog/bin/verify-ue1-early-format-identification.php`
- Primary test B03: `catalog/bin/verify-ue1-header-route-validation.php`
- Primary test B04: `catalog/bin/verify-ue1-genuine-texture-corpus.php` (requires DEV or an explicit `UE1_GENUINE_TEXTURE_ROOT` environment variable)
- Heritage source parity: `catalog/bin/verify-ue1-pre68-heritage-inspector.php`
- B05 source corpus: `catalog/compliance/inventory/ue1-packages-v68-v69.csv` and `catalog/compliance/inventory/UE1-V68-V69-CORPUS.md`
- B05 native-layout direct-byte test: `catalog/bin/verify-ue1-v68-v69-genuine-corpus.php`
- B05 full 92-file classification/profile/source/V5 file roundtrip: `catalog/bin/verify-ue1-genuine-production-paths.php`

## Exact next actions — restart here

1. Verify DEV device, `git status --short --branch`, `git log -1`, compare `origin/main`, and ensure prior committed changes reached PRD. Preserve any unrelated user edits.
2. **O01 closed; start O02** from Epic source: original 1996 `UnRes.cpp`, `UnResTyp.h` (resource v9/v10 EOF summary and table layouts); 1997 `UnLinker.h` (`FUnrealfileSummary` v23, objects/names/dependencies); 1998+ `FPackageFileSummary` classic package versions. Implement actual reader data output, not more identification heuristics.
3. For O02 implement separate format-specific summary/table loaders, use byte-exact source definitions, enforce checked bounds and preserve serialized identities. Feed their source-defined output into real UnrealDB reader pathways, rather than discarding supported old-format metadata as invalid modern magic.
4. Verify O02 with native-source-layout fixtures, real packages where available and existing reader regressions. Find genuine old files but do not wait for them when exact original C++ source is available. Keep source semantics authoritative over generic format assumptions.
5. Existing O01 evidence: **6/6 accepted**; DEV 20/20 relevant scripts, PRD 9/9, 92/92 original installed classic packages. **O02 is implementing**, NOT accepted, and the early resource/unrealfile formats remain unsupported as complete packages until reader work is done. Do not count detection as decoding.
6. Run relevant verifier scripts; commit via local Git CLI, `git push origin main`, deploy changed files with Robocopy to PRD (excluding storage/config), SHA-256 compare, update this page and repeat.

**Recovery rule:** read this page plus `UE1-IMPLEMENTATION.md` and `UE1-ACCEPTANCE.md`; source C++ is master truth. O01 is accepted for its frozen identification scope; do not reopen it for O02–O10 decoding, object or SQL work. Advance one bounded operation at a time and deploy verified code promptly.
