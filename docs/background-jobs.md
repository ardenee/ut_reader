# Durable background jobs

UnrealDB uses `ue_background_jobs` as a durable MySQL queue for maintenance work. The web application enqueues work; CLI workers claim one leased job at a time.

## States

- `queued`: available now or scheduled through `available_at`
- `running`: owned by one worker and one opaque lease token
- `completed`: result persisted and active deduplication key released
- `cancelled`: cancelled before claim or cooperatively stopped by the lease owner
- `dead_letter`: exhausted retries, unsupported job type, or an expired final attempt
- `failed`: legacy terminal state retained for upgrade compatibility; operators may retry it like a dead letter

## Lease ownership

Every claim records a worker ID, random lease token, lease start, expiry and heartbeat time. Completion, failure, cancellation and progress updates require the same lease token. A worker whose lease expired cannot overwrite a job claimed by another worker.

The default lease is 120 seconds and is configured with `queue.lease_seconds` or `UNREALDB_QUEUE_LEASE_SECONDS`. Handler checkpoints renew the lease approximately every third of that interval, bounded between 5 and 30 seconds.

Retries and recovered leases clear all former ownership fields before returning to `queued`.

## Resource classes and concurrency keys

Every queued job persists three scheduling fields:

- `resource_class`: the shared capacity pool
- `resource_limit`: maximum running jobs in that class for the queue
- `concurrency_key`: optional target lock preventing two jobs for the same game/file from running together

Current defaults:

| Job type | Resource class | Default limit | Target key |
| --- | --- | ---: | --- |
| game dependency rebuild | `dependency-heavy` | 1 | `dependency:game:<id>` |
| exact file dependency rebuild | `dependency-heavy` | 1 | `dependency:file:<id>` |
| affected-dependants dependency rebuild | `dependency-heavy` | 1 | `dependency:file:<id>` |
| file source-identity repair | `dependency-heavy` | 1 | `source-identity:file:<id>` |
| game source-identity repair | `dependency-heavy` | 1 | `source-identity:game:<id>` |
| unverified duplicate cleanup | `storage-heavy` | 1 | `unverified-duplicate-cleanup` |
| generated package build | `package-heavy` | 1 | `package:file:<id>` |
| upload-progress pruning | `housekeeping` | 2 | none |
| unknown/future type | `default` | 4 | none |

Limits may be overridden before enqueueing with:

```text
UNREALDB_JOB_RESOURCE_LIMIT_DEPENDENCY_HEAVY=1
UNREALDB_JOB_RESOURCE_LIMIT_STORAGE_HEAVY=1
UNREALDB_JOB_RESOURCE_LIMIT_PACKAGE_HEAVY=1
UNREALDB_JOB_RESOURCE_LIMIT_HOUSEKEEPING=2
UNREALDB_JOB_RESOURCE_LIMIT_DEFAULT=4
```

The resolved limit is stored on the job, so changing an environment variable affects newly queued work only. Claim selection skips saturated classes, allowing unrelated eligible work to continue. A short MySQL advisory lock serializes claim decisions so competing workers cannot overbook a class.

## Dependency refresh jobs

The administrator Dependency Refresh page enqueues one durable job instead of rebuilding each file through a separate browser request.

- A **single file** refresh rebuilds only that file's compact dependency links and derived package-summary rows.
- A **full game** refresh processes verified files in package order and retains the optional start offset.
- An **affected-dependants** refresh remains a separate internal/operator action for files whose package identity may resolve imports in other files.

The page polls `job-status.php` by job ID, displays persisted worker progress and final dependency totals, supports cooperative cancellation, and stores the active job ID in the page URL. Reloading or reopening that URL resumes the progress dialog. Closing the page does not stop the worker.

## Source identity repair jobs

The administrator Source Identity Repair page keeps its mismatch audit synchronous because the audit is read-only. Mutating repair operations are durable jobs.

- A **file repair** derives the canonical UE4/UE5 package identity from the primary mounted source path, updates the original filename and source path, rewrites export full paths in compact metadata, rebuilds current lookup projections and source-derived aliases, and refreshes the file plus referring dependency projections.
- A **game repair** processes every verified file in package order without rebuilding dependencies per file, collects bounded failure details, and performs one game-wide dependency pass after all successful identity changes.
- UE1/UE2/UE3 remain audit-only. The enqueue API rejects legacy-engine repair targets.

Both repair types share the exclusive `dependency-heavy` class with dependency rebuilds. The source-identity worker also retains the legacy database advisory lock so older maintenance code cannot overlap the same write boundary during a staged deployment.

The page stores the active job ID in the URL, resumes polling after reload, reports changed identities, retained aliases and failures, and supports cooperative cancellation. Closing the page does not interrupt repair work.

The former `source-identity-repair-api.php` endpoint remains only as a compatibility enqueue adapter. It no longer writes progress files or executes identity/dependency mutation inside HTTP requests.

## Unverified duplicate cleanup

The **Delete duplicate files** control on `unverified-files.php` now enqueues one `storage-heavy` job.

The worker inventories all physical Upload Bucket and game unverified queues, groups by size, calculates MD5 only for same-size candidates, and retains one copy of each exact size+MD5 identity. An indexed copy is preferred; otherwise the oldest queue copy is retained. Verified game storage is never included.

Progress reports inventory, hash candidates, exact duplicate groups, deleted files, freed bytes and errors. Detailed deletion output is bounded to 100 entries and detailed errors to 200 entries in the durable result.

Cancellation is cooperative. Files deleted before a cancellation checkpoint remain deleted, while unprocessed duplicate candidates remain in place. Every file is rechecked for its expected size and MD5 immediately before deletion.

Operator enqueue command:

```text
php catalog/bin/job-control.php enqueue-clean-unverified-duplicates
```

## Generated package jobs

`download-package.php` no longer builds ZIP, UMOD-family or PAK output inside Apache. It queues `catalog.generate_mod_package`, polls persisted progress and exposes the completed artifact through a separate download controller.

Package jobs:

1. revalidate the public download mode, selected verified file and enabled format
2. resolve the dependency closure and base-game exclusions
3. enforce configured file/byte limits and incomplete-dependency policy
4. build into a unique `.part` file
5. run the existing format validator
6. check cancellation and lease ownership before publication
7. atomically rename the validated artifact into `storage/generated-packages`
8. persist only the artifact identity, size, SHA-256, filename and expiry in the job result

The initiating browser session stores the random access token; only that session can poll or download the artifact. The token itself is not stored in job payloads—only its SHA-256 hash. Artifact filenames are not public authorization credentials.

Public enqueueing is IP-rate-limited. Defaults:

```text
UNREALDB_PACKAGE_GENERATION_MAX_REQUESTS=3
UNREALDB_PACKAGE_GENERATION_WINDOW_SECONDS=600
UNREALDB_GENERATED_PACKAGE_RETENTION_SECONDS=86400
```

The default artifact lifetime is 24 hours, configurable between 15 minutes and seven days. Expired artifacts and abandoned `.part` files are pruned by subsequent package jobs; an expired download request also deletes the artifact when present.

Archive writers are atomic but not forcibly interrupted mid-file. A cancellation requested during archive writing is observed before publication, and the completed temporary output is deleted rather than made downloadable. A worker crash may leave a `.part` file, which is removed by the orphan-pruning policy.

## Progress

Progress callbacks from maintenance handlers are persisted in `progress_json` with `progress_updated_at`. Progress is an operational snapshot, not the durable result. A successful completion stores the final result separately in `result_json`.

The job status API supports a positive `job_id` filter and decodes both progress and result objects for authenticated administrators. General multi-job listings omit result payloads so operator pages remain bounded. Public generated-package status uses a separate session-bound endpoint and never exposes arbitrary queue records.

## Cancellation

Queued jobs are cancelled immediately. Running jobs receive `cancel_requested_at`, `cancel_requested_by` and `cancel_reason`. The current lease owner observes the request at its next checkpoint and transitions the job to `cancelled`.

A cancelled request does not forcibly terminate PHP in the middle of a database or filesystem operation. Handlers call the execution context at safe boundaries. If a worker disappears after cancellation is requested, expired-lease recovery finalizes the job as cancelled.

## Dead letters and retries

A normal exception is retried with bounded exponential delay while `attempts < max_attempts`. The final failure enters `dead_letter`, clears the active deduplication key and records `dead_lettered_at` and `last_error`.

An operator can explicitly requeue a dead-letter or legacy failed job. Retry resets attempts, cancellation data, progress, result, terminal timestamps and lease ownership. Package retries use a new temporary file and atomically replace only an artifact produced for the same job ID.

## Operator commands

Run commands from a trusted CLI with the production configuration available:

```text
php catalog/bin/job-control.php status --queue=catalog --limit=50
php catalog/bin/job-control.php cancel --id=123 --reason="Operator requested stop"
php catalog/bin/job-control.php retry --id=123
php catalog/bin/job-control.php recover --queue=catalog
php catalog/bin/job-control.php enqueue-rebuild-game --game-id=1 --offset=0
php catalog/bin/job-control.php enqueue-rebuild-file --file-id=123
php catalog/bin/job-control.php enqueue-rebuild-affected --file-id=123
php catalog/bin/job-control.php enqueue-source-identity-file --file-id=123
php catalog/bin/job-control.php enqueue-source-identity-game --game-id=1
php catalog/bin/job-control.php enqueue-clean-unverified-duplicates
php catalog/bin/job-control.php enqueue-prune --max-age-seconds=86400
```

The administrator `job-action.php` API exposes equivalent CSRF-protected POST actions for dependency/identity enqueue, cancel, retry and recovery operations. Duplicate cleanup uses its unverified-files CSRF endpoint. Public generated-package jobs use a dedicated session-bound endpoint.

The normal worker claim path also recovers expired leases before selecting new work. The explicit recovery command is useful for diagnostics and scheduled maintenance.

## Worker execution

```text
php catalog/bin/catalog-worker.php --queue=catalog --max-jobs=100 --sleep-ms=250 --lease-seconds=120
```

The production container uses `deploy/docker/worker-loop.sh`, gives every process a stable worker ID for its lifetime and passes the configured lease duration.

## Scaling rules

Multiple workers may claim from the same queue. Advisory claim coordination, persisted resource limits, concurrency keys and lease-token ownership prevent overbooking, duplicate target work and stale completion.

Keep one worker replica until representative scanner, filesystem and package-generation jobs pass idempotency and crash-recovery tests. Queue scheduling safety alone does not make the underlying operation horizontally safe.

Before adding replicas:

1. confirm every job type is idempotent or resumable
2. confirm package storage supports concurrent access
3. review persisted resource limits and target keys
4. monitor queue age, class saturation, lease recovery count, dead letters and cancellation latency
5. test worker termination during each major stage

## Alerts

Page or warn on:

- oldest queued job exceeding its service target
- a resource class remaining saturated beyond its expected duration
- repeated lease recoveries for the same job or worker
- any growing dead-letter count
- running jobs with stale heartbeat timestamps
- cancellation requests not acknowledged before lease expiry
- generated-package artifact storage growth or repeated orphan pruning
- worker restart loops or database/advisory lock timeouts
