# Background Job history cleanup

Background Jobs history can contain large numbers of completed workflow rows and retained staged sources. Cleanup is therefore background work itself; the browser only queues the operation.

## Retention cleanup

The Maintenance control on \`background-jobs.php\` removes **resolved completed/stopped history older than the selected cutoff**.

Automatic retention deliberately keeps unresolved failed, dead-letter, rejected, unverified, partial and error roots for operator review.

The HTTP request:

1. calculates one fixed UTC cutoff;
2. snapshots at most 10,000 eligible top-level roots;
3. enqueues \`catalog.clean_background_job_history\`;
4. wakes the worker and returns immediately.

The 10,000-root value is now a **per-snapshot bound, not a total cleanup limit**. When a retention snapshot finishes, the same cleanup job requests the next bounded snapshot using the original cutoff. It continues until no eligible roots remain. Jobs becoming newer than that fixed cutoff are never swept into the same maintenance operation.

## Parent/child workflow rows

Large workflow trees are drained leaf-first in bounded batches so one root deletion cannot trigger a huge unobservable foreign-key cascade.

Every hidden child row now goes through \`CatalogBackgroundJobCleanup\` before deletion. This means child event logs and owned \`staged_path\` sources are not skipped simply because the row was hidden from the operator view.

## Staged-source safety

For every deleted row, cleanup collects its owned \`staged_path\`.

Before deleting that source, the database is checked for surviving jobs that can still need it:

- queued;
- running;
- failed;
- dead-letter;
- cancelled/retryable;
- completed jobs explicitly marked \`source_retained=true\`.

If any such job still references the staged source, the file is retained.

\`local-pak:\` and \`local-catalog:\` references are read-only references and are never deleted by history cleanup.

Chunked uploads and normal incoming staged files both report the bytes actually reclaimed.

## Reporting

The cleanup job reports:

- root jobs deleted;
- hidden workflow rows deleted;
- jobs skipped;
- staged sources deleted;
- staged bytes reclaimed;
- snapshot batch number.

The active Background Jobs page reports cleanup as queued work rather than claiming rows were removed in the browser request.

Deleting MySQL rows does not normally reduce the physical InnoDB file size on disk; MySQL reuses that freed space internally. Filesystem free space should increase when owned staged/job files are actually removed.

## Verification

Run:

\`\`\`bash
php catalog/bin/verify-job-history-cleanup-contract.php
\`\`\`

The contract verifies bounded resumable workflow deletion, automatic retention continuation, staged-source reference protection, reclaimed-byte reporting, active UI wiring, worker-code reload coverage and PHP syntax.
