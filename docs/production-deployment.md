# UnrealDB production deployment

## Deployment position

UnrealDB production is a **single-host Windows deployment**. The supported production stack is Apache + PHP + MySQL on one Windows server, with local package storage and durable background jobs stored in MySQL.

Docker, Docker Compose and Kubernetes are not production targets for this installation and are intentionally not part of the repository deployment path.

The application remains a modular PHP monolith. Package identity, parsing, storage, dependency publication and durable job state are transactionally related; splitting them into network services would add failure modes without a demonstrated operational benefit.

## Production topology

```text
Internet
   |
HTTPS / firewall / reverse-proxy boundary if used
   |
Apache 2.4 (Windows)
   |
PHP 8.5
   |
   +-- MySQL 8.4
   +-- catalog/storage on local durable storage
   +-- durable ue_background_jobs queue
   +-- independent PHP worker processes
   +-- scheduled maintenance and backup tasks
```

The browser is not part of the worker lifecycle. Background work must continue when an administrator closes the browser or signs out.

## Runtime responsibilities

### Apache/PHP web role

Apache serves the public catalogue and administrator UI. Request handlers should perform bounded validation/query/submission work and enqueue durable jobs for expensive package processing, dependency rebuilds, archive extraction and maintenance.

Do not run long package workflows for the lifetime of an HTTP request.

### MySQL

MySQL is the authoritative store for:

- catalogue identity and projections;
- administrator/security state;
- durable background jobs and workflow progress;
- operational settings such as resource-class limits.

MySQL is also the queue coordination authority. Do not add Redis or an external message broker merely to replace the existing durable queue.

### Package storage

The configured `catalog/storage` tree is local durable application storage. Monitor free space and storage errors. Cache/scratch data may be rebuilt, but verified package data, compact metadata and durable staging required for recovery must be protected with the database as one recovery set where consistency requires it.

### Worker processes

Workers are independent PHP processes, not child work owned by Apache or a browser request.

Worker recovery is process/connection ownership based. A healthy long-running job must remain running until it completes, fails, is cancelled, or the operator explicitly kills it. Elapsed runtime alone is not a reason to fail or steal a job.

Resource-class concurrency limits and worker-process count are separate controls:

- worker count determines available execution capacity;
- resource-class limits determine how much heavy work the server is allowed to run concurrently.

A failed package must not block unrelated queued jobs.

## Windows service management

Production workers should be started and supervised independently of Apache. Use a Windows service wrapper or another persistent service manager that can:

- start workers automatically at boot;
- restart a worker process after an unexpected exit;
- stop accepting new work during a controlled shutdown;
- preserve worker stdout/stderr in a known log location;
- expose the worker PID/process state to operations.

Do not depend on an administrator opening `background-jobs.php` to keep workers alive.

Scheduled maintenance and backups can use Windows Task Scheduler where an always-running service is unnecessary.

## Deployment workflow

A production release should be a controlled code revision update, not a live file copy performed while migrations/workers are changing underneath it.

Recommended release sequence:

1. Record the current Git commit and verify the working tree/deployment source.
2. Review changed migrations and deployment-sensitive configuration.
3. Confirm a current recovery point when schema/storage changes are involved.
4. Run PHP syntax and application contract checks.
5. Run migration status and dry-run.
6. Put only the required write paths into maintenance/drain mode when necessary.
7. Stop or drain worker processes before replacing code they execute.
8. Update the production code revision.
9. Apply and verify database migrations.
10. Restart Apache/PHP only when the changed runtime requires it.
11. Start/reconcile workers so every process uses the new code revision.
12. Run liveness/readiness and functional smoke tests.
13. Review logs, failed jobs, queue progress, MySQL pressure and storage capacity.

For normal PHP-only changes that do not alter schema or worker contracts, the maintenance window can be much smaller.

## Application quality gate

Run these on the deployment host where the real PHP extensions, filesystem and MySQL version are available:

```text
php -l <every changed PHP file>
php catalog/bin/migrate.php status
php catalog/bin/migrate.php migrate --dry-run
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
php catalog/bin/verify-security-hardening.php
php catalog/bin/verify-system-readiness-contract.php
php catalog/bin/verify-system-readiness-contract.php --run
php catalog/bin/verify-queue-runtime-invariants.php
php catalog/bin/verify-job-root-affinity-contract.php
php catalog/bin/verify-job-claim-concurrency.php --run
php catalog/bin/audit-legacy-runtime-references.php
```

Run additional focused contract tests for the subsystem being changed.

A passing source-marker verifier is a guardrail, not proof of live concurrency behaviour. Queue changes should be exercised against the actual MySQL server.

## Database migrations

Numbered migrations and schema-version tracking live under `catalog/migrations` and `catalog/bin/migrate.php`.

For schema changes:

1. take current MySQL/package-storage backups when appropriate;
2. run migration status and dry-run;
3. review potentially locking/backfill operations;
4. apply migrations before code paths that require the new schema;
5. run migration verification;
6. deploy/restart code and workers;
7. perform application smoke tests.

Use expand-and-contract for destructive or compatibility-sensitive changes. Large data backfills belong in bounded durable jobs, not one web request or an unbounded migration transaction.

A code rollback cannot automatically undo an incompatible database migration. Releases that change schema must therefore retain a forward-fix/compatibility path.

## Health boundaries

Use the existing endpoints for local monitoring and deployment checks:

- `/catalog/api/v1/live.php` — process/PHP liveness only;
- `/catalog/api/v1/readiness.php` — dependency-aware readiness including database, queue and storage requirements;
- `/catalog/api/v1/metrics.php` — authenticated operational metrics.

Do not make liveness depend on MySQL. A MySQL outage should be reported as not-ready; repeatedly restarting healthy Apache/PHP processes does not repair the database.

## Monitoring

Monitor at minimum:

### Host

- CPU and memory pressure;
- free disk space on the MySQL and package-storage volumes;
- disk latency/queue depth;
- Apache/PHP process availability;
- worker process count and unexpected restarts.

### Web

- request rate;
- response latency;
- HTTP 4xx/5xx rates;
- PHP fatal/error rate;
- upload failures and request-size/time-limit errors.

### MySQL

- connections;
- slow queries;
- lock waits/deadlocks;
- buffer-pool pressure;
- redo/IO pressure;
- database disk free space.

### Background jobs

- queued/running/failed/dead-letter counts;
- oldest queued-job age;
- completed-job throughput;
- worker activity;
- resource-class saturation;
- unusually long-running jobs and their last progress time.

A long runtime is an operator signal, not an automatic failure condition.

### Storage and backup

- package-storage used/free bytes and growth;
- failed filesystem operations;
- last successful backup time;
- last successful backup verification;
- restore-drill status.

## Logging

Keep Apache, PHP and worker logs in known durable locations and rotate them. Application errors should include the request/job identifiers required to correlate UI reports with server logs.

Do not log passwords, session cookies, federation secrets, security master keys or bearer tokens.

System Errors and Background Jobs remain the primary application-level operator views. OS/service logs are the secondary source for process crashes and infrastructure failures.

## Backup and recovery

The Windows backup and restore tooling under `deploy/backup/` is the supported recovery path. See `docs/windows-backup-recovery.md`.

The important rules are:

- database-only backups can normally be taken online using the provided process;
- a coherent database + package-storage recovery point requires controlled write quiescence;
- completed backups must pass verification before being considered recovery points;
- keep recovery copies on storage independent from the live database/package disk;
- run periodic restore drills against disposable targets.

Backup encryption is not an application requirement for the public catalogue data. Protect backup media according to normal host/storage access policy.

## Rollback strategy

Before every deployment record the previous known-good Git revision.

If a release fails smoke tests:

1. stop/drain workers that are running the new code;
2. determine whether the database migration remains backward compatible;
3. return the code tree to the previous known-good revision when safe;
4. restart Apache/PHP if required;
5. restart workers on the selected revision;
6. run readiness and smoke tests again;
7. forward-fix rather than attempting unsafe schema rollback when migration compatibility does not permit code rollback.

Do not recover running jobs by arbitrary elapsed-time timeout. Worker ownership and explicit operator action determine whether a running job is orphaned or should be stopped.

## Production checklist

### Host baseline

- [ ] Apache 2.4 is installed as a Windows service and starts automatically.
- [ ] PHP 8.5 CLI and Apache module/runtime point to the intended installation.
- [ ] MySQL 8.4 starts automatically and uses the intended production data directory/configuration.
- [ ] OPcache is enabled for web PHP.
- [ ] Production PHP error display is disabled.
- [ ] HTTPS/security headers are verified at the public endpoint.
- [ ] Storage and database volumes have free-space alerts.

### Application

- [ ] `catalog/config.php`/environment configuration is production-correct.
- [ ] MySQL schema/migrations are current and verified.
- [ ] Federation secret encryption requirements are satisfied when federation is enabled.
- [ ] Security hardening verifier passes.
- [ ] Runtime readiness verifier passes with `--run`.
- [ ] Worker pool/resource limits reflect actual server capacity.

### Worker reliability

- [ ] Workers run independently of Apache/browser sessions.
- [ ] Workers start automatically at boot.
- [ ] Unexpected worker exits restart automatically.
- [ ] Controlled stop/drain behaviour is documented/tested.
- [ ] A killed worker can be identified/recovered without stealing a healthy long-running job.
- [ ] One failed package does not stop unrelated queued work.

### Backup/recovery

- [ ] Backup destination is independent from the live database/package disk.
- [ ] Backup readiness preflight passes.
- [ ] Scheduled backups run successfully.
- [ ] Backup verification runs successfully.
- [ ] At least one restore drill has been completed.

### Before every release

- [ ] Current production Git revision recorded.
- [ ] Changed PHP files pass `php -l`.
- [ ] Migration status/dry-run reviewed.
- [ ] Relevant queue/runtime contract tests pass.
- [ ] Current backup exists when schema/storage changes require one.
- [ ] Worker restart/drain impact is understood.
- [ ] Rollback/forward-fix path is known.

### After every release

- [ ] `live.php` responds successfully.
- [ ] `readiness.php` reports ready.
- [ ] Apache/PHP error log is clean of new fatal errors.
- [ ] Expected worker count is active.
- [ ] Running jobs retain valid ownership and make progress.
- [ ] Representative search works.
- [ ] Representative upload/import path works when changed.
- [ ] Representative download path works when changed.
- [ ] Failed/dead-letter job counts are reviewed.
- [ ] MySQL pressure and storage capacity are reviewed.

## Scaling policy

This deployment is intentionally single-host. Optimize the host and workload controls before adding infrastructure.

Scale by:

- tuning MySQL from measured queries/IO;
- adjusting worker process count;
- adjusting per-resource-class concurrency limits;
- keeping expensive work asynchronous;
- reducing duplicate queries/work;
- moving large maintenance operations into bounded durable jobs;
- upgrading host CPU/RAM/storage when measurements show a hardware limit.

Do not introduce Redis, Kubernetes, replicas, message brokers or distributed storage unless the production deployment strategy changes explicitly in the future.
