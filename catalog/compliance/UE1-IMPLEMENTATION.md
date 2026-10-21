# UE1 — source-to-production implementation register

Updated 2026-10-21. Scope: new source-defined acceptance process, not earlier audit counts. The current operation is **O01**, which remains open.

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

## Real callers identified, whole operation still to trace

- `catalog/lib/GameProfiles.php`: summary identification and classification; `gp_classify_file` is the header/profile admission entry.
- `catalog/src/Infrastructure/Readers/CatalogReaderResolver.php`: chooses UE1/UE2 canonical stream reader.
- `catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php`: shared UE1/UE2 binary parser and wrappers.
- `catalog/src/Application/Catalog/CatalogPackageHeaderInspector.php`: user-facing header inspection, called by `catalog/file-examine.php` and `file-examine-header.php`.
- `catalog/src/Infrastructure/Metadata/Uedb5SourceSnapshotFactory.php`: game/source/profile dispatch, Unreal v227 special constructor, distinct UT99/Unreal V5 builders.
- `catalog/src/Infrastructure/Unverified/CatalogUnverifiedQueueStorage.php`, `src/Infrastructure/Source/CatalogHttpSourceScanService.php`, `src/Infrastructure/Source/CatalogSourceScanRunner.php`: header-reading consumers.
- Staging, download/archive, unverified processing, failure handling, V5 persistence still need full entry-to-result maps.

**Scope boundary:** Original pre-package Unrealfile structures and source branches with missing C++ are not equivalent to the later classic package reader. Record them explicitly rather than inventing compatibility.
