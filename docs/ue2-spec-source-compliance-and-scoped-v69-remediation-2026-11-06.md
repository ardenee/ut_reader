# UE2 source specification versus UnrealDB — scoped compliance and source-version boundary (6 November 2026)

## Scope

Compare only UnrealDB's **actual** classic UE2 header/name/import/export, game source policy, static `VerifyImport`, UEDB5 Pass 1/Pass 2 and dependency/statistics views with independently sourced UE2 and Unreal II/UT2003/UT2004 specifications. This does not certify class-specific asset payload deserialization, native runtime object creation, or the package search/mount order of a particular game installation.

Engine-source and game-source documentation is maintained independently from the UnrealDB implementation; this report is the explicit **comparison** and production rollout ledger.

## Authoritative source, independently confirmed

| Game | Local first-party game source | Source-emitted package profile | Confirmed serialized import-verification boundary |
|---|---|---|---|
| Unreal II December 2000 | `L:\Source\Games\Unreal II\Unreal II The Awakening [12-09-2000]\Unreal2_old\Core` | v69/lic0x7F; min60 | Version-69 linker supports older loadable v60–69 with compatible licensee; includes special UnrealI/UnrealShare alias, public export constraint |
| Unreal II January 2003 | `L:\Source\Games\Unreal II\Unreal II The Awakening [01-07-2003]\Core` | v126/lic0; min60 | Complete **game-specific** `ULinkerLoad::VerifyImport`: normal non-forgiving non-public export match can be retained; inactive `#if 0` ClassRemap and PackageRemap |
| UT2003 | `L:\Source\Games\UT2003\Unreal Tournament 2003 [v2107] [2002-10-01]\Core` | v120/lic28 | Independently selected v2107 source profile, not copied from Unreal II or UT2004 |
| UT2004 v3186/v3369/v129 | `L:\Source\Games\UT2004` source revisions | v127/lic29, v128/lic29, v129/lic29 (observed compatibility v129/lic27) | UT2004-selected source profile; UE2-owned earlier pre-v100 wire compatibility retained separately |

The Unreal II January 2003 source is **genuine UE2** source: `Core/Inc/UnObjVer.h` has `PACKAGE_FILE_VERSION=126`, `PACKAGE_FILE_VERSION_LICENSEE=0`, `PACKAGE_MIN_VERSION=60`, while `Core/Src/UnLinker.cpp` has a complete `ULinkerLoad::VerifyImport` (SHA-256 `1934e3ef1c4042e33d3af82d68cd593dba7635a173ba8435d3d9aee18fd69a0b`). Several historical spec checkpoints wrongly called this tree UE4 4.0.2 and denied its existence. This is a *documentation error*; UnrealDB had already incorporated the verified January 2003 branch in the 5 November 2026 code review.

## Actual profile coverage from READ ONLY PRD source inventory

| Game ID | Verified file records | Explicit matching game-specific VerifyImport | No exact game-specific VerifyImport |
|---|---:|---:|---:|
| 2 — Unreal II, prior dispatch | 808 | 324 | 484 |
| 2 — Unreal II, after this **code correction and pending two-file restage** | 808 | **326** | **482** |
| 4 — UT2003 | 5,082 | 889 | 4,193 |
| 5 — UT2004 | 72,883 | 65,581 | 7,302 |

Unreal II's 323 original v126/licensee0 files were already source-covered. Two additional older game #2 files are now recognized under the December 2000 v69/min60 loader:
- `Detail.utx` **#149492**, v61/licensee0, 90 Names/5 Imports/51 Exports, original SHA-256 `42c79438778910108f46a40db91f0d1cb177555075da516031d2e46f00a7d837`.
- `ShaneChurch.utx` **#1295446**, v68/licensee0, 158 Names/7 Imports/134 Exports, original SHA-256 `315a191cad1ec6a2f747bd8f1be390f806804d65c2987e842e8ba5d129d9ef98`.

On PRD these two files currently have UEDB5 `source_policy=ue2-warfare-v126-serialization`. DEV corrected `Uedb5Unreal2SnapshotBuilder` and `Uedb5Ue2VerifyImportSourceProfile` to recognize v60–69/licensee0-or-0x7F, while refusing v69/licensee63 and other unsupported branches. DEV's new actual-original, read-only UEDB5 snapshot builds resolve both under `ue2-unreal2-2000-12-09-package-v69`. **This code change does not retroactively change their two PRD UEDB5 containers**.

The largest *remaining* unproven game-specific version/licensee populations are:
- Unreal II v110/licensee2481 (289 files), v110/licensee2609 (142 files), and other non-v69/v126 branches: combined **482** once the two source fixes are republished.
- UT2003 v121/licensee29 (**3,409** files) among **4,193** without its exact v120/licensee28 original-source profile.
- UT2004 v121/licensee29 (**3,855** files), v118/licensee2 (**2,060** files) among **7,302** without an exact v127/128/129 source-verification profile.

**Not a parsing-failure metric:** these files are recorded as verified with serialized package headers and tables. No unsupported version is automatically assigned an unrelated game's static VerifyImport branch. These cases require matching first-party version/game evidence before claiming *full game-release source parity*. A v121 package may be compatible with a newer game loader without proving that its own original production branch used identical handling.

## User-visible impact and actual source operation

The 5 November UE2 v15 common-bypass correction is complete in PRD for all three games: **808/808 Unreal II; 5,082/5,082 UT2003; 72,883/72,883 UT2004**. Their game statistics freshness gates report **CURRENT**, and the UE2 `common` shortcut remains absent.

However, v15 Pass 2 status is not proof that an offline catalogue knows the single **runtime-selected** `SourceLinker`. It is correct to show unresolved when package mount/search context is not known; do **not** label a package objectively missing based on an unmounted candidate set. Independently rank and display **offline-compatible** providers where useful, but never mislabel them source-selected runtime resolves.

The original two-file source-policy mismatch is an actual reader-to-UEDB5 attribution defect, distinct from missing runtime mount order. Its original-byte differential and conditional gate are verified without replaying broad Epic native tests.

## Accepted prior differential and new verification

Reused without duplication:
- `tests/evidence/ue2/ue2-source-conformance-code-review-20261105.json`: exact Unreal II game v69/v126, UT2003, UT2004 source hashes; original game files; source-specific private/public export test; the January 2003 v126 branch already PASS.
- `tests/evidence/ue2/ue2-real-original-metadata-and-dependency-parity-20261102.json`: 10 original files, 1,152 names, 333 imports and 648 exports, source-shaped UEDB5 table comparison.
- `tests/evidence/ue2/ue2-v15-common-bypass-ownership-prd-readonly-20261105.json`: UE2 no-common code-path correction and source isolation.

New:
- `tests/tools/verify_ue2_unreal2_legacy_v60_v69_profile.php`: **15** source/version/engine cases, **8** source-policy/UEDB5 authorization cases, including wrong licensee/wrong game/wrong policy, **PASS**.
- `tests/evidence/ue2/ue2-source-spec-version-coverage-20261106.json`: exact production version counts, SHA-256-pinned original file IDs, corrected source selection and remaining unsupported cohorts.
- `catalog/bin/diagnose-ue2-unreal2-v69-legacy-source.php`: full original SHA-256 and raw UE2 reader check, exact source-policy/diff report, READ ONLY. DEV against PRD via UNC identifies exactly **two** `needs_resnapshot` rows.
- Existing source-specific UE1, UE3, UE4, UE5 reader modules were **not** modified.

## PRD remediation: exactly two files, no full resync

Deployed publisher `catalog/bin/publish-ue2-unreal2-legacy-v69-scoped.ps1` must run **locally on Lelly-pc** and requires explicit `-Apply`. Preview is read-only. It validates unchanged original bytes against pinned SHA-256, restages the **two** source UEDB5 metadata containers with the new source policy (Pass 1), republishes only their dependencies (Pass 2 `--force`), rebuilds game #2 statistics and the small game #2 package-coverage cache, and verifies source and dashboard freshness.

Preview:
```powershell
& "C:\Apache24\htdocs\unrealdb\catalog\bin\publish-ue2-unreal2-legacy-v69-scoped.ps1"
```
Apply on PRD:
```powershell
& "C:\Apache24\htdocs\unrealdb\catalog\bin\publish-ue2-unreal2-legacy-v69-scoped.ps1" -Apply
```

The script is idempotent for already-updated source policies and stops on a hash/identity, Pass 1, Pass 2, statistics or final-freshness failure. **No full file resync, no complete game dependency republish and no UEDB5 backup** is required.

Until the PRD `-Apply` command actually completes, the **new source policy is deployed but two stored snapshots remain old**. Do not report production as fully source-synchronised to the new code merely because the prior v15 dependency publication remains current.
