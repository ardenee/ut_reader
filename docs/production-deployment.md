# UnrealDB production deployment

## Deployment position

UnrealDB is currently a modular PHP monolith with MySQL-backed metadata, filesystem-backed package storage, PHP sessions, and a durable MySQL job queue. The production design keeps the monolith because package identity, parsing, storage, and dependency updates are transactionally related. Kubernetes is an optional operating platform, not a requirement.

The default manifests intentionally deploy one web replica and one worker replica. Do not enable horizontal web scaling until sessions use Redis and package storage is ReadWriteMany or object-backed. Do not scale workers horizontally until concurrent queue claiming and lease renewal have been load-tested.

## Target infrastructure architecture

1. CDN/WAF and managed DNS terminate abusive traffic before the cluster.
2. A TLS load balancer or Kubernetes ingress sends requests to the web deployment.
3. Stateless web containers serve PHP and static assets.
4. Redis stores PHP sessions for multi-instance safety.
5. Managed MySQL 8.4-compatible infrastructure stores catalogue and job metadata.
6. Package storage uses a durable shared volume initially, with object storage as the long-term scaling target.
7. A dedicated worker deployment processes background jobs independently of web requests.
8. Platform agents ship stdout/stderr logs to the central log system.
9. Prometheus-compatible exporters and synthetic probes feed dashboards and alerting.
10. Database and package storage backups are managed independently and restore-tested.

## Availability boundaries

- Web availability depends on ingress, web pods, Redis sessions, MySQL, and package storage.
- Public read-only pages currently depend on MySQL.
- Package downloads depend on package storage and configured mirror policy.
- Background processing may be unavailable without taking public browsing offline.
- Readiness checks include database connectivity. Liveness checks do not.

## Container layout

The repository Dockerfile produces one immutable image for both roles:

- Web role: default Apache command on port 8080.
- Worker role: the same image with `deploy/docker/worker-loop.sh` as the command.

Runtime configuration is read from environment variables through `deploy/docker/config.php`. Secrets are never baked into the image. Package data is mounted at `catalog/storage`.

The Compose stack is suitable for local integration testing and a single-host staging environment. It is not the recommended high-availability production database deployment.

## Kubernetes layout

The base manifest creates:

- Namespace `unrealdb`.
- Non-secret runtime ConfigMap.
- Persistent package-storage claim.
- One rolling-update web Deployment.
- One recreate-strategy worker Deployment.
- ClusterIP web Service.
- TLS Ingress.

The `unrealdb-secrets` Secret must exist before deployment. Use External Secrets, Sealed Secrets, a cloud secret manager, or another encrypted delivery system. The tracked example file must never be applied unchanged.

The base PersistentVolumeClaim is ReadWriteOnce. It is safe for the default single web replica when the web and worker can mount the same volume on the same cluster topology. Production clusters that schedule them across nodes require ReadWriteMany storage or object storage.

## Scaling policy

### Stage 1: single-node or small production

- One web replica.
- One worker replica.
- Managed MySQL or a separately backed-up MySQL host.
- Redis session service.
- One durable shared package volume.
- CDN for static assets and permitted downloads.

### Stage 2: horizontally scaled web tier

Prerequisites:

- Redis-backed PHP sessions.
- ReadWriteMany package storage or object storage.
- Load-tested database indexes and connection capacity.
- Ingress metrics server installed.
- Synthetic health monitoring in place.

Apply `deploy/kubernetes/optional/web-scaling.yaml` only after those prerequisites. It maintains three to twenty web replicas and a disruption budget.

### Stage 3: high-volume catalogue

- Move package blobs to S3-compatible object storage behind the storage interface.
- Keep MySQL as the system of record for identities and dependencies.
- Add read replicas only for measured read pressure.
- Move substring search to a derived search index when MySQL latency exceeds the agreed budget.
- Introduce separate bounded worker pools by job type.
- Add per-job resource classes for package parsing, archive extraction, dependency rebuilds, and distribution.

## CI pipeline

`catalog-quality.yml` remains the application correctness gate:

- PHP syntax checks.
- Architecture-boundary tests.
- UI component tests.
- Duplicate-cleanup behavior tests.
- Package-format tests.
- Fresh MySQL schema import and seed checks.

`container-release.yml` adds the delivery gate:

1. Validate Compose configuration.
2. Build the exact production image.
3. Validate Apache configuration inside the image.
4. Validate the liveness endpoint.
5. Scan the built image and fail on high-severity findings.
6. Publish immutable SHA and release tags to GHCR after non-PR runs.
7. Generate build provenance attestation.
8. Record the content digest used for deployment.

Images must be deployed by digest, never by a mutable tag.

## Deployment workflow

`deploy-production.yml` is manual and uses the protected GitHub `production` environment.

Recommended environment rules:

- Required reviewer approval.
- Deployment branch restricted to `main` and release tags.
- Separate production cluster credential.
- No secret available to pull-request workflows.
- Deployment history retained.

Release procedure:

1. Confirm application quality and container release workflows passed.
2. Review vulnerability scan results and SBOM/provenance records.
3. Back up MySQL and package storage before schema changes.
4. Apply backward-compatible schema migrations separately.
5. Confirm `unrealdb-secrets`, TLS, Redis, database, and storage are healthy.
6. Start the production workflow with the published image digest.
7. Apply the rendered immutable Kubernetes manifest.
8. Wait for web and worker rollout completion.
9. Run in-cluster readiness checks.
10. Run an external HTTPS smoke test.
11. Monitor error rate, latency, restarts, queue age, and database load.
12. Roll back the deployments if error budgets or smoke tests fail.

Do not run destructive or long-locking schema migrations in the same step as the web rollout. Use expand-and-contract migrations:

1. Add compatible columns/tables/indexes.
2. Deploy code that supports both schemas.
3. Backfill in bounded jobs.
4. Switch reads and writes.
5. Remove obsolete schema in a later release.

## Rollback strategy

Application rollback is an image rollback, not a source checkout on the server.

- Kubernetes keeps five deployment revisions.
- Failed automated rollouts request `kubectl rollout undo` for web and worker.
- Operators must verify the previous image remains compatible with any applied schema migration.
- Irreversible migrations require a forward-fix plan before approval.
- Package storage changes require snapshots or versioned object keys.

## Monitoring strategy

### Service-level objectives

Initial objectives should be reviewed after real traffic baselines exist:

- Public catalogue availability: 99.9% monthly.
- Successful public request latency: p95 below 1 second for normal browse pages.
- Administrative request latency: p95 below 2 seconds, excluding queued operations.
- Background queue age: oldest normal-priority job below 5 minutes.
- Error budget: fewer than 0.1% server errors over a rolling 30-day period.

### Metrics

Collect at least:

- Ingress request rate, p50/p95/p99 latency, 4xx, 5xx, and active connections.
- Web and worker CPU, memory, throttling, restarts, OOM kills, and readiness failures.
- Persistent-volume capacity, inode usage, latency, and mount errors.
- MySQL connections, lock waits, deadlocks, slow queries, buffer-pool hit rate, disk latency, and backup age.
- Redis availability, memory, evictions, connected clients, and command latency.
- Queue depth by status and type, oldest queued job age, attempts, lease expirations, and failures.
- Upload/import counts, processing duration, decompression failures, and storage growth.
- External federation transfer failures and stale jobs when federation is enabled.

### Logs

- Apache access logs go to stdout.
- Apache, PHP, and worker errors go to stderr.
- The platform log agent must add cluster, namespace, pod, container, release digest, and environment fields.
- Retain application logs for at least 30 days and security/audit logs according to the operational policy.
- Redact passwords, session cookies, CSRF values, remember tokens, federation secrets, cron tokens, and claim tokens.
- Alert on repeated authentication failures, signature failures, archive limit violations, and unexpected admin actions.

### Synthetic monitoring

Probe from outside the cluster:

- `/catalog/api/v1/live.php` for process availability.
- `/catalog/api/v1/health.php` for database-backed readiness.
- A representative game list page.
- A representative exact package/GUID search.
- A permitted small download path.

Do not use expensive package generation or wildcard searches as frequent probes.

### Alerting

Page immediately for:

- Public 5xx rate above 5% for five minutes.
- No ready web pod.
- MySQL unavailable or storage read-only.
- PVC above 90% capacity.
- Repeated worker crash loop.
- Backup or restore-verification failure.
- Security-signature failure surge.

Create warning alerts for:

- p95 latency above two seconds for fifteen minutes.
- Queue age above ten minutes.
- PVC above 75% capacity.
- MySQL connection use above 75%.
- Redis memory above 75% or any eviction.
- Growing failed-job count.

## Reliability controls

- Immutable images and digest deployments.
- Zero-unavailable rolling web updates.
- Readiness gates based on database connectivity.
- Separate liveness endpoint that avoids restart loops during database incidents.
- Dedicated worker lifecycle and bounded backoff.
- Persistent storage separated from image lifecycle.
- Redis-backed sessions for web replica independence.
- Managed TLS and certificate renewal.
- Database point-in-time recovery and daily storage snapshots.
- Quarterly restore exercises.
- Capacity alerts before storage exhaustion.
- One active production deployment at a time through workflow concurrency.

## Production deployment checklist

### Before first deployment

- [ ] Resolve high-severity security audit findings, especially legacy download bypass and plaintext federation secrets.
- [ ] Choose managed MySQL or document database ownership, backups, patching, and failover.
- [ ] Provision Redis with authentication, TLS where supported, persistence policy, and monitoring.
- [ ] Provision package storage with sufficient capacity and a tested backup/restore path.
- [ ] Create the Kubernetes `unrealdb-secrets` Secret through a secret manager.
- [ ] Replace the example ingress hostname and TLS secret.
- [ ] Confirm the ingress controller supports the configured annotations.
- [ ] Configure DNS, TLS, WAF, request-size limits, and rate limiting.
- [ ] Disable public development viewers and HTTP cron endpoints.
- [ ] Create the first administrator through the CLI only.
- [ ] Import the canonical schema for a new database or apply reviewed migrations for an existing database.
- [ ] Verify the database user follows least privilege and cannot administer unrelated databases.
- [ ] Configure central logs, metrics, dashboards, and alert routing.
- [ ] Run a complete restore test.

### Before every release

- [ ] All quality checks passed.
- [ ] Container image scan passed or an approved exception exists.
- [ ] Deployment uses an immutable image digest.
- [ ] Schema changes are backward-compatible with the current and previous image.
- [ ] Database and storage backups are current.
- [ ] Capacity is sufficient for rollout surge and temporary files.
- [ ] Rollback image and procedure are known.
- [ ] Maintenance communication is prepared when required.

### After every release

- [ ] Web and worker rollouts completed.
- [ ] Liveness and readiness probes are healthy.
- [ ] External HTTPS smoke test passed.
- [ ] Authentication, search, upload, download policy, and one background job were smoke-tested.
- [ ] Error rate, latency, database load, Redis, queue age, and storage were reviewed.
- [ ] Release digest and operator approval were recorded.
- [ ] Temporary deployment credentials and artifacts were removed.

## Remaining platform work

- Add versioned database migrations and a schema-version table.
- Replace the generic kubeconfig deployment secret with cloud OIDC/workload identity.
- Add an application metrics endpoint or OpenTelemetry instrumentation.
- Move federation workers from HTTP-token endpoints to CLI/background jobs.
- Replace local package storage with object storage before broad horizontal scaling.
- Load-test concurrent queue claims before adding worker replicas.
- Build a rootless FPM/web-server image if the operating environment requires strict non-root workloads.
