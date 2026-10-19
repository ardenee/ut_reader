# Unreal Engine 5.8.3 compliance — verified scope and certification status

**Checkpoint:** 19 October 2026. **Status: NOT YET CERTIFIED.**

This is the single current entry point for UE5.8.3 status. The strict Epic-source verifier, real-file integrations and source-to-production workflow audit are deliberately separate acceptance gates. A passing component test is not full default-engine parity. UE4.27.2 and UE1–UE4 game profiles are separate future work.

## Source and real-file evidence

- **18/18 original registered operations:** rerun 19 October 2026 exit 0, 41 source hashes, 91 symbol checks, 312 contract assertions, 120 reachability assertions, zero issues; `catalog/bin/verify-source-compliance.php --strict` using pinned official `L:/Source/Engine/UE5/UE 5.8.3`. This is the *registered operation subset*, not an exhaustive source symbol/conditional branch denominator.
- **Real Satisfactory Zen/IoStore:** independent native Epic UnrealPak -List comparison matches **27,240/27,240 logical package names**; and independent **source-SHA-pinned Epic IoStore extraction** matches **27,240/27,240 reconstructed Zen package SHA-256 and lengths** (1,398,535,898 bytes), zero discrepancies. See [native byte comparison](UE583-EPIC-IOSTORE-CONTENT-DIFFERENTIAL-20261019.md); see [Epic Zen name differential](UE583-EPIC-NATIVE-ZEN-NAMES-20261019.md). Also 27,240/27,240 persisted/published V5 metadata records, dependency Pass 2 results and validation, zero failures, against DEV *isolated* MySQL port 3339 (not production).
- **Real modern PAK v11:** 4,761/4,761 Satisfactory members decoded, 212,783,532 bytes; five pinned hash samples, including Oodle. Official Epic UE5.8.3 UnrealPak independently matched **4,761/4,761 entry name/offset/stored-size/compression records and 4,761/4,761 decoded SHA-256+size**, totaling 212,783,532 decoded bytes (2,978 stored without compression and 1,783 Oodle). See [independent Epic differential](UE583-PAK-EPIC-INDEPENDENT-DIFFERENTIAL-20261019.md).
- **Five IoStore fixture tests:** package inspection, SQL rollback removal, tampered backup rejection, disposable missing-derived-member restoration, and real PHP multipart upload with durable import job; all passed.
- **Durable backup/restore:** queued backup and restore of a genuine ready `global.utoc` container passed; the separate 27,240-member service backup/restore passed earlier.
- **Metadata search:** real Satisfactory V5 exact name/export object and negative cases passed. The current `verify-search-descendant-prefix-contract.php` has been updated to verify the active V5 compact-candidate pipeline, actual V5 roundtrip, and isolated SQL-backed nested-path search: 12/12 checks passed. Exhaustive scan of 27,240 V5 snapshots found **zero nested export paths and zero relative nested import paths**. Synthetic UE5 V5 roundtrip and SQL-backed temporary-table candidate discovery with six descendant-path checks passed. Synthetic search is a functional test, *not* independently cooked Epic fixture proof.
- **Genuine classic UE5 inputs:** [pinned engine .uasset sample](UE583-REAL-CLASSIC-ENGINE-FIXTURES-20261019.md) accepted 475 UE5 classic assets spanning ten UE5 version values with no unexplained parser issues; 25 pre-UE5-summary legacy assets explicitly rejected by the dedicated UE5 reader. This is input coverage, **not yet a native field-by-field Epic comparison**.
- **Archive/member workflows:** ingress, file-content classification, nesting/retention and retries pass targeted regressions. The `JobFailureRetryPolicy` patch prevents futile automatic retries after immutable nested depth-limit errors without misreporting source corruption.

## Actual production environments

DEV: `game-amd`, code `C:/Users/arden/source/repos/ut_reader`. PRD: `Lelly-pc`, checkout `C:/Apache24/htdocs/unrealdb`. DEV and PRD application IoStore schema migrations `202610150001` and `202610150002` were applied and verified. They hold independent datasets. All large UE5 Satisfactory publication tests operate in separate DEV isolation. No production Satisfactory import has been authorized or performed.

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
