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
| upload-progress pruning | `housekeeping` | 2 | none |
| unknown/future type | `default` | 4 | none |

Limits may be overridden before enqueueing with:

```text
UNREALDB_JOB_RESOURCE_LIMIT_DEPENDENCY_HEAVY=1
UNREALDB_JOB_RESOURCE_LIMIT_HOUSEKEEPING=2
UNREALDB_JOB_RESOURCE_LIMIT_DEFAULT=4
```

The resolved limit is stored on the job, so changing an environment variable affects newly queued jobs only. Claim selection skips saturated classes, allowing housekeeping work to continue while heavy dependency work is active. A short MySQL advisory lock serializes claim decisions so competing workers cannot overbook a class.

## Dependency refresh jobs

The administrator Dependency Refresh page now enqueues one durable job instead of rebuilding each file through a separate browser request.

- A **single file** refresh rebuilds only that file's own `ue_dependencies` rows.
- A **full game** refresh processes verified files in package order and retains the optional start offset.
- An **affected-dependants** refresh remains a separate internal/operator action for files whose package identity may resolve imports in other files.

The page polls `job-status.php` by job ID, displays persisted worker progress and final dependency totals, supports cooperative cancellation, and stores the active job ID in the page URL. Reloading or reopening that URL resumes the progress dialog. Closing the page does not stop the worker.

## Progress

Progress callbacks from maintenance handlers are persisted in `progress_json` with `progress_updated_at`. Progress is an operational snapshot, not the durable result. A successful completion stores the final result separately in `result_json`.

The job status API supports a positive `job_id` filter and decodes both progress and result objects for authenticated administrators.

## Cancellation

Queued jobs are cancelled immediately. Running jobs receive `cancel_requested_at`, `cancel_requested_by` and `cancel_reason`. The current lease owner observes the request at its next checkpoint and transitions the job to `cancelled`.

A cancelled request does not forcibly terminate PHP in the middle of a database or filesystem operation. Handlers must call the execution context at safe boundaries. If a worker disappears after cancellation is requested, expired-lease recovery finalizes the job as cancelled.

## Dead letters and retries

A normal exception is retried with bounded exponential delay while `attempts < max_attempts`. The final failure enters `dead_letter`, clears the active deduplication key and records `dead_lettered_at` and `last_error`.

An operator can explicitly requeue a dead-letter or legacy failed job. Retry resets attempts, cancellation data, progress, result, terminal timestamps and lease ownership.

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
php catalog/bin/job-control.php enqueue-prune --max-age-seconds=86400
```

The administrator `job-action.php` API exposes equivalent CSRF-protected POST actions for supported enqueue, cancel, retry and recovery operations.

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
- worker restart loops or database/advisory lock timeouts
