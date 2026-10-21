# UnrealDB — engine-source compatibility status

Updated: 2026-10-21. This is the **new operation-level acceptance process**; earlier VERIFIED claims are historical evidence, not acceptance.

## Engine sequence

| Phase | Engine | New acceptance status |
|---|---|---|
| 1 | UE1 | IN PROGRESS — operation scope and source revision inventory being established |
| 2 | UE2 / 2.5 | NOT STARTED |
| 3 | UE3 / UDK | NOT STARTED |
| 4 | UE4 | NOT STARTED (historical implementation retained) |
| 5 | UE5 | NOT STARTED (historical implementation retained) |

**Primary metric:** Accepted operations / agreed applicable operations. The UE1 denominator is **NOT YET FROZEN**. Do not report a percentage based on provisional categories, or label UE1 complete.

## UE1 initial operation register (provisional, awaiting full source-defined scope)

| ID | Category | State | Evidence / next acceptance work |
|---|---|---|---|
| O01 | File identification and version dispatch | IMPLEMENTING | UE1-only package-tag defect corrected and checked; end-to-end admission, branch/profile/version tests still open |
| O02 | Summary and format metadata | NOT STARTED | Trace all applicable pinned releases including v227 licencee and pre-68 heritage |
| O03 | Names | NOT STARTED | Epic context-dependent NameMap and name serialization |
| O04 | Imports and exports | NOT STARTED | Source-defined record variants and object identities |
| O05 | Dependency resolution | NOT STARTED | Runtime import verification, package discovery, redirects |
| O06 | Object and property serialization | NOT STARTED | Object payload operations and relevant supported classes |
| O07 | Bulk/external data | NOT STARTED | Establish applicable UE1 formats, conditional support |
| O08 | Cooked/package variants | NOT STARTED | Establish source-defined applicable variants; no IoStore inference |
| O09 | Production data integration | NOT STARTED | V5 normalized storage, reads, indexes, identity preservation |
| O10 | Complete production workflows | NOT STARTED | Import, inspection, search/dependency, error response comparisons |

These rows are **planning categories only**. Freeze the complete source-defined operation register before using a percentage; new subfunctions belong within agreed operations.

## Reference inventory

- Engine baseline: `L:\Source\Engine\UE1` (README describes a community-maintained version-200-derived project; not presumed verbatim Epic).
- Game branches: `L:\Source\Games\Unreal`, `L:\Source\Games\UT99`.
- Other engine-era game source, to inspect in phase order: `L:\Source\Games\Unreal II`, `L:\Source\Games\UT2003`, `L:\Source\Games\UT2004`, `L:\Source\Games\UT3`, `L:\Source\Games\UT4`.
- UE1 branches inspected: UT99 v1.400 and Unreal v1.227. Unreal v1.227 source includes explicit Legend/Licensee modifications; those are not interchangeable with UT99 v1.400.
- Additional UE1 branch families visible but **not yet pinned or classified**: early Unreal builds, Unreal v1.200, UT99 v432.

## Current measured results

- Original source-linked defect UE1-D001: UE1 parser accepted the UE2/Killing Floor tag. Corrected only UE1 admission in the canonical shared legacy parser; UE2 admission remains unchanged.
- Reproduced pre-fix: two incorrect acceptances in the new source-anchored test.
- Reproduced post-fix: 6/6 source-anchored checks and 8/8 executed regression scripts passed.
- **Acceptance: 0 operations accepted under the new process.** This local correction does not prove the whole O01 path or end-to-end workflow.
- Deployment: on 2026-10-21, six affected production/test/documentation files copied to PRD via targeted Robocopy; SHA-256 hashes matched DEV for 6/6 files. No deployment is evidence of whole-engine acceptance.

## Source and evidence pointers

- Epic-only specification: `specs/epic-source/ue1/ut99-v1400/01-package-entry-tag.md`
- Differences and implementation: `catalog/compliance/UE1-IMPLEMENTATION.md`
- Tests and acceptance boundaries: `catalog/compliance/UE1-ACCEPTANCE.md`
- Test: `catalog/bin/verify-ue1-package-tag-source.php`

Next: complete UE1 source provenance and game-version scope; trace O01 through actual import/staging, profile dispatch, browser inspection and failure handling; compare against genuine native package inputs before accepting O01.
