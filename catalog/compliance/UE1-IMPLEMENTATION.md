# UE1 — source-to-production implementation register

Updated 2026-10-21. **O01 identification/dispatch ACCEPTED** (6/6 fixed requirements). **O02 original/standard summary and metadata decoding IMPLEMENTING**. Epic serialization source is the authoritative implementation reference.

## Source revisions and relevant roots

- UT99 v400: `L:\Source\Games\UT99\Unreal Tournament [v1.400] [1999-11-30]\Core\Inc\UnObjVer.h` and `Core\Src\UnLinker.h`.
- Unreal v227: `L:\Source\Games\Unreal\Unreal [v1.227]\Core\Inc\UnObjVer.h` and `UnLinker.h`, with explicit Legend licencee modifications; bodies incomplete.
- Original Unreal v0.867: `L:\Source\Games\Unreal\Unreal [v0.867] [1997-07-25]\Src\Inc\UnBuild.h`, `UnObjVer.h`, `UnLinker.h`. Source hashes and exact execution path in `specs/epic-source/ue1/unreal-v0867/01-unrealfile-entry.md`.
- Unreal v1.200: `Core\Inc\UnObjVer.h` defines version 61, minimum 34; `Core\Src\UnLinker.h:303-339` defines the older classic package summary, with the package flags field conditional on version 34.
- UT99 v432 tree: `Core\Inc\UnObjVer.h` defines `ENGINE_VERSION=430`, `PACKAGE_FILE_VERSION=69`, min 60; maintain this source/config ambiguity as explicit provenance work.
- Community `L:\Source\Engine\UE1` version 61/34 is additional source; do not assume that this is identical to Epic's original branch.

## UE1-D001 — UE2 special tag was admitted by UE1 reader (INCORRECT → CORRECTED, O01 open)

Original UT99 v400 `UnObjVer.h:20` defines `PACKAGE_FILE_TAG=0x9E2A83C1`; `UnLinker.h:362-369` checks exact equality in the loader. Unreal v227 separately defines the same tag in `UnObjVer.h:23`.

Previously `catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php` accepted both the standard tag and UE2/Killing Floor's 0x9E2A83C2 via the shared helper even when instantiated for UE1. Corrected to require the standard tag for UE1, retaining UE2 special-tag admission.

Before patch, two UE1 negative assertions failed. After patch, `verify-ue1-package-tag-source.php` passes 6/6 with 8 existing regression scripts successful. See `UE1-ACCEPTANCE.md`.

## UE1-D002 — minimum-version policy not established end-to-end (OPEN)

Original `ULinkerLoad` is branch-dependent: v0.867 `UnLinker.h:441-454` prompts below version 22, v1.200 `UnLinker.h:473-478` prompts below version 34, UT99 v400 `UnLinker.h:371-376` prompts below version 60. In all three, this is an interactive user choice, not unconditional rejection. UnrealDB is a noninteractive service and its supported scope needs an explicit documented policy. No verdict of incompatible accepted version is claimed yet.

## UE1-D003 — early UE1 Unrealfile identification hidden as generic invalid magic (INCOMPLETE → CLASSIFICATION CORRECTED, READER MISSING)

Original v0.867 `UnBuild.h:108`, `UnLinker.h:258-310,413-454` uses `UNREALFILE_TAG = "Unrealfile\\x1A"` as a fixed 32-byte field, not the later numeric `0x9E2A83C1` tag. Its summary records object count/name count/object and name offsets plus dependency filenames rather than later import/export maps.

Before fix, `gp_read_legacy_summary` classified the authentic early signature as generic `unreal.magic_not_found`, with unknown engine/version; `CatalogPackageHeaderInspector` displayed a generic bad tag. No dedicated early-format package reader exists.

Correction: `catalog/lib/GameProfiles.php` recognises the exact original fixed-string prefix and reads its version at byte 32, returning `ok=false`, `format=early_unrealfile`, `engine_hint=UE1`, and `error_code=unreal.early_unrealfile_unsupported`. `catalog/src/Application/Catalog/CatalogPackageHeaderInspector.php` now gives an informative unsupported-format diagnostic. This does **not** claim support for its original summary/object structures, and is not an O01 acceptance.

Test `catalog/bin/verify-ue1-early-format-identification.php` contains synthetic source-driven v23 header plus a near-miss and conventional v68 control. New test reproduced baseline failures; after fix 9/9 pass.

## UE1-D004 — file-examine header admitted UE2's tag as UE1 (INCORRECT → CORRECTED, O01 open)

`CatalogPackageHeaderInspector::inspect()` previously used the generic global tag policy even for rows explicitly marked `engine_key=UE1`. That allowed a Killing Floor-specific tag to show as a valid UE1 package header. It now requires `CatalogUnrealPackageTag::STANDARD` for a UE1-labelled file. Generic/UE2 handling is untouched. Verified by the two opposite controls in the new 9-check test.

## UE1-D005 — production header-only helper discarded reader failures (INCORRECT → CORRECTED, O01 open)

Epic reference: UT99 v400 `Core/Src/UnLinker.h:354-369` loads a summary then throws/aborts when the package tag is incorrect; it does not substitute a successful empty package. The real UnrealDB helper `catalog/lib/CatalogParser.php::catalog_try_read_package_header()` previously instantiated `CatalogUE1PackageReader` and returned `getHeader()` without invoking `validatePackage()`. Because `CatalogLegacyPackageReaderBase::__construct` catches parser exceptions and replaces the header with a blank structure, callers silently received an empty header on a wrong tag, a truncated file, or an early `Unrealfile` input.

The same helper is used by `CatalogSourceScanRunner.php` (source-scan GUID path), `CatalogHttpSourceScanService.php` (HTTP deep GUID scan) and `CatalogUnverifiedQueueStorage.php` (queue identity). In B03, the helper checks validation issues for **UE1 only** before returning a header, and throws a descriptive RuntimeException on failure. UE2/UE3/UE4/UE5 behaviour is unchanged pending their respective acceptance work.

Test `catalog/bin/verify-ue1-header-route-validation.php`: valid v68 summary succeeds; UE2-only tag, truncated file and v23 early header must throw instead of returning a blank fallback. Baseline 3/4 failed; after the patch 4/4 pass. All 15 selected adjacent regression scripts pass, including source-scan boundaries and profile reader admission. This establishes one bounded caller-path fix, not O01 acceptance.

## UE1-D006 — pre-68 heritage GUID inspector (CORRECTED; O01 remains open)

**Epic:** UT99 v400 `Core/Src/UnLinker.h:173-200` serializes heritage count and offset below version 68; it seeks to the GUID array, reads its entries, and retains the last GUID. At version 68+, GUID is inline. Unreal v1.200 `Core/Src/UnLinker.h:303-339` independently confirms the older summary layout.

**Discrepancy:** `CatalogPackageHeaderInspector::inspectLegacy()` always read byte 44 as though an inline GUID were present. All 61 newly found genuine texture packages happened to have a single heritage entry starting at byte 44, which hid the error. Controlled tests reproduced four failures: multi-record GUID offset, zero-entry fabricated GUID, GUID past the first MiB, and malformed heritage bounds. Version 68 inline handling was already correct.

**Fix:** read and validate `HeritageCount/HeritageOffset` against physical bounds, retrieve the last record even beyond the inspection prefix, and attach its actual offset to the diagnostic row; preserve the v68+ inline GUID path. The test `catalog/bin/verify-ue1-pre68-heritage-inspector.php` passes **5/5** after **1/5** pre-fix. Production inspector and 61 genuine v35–61 packages agree on GUID, including independent source summary field comparison. This is bounded verification, **not** compiled-engine acceptance.

## B04 — genuine UE1 version 35–61 corpus

The read-only verifier `catalog/bin/verify-ue1-genuine-texture-corpus.php` checks SHA-256 pinned source packages, 11 independent direct-byte summary fields, canonical reader name/import/export counts, real header-reader helper, and file-examine GUID. All **61/61 passed** across 13 package versions. All 61 have `HeritageCount=1` and `HeritageOffset=44`. Synthetic heritage variants cover omitted arrangement cases, but no real Epic compiled oracle, object serialization, or V5 database workflows have been accepted.

## Real callers identified, whole operation still to trace

- `catalog/lib/GameProfiles.php`: summary identification and classification; `gp_classify_file` is the header/profile admission entry.
- `catalog/src/Infrastructure/Readers/CatalogReaderResolver.php`: chooses UE1/UE2 canonical stream reader.
- `catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php`: shared UE1/UE2 binary parser and wrappers.
- `catalog/src/Application/Catalog/CatalogPackageHeaderInspector.php`: user-facing header inspection, called by `catalog/file-examine.php` and `file-examine-header.php`.
- `catalog/src/Infrastructure/Metadata/Uedb5SourceSnapshotFactory.php`: game/source/profile dispatch, Unreal v227 special constructor, distinct UT99/Unreal V5 builders.
- `catalog/src/Infrastructure/Unverified/CatalogUnverifiedQueueStorage.php`, `src/Infrastructure/Source/CatalogHttpSourceScanService.php`, `src/Infrastructure/Source/CatalogSourceScanRunner.php`: header-reading consumers.
- Staging, download/archive, unverified processing, failure handling, V5 persistence still need full entry-to-result maps.

**Scope boundary:** Original pre-package Unrealfile structures and source branches with missing C++ are not equivalent to the later classic package reader. Record them explicitly rather than inventing compatibility.

## B05 / UE1-D007 — genuine v68/v69 packages, full V5 roundtrip and UE1 admission tag (CORRECTED)

Installed source tree produced a separate SHA-256-pinned **31-package** corpus at `catalog/compliance/inventory/ue1-packages-v68-v69.csv`. Versions: UT99 27 × v68; Unreal v227 2 × v68, 1 × v69, and 1 × v69 with licensee 227. Original C++ anchors: UT99 v400 `Core/Src/UnLinker.h:144-204`, Unreal v227 `Core/Inc/UnLinker.h:97-170`. Independent little-endian inspection of tag/version/licensee, names/imports/exports, inline GUID, generation count and package field layout agrees with the canonical reader and file inspection **31/31**.

Actual production integration `catalog/bin/verify-ue1-genuine-production-paths.php` checks **92/92** genuine installed files through `gp_classify_file` with isolated SQLite UE1 profiles, `Uedb5SourceSnapshotFactory::buildForGameId` (Unreal or UT99), `Uedb5MetadataSnapshotWriter::write` and `Uedb5MetadataReader::snapshot`. Every V5 summary, name, import and export section agrees with its source snapshot. Files are written to and deleted from unique temporary storage, with no live production SQL/storage changes.

**Remaining source difference UE1-D007 found and corrected:** Before patch, the preliminary `gp_read_legacy_summary` (shared across engines) recognised the UE2/Killing Floor tag `0x9E2A83C2`; `gp_classify_file` for a game explicitly assigned to UE1 then returned a successful `header_ok` and `ok_for_selected_game`. This contradicted Epic UT99 v400's UE1 linker tag check (`Core/Src/UnLinker.h:354-369`), although the later canonical reader and source snapshot rejected the package. The real production path's negative-control test failed before correction. `catalog/lib/GameProfiles.php` now sets an explicit invalid-for-selected-engine status/error for UE1 tag variants other than `standard`, without changing global UE2 tag recognition. Both invalid-tag and corrupt-name-offset cases pass, with UE2 retained as a positive admission control.

**Verification:** 19/19 selected production, original source, UE1/UE2/UE4/UE5 regression scripts exited 0; all modified PHP files pass lint. This is not final UE1 O01 source compliance or a live SQL publisher acceptance test. The original v0.867 string-tagged `Unrealfile` requires independent format support.

## Early pre-v0.86x native-source formats — actual source found (2026-10-21)

The previously unclassified 1996 versions **have original reader/writer source**. v0.82, v0.83, v0.84a are not the same `FUnrealfileSummary` family as v0.86x/v0.867 and are not later standard-magic packages.

- v0.82: `L:\Source\Games\Unreal\Unreal [v0.82] [1996-00-00]\SRC\INC\UNBUILD.H:69–70` defines `RES_FILE_VERSION=0x0009` and `RES_FILE_TAG="Unreal Resource\x1A"`. `SRC\INC\UNRESTYP.H:164–185` declares `FResourceFileTrailer`, stored at the **end** of files. `SRC\ENGINE\UnRes.cpp:898–943` loads trailer and names/imports/exports/headers; lines 1324–1453 produce the original trailer and tables.
- v0.83: same resource file version **9**, source `Src\Inc\UnBuild.h:69–70`, `Src\Inc\UnResTyp.h:164–185` and `Src\Engine\UnRes.cpp:898–943,1326–1453`. The source tree's engine version label is still `0.82` despite the v0.83 folder name, so source-config provenance differs from the directory label.
- v0.84a: resource file version **10**, source `Src\Inc\UnBuild.h:100–101`, `Src\Inc\UnResTyp.h:178–196`, `Src\Engine\UnRes.cpp:945–997,1404–1521`.
- v0.86x and v0.867: `Src\Inc\UnLinker.h:258–310,413–490` describes a *different* beginning-of-file `FUnrealfileSummary` and its names/objects/dependencies. The source is available to implement a dedicated loader; no need to wait for an archived compiled executable.

**Correction to prior audit wording:** the 1996 cases are **implementation work**, not lack of source. The filename suffix `.RES` in the v0.82 Visual C++ `Release` build folders refers to Windows linker resource artifacts; it is not evidence of authentic original Unreal resource-package files. Native executables and genuine old-format package fixtures are independent evidence, not mandatory prerequisites for source-based implementation. Genuine partial-source limitations mainly apply to the v1.224/v1.227 subbranches, for which later/nearby source may help only after equivalence is demonstrated.

## O01 accepted — original 1996 resource-file identification completed (2026-10-21)

Production `CatalogUE1OriginalResourceFormat::identify()` checks Epic's **leading** `Unreal Resource\x1A\0` and reads the 164-byte `FResourceFileTrailer` from **EOF**. This is intentionally not a modern package header. Its fields are nine native 32-bit little-endian values plus 16-byte padding, 80-byte delta filename and 32-byte duplicated tag. Tested original resource versions 9 and 10, unknown version 11, and malformed/truncated inputs. Epic `FGlobalResourceManager::AddFile` does **not** require the writer's `[SUMMARY]` marker or duplicated trailer tag; these two pieces of information must not become extra rejection criteria.

Source-backed behaviour implemented in `GameProfiles.php`, `CatalogPackageHeaderInspector.php`, `CatalogParser.php`, `CatalogUnverifiedStagingIndex.php` and `CatalogUnverifiedQueueStorage.php`: preserve known serialized format/version, stop routing unsupported v9/v10/v23 originals into the later UE1 reader, return a true source-family diagnostic, retain queue hashes without invented GUIDs. Test `verify-ue1-original-resource-identification.php` **36/36**; 20/20 DEV regressions and 9/9 PRD tests; code commit `4dad9618`, 7/7 code/test files deployed and SHA-256 matched. **O01 acceptance is strictly identification**, not full early file support. O02 must implement the actual Epic original-format reader data handling.
