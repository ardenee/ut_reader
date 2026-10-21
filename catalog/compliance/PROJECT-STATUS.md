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
| Active engine / operation | **UE1 / O01 — file identification and version dispatch** |
| Current O01 status | **IMPLEMENTING**, not accepted |
| Primary acceptance metric | **0 accepted / not-yet-frozen UE1 operation scope** |
| Latest completed work batch | **UE1-B05 — genuine UE1 v68/v69 source corpus, 92-file V5 roundtrips, UE1 classifier tag correction** |
| Latest verified code checkpoint | `1bbb837a` — B05 code, two executable corpus/production tests and 31-file SHA manifest committed/pushed, **8/8** files SHA-256 matched on PRD; 6 PRD regression scripts passed. |

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
| O01 | File identification and version dispatch | IMPLEMENTING |
| O02 | Package summary and metadata | NOT STARTED; pre-1998 format reader missing |
| O03 | Names | NOT STARTED |
| O04 | Imports/exports | NOT STARTED |
| O05 | Dependency resolution | NOT STARTED |
| O06 | Object and property serialization | NOT STARTED |
| O07 | Bulk/external data | NOT STARTED |
| O08 | Cooked and game-specific formats | NOT STARTED |
| O09 | Production data integration | NOT STARTED |
| O10 | Complete production workflows | NOT STARTED |

These are planning categories, not evidence of ten accepted/independent operations. Freeze supported source revisions and actual workflows before reporting progress as a percentage.

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
2. **Early formats now source-classified:** v0.82/0.83 resource trailer v9, v0.84a resource trailer v10 and v0.86x/v0.867 string-tagged Unrealfile v23 all have loader/writer definitions in the provided source. Use these to implement dedicated earlier readers; do not wait for an old executable. For v1.224/v1.227 and v430/v432, verify only actual code differences and missing specific bodies against the available overlapping source, without assuming forks are identical.
3. Trace the full O01 production entry-to-result graph: `gp_read_legacy_summary` → `gp_classify_file` → game profile/compatibility decision → catalog reader resolver → staging/indexing/metadata/V5; include file-examine, HTTP scan, alternate import routes and negative/error paths.
4. Establish a fixed supported-format and operation register with explicit branch/reader support. **Implement the dedicated early Unrealfile reader where in scope**; do not silently turn recognised format into accepted supported data.
5. **Already passed:** 61/61 genuine v35–61 files plus 31/31 genuine v68/v69 files, including a v69/licensee-227 sample; 92/92 real classification→reader→source builder→isolated V5 write/read roundtrips. Validate newly implemented old-format readers against source-driven positive/negative fixtures now; seek genuine early-format files or a compiled original oracle *if available* as additional independent evidence, not as an excuse to postpone implementation. Do not treat V5 roundtrips as equivalent to native Epic execution.
6. Run relevant verifier scripts; commit via local Git CLI, `git push origin main`, deploy changed files with Robocopy to PRD (excluding storage/config), SHA-256 compare, update this page and repeat.

**Recovery rule:** reading this page plus `UE1-IMPLEMENTATION.md` and `UE1-ACCEPTANCE.md` should suffice for a fresh chat. Do not reopen earlier engine audits or create arbitrary new backlog identifiers. Never mark O01 ACCEPTED until complete source-linked workflow and independent acceptance evidence is available.
