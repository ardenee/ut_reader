# UnrealDB production deployment

## Deployment position

UnrealDB is a modular PHP monolith with MySQL metadata, durable package storage, PHP sessions, and a MySQL-backed job queue. Keep it as a modular monolith: package identity, parsing, storage, and dependency updates are transactionally related, and splitting them into network services would add operational failure modes without providing useful independence.

Kubernetes is optional. Docker Compose is the recommended local and single-host staging path. The Kubernetes baseline starts with one web replica and one worker replica.

## Infrastructure architecture

1. Managed DNS, CDN, and WAF protect the public edge.
2. A TLS load balancer or Kubernetes ingress forwards requests to the web service.
3. Immutable web containers serve PHP and static assets on port 8080.
4. Redis stores PHP sessions so web instances do not depend on local session files.
5. Managed MySQL 8.4-compatible infrastructure stores catalogue and job metadata.
6. ReadWriteMany package storage is mounted by web and worker pods.
7. A dedicated worker processes background jobs independently of HTTP requests.
8. Logs are written to stdout and stderr and collected centrally.
9. Prometheus-compatible infrastructure metrics and synthetic probes feed dashboards and alerting.
10. MySQL and package storage are backed up independently and restore-tested.

Object storage is the long-term package-storage target. The current filesystem storage interface is retained for compatibility.

## Availability boundaries

- Public catalogue pages depend on ingress, web, MySQL, and Redis sessions.
- Downloads also depend on package storage and the configured mirror policy.
- Worker failure should not take public browsing offline.
- Readiness checks include database connectivity.
- Liveness checks only confirm that the PHP/Apache process can respond.

## Container layout

The repository Dockerfile produces one image for both roles:

- Web: default Apache command.
- Worker: the same image with `deploy/docker/worker-loop.sh` as its command.

Runtime values are read from environment variables through `deploy/docker/config.php`. Secrets are not baked into the image. The production image disables displayed PHP errors, enables OPcache, writes errors to stderr, limits request size, and blocks development viewers and internal catalogue directories at Apache level.

The Compose stack includes MySQL, Redis, web, worker, persistent database data, persistent Redis data, and persistent package storage. It is intended for integration testing and single-host staging, not highly available database production.

## Kubernetes layout

The base manifest creates:

- Namespace `unrealdb`.
- Non-secret ConfigMap.
- ReadWriteMany package-storage claim.
- One zero-unavailable rolling web Deployment.
- One recreate-strategy worker Deployment.
- ClusterIP Service.
- TLS Ingress.

The `unrealdb-secrets` Secret must be created through External Secrets, Sealed Secrets, a cloud secret manager, or another encrypted delivery mechanism. Never apply the tracked example unchanged.

The cluster storage class must support ReadWriteMany because web and worker are separate pods and web rollouts may temporarily run two pods. Clusters without RWX storage should use object storage or the single-host Compose deployment.

## Scaling policy

### Stage 1: initial production

- One web replica.
- One worker replica.
- Redis-backed sessions.
- Managed or independently backed-up MySQL.
- RWX package storage.
- CDN for static assets and permitted downloads.

### Stage 2: horizontally scaled web tier

Prerequisites:

- Redis session service is production-ready.
- RWX or object-backed package storage is healthy and monitored.
- MySQL indexes and connection capacity have been load-tested.
- Kubernetes Metrics Server is installed.
- External synthetic monitoring is active.

Only then apply `deploy/kubernetes/optional/web-scaling.yaml`. It maintains three to twenty web replicas with controlled scale-down and a disruption budget.

Do not scale workers horizontally until queue claiming, lease renewal, idempotency, and recovery have been concurrency-tested.

### Stage 3: high-volume catalogue

- Move package blobs to S3-compatible object storage.
- Keep MySQL as the identity and dependency system of record.
- Add read replicas only after measured read pressure.
- Add a derived search index only after measured substring-search latency justifies it.
- Split workers into bounded pools by job type and resource class.

## CI/CD pipeline

### Application quality gate

`.github/workflows/catalog-quality.yml` performs:

- PHP syntax checks.
- Architecture-boundary tests.
- UI component tests.
- Duplicate-cleanup behavior tests.
- Package-format tests.
- Fresh MySQL schema import and seed validation.

### Container release gate

`.github/workflows/container-release.yml` performs:

1. Compose configuration validation.
2. Production image build.
3. Apache configuration validation inside the image.
4. Liveness endpoint syntax validation.
5. Container vulnerability scanning with failure on high-severity findings.
6. GHCR publication for approved non-PR runs.
7. Provenance attestation.
8. Release digest recording.

Images are deployed by digest, never by mutable tag.

### Production deployment

`.github/workflows/deploy-production.yml` is manually dispatched through the protected GitHub `production` environment.

Configure the environment with:

- Required reviewer approval.
- Deployment branch restrictions.
- A production-only cluster credential.
- No cluster secret exposure to pull-request workflows.
- Retained deployment history.

The generic workflow currently consumes `KUBE_CONFIG_B64`. Replace this with cloud OIDC or workload identity once the production platform is chosen.

## Release workflow

1. Confirm application quality and container release workflows passed.
2. Review image vulnerability results and provenance.
3. Back up MySQL and package storage before schema changes.
4. Apply reviewed backward-compatible schema migrations separately.
5. Verify MySQL, Redis, RWX storage, TLS, and `unrealdb-secrets`.
6. Start the production workflow with the published SHA-256 digest.
7. Apply the immutable rendered manifest.
8. Wait for web and worker rollout completion.
9. Run in-cluster readiness checks.
10. Run an external HTTPS smoke test.
11. Review error rate, latency, restarts, queue age, and database load.
12. Roll back if smoke tests or error budgets fail.

Schema changes use expand-and-contract deployment:

1. Add compatible schema.
2. Deploy code supporting old and new forms.
3. Backfill in bounded jobs.
4. Switch reads and writes.
5. Remove obsolete schema in a later release.

Never combine destructive or long-locking schema changes with the web rollout.

## Rollback strategy

- Kubernetes retains five web and worker revisions.
- Failed workflow rollouts request `kubectl rollout undo`.
- The previous image must remain compatible with the migrated database.
- Irreversible migrations require an approved forward-fix plan.
- Storage changes require snapshots or versioned object keys.
- Rollback means restoring an immutable image digest, not editing files on a server.

## Monitoring strategy

### Initial service objectives

Review these after real traffic baselines exist:

- Public availability: 99.9% monthly.
- Normal public page latency: p95 below 1 second.
- Administrative page latency: p95 below 2 seconds, excluding queued work.
- Oldest normal-priority queued job: below 5 minutes.
- Server-error rate: below 0.1% over 30 days.

### Metrics

Collect:

- Ingress request rate, latency percentiles, 4xx, 5xx, and connections.
- Web and worker CPU, memory, throttling, restarts, OOM kills, and probe failures.
- PVC capacity, inode usage, latency, and mount errors.
- MySQL connections, lock waits, deadlocks, slow queries, buffer-pool performance, disk latency, and backup age.
- Redis availability, memory, evictions, clients, and command latency.
- Queue depth by status and type, oldest job age, attempts, lease expirations, and failures.
- Upload/import volume and duration, archive-limit failures, and storage growth.
- Federation transfer failures and stale jobs when federation is enabled.

### Logging

- Apache access logs go to stdout.
- Apache, PHP, and worker errors go to stderr.
- The log agent adds environment, cluster, namespace, pod, container, and image digest.
- Retain operational logs for at least 30 days; retain audit/security logs according to policy.
- Redact passwords, cookies, CSRF values, remember tokens, federation secrets, cron tokens, and claim tokens.
- Alert on repeated login failures, signature failures, archive-limit violations, and unexpected admin actions.

### Synthetic monitoring

Probe externally:

- `/catalog/api/v1/live.php` for process liveness.
- `/catalog/api/v1/health.php` for database readiness.
- A representative game-list page.
- An exact GUID or package lookup.
- A permitted small download.

Do not use wildcard searches or package generation as frequent probes.

### Alerts

Page immediately for:

- 5xx rate above 5% for five minutes.
- No ready web pod.
- MySQL unavailable.
- Package storage read-only or above 90% capacity.
- Repeated worker crash loops.
- Backup or restore-verification failure.
- A surge in federation signature failures.

Warn for:

- p95 latency above two seconds for fifteen minutes.
- Queue age above ten minutes.
- Storage above 75% capacity.
- MySQL connections above 75% of the configured maximum.
- Redis memory above 75% or any eviction.
- A growing failed-job count.

## Reliability controls

- Immutable images and digest deployments.
- Zero-unavailable rolling web updates.
- Database-backed readiness checks.
- Database-independent liveness checks.
- Worker backoff and graceful termination.
- Persistent data separated from image lifecycle.
- Redis-backed sessions.
- Managed TLS renewal.
- MySQL point-in-time recovery.
- Daily package-storage snapshots.
- Quarterly restore exercises.
- Capacity alerts before exhaustion.
- One active production deployment at a time.

## Production deployment checklist

### Before first deployment

- [ ] Resolve the high-severity security audit findings, especially the legacy download bypass and plaintext federation secrets.
- [ ] Choose managed MySQL or document database ownership, patching, backups, and failover.
- [ ] Provision Redis with authentication, persistence policy, and monitoring.
- [ ] Provision RWX or object storage with tested backup and restore.
- [ ] Create `unrealdb-secrets` through a secret manager.
- [ ] Replace the example hostname and TLS secret.
- [ ] Confirm ingress annotations are supported.
- [ ] Configure DNS, TLS, WAF, request-size limits, and rate limiting.
- [ ] Disable public development viewers and HTTP cron endpoints.
- [ ] Create the first administrator through CLI only.
- [ ] Import the canonical schema for a new database or apply reviewed migrations.
- [ ] Use a least-privilege database account restricted to the UnrealDB database.
- [ ] Configure logs, dashboards, alerts, and on-call routing.
- [ ] Complete a full restore test.

### Before every release

- [ ] Quality checks passed.
- [ ] Image scan passed or an approved exception exists.
- [ ] Deployment uses an immutable digest.
- [ ] Schema changes support the current and previous images.
- [ ] Database and storage backups are current.
- [ ] Capacity covers rollout surge and temporary files.
- [ ] Rollback image and procedure are known.
- [ ] Maintenance communication is prepared when required.

### After every release

- [ ] Web and worker rollouts completed.
- [ ] Liveness and readiness probes are healthy.
- [ ] External HTTPS smoke test passed.
- [ ] Authentication, search, upload, download policy, and one background job were tested.
- [ ] Error rate, latency, MySQL, Redis, queue age, and storage were reviewed.
- [ ] Release digest and approval were recorded.
- [ ] Temporary deployment credentials and artifacts were removed.

## Remaining platform work

- Add numbered database migrations and a schema-version table.
- Replace generic kubeconfig deployment with cloud OIDC/workload identity.
- Add application metrics or OpenTelemetry instrumentation.
- Move federation workers from HTTP-token endpoints to CLI/background jobs.
- Replace filesystem package storage with object storage before broad scaling.
- Load-test concurrent queue claims before adding worker replicas.
- Produce a rootless FPM/web-server image if strict non-root workloads are required.
