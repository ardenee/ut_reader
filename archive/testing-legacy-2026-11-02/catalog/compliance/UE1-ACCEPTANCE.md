# UE1 — acceptance evidence and runnable checkpoints

Updated 2026-10-21. **O01 accepted (identification/dispatch); O02 accepted (source-defined summary and native structural metadata, NOT full payloads); O03 next.** UE1 overall not accepted.

## B01 — numeric UE1 package tag boundary

Pinned source: UT99 v400 `Core/Inc/UnObjVer.h:20-27`, `Core/Src/UnLinker.h:354-376`; Unreal v227 `Core/Inc/UnObjVer.h:23`.

Before: 2/6 checks failed because a UE1 reader admitted 0x9E2A83C2. After: 6/6 passed with UE2/Killing Floor acceptance retained. Eight relevant regression scripts passed. Committed/deployed in `698f028f`; DEV/PRD SHA-256 matched for six files.

## B02 — early Unrealfile detection and header-inspector parity

Pinned original-source evidence: `specs/epic-source/ue1/unreal-v0867/01-unrealfile-entry.md`; exact v0.867 C++ linker header and tag defined by `UnBuild.h:108` and `UnLinker.h:258-310,428-454`.

Before changes: 5/9 expected cases failed in `verify-ue1-early-format-identification.php`, including generic unrecognised-magic outcome and UE1-tag misclassification in file-examine. After: **9/9 passed**. Commit `b5f4a214` pushed to GitHub. All seven changed B02 code/test/source/evidence files deployed to PRD through targeted Robocopy, with SHA-256 parity **7/7**.

The test verifies: early fixed-string magic detection, meaningful unsupported error, UE1 identity, version byte 32 (23), negative near-miss, modern v68 header unaffected, UE1 rejection of UE2-only tag, and preserved UE2 acceptance.

Additional selected regressions: **12/12 scripts exited 0**, including `verify-header-only-staging-first-upload.php`, `verify-killing-floor-package-tag.php`, `verify-ue1-package-tag-source.php`, `verify-ue1-pre50-import-layout.php`, `verify-ue1-root-import-integrity.php`, `verify-ue1-verify-import-profile-contract.php`, `verify-unreal-v227-summary-contract.php`, `verify-epic-ue123-reader-parity.php`, `verify-uedb5-ut99-persistence.php`, `verify-ue427-legacy-summary-inspector.php`, and `verify-ue5-583-workflow-identify-inspector.php`. Production modifications and verifier pass PHP syntax checks.

Run all of these on DEV via the local PHP CLI:

```powershell
cd C:\Users\arden\source\repos\ut_reader
$tests = @(
    'verify-ue1-early-format-identification.php',
    'verify-ue1-header-route-validation.php',
    'verify-ue1-package-tag-source.php',
    'verify-header-only-staging-first-upload.php',
    'verify-killing-floor-package-tag.php',
    'verify-ue1-pre50-import-layout.php',
    'verify-ue1-root-import-integrity.php',
    'verify-ue1-verify-import-profile-contract.php',
    'verify-unreal-v227-summary-contract.php',
    'verify-epic-ue123-reader-parity.php',
    'verify-uedb5-ut99-persistence.php',
    'verify-ue427-legacy-summary-inspector.php',
    'verify-ue5-583-workflow-identify-inspector.php',
    'verify-source-scan-boundaries.php',
    'verify-profile-reader-admission.php'
)
foreach ($t in $tests) { php ("catalog/bin/" + $t); if ($LASTEXITCODE -ne 0) { throw $t } }
```

## B03 — header-only production callers must reject failed UE1 parses

Native source: UT99 v400 `Core/Src/UnLinker.h:354-369` (bad package tag aborts). UnrealDB `catalog/lib/CatalogParser.php` is used by source-scan, HTTP-scan and queue-identity callers. Before correction, it silently returned the canonical UE1 reader's fallback blank header instead of examining `validatePackage()` failures.

`php catalog/bin/verify-ue1-header-route-validation.php` reproduced **three failing assertions out of four** before the patch: the UE2-only tag, truncated data and old Unrealfile header did not throw. After the UE1-only validation guard, **4/4 pass**, including unchanged success on a valid v68 summary. A combined selection of **15/15** scripts passed, including B01/B02 tests, source-scan boundaries, profile reader admission, UE2, UE4 and UE5 checks. Both changed PHP files lint.

B03 committed/pushed as `aa3eb737`; all 5 changed code/test/documentation files deployed to PRD and SHA-256 verified 5/5. B03 remains a narrow source-corresponding entry-point correction, not full engine or workflow acceptance.

**Evidence limits:** The early v23 fixture is synthetic but source-anchored; no authentic compiled v0.867 linker oracle or genuine matching binary package has yet been compared. The `.u` files sampled from v0.86x/v0.867 `Classes` are text source files and must not be represented as binary-package test inputs. Do not mark O01 accepted on classification alone.

## O01 — ACCEPTED: IDENTIFY / DISPATCH (2026-10-21)

**Responsibility:** Given real package bytes and a selected-game profile, identify source-defined UE1 format family and serialized version/licensee where available, route to the correct supported reader, or explicitly report the format unsupported/invalid. Do not accept another engine's tag into UE1. Only read the byte ranges necessary to identify; do **not** require full package deserialization.

- [x] Source-map original 1996 resource trailers (v9/v10), 1997 `Unrealfile` summary (v23), and 1998+ numeric package headers (v34–69) to corresponding supplied engine/game source and identify distinct format families.
- [x] Enforce UE1 numeric package tag, header reader failure propagation, original `Unrealfile` signature recognition, UE2-tag isolation and source-aligned v68/v69 licensee extraction on the currently tested paths.
- [x] Recognise the original **leading** `Unreal Resource\x1A` tag plus **EOF** `FResourceFileTrailer` and report resource versions 9/10 without routing into the later UE1 parser. The `[SUMMARY]` marker and duplicated trailer tag are **informational** in Epic's loader and are deliberately not required.
- [x] Branch-specific header and version policy verified: v9/v10 resource trailers, v23 Unrealfile, versions 35–61 and 68/69 standard packages, nonstandard licensee 227, and source-defined minimum-version prompting replaced by noninteractive reader-validation policy rather than a false global version cutoff. Invalid and unknown versions are not reported as fully parsed.
- [x] Production entry-point coverage: actual `gp_classify_file`, `CatalogVerifiedPackageInspector::inspect`, `CatalogUnverifiedStagingIndex::detectEngine/parse`, `CatalogUnverifiedQueueStorage::identity`, `catalog_try_read_package_header` (shared source/HTTP/queue entry), and `CatalogPackageHeaderInspector::inspect`, with explicit unsupported outcomes and no fabricated GUID. Shared callers verified by the existing scan-boundary regression.
- [x] DEV original-resource test **36/36**, genuine UE1 files **92/92** and relevant regression scripts **20/20**; seven PHP files passed syntax checks. Production code/tests commit `4dad9618` pushed to GitHub, all **7/7** changed files SHA-256 matched on PRD. Nine relevant PRD scripts passed, including 36/36 original-format tests and 92/92 genuine-file roundtrips. **O01 ACCEPTED; O02 is next.**

**Explicitly deferred, not O01 gates:** original v9/v10/v23 full readers and complete summary tables (O02); name/import/export decoding (O03/O04); object data (O06/O07); live MySQL publication/search (O09); end-to-end workflow checks (O10). A compiled original Epic executable and authentic early-game binaries are useful independent corroboration when available, but their absence cannot indefinitely block a source-defined O01 identification contract. Do not add unrelated acceptance gates to O01.

All six frozen identification/dispatch criteria passed. O01 is narrowly accepted; original native structural metadata has since been implemented under O02. Neither operation claims that original files' complete object/property/payload serializers are implemented.

## O02 — ACCEPTED: source-defined summary and structural metadata (2026-10-21)

**Responsibility:** Decode the original UE1 package/resource summary and native structural name/import/export/object-base records according to their specific source serializers. Correctly retain this information in the unverified staging system without inventing modern Unreal object hierarchy, class-package references or false verified state. **Full payload deserialization is O06/O07**, not an O02 acceptance gate.

- [x] 1996 Unreal v0.82/0.83 resource version 9 and v0.84a resource version 10: original EOF 164-byte trailer, 36-byte name entries, 44-byte resource import/export entries, native 32-bit resource headers and physical data offsets. Source: `UnResTyp.h` and `UnRes.cpp` from each original game tree.
- [x] 1997 Unreal v0.86x/v0.867 Unrealfile version 22/23: fixed summary tag and variable-length serialized dependencies/name table, 16-bit one-based FName references, variable-size UObjectBase import/export entries and exported-class preload records. Source: `UnLinker.h`, `UnArc.h`, `UnName.h`, `UnObj.cpp`. No fabricated outer/index paths.
- [x] Original structural records integrated into actual `CatalogUnverifiedStagingIndex::parse/indexPath`; `CatalogUE1OriginalStagingMetadata` preserves typed native records, `CatalogUnverifiedMetadataSnapshotBuilder::fromOriginalNative` isolates native object records from classic modern import/export rows. True source counts and flags retained. Original files remain unverified; supported name rows retain their original indices.
- [x] Real MySQL/PDO acceptance uses session-temporary `ue_files` and `ue_unverified_metadata` tables: **13/13** roundtrip/count/status assertions, with zero permanent catalogue updates. Structural native-path tests **27/27**, legacy invalid-format negatives **36/36**.
- [x] Classic genuine UE1 package metadata covers **92/92** files (61 versions 35–61 plus 31 versions 68/69). Selected DEV regression scripts **26/26** and PRD **8/8** passed; **6/6** changed code/test files SHA-256 matched on PRD. Source-parity code committed/pushed as `123de5b8`.

**Explicitly outside O02 acceptance:** name-resolution edge behaviours (O03), full cross-object dependency and import-resolution semantics (O04/O05), type-specific SerializeHeader/SerializeData and property values (O06), bulk/external resources (O07), complete verified UEDB5 publishing/search (O09), and full engine workflow acceptance (O10). Metadata-only staged files are not equivalent to Epic's runtime-loaded packages. No genuine original 1996/1997 binaries or compiled original runtime oracle were used in these tests; the early layouts were verified with source-defined fixtures and native code inspection.

O02 **accepted within this scope**, not an engine-wide compliance claim.

## B04 — original Unreal v35–61 corpus and heritage inspector correction (2026-10-21)

**Source:** Unreal v1.200 `Core/Src/UnLinker.h:303-339` defines the pre-68 summary fields (package flags are serialized from version 34). UT99 v400 `Core/Src/UnLinker.h:173-200` defines the distinction between pre-68 heritage GUIDs (offset/count, last GUID wins) and version-68+ inline GUIDs.

**Real-file check:** `php catalog/bin/verify-ue1-genuine-texture-corpus.php` reads all 61 SHA-256-pinned `.utx` packages from `L:\Source\Engine\UE1\Source\Unreal\Textures`. All **61/61 passed** across 13 serialized package versions 35–61. The check independently unpacks 11 native serialized summary fields directly from the physical first 44 bytes and compares them to the canonical reader; also verifies names/imports/exports counts, source/HTTP/queue-facing header helper and file-examine GUID agreement. All 61 genuine files have exactly one heritage record at byte 44, which limits this sample's variation.

**Defect UE1-D006:** the UI inspection route was incorrectly reading a GUID at byte 44 unconditionally for pre-68 summaries. `verify-ue1-pre68-heritage-inspector.php` reproduced **4 failures out of 5** before the correction (multi-record/offset, zero heritage, GUID past 1 MiB, invalid bounds); after patch **5/5 passed**, including unchanged version-68 inline GUID handling. The inspector now obtains the last GUID from the validated heritage table. An additional **15/15 existing regression scripts passed**; modified PHP and both new test scripts lint.

**Deployment evidence:** B04 commit `4d56c729` pushed to GitHub; six changed source/test/evidence files copied DEV→PRD with 6/6 SHA-256 identity checks. The final implementation-register and recovery cursor were recorded separately afterwards.

**Limits:** the B04 fixtures prove genuine-input parser operation and source-defined byte layout checks, not a compiled Epic engine oracle or property/object payload, V5 persistence or full end-to-end acceptance. O01 remains IMPLEMENTING; UE1 acceptance is still **0 operations accepted**.

Run on DEV:

```powershell
cd C:\Users\arden\source\repos\ut_reader
php catalog/bin/verify-ue1-genuine-texture-corpus.php
php catalog/bin/verify-ue1-pre68-heritage-inspector.php
```

## B05 — v68/v69 original installed packages, UE1 tag admission and true V5 container roundtrip (2026-10-21)

**New SHA-256 inventory:** `catalog/compliance/inventory/ue1-packages-v68-v69.csv`, and explanation `catalog/compliance/inventory/UE1-V68-V69-CORPUS.md`. Files: **31**, with the UT99 v400 version 68 and Unreal v227 version 68/69 source families including `LicenseeVersion=227`. Test `php catalog/bin/verify-ue1-v68-v69-genuine-corpus.php` passes **31/31** with source-defined direct-byte checks; the old corpus still passes **61/61**.

**Real production pathway:** `php catalog/bin/verify-ue1-genuine-production-paths.php` uses a disposable in-memory SQLite catalog game/profile model (game IDs 3 UT99, 12 Unreal). Against the two SHA manifests (92 file paths) it calls the actual `gp_classify_file`, actual UE1 reader resolver and `Uedb5SourceSnapshotFactory`, then writes each source snapshot with the production V5 block container writer. The production V5 reader reads each snapshot back, with **92/92 exact section comparisons for summary/names/imports/exports**. Only unique temporary test storage is written; production MySQL and persisted catalog rows are untouched. By source: **65 Unreal**, **27 UT99**.

**Found UE1-D007:** The initial classifier still admitted the UE2-only Killing Floor tag for a UE1 selected game. Independent negative test failed before patch; after engine-specific admission protection in `catalog/lib/GameProfiles.php` it rejects the UE2 tag, while UE2 classification continues to admit it. Separate truncated/invalid-name-table source input is rejected by reader and V5 factory without any persisted V5 snapshot. Both negative checks now pass.

**Broader regressions:** 19/19 selected verifier scripts passed, including all B01–B05 tests, Unreal/UT99 builder persistence, UE2/Killing Floor, UE4/UE5 header inspection, source scan bounds and profile admission. This does **not** demonstrate compiled Epic C++ execution, early Unrealfile support, actual MySQL projection/publishing/search or all source forks; O01 still IMPLEMENTING and **0 fully accepted** operations.

**B05 deployment confirmation:** Commit `1bbb837a` was pushed to GitHub. All 8 changed code/test/evidence files were deployed from DEV to PRD and independently SHA-256 matched. Deployed PRD code passed both new verification scripts (31/31 and 92/92), both negative controls, and four previous profile/header reader scripts. Live SQL projection and search were not run; this remains a defined acceptance gap.

## O03 — ACCEPTED: serialized names and file-local mapping (2026-10-21)

**Responsibility:** Read names from each source-defined UE1 disk format, preserve original case and flags, reject unsafe/broken name references, represent 1997 source-defined context filtering only when an explicit load context is given, and persist original names without mistaken text encoding. Cross-file/global FName interning and object lookup are O05, not implicit claims under O03.

- [x] Original 1996 resource v9/v10 `FNameEntry` fixed 32-byte characters + DWORD flags, v22/v23 `Unrealfile` `FArchive::String(Name,32)` (31 serialized bytes maximum), original one-based WORD `NAME_INDEX` (0 = `NAME_None`) with source bounds checked. Original metadata preserved via unverified staging.
- [x] UE1 classic package v35–61 pre64 ANSI NUL-terminated entries, v68/v69 compact-length `FString` entries, preserving table indices/casing/flags. UE1's invalid `NameMap` indices now fail rather than falsely resolving to empty strings. No UE2 policy change.
- [x] Epic `Core.h::FromAnsi` maps **each unsigned input byte separately**. Shared `CatalogUE1AnsiName` provides reversible UTF-8 presentation of 8-bit source names across all three families, without UTF-8/Windows-1252 guesses. Original typed records and real compressed MySQL staging retain extended-byte identities. FName's source-visible 64-character fixed storage rejects overlength and unterminated classic FString data instead of presenting a corrupt name as valid.
- [x] v0.86x/v0.867 editor/client/server load-context flags applied only when explicitly provided; raw serialized names remain available and no global runtime context is assumed. Original `None` sentinel and one-based reference mapping verified.
- [x] Verifiers: classic ANSI identity **24/24**, original ANSI identity **18/18**, classic FString boundary **14/14** (baseline **8/14**), classic invalid reference **8/8** (baseline **2/8**), original context **15/15**, isolated native MySQL with extended ANSI names **15/15**. DEV regressions **31/31**, PRD targeted **6/6** (including genuine UE1 **92/92**). Implementation commits `3da09743`, `14599c2e`, `87d9f753`, `80651482` pushed and SHA-256 deployed.

**O03 accepted only for names as serialized and file-local mapping.** Runtime global FName hashing/interning, cross-file name identity/canonical casing and import/export object resolution are not certified and belong to O05. Full early object/property payloads remain open in O06. Advance to O04 imports/exports.

## O04 — ACCEPTED: source-defined import/export records and structural references (2026-10-21)

**Responsibility:** Decode and retain each UE1 generation's actual imported/exported object record identity, version-specific fields and signed local indices; prevent invalid source-map references and out-of-bounds serialized object data from being treated as valid catalogued identities. Cross-package import verification and runtime object creation are O05/O06, not O04.

- [x] Original v0.82/0.83 resource v9 and v0.84a resource v10: preserved native `FResNameFileEntry` import/export records, source-defined counts, types, resource version/header sizes and shared native header offset/data-size/CRC fields without fabricated modern `OuterIndex` semantics.
- [x] Original v0.86x/v0.867 `Unrealfile` v22/v23: `UObjectBase` import/export kind determined by zero/non-zero `FileHeaderOffset`; class preload records are read only for `Class` exports. `FArchiveResLoad::operator<<(UObject*)` uses one-based DWORD references into `ResMap` and zero for NULL. Validate preload parent object references against the summary's object count and retain a separately derived zero-based local index; do not invent object identity.
- [x] Classic UE1 v35–69: source `FObjectImport/FObjectExport` version gates preserved (pre50 ObjectPackage vs PackageIndex, export class/parent/super/package, nonzero compact SerialSize followed by SerialOffset). Signed references retain exact serialized values: positive refers to `ExportMap[index-1]`, negative to `ImportMap[-index-1]`, zero to root/NULL. `VerifyImport` requires `Import.PackageIndex <= 0`; out-of-bounds class/super/outer indices are rejected from source-backed structural catalog paths, and positive serialization ranges must fit file length. The native engine may defer some reference dereferences until runtime; our noninteractive catalog safely rejects malformed structural references.
- [x] Independent baseline classic fixture: **4/32 passed before change**, **44/44 after** (28 invalid signed-reference cases plus 12 serial-size/offset failures now caught), with valid fixtures for v49/v61/v68/v69. Native v22/v23 class-preload source-index tests **25/25** after (19/25 before). Existing original resource table/header verifiers and native staging tests pass. Genuine classic UE1 reader/classifier/V5 isolated roundtrips **92/92**. DEV regressions **32/32** scripts, PRD **9/9**, **4/4 code/test files SHA-256 matched**, committed/pushed as `afecd553`.

**Scope boundary:** Structural import/export decoding is accepted; real `VerifyImport`, `IndexToObject`, external package resolution and global FName interning are O05. Original object/property SerializeHeader/SerializeData and verified full imports are O06/O09/O10. Source-defined fixtures were used for the 1996/1997 formats; their original compiled engine runtime was not executed. O04 acceptance is not UE1 engine-wide equivalence. Proceed to O05.

## O05 — ACCEPTED: deterministic file-metadata dependency projections (2026-10-21)

**Frozen O05 contract:** For every source-defined UE1 file family handled by O01–O04, project dependency identities and file-backed import targets according to the applicable native loader. Resolve only when **serialized data and a selected provider** determine the result. Reject impossible class/outer/visibility matches. If Epic requires an already-loaded global registry, StaticFindObject, CLASS_SafeReplace, native/transient objects, or physical load order unavailable to the catalog, report **unresolved/not-evaluated**, not fabricated success or a proven runtime miss. Original files and one-based indices remain distinct from modern UObject package paths. This acceptance does NOT certify runtime object loading or full production publishing.

| Source family | Evidence and behaviour | Boundary |
|---|---|---|
| Original 1996 v9/v10 | v0.82/v0.83/v0.84a UnRes.cpp: FindResource, PrivateLookup, AddFile. Case-insensitive native resource (type,name) lookup by ascending global ResArray slot; lookup keys stored by native staging and real MySQL | Without captured loaded registry: runtime_registry_not_available. Supplied registry comparison does NOT claim engine object loaded. Native lookup 17/17, staging 29/29, isolated MySQL 16/16 |
| Original 1997 v22/v23 | v0.867 UnLinker.h FUnrealfileSummary, FArchiveResLoad, UnObj.cpp UObjectBase. One-based DWORD class-parent ResMap references map to serialized target records including null/import/forward export. Dependency filenames retained | Runtime parent object status not_evaluated; no invented OuterIndex, package identities or successful dependency open. Fixture tests 33/33 |
| Classic Unreal v34–59 | v1.200 ULinkerLoad VerifyImport and GenerateExportInfo: pre50 _ObjectPackage, source class/package/outer and legacy texture fallback | Unsupported version forks do not inherit unproven semantics |
| UT99 v68 retail | v1.400 ULinkerLoad GetExportClassPackage/Name and VerifyImport: Core.Package root, RF_Public, class hacks, mesh/LodMesh, reverse export hash traversal, parent SourceIndex and top-level fallback, source-native runtime-only states | Private parents prevent false child matches; a runtime-only parent with valid SourceLinker can still match a source top-level child. V4 SQL and V5 both tested |

**Executable closure:** php catalog/bin/verify-ue1-o05-acceptance.php must pass 10/10 independent gates covering V4 real MySQL/PDO temporary tables and isolated on-disk UEDB5 provider binaries, V5 dependency resolution, original reference fixtures and genuine UE1 provider maps. Genuine providers: 27 SHA-pinned UT99 packages, 1,335 export records examined, 75 source-backed provider matches and 75 wrong-class negatives, using synthetic consumer probes. Full classic corpus 92/92. Full DEV regression suite and PRD targeted tests must pass and deployments be SHA-256 matched.

**Explicit runtime boundary:** No compiled original 1996/1997 engine oracle was executed. Dynamic loaded ResArray, global FName interning, native/transient find and safe-replace object creation, and cooked allocation/GC are not established from file bytes. Those states must remain unresolved/not-evaluated. Demonstrating native runtime execution and entire file-to-published-data parity belongs to O10. Full object/property bytes O06 and bulk data O07. O05 acceptance is NOT engine-wide Epic equivalence.
