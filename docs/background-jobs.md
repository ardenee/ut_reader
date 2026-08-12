# Background jobs, workflows and recovery

UnrealDB uses database-backed background workers for work that should not keep an HTTP request/browser open.

The current design is explicitly **recoverable**, not merely asynchronous. A job that can take minutes or hours must either be decomposed into durable independently retryable units, or persist an exact restart cursor/journal where decomposition is not appropriate.

## Core rules

1. A long operation must not depend on the browser remaining open.
2. Restart/recovery preserves the last durable progress/checkpoint state.
3. Successful work is never deliberately replayed just because another unit failed.
4. A natural file/archive-entry/item boundary should become a child job.
5. Coordinators release their worker slot while waiting for children.
6. Child creation is idempotent through `(parent_job_id, workflow_unit_key)`.
7. Routine status/event logging is optional and separate from recovery state.
8. Failed/dead-letter/cancelled child units remain visible to the operator; routine successful children are hidden from the default Background Jobs view.

## Queue schema

`ue_background_jobs` stores the durable queue state.

Important recovery fields include:

- `status`
- `payload_json`
- `progress_json`
- `result_json`
- lease/heartbeat fields
- retry/attempt information
- `resource_class`
- `resource_limit`
- `concurrency_key`
- `parent_job_id`
- `workflow_unit_key`

`parent_job_id` and `workflow_unit_key` identify a workflow unit uniquely. If a coordinator is reclaimed/restarted and attempts to enqueue the same unit again, the existing child is reused rather than duplicated.

## Progress versus recovery state

`progress_json` is used for live progress and, where a handler needs sequential restart state, durable checkpoint information.

Queue recovery paths do **not** erase it:

- normal claim
- automatic retry
- expired-lease recovery
- manual Restart
- bulk Restart
- detached-worker stop/requeue
- manual orphan recovery

`ClaimedJob::resumeProgress` exposes the previous durable checkpoint to the handler.

A handler must not overwrite a good recovery checkpoint with a generic `failed` stage simply to report an exception. The queue stores `last_error`/terminal error state separately.

## Coordinator defer

A parent waiting for child work uses `JobExecutionContext::defer()`.

Defer:

- persists updated parent progress;
- releases the worker lease;
- requeues the parent for a short future check;
- does not consume a failure/retry attempt.

This avoids the old pattern where a coordinator occupied one worker for hours while looping or polling.

## Workflow patterns

### Parent + per-file/entry children

Used when each item is independently meaningful and retryable.

Examples:

- Full Sync reimport files
- Full Sync dependency files
- whole-game dependency rebuild
- affected dependency refresh
- projection reconciliation
- game-wide source-identity repair
- unverified exact-game matching
- cross-game copy preparation
- PAK entries
- Game Backup restore entries
- unverified duplicate hash/delete operations
- unverified-storage reconciliation

A failed child blocks completion of the parent but does not invalidate completed siblings. Restart the failed child; the parent notices when it completes and continues.

### Exact sequential cursor

Used when discovery/order is naturally sequential and recreating thousands of child rows is unnecessary.

Local source scanning uses deterministic normalized source-relative ordering and persists `scan_last_relative_path` after each completed loose package. Container preparation is a separate checkpointed phase.

A recovered source scan can repeat inexpensive discovery but continues **after** the last completed path instead of reimporting earlier files.

### Durable plan/journal

Game Backup export uses an immutable export plan plus completion journal.

On restart it verifies/adopts already copied files and continues from the first incomplete planned entry. It does not delete the partial backup and start over.

### Atomic artifact

Generated download/package output is one artifact unit. The builder writes a temporary file, validates it, then publishes the completed artifact. If that one artifact fails, rebuilding that artifact is acceptable because it is itself the durable work unit; it does not represent thousands of independent catalogue mutations.

## Full Sync

`catalog.full_sync_game` is a coordinator.

The workflow is broadly:

1. plan and execute `catalog.full_sync_file` children;
2. rebuild/reconcile provider projections;
3. plan and execute `catalog.full_sync_dependency_file` children;
4. finalize dependency summaries and cached game statistics.

A successful child remains completed across a restart. If the parent fails only during finalization, Restart returns to finalization rather than starting again at file 1.

This is especially important for failures near 97–100%: the expensive reimport/dependency phases are not replayed solely because final summary/stat publication failed.

## Whole-game dependency rebuild

A game dependency rebuild is a parent workflow that creates one file child per verified file and publishes game statistics at the end.

Per-file dependency failures are independently restartable.

## Affected dependency refresh

When a new provider package becomes available, UnrealDB determines which current files reference that package.

New work is **one affected file per child job**. The child calls the targeted `rebuildForPackages(fileId, [packageName])` path instead of performing an unrelated full dependency rebuild.

After all children complete, the parent bulk-refreshes dependency package summaries and the game's cached counters.

Older queue rows created by the previous 50-file batching implementation are supported by a compatibility path that honors their persisted `done` cursor. New jobs no longer create those multi-file child batches.

Default resource class: `affected-dependency-batch` (the historical class name is retained; it now represents per-file affected/projection units), default limit `2`.

## Projection reconciliation

Provider/projection reconciliation can affect many dependency owners. The parent performs provider/source preparation, then creates one `catalog.reconcile_catalog_projection_file` child per affected owner.

Each child runs the targeted dependency reconciliation. The parent bulk-refreshes summaries and game stats after all units are successful.

## Source identity repair

Game-wide source-identity repair is parent/child work rather than a single game loop.

After source identity file units complete, the workflow invokes the normal resumable game dependency workflow instead of rebuilding the game inline.

## Cross-game dependency copy preparation

The Cross-Game Dependency page submits one lightweight parent job containing selected source file IDs and destination game.

The parent creates one durable source-preparation child per selected verified file. Each child:

1. revalidates the destination's **current** missing dependency evidence;
2. treats already-present/no-longer-needed selections as normal skips;
3. queues the normal destination import if still valid.

An unexpected error affects only that source-preparation child. Destination package imports then continue as their own independent import jobs.

The source package is never moved. A read-only catalog-local source reference is used instead of performing an extra synchronous staging copy/hash before queueing.

## PAK import

PAK import uses a durable workspace under job storage.

The parent:

1. validates/extracts/selects the PAK index;
2. promotes extracted content/state into a durable `jobs/pak-import/job-<parent>` workspace;
3. creates one `catalog.import_staged_pak_entry` child per archive entry;
4. waits for entries;
5. invokes the normal resumable game dependency workflow;
6. finalizes the retained PAK record;
7. cleans the recovery workspace only after final completion.

An entry child uses a disposable working link/copy from its durable extracted source. A package import cannot consume another entry's recovery bytes.

Unsupported/encrypted/non-package entries are recorded as entry outcomes and do not block unrelated entries. Infrastructure/database failures fail that entry child and are restartable.

## Game Backup restore/export

### Restore

Game Backup restore creates durable manifest-entry children. Canonical entries complete before alias entries. After all entries are successful, the parent invokes the normal game dependency workflow.

### Export

Game Backup export uses an immutable plan/completion journal. Every copied output is size/MD5 verified before being journaled. Restart verifies/adopts already copied files and continues.

## Unverified duplicate cleanup

Duplicate cleanup is a two-phase child workflow:

1. only same-size candidates receive independent MD5 hash jobs;
2. persisted hash results are grouped by exact `size + MD5`;
3. one delete child is created for each exact duplicate that is not the chosen keeper;
4. the delete child rechecks size/MD5 immediately before deletion.

Deletion is idempotent. If a worker dies after unlinking the data file but before deleting the note/database record, Restart recognizes the physical file is already absent and completes the remaining metadata cleanup.

Default resource class for hash/delete units: `unverified-file-maintenance`, default limit `2`.

## Unverified storage reconciliation

Filesystem/database reconciliation is one child per unverified queue file. Missing files are normal skips; unexpected indexing failures fail only that child.

## Stale artifact cleanup

Stale artifact maintenance has separate durable child units for:

- generated artifacts;
- incomplete chunk-upload sessions;
- job recovery/storage artifacts.

A failure in one category does not replay the successful categories.

Recovery artifacts are retained while the owning job is restartable (`queued`, `running`, `failed`, `dead_letter`, `cancelled`). Completed historical job rows do not pin incoming/prepared/PAK recovery storage forever; stale completed-job artifacts become eligible for age-based cleanup.

## Upload boundary

Upload transport and background recovery are deliberately separate.

### Upload to Game

For ordinary PHP uploads, the complete received file is first moved into `jobs/incoming` and only then is an import job created.

For chunked uploads, the chunk store must reach `complete` before the package/PAK job is created.

### Upload Bucket

The complete Bucket upload must exist in completed chunk storage before the Bucket processing job is finalized/queued.

### What is resumable

The browser/network upload session itself is **not** promised to survive a lost tab, browser or client state.

After the complete file exists on the server, the background pipeline is recoverable. Durable prepared storage can preserve completed redirect decompression or copy/hash work so a later infrastructure failure does not require retransferring the file or redoing completed preparation.

## Job resource classes

The administrator can change class limits on the Job Resource Limits page. Applying limits updates compatible queued rows immediately while preserving narrower child concurrency keys.

Current classes include:

| Resource class | Default | Typical work |
|---|---:|---|
| `dependency-heavy` | 1 | dependency/projection coordinators, whole-game dependency work |
| `full-sync-unit` | 2 | Full Sync per-file reimport/dependency units |
| `affected-dependency-batch` | 2 | affected dependency and projection per-file units |
| `search-heavy` | 1 | file search-index rebuild |
| `import-heavy` | 8 | normal independent staged package imports |
| `archive-import-heavy` | 1 | PAK/backup coordinators and archive-entry units |
| `bucket-processing` | 8 | Upload Bucket processing / redirect prep / unverified metadata repair |
| `unverified-matches` | 2 | exact unverified game-match projection |
| `unverified-file-maintenance` | 2 | duplicate hash/delete and reconciliation file units |
| `storage-heavy` | 1 | storage/backup coordinators |
| `package-heavy` | 1 | generated download package artifacts |
| `housekeeping` | 2 | cleanup/pruning |
| `default` | 4 | uncategorized bounded jobs |

The global worker-pool size remains a separate upper bound.

## Concurrency keys

A resource-class slot answers **how many** jobs of that class may run. A concurrency key answers **which jobs must not overlap**.

Examples include per-file import/dependency keys, provider/affected file keys, per-parent PAK entry keys and the global projection-maintenance coordinator key.

The resource-limit synchronizer must not replace a per-file child key with an old game-wide coordinator key.

## Retry and restart semantics

`Restart` means **resume/retry the durable unit**, not “clear progress and begin the entire workflow again.”

For parent/child workflows:

- restart the failed child when possible;
- the parent remains waiting;
- completed siblings remain completed;
- once all required children are good, the parent continues automatically.

For exact-cursor/journal operations, Restart uses the persisted cursor/journal.

If a job truly has no trustworthy recovery state from an older code version, the handler may deliberately convert it to the new workflow/compatibility mode; this must be explicit in code rather than silently treating a display percentage as a cursor.

## Logging policy

Durable progress/result storage is not optional logging.

The Job Logging page controls event/diagnostic streams independently. Defaults are errors-first:

| Event stream | Default |
|---|---|
| Errors | ON |
| Progress | OFF |
| Success/completed | OFF |
| Duplicate | OFF |
| Skipped | OFF |
| Cancelled | OFF |
| Worker diagnostics | OFF |

This keeps the operator logs focused on problems that need investigation while preserving job state/progress in the queue database.

Terminal background-job failures are also recorded in System Errors. System Errors can be filtered and exported as a Markdown diagnostic report with available stack trace/context information; secret-like context values are redacted.

## Background Jobs operator view

The default Background Jobs view shows top-level workflows plus failed/dead-letter/cancelled child units that require attention. It does not list thousands of successful workflow children by default.

Child rows preserve useful unit identity such as:

- affected file ID/provider package;
- PAK/entry number;
- cross-game source file;
- unverified queue filename;
- cleanup category.

## Deploying workflow changes

Stop/restart detached workers when deploying changes that add job types or handler semantics so old PHP processes do not continue executing the pre-deploy handler graph.

Apply migrations before starting the new workers:

```bash
php catalog/bin/migrate.php migrate
```

The current recovery/logging model requires `202608120001_job_workflow_recovery_logging.php`. Unverified exact-game-match caching requires `202608110001_unverified_game_match_cache.php`.

Run the architectural regression gate after deployment:

```bash
php catalog/bin/verify-resumable-job-workflows.php --database
```

This is read-only. It checks queue recovery boundaries, parent/child registration, workflow decomposition, upload handoff rules, artifact retention and logging/schema prerequisites.
