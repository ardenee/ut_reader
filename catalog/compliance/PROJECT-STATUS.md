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
| Latest completed work batch | **UE1-B03 — reject invalid UE1 fallback headers in real scanner/queue/HTTP helper** |
| Latest verified code checkpoint | `aa3eb737` — B03 committed/pushed; 5/5 files deployed and SHA-256 verified on PRD. Earlier B02 commit `b5f4a214` deployed 7/7 files. |

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
| `Unreal [v0.82]` | Not extracted | No `UnObjVer.h` in inspected tree; classify surviving source/assets |
| `Unreal [v0.83]` | Not extracted | No `UnObjVer.h`; classify |
| `Unreal [v0.84a]` | Not extracted | No `UnObjVer.h`; classify |
| `Unreal [v0.86x]` | `UNREAL_FILE_VERSION=23`, min 22 | Early 32-byte `Unrealfile\x1A` header; separate format |
| `Unreal [v0.867]` | Version 23, min 22 | `FUnrealfileSummary`, not modern UE1 package summary |
| `Unreal [v1.200]` | `PACKAGE_FILE_VERSION=61`, min 34 | Classic numeric tag; different pre-v68 summary serializer; original complete source |
| `Unreal [v1.224]` | Version 68, min 60 | Tree labelled INCOMPLETE; provenance and source gaps |
| `Unreal [v1.227]` | Version 69, min 60 | Source includes licencee/Legend changes; header/linker bodies incomplete; git HEAD previously `2bd1ce9` |
| `UT99 [v1.400]` | Version 68, min 60 | Complete matching C++ `ULinkerLoad` source inspected |
| `UT99 v432` | Version 69, min 60 | Folder labelled v432 but `ENGINE_VERSION=430` in header; pin actual source config; git HEAD previously `db43a2e` |
| `Engine\UE1` | Version 61, min 34 | Community-maintained version-200-derived baseline; git HEAD previously `403fe5e` |

All paths above are under `L:\Source\Games\Unreal`, `L:\Source\Games\UT99`, or `L:\Source\Engine\UE1`. Each relevant fork must be traced under its correct file-format family. UE2 and newer game roots are reserved for later phases.

## Actual completed findings, code, and evidence

| Finding | Status | Production path | Evidence |
|---|---|---|---|
| UE1-D001: shared legacy reader admitted UE2-only Killing Floor tag into UE1 | CORRECTED in B01 | `catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php` | 6/6 source-anchored checks; earlier 8 regression scripts passed |
| UE1-D002: source-defined minimum version prompt vs noninteractive product policy | OPEN investigation | `catalog/lib/GameProfiles.php`, UE1 reader | Minimum is 22 (old v0.867), 34 (v1.200), or 60 (UT99 v400), dependent on branch |
| UE1-D003: original 32-byte Unrealfile header incorrectly treated as generic unknown magic | CORRECTED classification; reader MISSING | `catalog/lib/GameProfiles.php`, `catalog/src/Application/Catalog/CatalogPackageHeaderInspector.php` | 9/9 new checks after reproducing five baseline failures |
| UE1-D004: file-examine header inspector still admitted UE2 tag as UE1 | CORRECTED for engine-specific inspection | `catalog/src/Application/Catalog/CatalogPackageHeaderInspector.php` | Same 9-check test; preserves UE2 tag |
| UE1-D005: production header-only helper returned fallback blank headers after reader failures | CORRECTED for UE1 only | `catalog/lib/CatalogParser.php` | New 4-check real-entry-point test (three failed before fix, 4/4 pass after); 15/15 selected regressions passed |

The early `Unrealfile` header now returns an explicit **unsupported format** result with UE1 identity/version at classification. It is **not** deserialized or accepted as a usable package. This is a genuine unresolved feature gap.

**Genuine legacy corpus discovered 2026-10-21:** `L:\Source\Engine\UE1\Source\Unreal\Textures` contains 61 standard-magic `.utx` packages: 59 are versions 35–60, and two are version 61; all licensee versions are zero. The other 85 files are PCX images. Per-file SHA-256/version inventory at `catalog/compliance/inventory/ue1-textures-v35-v61.csv`, explanation at `catalog/compliance/inventory/UE1-TEXTURES-CORPUS.md`. Source reader parity with this corpus has **NOT** yet been tested.

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

## Exact next actions — restart here

1. Verify DEV device, `git status --short --branch`, `git log -1`, compare `origin/main`, and ensure prior committed changes reached PRD. Preserve any unrelated user edits.
2. Complete UE1 source branch inventory: classify v0.82/v0.83/v0.84a file formats, source availability, and remaining branch forks. Cross-check v0.86x vs v0.867, v1.200, v1.224, v1.227, UT99 v400 and v430/v432 without assuming shared version rules.
3. Trace the full O01 production entry-to-result graph: `gp_read_legacy_summary` → `gp_classify_file` → game profile/compatibility decision → catalog reader resolver → staging/indexing/metadata/V5; include file-examine, HTTP scan, alternate import routes and negative/error paths.
4. Establish a fixed supported-format and operation register with explicit branch/reader support. **Implement the dedicated early Unrealfile reader where in scope**; do not silently turn recognised format into accepted supported data.
5. Compare source-driven positive/negative and boundary inputs against actual compiled Epic behaviour or genuine binary corpora when available. Record absent evidence honestly. Work one complete operation at a time.
6. Run relevant verifier scripts; commit via local Git CLI, `git push origin main`, deploy changed files with Robocopy to PRD (excluding storage/config), SHA-256 compare, update this page and repeat.

**Recovery rule:** reading this page plus `UE1-IMPLEMENTATION.md` and `UE1-ACCEPTANCE.md` should suffice for a fresh chat. Do not reopen earlier engine audits or create arbitrary new backlog identifiers. Never mark O01 ACCEPTED until complete source-linked workflow and independent acceptance evidence is available.
