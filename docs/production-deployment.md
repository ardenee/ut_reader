# UnrealDB production deployment

## Deployment position

UnrealDB is a modular PHP monolith with MySQL metadata, durable package storage, PHP sessions and a MySQL-backed job queue. Keep it as a modular monolith: package identity, parsing, storage and dependency updates are transactionally related, and splitting them into network services would add operational failure modes without demonstrated benefit.

The current Windows Apache/PHP/MySQL deployment remains supported. Docker Compose is useful for integration/single-host staging; Kubernetes is optional and should not be treated as a prerequisite for operating the application.

## Infrastructure architecture

For a containerized production deployment:

1. DNS/CDN/WAF protect the public edge.
2. TLS ingress/load balancer forwards to the web service.
3. Immutable web containers serve PHP/static assets.
4. Shared/central session storage is required before horizontally scaling the web tier.
5. MySQL 8.4-compatible infrastructure stores catalogue and durable-job metadata.
6. Package storage must be available to both web and worker roles.
7. Background workers run independently of HTTP requests.
8. Logs are collected centrally.
9. MySQL and package storage are backed up and restore-tested independently.

Object storage remains a possible long-term package-storage target. Do not migrate solely for architectural fashion; preserve existing storage semantics until the operational benefit is clear.

## Durable worker ownership

Worker recovery is process-ownership based, not timeout-theft based.

Each worker holds a connection-scoped MySQL ownership lock through `PdoWorkerOwnership`. A legitimate long-running job keeps that lock for as long as its PHP/MySQL connection is alive. If the process or connection dies, MySQL releases the lock and orphan recovery can safely recover its running row. `lease_token` remains the fencing token on queue lifecycle writes.

Host-local detached-worker state files are supervisor diagnostics only and are not the durable ownership authority.

## Scaling policy

### Stage 1: current/single-host production

- One web server/site instance.
- A bounded detached worker pool.
- MySQL with tested backups.
- Durable package storage with capacity monitoring.
- Resource-class limits configured for the actual server rather than assumed from the number of PHP worker processes.

### Stage 2: horizontally scaled web tier

Before adding web replicas, ensure sessions and package storage are shareable and MySQL connection/index capacity has been load-tested.

### Stage 3: additional workers

Before increasing worker replicas/processes materially:

- run the real MySQL queue concurrency verifier;
- check database lock waits/deadlocks/IO;
- verify resource-class limits and concurrency keys match the workload;
- confirm orphan recovery on a killed worker;
- confirm a healthy long-running job is not recovered merely because it runs for a long time.

The queue is designed to allow multiple workers. Scale based on measured database/storage pressure, not CPU availability alone.

### Stage 4: high-volume catalogue

Only after measurement:

- move package blobs to object storage if filesystem/RWX operations are the limiting factor;
- add MySQL read replicas for demonstrated read pressure;
- add an n-gram/derived search projection or external search service for demonstrated substring-search pressure;
- split worker pools further by job/resource class when independent workload control is useful.

## Repository workflows

There is **no required `catalog-quality.yml` GitHub Actions application gate**.

The tracked release/deployment workflows are intentionally limited. Do not add an application workflow merely to reproduce local PHP/MySQL checks if it creates noisy failure email without improving deployment confidence.

### Container release

`.github/workflows/container-release.yml` handles container/release-specific checks such as image/config validation, image build and scanning/publishing according to its current definition.

### Production deployment

`.github/workflows/deploy-production.yml` is the repository deployment workflow. Treat its protected environment/credentials as deployment infrastructure, not as a substitute for application/database verification.

## Application quality gate: manual/deployment-host

Run these on the test/deployment host where the real PHP extensions, filesystem and MySQL version are available:

```text
php -l <every changed PHP file>
php catalog/bin/migrate.php status
php catalog/bin/migrate.php migrate --dry-run
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
php catalog/bin/verify-queue-runtime-invariants.php
php catalog/bin/verify-job-root-affinity-contract.php
php catalog/bin/verify-job-claim-concurrency.php --run
php catalog/bin/audit-legacy-runtime-references.php
```

`verify-job-claim-concurrency.php --run` creates uniquely named temporary queue/resource rows, launches concurrent PHP claimers against the configured MySQL database, verifies admission/ownership invariants and cleans up its temporary data.

Source-marker architecture scripts are useful guardrails only. A passing marker script is not evidence that concurrent queue behaviour is correct.

## Database migrations

Numbered migrations and schema-version tracking already exist under `catalog/migrations` and `catalog/bin/migrate.php`.

For schema changes:

1. take current MySQL/package-storage backups;
2. run migration status and dry-run;
3. review potentially locking/backfill operations;
4. apply migrations before code paths that require the new schema;
5. run migration verification;
6. deploy/restart code/workers;
7. perform the application smoke tests.

Use expand-and-contract for large/destructive changes. Long data backfills belong in bounded jobs/maintenance operations rather than one web request or an unbounded migration transaction.

The verified-metadata publication-state migration adds `metadata_status`, `metadata_error` and `metadata_updated_at` to `ue_files`; apply current migrations before relying on those operational fields.

## Release workflow

1. Review the commit range and changed migrations.
2. Run PHP syntax checks and the relevant manual application verifiers.
3. Back up MySQL and package storage before schema changes.
4. Apply/verify migrations.
5. Deploy the immutable release/code revision.
6. Restart/reconcile worker processes so they load the current code fingerprint.
7. Run liveness/readiness checks.
8. Test authentication, search, one upload/import path, download policy and one background job.
9. Review Apache/PHP/worker logs plus MySQL locks/deadlocks/slow queries.
10. Review Background Jobs queue depth, running ownership and failed/dead-letter rows.
11. Roll back/forward-fix if smoke tests fail.

## Rollback strategy

- Keep the previous code/image available.
- A previous release must remain compatible with migrations applied during rollout, or the change needs an explicit forward-fix plan.
- Storage changes require snapshots/versioned objects as appropriate.
- Do not attempt to recover running work by arbitrary lease timeout; worker ownership determines whether a job is actually orphaned.

## Monitoring

Collect/observe at minimum:

- HTTP request rate/latency/4xx/5xx;
- Apache/PHP errors and process crashes;
- worker process count/restarts/OOM kills;
- queue depth by status/resource type, active running jobs, retries and failures;
- MySQL connections, lock waits, deadlocks, slow queries, buffer pool and disk latency;
- package-storage capacity/latency/errors;
- upload/import duration/failure rate;
- backup age and restore-test status.

For queue operations, a stale `lease_expires_at` timestamp is not by itself a failure signal. Operator diagnostics should consider the database worker-ownership lock and running process state.

## Capacity and safety rules

- Resource-class concurrency and worker-process count are separate controls.
- Lower database-heavy workload classes before lowering the entire worker pool when only one workload is causing pressure.
- Limit changes are live at claim time; saving them must not rewrite the entire queued backlog.
- One package failure must not block unrelated queued packages.
- Large coordinators must use durable bounded child units so completed work is not replayed.
- Keep package parsing/import out of long synchronous HTTP requests.
- Measure `%LIKE%`/substring search before adding a new search service.
- Measure queue/database pressure before adding another message broker.

## Production checklist

### Before first/current-platform deployment

- [ ] MySQL schema/migrations are current and verified.
- [ ] Storage location/capacity/backup are tested.
- [ ] PHP CLI used by detached workers resolves to the intended PHP installation.
- [ ] Worker pool/resource limits reflect the server's actual database/storage capacity.
- [ ] Logs and backup alerts are configured.
- [ ] Administrator/security configuration is reviewed.
- [ ] A restore test has been completed.

### Before every release

- [ ] Changed PHP files pass `php -l`.
- [ ] Migration dry-run/status reviewed.
- [ ] Real queue concurrency verification passes for queue-affecting changes.
- [ ] Database/storage backups are current when schema/storage changes are involved.
- [ ] Worker code fingerprint/restart implications are understood.
- [ ] Rollback/forward-fix path is known.

### After every release

- [ ] Web health checks pass.
- [ ] Expected worker count is active.
- [ ] Running jobs show valid ownership and make progress.
- [ ] Exact identity search and representative broad search work.
- [ ] Upload/import and download smoke tests work.
- [ ] Error log, failed-job count, MySQL pressure and storage capacity are reviewed.

## Remaining platform work

These are optional/platform improvements, not unresolved correctness defects in the application architecture:

- replace generic cluster credentials with cloud OIDC/workload identity if Kubernetes becomes the production platform;
- add application metrics/OpenTelemetry when operational dashboards need finer-grained traces;
- move to object storage when filesystem sharing/backup becomes a measured limit;
- produce a stricter rootless container image if the production container platform requires it.
