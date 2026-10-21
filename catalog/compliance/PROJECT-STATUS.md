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
| Active engine / operation | **UE1 / O06 — object and property serialization** |
| O01 status | **ACCEPTED — 6/6 fixed identification/dispatch criteria passed**. Original structural metadata O02 is also accepted; type-specific object/payload parsing belongs to O06/O07. |
| Primary acceptance metric | **UE1: O01–O05 accepted within explicit source-backed file-handling contracts; O06–O10 open.** Runtime engine and full production equivalence NOT established. |
| Latest completed work batch | **UE1 O06 — 1996 UTexture and UDatabase subclass native records; 1997 UClass/FProperty plus source-defined instance values incl. FArchive::String; genuine classic 2,465 exports remain fully covered in pinned corpus. O06 IMPLEMENTING.** |
| Latest verified code checkpoint | **O06 ongoing:** source-defined classic tagged/native readers parse **92/92 SHA-pinned packages, 2,465/2,465 exports, 15,475 property tags** (805 palettes, 1,228 textures, 396 sounds, 22 fonts, 14 packages). Original v9/v10 typed byte ranges verified 20/20; original v22/v23 UObjectBase/ranges 35/35 and FExecStackMain 18/18; genuine negative/CLI cases 9/9. **DEV regressions 45/45**. Original v9/v10 UDatabase/UPalette native fields now decode (12/12); original v22/v23 UClass/FProperty schemas and local native binary instance Int/Bool/UObject values decode (18/18). 1996 UTexture + UTextBuffer/UBuffer/UArray/UEnum native fields/records now decoded; remaining 1996 classes and 1997 inherited/external schemas/data serializers remain open; do NOT mark O06 accepted. See UE1-O06-SERIALIZATION.md. |

**O06 test-resume protocol:** Before running any UE1 O06 tests, read `catalog/compliance/UE1-O06-TEST-STATUS.json` and append-only `UE1-O06-TEST-EVENTS.jsonl`. Use `catalog/bin/run-ue1-o06-tests.ps1 -Tests <named-test.php>` (optional `-TargetRoot 'Z:\Apache24\htdocs\unrealdb'` for PRD). Each test has a flushed STARTED event and a terminal PASS/FAIL, exit code, code/test SHA-256 fingerprint and full output under `catalog/compliance/evidence/o06`. Incomplete STARTED events are interrupted. Skip only a latest matching PASS, rerun after source changes. The historical pre-journal 41/41 DEV suite is separately documented; it is not fabricated as a journal entry. Latest code checkpoint `c18214c0` implements 1996 texture/primitive database records and 1997 serialized strings. O06 remains OPEN for other per-class serializers and external/inherited schemas.

## Engine sequence

| Phase | Engine | New-process status |
|---|---|---|
| 1 | UE1 | IN PROGRESS |
| 2 | UE2/2.5 | NOT STARTED |
| 3 | UE3/UDK | NOT STARTED |
| 4 | UE4 | NOT STARTED; historical work preserved |
| 5 | UE5 | NOT STARTED; historical work preserved |

Do not move to UE2 before UE1 acceptance. Earlier VERIFIED work cannot be substituted for the new acceptance requirements.

## UE1 operation register (10 fixed categories)

| ID | Category | Status |
|---|---|---|
| O01 | File identification and version dispatch | **ACCEPTED (6/6 source-backed scoped requirements)** |
| O02 | Package summary and metadata | **ACCEPTED (source-defined structural metadata only)** — original 1996 resource v9/v10, original 1997 Unrealfile v22/v23, genuine classic v35–69, and correct unverified MySQL persistence; NOT payload/full-reader acceptance |
| O03 | Names | **ACCEPTED (name serialization and context mapping)** — source ANSI byte identity, v9/10 fixed strings, v22/23 one-based names, v35–69 classic entries, explicit load contexts, malformed bounds rejected. Cross-package runtime internment O05. |
| O04 | Imports/exports | **ACCEPTED — structural tables and source-defined reference bounds only**. v9/v10 resource entries, v22/v23 UObjectBase with class preload object index, classic v35–69 compact signed references/outer gates/serial ranges. Runtime binding is O05. |
| O05 | Dependency resolution | **ACCEPTED — source-determined static dependencies**. Original v9/v10 global ResArray lookup keys; original v22/v23 serialized ResMap/declared filenames; classic v34–69 source VerifyImport including class/outer/privacy, V4 SQL + V5 provider matching. Native-only runtime state remains explicitly unresolved, NOT falsely marked linked. Frozen 10/10 executable gates. |
| O06 | Object and property serialization | **IMPLEMENTING** — classic UE1 property tags and 5 native serializer families pass 2,465/2,465 exports in 92 SHA-pinned packages; original v9/v10 UDatabase Num/Max and palette rules decoded; v22/v23 FExecStackMain, UClass/FProperty schema and available local SerializeBin values decoded. Derived UTexture and original primitive database records added; other registered resource classes plus inherited/external binary serializers remain OPEN. No acceptance claim. |
| O07 | Bulk/external data | NOT STARTED |
| O08 | Cooked and game-specific formats | NOT STARTED |
| O09 | Production data integration | NOT STARTED |
| O10 | Complete production workflows | NOT STARTED |

These TEN categories are fixed for UE1; new findings go under an existing operation as defects rather than inventing endless new operation IDs. Acceptance covers each explicitly bounded responsibility, NOT engine-wide parity. Runtime object-loading and actual workflow verification remain O10; property serialization O06 and bulk data O07.

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
| `Unreal [v0.82]` | `RES_FILE_VERSION=0x0009` | Original EOF resource trailer, source-defined names/imports/exports/native headers and unverified staging implemented. **Type-specific object payloads O06 still open** |
| `Unreal [v0.83]` | `RES_FILE_VERSION=0x0009` | Same source-defined v9 family structurally read and staged; true loaded ResArray runtime binding unavailable, recorded as not-evaluated; payloads O06 |
| `Unreal [v0.84a]` | `RES_FILE_VERSION=0x000A` | Original v10 structural tables/headers read and staged; type/name source lookup projected, payloads O06 |
| `Unreal [v0.86x]` | `UNREAL_FILE_VERSION=23`, min 22 | Early 32-byte `Unrealfile\x1A` header; separate format |
| `Unreal [v0.867]` | Version 23, min 22 | `FUnrealfileSummary`, not modern UE1 package summary |
| `Unreal [v1.200]` | `PACKAGE_FILE_VERSION=61`, min 34 | Classic numeric tag; different pre-v68 summary serializer; original complete source |
| `Unreal [v1.224]` | Version 68, min 60 | Tree labelled INCOMPLETE; provenance and source gaps |
| `Unreal [v1.227]` | Version 69, min 60 | Source includes licencee/Legend changes; header/linker bodies incomplete; git HEAD previously `2bd1ce9` |
| `UT99 [v1.400]` | Version 68, min 60 | Complete matching C++ `ULinkerLoad` source inspected |
| `UT99 v432` | Version 69, min 60 | Folder labelled v432 but `ENGINE_VERSION=430` in header; pin actual source config; git HEAD previously `db43a2e` |
| `Engine\UE1` | Version 61, min 34 | Community-maintained version-200-derived baseline; git HEAD previously `403fe5e` |

All paths above are under `L:\Source\Games\Unreal`, `L:\Source\Games\UT99`, or `L:\Source\Engine\UE1`. Each relevant fork must be traced under its correct file-format family. UE2 and newer game roots are reserved for later phases.

**Source coverage clarification (2026-10-21):** Source-code absence is **not** a blocker for the early v0.82/0.83/0.84a resource formats or v0.86x/v0.867 Unrealfile format. Their structural/native metadata readers and typed unverified staging are now implemented, but complete object/property/payload readers remain O06 and onward. The v0.82/0.83 resource versions are **9** and v0.84a is **10**, distinct from later classic package versions 35–69. Unreal v1.224 directory genuinely contains mostly headers (8 C++ files observed); v1.227 contains the file summary/layout headers, while some specific linker implementations are absent. Nearby complete source branches can inform shared behaviour but must not automatically be treated as identical. Genuine old-format binary fixtures and native runtime runs improve *independent validation*; **neither is a prerequisite to read/implement the available documented source**. The remaining production MySQL/search work is an **UnrealDB implementation test**, not an Epic-source gap. See `catalog/compliance/UE1-IMPLEMENTATION.md`.

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
2. **O02 accepted for structural metadata**: original resource v9/v10 and Unrealfile v22/v23 native records preserve Epic fields in MySQL unverified staging. Classic v35–69 genuine corpus also passes. Do NOT promote early files as verified or normalize original records into modern class/outer paths; payloads are separate O06/O07 operations.
3. **Continue O06 to real completion:** classic tagged/native serialization now passes the established genuine corpus; do not re-audit this evidence without a regression. Next finish **remaining 1996 registered resource types and runtime reference-fixup boundaries** plus **1997 external/inherited class schemas and class-specific SerializeData**. Registered UTexture, UDatabase and original String/primitive records are now source-tested. Source-defined local UClass/FProperty and binary instance fields are now decoded and tested. Preserve exact original 1-based indices and separate O07 bulk data. Do not accept O06 based on hashes/prefixes alone; see UE1-O06-SERIALIZATION.md.
4. **Completed O02 production distinction:** `CatalogUE1OriginalStagingMetadata` retains original typed records and `CatalogUnverifiedMetadataSnapshotBuilder::fromOriginalNative` stores them separately; `ue_files` counts native imports/exports without populating unsupported modern import/export paths. Original-format `scan_status` and compatibility remain unverified; genuine source-specific full payload work is still open.
5. **Tests:** O01–O05 remain accepted. O06 classic corpus **92/92 packages**, **2,465/2,465 exports**, 15,475 tags, 5 class-native serializer groups. Original resource v9/v10 20/20 plus UDatabase 12/12, UTexture 12/12 and primitive database records 32/32; original Unrealfile base/ranges 35/35, native stack 18/18, UClass schema/instance 18/18, genuine corruption/CLI 9/9; **DEV regression suite 45/45** after original UTexture/database extension; PRD retest required on deployment. Original 1996/1997 evidence is source-defined synthetic only (the .u files in v0.867 Classes are text declarations). O06 remains open until original class members are decoded; O07–O10 remain open.
6. Run relevant verifier scripts; commit via local Git CLI, `git push origin main`, deploy changed files with Robocopy to PRD (excluding storage/config), SHA-256 compare, update this page and repeat.

**Recovery rule:** read this page plus `UE1-IMPLEMENTATION.md` and `UE1-ACCEPTANCE.md`; source C++ is master truth. O01 is accepted for its frozen identification scope; do not reopen it for O02–O10 decoding, object or SQL work. Advance one bounded operation at a time and deploy verified code promptly.
