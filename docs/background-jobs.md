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

## Progress

Progress callbacks from maintenance handlers are persisted in `progress_json` with `progress_updated_at`. Progress is an operational snapshot, not the durable result. A successful completion stores the final result separately in `result_json`.

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
```

The normal worker claim path also recovers expired leases before selecting new work. The explicit recovery command is useful for diagnostics and scheduled maintenance.

## Worker execution

```text
php catalog/bin/catalog-worker.php --queue=catalog --max-jobs=100 --sleep-ms=250 --lease-seconds=120
```

The production container uses `deploy/docker/worker-loop.sh`, gives every process a stable worker ID for its lifetime and passes the configured lease duration.

## Scaling rules

Multiple workers may claim from the same queue. Row-level locks and lease-token ownership prevent duplicate active claims and stale completion. Keep one worker replica until representative heavy jobs have passed resource and idempotency tests; queue correctness alone does not make package parsing or filesystem operations horizontally safe.

Before adding replicas:

1. confirm every job type is idempotent or resumable
2. confirm package storage supports concurrent access
3. define per-job resource and concurrency limits
4. monitor queue age, lease recovery count, dead letters and cancellation latency
5. test worker termination during each major stage

## Alerts

Page or warn on:

- oldest queued job exceeding its service target
- repeated lease recoveries for the same job or worker
- any growing dead-letter count
- running jobs with stale heartbeat timestamps
- cancellation requests not acknowledged before lease expiry
- worker restart loops or database lock timeouts
