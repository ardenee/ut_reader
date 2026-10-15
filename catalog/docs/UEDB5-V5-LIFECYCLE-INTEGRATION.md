# UEDB5 V5-only lifecycle integration checkpoint

Updated 2026-10-15. Branch: `main`, baseline `b5e4e8cc`.

## Verified with actual code

| Path | Test | Evidence / limits |
| --- | --- | --- |
| Fresh source parse → new V5 metadata → compact SQL | `catalog/bin/verify-uedb5-source-sql-publisher-isolated.php` | Seven real verified game packages passed source parse, V5 registration inspection, compact publish and rollback on 2026-10-15. Source read-only; disposable V5 files; SQL session-temporary. |
| V5 dependency resolution and provider arbitration | `catalog/bin/diagnose-uedb5-full-provider-parity-isolated.php` | Seven bounded game samples matched stored import outcomes/reasons. Only Unreal II sample included selected external physical providers. |
| Dependency SQL projection | `catalog/bin/verify-uedb5-dependency-sql-isolated.php` | Actual publisher exercised against temporary SQL projection tables. |
| Worker claim and terminal transitions | `catalog/bin/verify-uedb5-worker-claim-lifecycle-isolated.php` | Actual `JobWorker` and dependency handler; isolated in-memory queue, NOT durable MySQL. |
| Repair workflow affinity | `catalog/bin/verify-uedb5-repair-workflow-isolated.php` | Real worker releases affinity on failed/cancelled/dead-letter child and retains it for queued child. |
| Reimport: missing source, stale ID, verified relational rollback | `catalog/bin/verify-uedb5-reimport-safety-isolated.php` and `catalog/bin/verify-uedb5-reimport-rollback-isolated.php` | Actual service/support code; SQLite only. Does NOT execute successful scanner replacement. |
| Removal: success, precommit rollback, postcommit failure, transaction ownership | `catalog/bin/verify-uedb5-removal-isolated.php` | Actual removal service on disposable local files and isolated SQLite fixtures. Does NOT exercise non-deferred successful dependency reconciliation. |
| Bounded integrity | `catalog/bin/verify-uedb5-post-cutover-bounded.php` | Production inspection, no package rescan. |

## End-to-end gaps (do not mark complete)

1. **Complete successful scanner-driven reimport**: fresh parser → stable `ue_files` ID → compact metadata → provider and dependency SQL projections → downstream affected-file refresh.
2. **Upload and backup restore**: normal, duplicate, fault and rollback paths against complete disposable storage and database.
3. **Durable real-MySQL job lifecycle**: `PdoJobQueue` enqueue → claim with admission/resource/root locks → heartbeat → handler → dependency publication → persisted completion/retry.
4. **Non-deferred deletion**: successful affected dependency refresh/reconciliation and post-commit failures with durable retry.
5. **Other-game external-provider parity**: the six non-Unreal-II fixtures exercise no selected external provider.
6. **Final cutover cleanup**: identify remaining historical V4-only schema/code and live readers/writers before dropping any old tables; reconcile PRD/DEV/GitHub and update specifications.

## Hard isolation blocker

The PRD PHP database account connects to `hwmvlite_ut_reader_catalog` (MySQL 8.4.11), has no visible test/sandbox database, and no database-creation grant. It has privileges on the **live** schema only.

MySQL session-temporary tables can support source/SQL publisher tests but **cannot** support the production `PdoJobClaimer` because its self-joins produce `Can't reopen table`. Do not replace this with persistent test tables in production.

**Required**: provision a completely independent disposable MySQL database/instance with equivalent schema and ordinary tables, plus disposable package/metadata storage and queue workers pointing exclusively to it. Verify its connection identity and paths before any destructive tests. A containerized local MySQL instance is acceptable if it is independently provisioned and isolated.

## Operating rules

- Do not reparse/rescan 235,239 packages; use bounded known-good samples.
- No production queue jobs, persistent SQL writes, storage rename/unlink or backup-restore changes for tests.
- Preserve Epic-source parity and never enable V4 fallbacks or silent unsupported-version gates.
- Report **which** layer passed: source parser, compact publisher, worker with fake queue, actual durable queue, or full user lifecycle.
- When finishing an integration gap, update this checklist with fixture identity, exact command, outcome and any limits. Avoid rerunning unchanged broad audits.
