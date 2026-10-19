# Unreal Engine 5.8.3 compliance — verified scope and certification status

**Checkpoint:** 19 October 2026. **Status: NOT YET CERTIFIED.**

This is the single current entry point for UE5.8.3 status. The strict Epic-source verifier, real-file integrations and source-to-production workflow audit are deliberately separate acceptance gates. A passing component test is not full default-engine parity. UE4.27.2 and UE1–UE4 game profiles are separate future work.

## Current reconciliation checkpoint (19 October 2026)

- Fixed P01–P11 primary sequence reviewed; P01 independently verified on **140 Epic native classic package summaries / 5,279 decoded fields, zero mismatches**, and P02 on **140 native name/import/export tables / 44,840 fields**, plus **140 native DependsMap packages / 2,832 export rows / 9,069 exact dependency reference indices**, zero mismatches ([P02 native identity evidence](UE583-P02-EPIC-NATIVE-DEPENDS-IDENTITIES-20261019.md)). All 11 registered source units now have verified evidence within their explicitly bounded metadata/provider scopes; editor optional native provider 7/7 and B004/B012/source arbitration drivers passed. P07 native non-editor file-package-store mount/recovery is now independently verified (70 comparisons). The P03 header wire format is now verified within stated scope.
- **S001 resolved in production:** classic FObjectExport `RF_Load` effective bitmask `0xA2D4083B` with raw serialized flags separately retained. Positive/negative mutated UE5 engine asset regression and 119 real classic V5 roundtrips passed; deployed DEV/PRD as `bbaac6f1`.
- **Current source denominator:** **11 verified / 0 partial**, 33 Epic source hashes, 42 symbol checks, 24 implementation file checks. The denominator integrity and certification checks are rerun after this update. **16/16 UE5.8.3 production workflows have been formally accepted** (identify, read_decode, extract_import_export, discover_dependencies, select_physical_providers, resolve_object_references, stage_v5, dependency_pass2, persist_sql_projections, metadata_query_search, archive_import, backup_restore, resync, repair, validation, upload), and overall UE5.8.3 certification remains open.
- **Current post-correction strict verifier:** 18/18 registered operations, 41 Epic source hashes, 91 symbols, 312 contracts and 120 reachability assertions, zero issues. Implementation pins refreshed after verified P03/P04 changes; see [Zen pin reconciliation](UE583-ZEN-PIN-REFRESH-20261019.md). Recovery negative regression suite now passes with its D007-specific test corrected.
- **P08 TOC wire-format source closure:** [360,091 genuine v8 native fields](UE583-EPIC-NATIVE-TOC-FIELDS-20261019.md) plus [11/11 positive and 5/5 negative native Epic TOC variant checks](UE583-EPIC-NATIVE-TOC-VERSION-MATRIX-20261019.md), zero mismatches; SRC-008 now verified within metadata TOC wire scope, separate from cryptographic signature policy and live mounts.
- **P03/P04 independent Zen differential:** 100 real Satisfactory packages / **6,546 native decoded fields**, zero mismatches, with a versioned synthetic native comparison (38 fields) and distinct optional container provider IDs (12 native fields) also passed. The editor-only optional provider native comparison passed 7/7; broader UObject execution is not claimed. See [native Zen field oracle](UE583-EPIC-NATIVE-ZEN-HEADER-FIELDS-20261019.md).
- **Current workflow regression:** [18/18 bounded executable checks passed](UE583-WORKFLOW-REGRESSION-RERUN-20261019.md) at `bbaac6f1`. **16/16 production workflow acceptance statuses are formally closed**; tests and dispatch maps alone do not constitute full integration acceptance.
- Primary audit and latest results are in [UE583-PRIMARY-PLAN.md](UE583-PRIMARY-PLAN.md) and [P08–P11 review](UE583-P08-P11-IOSTORE-PAK-PRIMARY-REVIEW.md). UE4.27.2 stays blocked.

## Source and real-file evidence

- **18/18 original registered operations:** rerun 19 October 2026 exit 0, 41 source hashes, 91 symbol checks, 312 contract assertions, 120 reachability assertions, zero issues; `catalog/bin/verify-source-compliance.php --strict` using pinned official `L:/Source/Engine/UE5/UE 5.8.3`. This is the *registered operation subset*, not an exhaustive source symbol/conditional branch denominator.
- **Real Satisfactory Zen/IoStore:** independent native Epic UnrealPak -List comparison matches **27,240/27,240 logical package names**; and independent **source-SHA-pinned Epic IoStore extraction** matches **27,240/27,240 reconstructed Zen package SHA-256 and lengths** (1,398,535,898 bytes), zero discrepancies. See [native byte comparison](UE583-EPIC-IOSTORE-CONTENT-DIFFERENTIAL-20261019.md); see [Epic Zen name differential](UE583-EPIC-NATIVE-ZEN-NAMES-20261019.md). Also 27,240/27,240 persisted/published V5 metadata records, dependency Pass 2 results and validation, zero failures, against DEV *isolated* MySQL port 3339 (not production).
- **Real modern PAK v11:** 4,761/4,761 Satisfactory members decoded, 212,783,532 bytes; five pinned hash samples, including Oodle. Official Epic UE5.8.3 UnrealPak independently matched **4,761/4,761 entry name/offset/stored-size/compression records and 4,761/4,761 decoded SHA-256+size**, totaling 212,783,532 decoded bytes (2,978 stored without compression and 1,783 Oodle). See [independent Epic differential](UE583-PAK-EPIC-INDEPENDENT-DIFFERENTIAL-20261019.md).
- **Five IoStore fixture tests:** package inspection, SQL rollback removal, tampered backup rejection, disposable missing-derived-member restoration, and real PHP multipart upload with durable import job; all passed.
- **Durable backup/restore:** queued backup and restore of a genuine ready `global.utoc` container passed; the separate 27,240-member service backup/restore passed earlier.
- **Metadata search:** real Satisfactory V5 exact name/export object and negative cases passed. The current `verify-search-descendant-prefix-contract.php` has been updated to verify the active V5 compact-candidate pipeline, actual V5 roundtrip, and isolated SQL-backed nested-path search: 12/12 checks passed. Exhaustive scan of 27,240 V5 snapshots found **zero nested export paths and zero relative nested import paths**. Synthetic UE5 V5 roundtrip and SQL-backed temporary-table candidate discovery with six descendant-path checks passed. Synthetic search is a functional test, *not* independently cooked Epic fixture proof.
- **Genuine classic UE5 inputs:** [pinned engine .uasset sample](UE583-REAL-CLASSIC-ENGINE-FIXTURES-20261019.md) accepted 475 UE5 classic assets spanning ten UE5 version values with no unexplained parser issues; 25 pre-UE5-summary legacy assets explicitly rejected by the dedicated UE5 reader. This is input coverage, **not yet a native field-by-field Epic comparison**. Additionally, [real classic V5 staging](UE583-REAL-CLASSIC-V5-ROUNDTRIP-20261019.md) passes 119 genuine UE5 assets from the reader through V5 builder, file write, and staging read, across versions 1004, 1006, 1007, 1009 and 1018, with 21 older assets separately excluded.
- **Archive/member workflows:** ingress, file-content classification, nesting/retention and retries pass targeted regressions. The `JobFailureRetryPolicy` patch prevents futile automatic retries after immutable nested depth-limit errors without misreporting source corruption.

## Latest full-file validation rerun

[Retained Satisfactory verification, 19 October 2026](UE583-VALIDATION-RERUN-20261019.md): all 27,240 existing V5/Pass-2 files passed fresh read-only isolated validation, zero failures; the strict Epic-source verifier also passed all 18 registered operations again. These remain separate from exhaustive certification.

## Latest workflow regression evidence

[The 19 October UE5 workflow regression checkpoint](UE583-WORKFLOW-REGRESSION-20261019.md) verified 13/13 archive, upload, repair, backup, search and V5 persistence drivers on DEV. These are passing targeted tests, **not** an automatic upgrade of all workflow rows from `mapped` to `accepted`.

## Actual production environments

DEV: `game-amd`, code `C:/Users/arden/source/repos/ut_reader`. PRD: `Lelly-pc`, checkout `C:/Apache24/htdocs/unrealdb`. DEV and PRD application IoStore schema migrations `202610150001` and `202610150002` were applied and verified. They hold independent datasets. All large UE5 Satisfactory publication tests operate in separate DEV isolation. No production Satisfactory import has been authorized or performed.

## Source-denominator and D035 checkpoint (19 October 2026)

- The bounded source manifest [`UE583-SOURCE-DENOMINATOR.json`](UE583-SOURCE-DENOMINATOR.json) defines **11 format-behavior units** with explicit pinned Epic source, UnrealDB implementation, branch families and evidence. The executable `catalog/bin/verify-ue5-583-source-denominator.php` passed **33 source hashes, 42 source symbols, 24 implementation files and 21 evidence files**, with no integrity issues. The units were **5 verified / 6 partial at that historical checkpoint**, updated above to 6 verified / 5 partial. An accounted unit is not necessarily semantically verified. The list is a controlled format-reader coverage model, not a claim to enumerate every Unreal Engine function.
- `RECOVERY.json` and `DIFFERENCES.md` now reciprocally include **UE5-D032 through UE5-D035** and retain open coverage gates for their outstanding independent semantics. D035 removed the extra terminal TOC EOF rejection not present in Epic `FIoStoreTocResourceView::Read` / `FinalizeRead`; the new assertion failed on the old reader and passed after correction, with the full foundation fixture **34/34 at that earlier checkpoint**, subsequently extended to 37 foundation assertions.
- The affected B001/B002/B006/B007/B008/B015/B016 acceptance drivers passed, followed by a full strict Epic-source verification (**18/18**, 41 hashes, 91 symbols, 312 contracts, 120 reachability assertions, zero issues). The recovery integrity verifier passes but reports **16/16 production workflows still unaccepted** and global scope incomplete.
- Commit [`30dea3dd`](https://github.com/ardenee/ut_reader/commit/30dea3dd) was pushed to `main` and fast-forwarded to PRD; PRD has no modified tracked files. DEV's separate audit checkout is at the same commit. The older DEV live application checkout under `D:/Apache24/htdocs/unrealdb` has not been forcibly replaced.

## What blocks full UE5.8.3 certification

1. **Finite Epic UE5 source denominator:** complete relevant public classic and Zen parser/IoStore/package-store branch inventory and explicitly document out-of-scope encrypted/private/unsupported layouts.
2. **Complete production consumer denominator:** verify all 16 named `RECOVERY.json.required_workflow_paths` from actual dispatch entrypoints to readers, V5 publication/dependency providers, persisted results and queries, including failures, retries and recovery. All 16 routes now have **UE5-subset `mapped` entries** in the recovery ledger, but **0 are accepted**; this mapping is not full end-to-end proof.
3. **Independent differential:** compare Epic-produced output semantics against UnrealDB for genuinely supported classic and Zen fixtures and selected negative/boundary cases, rather than using synthetic data as proof of Epic behaviour.
4. **Final integration and release checks:** close or explicitly exclude each applicable branch; run the full current suite; reconcile the difference register; record evidence and only then issue a UE5-only certification decision.

**Do not claim certification by relabelling the original 18 operations or the 16 workflows.** Read `catalog/compliance/RECOVERY.json` for formal outstanding gates, and `catalog/compliance/UE583-WORKFLOW-REACHABILITY-20261019.md` for the bounded production dispatch map. That earlier document includes superseded pre-migration comments; the DEV/PRD schema parity document is authoritative for the later migration result.

## Reproducible acceptance drivers

`catalog/bin/verify-source-compliance.php --strict`,
`catalog/bin/verify-ue5-583-satisfactory-full-isolated.php`,
`catalog/bin/verify-ue5-583-pak11-real-satisfactory.php`,
`catalog/bin/verify-ue5-583-real-zen-compact-search.php`,
`catalog/bin/verify-ue5-583-satisfactory-nested-path-inventory.php`,
`catalog/bin/verify-ue5-583-synthetic-nested-search.php`,
`catalog/bin/verify-ue5-583-synthetic-nested-sql-search.php`,
`catalog/bin/verify-archive-member-content-routing.php`,
`catalog/bin/verify-ue5-583-nested-archive-retry.php`,
`catalog/bin/verify-iostore-backup-jobs-standalone.php`.

Isolated standalones may intentionally mutate or inspect disposable test data. Read their source and verify MySQL port/datadir and fixture prerequisites before replay. Never redirect these at application MySQL on port 3306.
