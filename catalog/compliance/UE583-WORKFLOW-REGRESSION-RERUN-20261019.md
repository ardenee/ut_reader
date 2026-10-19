# UE5.8.3 workflow acceptance regression rerun — 2026-10-19

Environment: DEV game-amd at Git HEAD `bbaac6f1`; PHP CLI in `C:/Users/arden/source/repos/ut_reader`; pinned Epic root `L:/Source/Engine/UE5/UE 5.8.3`. Tests executed serially to prevent fixture contamination; disposable or isolated backing data only, not production SQL 3306. **18/18 executable drivers EXIT 0**.

| Driver | Exit |
|---|---|
| verify-archive-ingestion.php | 0 |
| verify-archive-member-content-routing.php | 0 |
| verify-archive-parent-lifecycle.php | 0 |
| verify-archive-source-retention-contract.php | 0 |
| verify-retained-archive-member-source-recovery.php | 0 |
| verify-profiled-upload-ingress-isolation.php | 0 |
| verify-upload-worker-contracts.php | 0 |
| verify-job-workflow-integration-contract.php | 0 |
| verify-ingress-workflow-isolation.php | 0 |
| verify-uedb5-repair-workflow-isolated.php | 0 |
| verify-uedb5-staged-repair-contract.php | 0 |
| verify-iostore-backup-jobs-standalone.php | 0 |
| verify-iostore-worker-guards-standalone.php | 0 |
| verify-search-descendant-prefix-contract.php | 0 |
| verify-uedb5-ue5-classic-persistence.php | 0 |
| verify-resumable-job-workflows.php | 0 |
| verify-full-sync-workflow.php | 0 |
| verify-ue5-583-workflow-evidence-links.php | 0 |

Previously retained independent Epic IoStore and modern PAK byte differentials are **not rerun**. This suite verifies targeted production routes and test evidence integrity only. **All 16 `RECOVERY.json.required_workflow_paths` remain `mapped`, NOT `accepted`** until each mapped dispatch, real persistence/recovery, denied/error branch and Epic-source behavior are demonstrated. Passing this suite must not be used to overwrite that status. Five source-denominator units likewise remain partial.

The S001 raw-vs-effective `RF_Load` semantic correction was applied to production before these tests; the dedicated synthetic mutation case and 119 classic V5 roundtrips passed separately at `bbaac6f1`.
