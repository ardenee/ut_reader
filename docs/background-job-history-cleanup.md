# Background Job history cleanup

Background Jobs history can contain large numbers of completed, failed, dead-letter and cancelled rows. Cleanup is therefore background work itself; the browser must not delete thousands of rows and retained staged files synchronously.

## Single-row delete

Deleting one terminal job remains an immediate bounded administrator action.

## Bulk Delete and Clean old jobs

Bulk Delete and retention cleanup use `catalog.clean_background_job_history`.

The HTTP request only:

1. resolves the same visible/filter scope used by the Background Jobs page;
2. snapshots up to 10,000 currently eligible terminal job IDs;
3. enqueues one durable cleanup job;
4. wakes the worker and returns immediately.

The worker processes at most 200 snapshot IDs per claim and persists `snapshot_offset`. It then defers itself so other queue work can run. A worker/server restart continues after the last processed snapshot slice rather than starting the cleanup from ID 1.

The snapshot is immutable. Jobs that become terminal after the request are not silently added to the running cleanup operation.

If more than 10,000 rows match, the cleanup result reports the snapshot limit and the administrator can run cleanup again after the first job completes.

## Parent/child rows

Bulk mutation uses the same workflow-child visibility scope as the Background Jobs read model. Therefore **Select all matching** does not unexpectedly mutate routine successful child jobs that were hidden from the operator view.

Deleting a terminal workflow parent may cascade its historical child rows through the `ue_background_jobs.parent_job_id` foreign key. This occurs in the background cleanup transaction, not in the browser request.

## Retained staged files

Before deleting each terminal job row, `CatalogBackgroundJobCleanup` checks its payload for owned staged sources and removes those files where appropriate. Read-only catalog-local/local-PAK references are never treated as owned staged files.

Actual deleted/skipped/staged-file counts are reported by the cleanup job. The Background Jobs page reports the cleanup as **queued** instead of claiming the rows were already removed when the HTTP request returns.

## Empty cleanup

If no terminal rows match a retention cleanup, no no-op background job is created.

## Verification

Run:

```bash
php catalog/bin/verify-job-history-cleanup-contract.php
```

The contract verifies the bounded 200-ID worker batches, immutable 10,000-ID snapshot, worker registration, asynchronous API boundary, current Background Jobs scripts and the affected-dependency resource rekey guard.
