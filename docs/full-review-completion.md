# Full production review completion

This runbook describes the repository changes delivered from the production-readiness review and the external actions required to activate them safely.

## Application changes completed

### Durable ingestion

Profiled Upload and PAK Import now copy incoming files into controlled job storage and enqueue background work. The worker performs redirect decompression, PAK extraction, package parsing, identity matching, physical storage, database persistence, dependency refresh and unverified fallback. Browser closure no longer interrupts the work.

The `import-heavy` resource class defaults to one concurrent job per queue. Staged originals are immutable and retained until successful import, deterministic unverified retention, cancellation or age-based cleanup.

### Storage maintenance

Two durable maintenance jobs are available:

```text
php catalog/bin/job-control.php enqueue-reconcile-unverified --max-files=1000
php catalog/bin/job-control.php enqueue-prune-artifacts --incoming-max-age-seconds=172800
```

Compose can run `deploy/docker/maintenance-loop.sh`; Kubernetes includes daily CronJobs with `concurrencyPolicy: Forbid`.

### Authentication and administrator security

Migration 008 adds encrypted TOTP MFA and one-time recovery codes. Configure a dedicated application security key before enrollment:

```text
UNREALDB_SECURITY_MASTER_KEY=base64:<32-byte-key>
```

Generate a key with a trusted secret manager or operating-system cryptographic tool. Do not reuse the database password or federation master key.

Administrators enroll and reauthenticate at `catalog/admin-security.php`. MFA-enabled accounts cannot use persistent remember-me tokens. Administrator API mutations require a recent password and MFA confirmation; the default recent-auth window is 600 seconds.

### Federation trust

Migration 007 adds Ed25519 public-key rotation and revocation while retaining HMAC compatibility. Federation JSON and binary transfers use HTTPS-only, DNS-pinned, no-redirect cURL requests and reject private/link-local/reserved target addresses.

Generate a local signing key:

```text
php catalog/bin/federation-key.php generate
```

Store the returned private seed only in the deployment secret:

```text
UNREALDB_FEDERATION_ED25519_PRIVATE_KEY=<base64url-seed>
UNREALDB_FEDERATION_SIGNATURE_ALGORITHM=ed25519
```

Install the remote public key for each peer:

```text
php catalog/bin/federation-key.php set-peer --peer-id=1 --public-key=<remote-public-key>
```

Keep `UNREALDB_FEDERATION_SIGNATURE_ALGORITHM=hmac-sha256` until both sides have migration 007, sodium support and each other's public key. Use `use-hmac` for compatibility rollback and `revoke-peer` to reject a compromised key.

### Public request controls

Application-side limits now cover broad search, individual downloads, package generation and federation join submissions. Defaults may be adjusted with:

```text
UNREALDB_PUBLIC_SEARCH_MAX_REQUESTS=60
UNREALDB_PUBLIC_SEARCH_WINDOW_SECONDS=600
UNREALDB_PUBLIC_DOWNLOAD_MAX_REQUESTS=30
UNREALDB_PUBLIC_DOWNLOAD_WINDOW_SECONDS=600
UNREALDB_FEDERATION_JOIN_MAX_REQUESTS=5
UNREALDB_FEDERATION_JOIN_WINDOW_SECONDS=3600
```

Exact MD5, SHA1 and GUID input uses indexed identity queries and returns without executing broad wildcard stages. Broad search requires at least three characters and remains limited to 200 files.

### Metrics

`catalog/api/v1/metrics.php` exposes Prometheus text metrics for:

- queue status and resource class counts
- lease recoveries and oldest queued job age
- verified/unverified file counts and bytes
- generated artifact, staged import and federation incoming storage
- total and free filesystem capacity

Set a long random bearer value:

```text
UNREALDB_METRICS_TOKEN=<random-token>
```

Send it as `Authorization: Bearer <token>`. An authenticated administrator may also view the endpoint interactively.

### Browser and PHP policy

The production Apache configuration enforces PHP error display off with `php_admin_flag`, blocks source/storage/development directories and emits CSP, frame, MIME, referrer, permissions and cross-origin isolation headers. The CSP currently permits same-origin inline script/style for legacy pages; removing those remaining inline blocks is a future tightening step, not a missing baseline header.

## Deployment sequence

1. Pause workers and browser maintenance operations.
2. Create a database and storage backup.
3. Deploy the immutable image.
4. Run migration dry-run, migration, then verification through migration 008.
5. Configure security, metrics and optional Ed25519 secrets.
6. Start web, worker and maintenance services.
7. Test login and MFA before ending the current administrator session.
8. Test one package upload and one PAK job.
9. Test cancellation, reconciliation and artifact pruning.
10. Test exact MD5/GUID search and a public download limit.
11. Validate metrics scraping.
12. Rotate one federation peer to Ed25519 and confirm bidirectional JSON and binary transfers before rotating others.

## Compose

```text
docker compose -f compose.yaml -f compose.security.yaml build
docker compose -f compose.yaml -f compose.security.yaml run --rm migrate
docker compose -f compose.yaml -f compose.security.yaml up -d
```

## Kubernetes

The base kustomization includes the security ConfigMap patch and maintenance CronJobs. Supply the following keys through the existing `unrealdb-secrets` Secret or an external secret operator:

- `UNREALDB_SECURITY_MASTER_KEY`
- `UNREALDB_METRICS_TOKEN`
- `UNREALDB_FEDERATION_MASTER_KEY`
- `UNREALDB_FEDERATION_ED25519_PRIVATE_KEY` when Ed25519 is activated
- database and Redis credentials

Use immutable image digests in production overlays instead of `latest`.

## Backup and restore

Create a consistent compressed database and storage backup:

```text
UNREALDB_BACKUP_PATH=/backups/unrealdb deploy/backup/unrealdb-backup.sh
```

The script writes `database.sql.gz`, `storage.tar.gz`, `SHA256SUMS` and metadata, then removes directories older than `UNREALDB_BACKUP_RETENTION_DAYS`.

Restore only during an approved outage:

```text
export UNREALDB_RESTORE_CONFIRM="$UNREALDB_DB_NAME"
export UNREALDB_RESTORE_STORAGE=1
deploy/backup/unrealdb-restore.sh /backups/unrealdb/20260719T020000Z
```

The restore verifies checksums and runs migration verification. Quarterly restore tests should use an isolated database and copied storage rather than production paths.

## External platform actions

The repository cannot provision or operate the following by itself:

- managed MySQL point-in-time recovery
- managed Redis and secret-manager integration
- TLS certificates, DNS and WAF policy
- workload identity and cloud IAM
- off-site/object-storage backup replication
- alert routing and incident escalation
- production storage sizing
- real retail package fixtures that cannot legally be committed

These are deployment obligations. The code, manifests, metrics and backup commands required to integrate them are present, but successful execution must be verified in the selected environment.

## Known retained compatibility boundary

A small amount of legacy schema-inspection code remains in older scanner/unverified helpers. Production database users should not have DDL privileges, and `migrate verify` is required before web/worker startup. Runtime schema mutation is no longer a supported deployment path. Removing the final compatibility branches requires a controlled compatibility-window decision for older shared-host installations.
