# Full production review completion

This runbook records the major production-readiness work that remains relevant to the supported **single-host Windows deployment** and the external actions required to activate it safely.

Docker, Docker Compose and Kubernetes are no longer deployment targets for UnrealDB and their repository deployment assets have been removed.

## Application changes completed

### Durable ingestion

Profiled Upload and PAK Import move work into controlled durable staging and enqueue background processing. Workers perform redirect decompression, PAK extraction, package parsing, identity matching, physical storage, database persistence, dependency refresh and unverified fallback. Browser closure does not interrupt work after the durable staging boundary.

The `import-heavy` resource class limits concurrent heavy imports independently from the total worker-process count. Staged originals are retained until successful import, deterministic unverified retention, cancellation or bounded cleanup.

### Storage maintenance

Durable maintenance jobs include:

```text
php catalog/bin/job-control.php enqueue-reconcile-unverified --max-files=1000
php catalog/bin/job-control.php enqueue-prune-artifacts --incoming-max-age-seconds=172800
```

On production these are scheduled through the normal Windows operations path, such as Task Scheduler or an administrator-controlled maintenance job, rather than a container scheduler.

### Authentication and administrator security

Administrator MFA/security state uses the application security master key where those features are enabled:

```text
UNREALDB_SECURITY_MASTER_KEY=base64:<32-byte-key>
```

Generate the key with a trusted operating-system cryptographic tool. Do not reuse the database password or federation master key.

Recent-auth requirements should remain limited to genuinely security-sensitive operations. Routine background-job administration should not be made impractical by unnecessary reauthentication prompts.

### Federation trust

Federation supports signed requests, replay protection, bounded request handling and encrypted peer secrets. Configure the federation encryption key before using strict encrypted-secret mode:

```text
UNREALDB_FEDERATION_MASTER_KEY=base64:<32-byte-key>
```

When Ed25519 is used, generate/manage the local signing key with the existing federation key tooling and keep private key material outside the repository.

### Public request controls

Application-side controls cover broad search, downloads, package generation and federation join submissions. Exact MD5, SHA1 and GUID input uses indexed identity queries and avoids broad wildcard stages.

### Metrics

`catalog/api/v1/metrics.php` exposes Prometheus-format operational metrics including:

- queue status and resource-class counts;
- job recoveries and oldest queued-job age;
- verified/unverified file counts and bytes;
- operational storage usage;
- total and free filesystem capacity.

Set a long random bearer value when machine scraping is used:

```text
UNREALDB_METRICS_TOKEN=<random-token>
```

An authenticated administrator may also view the endpoint interactively.

### Browser and PHP policy

Production PHP error display is disabled by the shared runtime security boundary. Apache/.htaccess protections block internal source/storage paths and the application emits CSP and related browser-security headers.

## Supported production platform

The production server is one Windows host running:

```text
Apache 2.4
PHP 8.5
MySQL 8.4
local catalog/package storage
independent PHP worker processes
Windows backup/maintenance scheduling
```

MySQL remains the durable job queue and application system of record. Redis, external message brokers, replicas and distributed storage are not required for this deployment.

## Deployment sequence

1. Record the currently deployed Git revision.
2. Review changed PHP, migrations and operational configuration.
3. Create/verify a current recovery point when schema or storage changes require one.
4. Run migration status and dry-run.
5. Drain/stop workers before replacing worker-executed code when required.
6. Update the production code to the intended Git revision.
7. Apply and verify migrations.
8. Restart Apache/PHP only when required by the changed runtime/configuration.
9. Restart/reconcile workers so all processes use the current code.
10. Test `live.php` and `readiness.php`.
11. Test representative authentication, search, upload/import, download and background-job paths affected by the release.
12. Review Apache/PHP/worker logs, failed jobs, MySQL pressure and storage capacity.

## Deployment-host verification

Use the real production PHP/MySQL/filesystem environment for the important checks:

```text
php catalog/bin/migrate.php status
php catalog/bin/migrate.php migrate --dry-run
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
php catalog/bin/verify-security-hardening.php
php catalog/bin/verify-system-readiness-contract.php
php catalog/bin/verify-system-readiness-contract.php --run
php catalog/bin/verify-queue-runtime-invariants.php
php catalog/bin/verify-job-claim-concurrency.php --run
```

Run focused subsystem verifiers for the files changed in each release.

## Backup and restore

The supported Windows recovery tooling lives in `deploy/backup/`:

```text
unrealdb-backup-readiness.ps1
unrealdb-backup.ps1
verify-unrealdb-backup.ps1
unrealdb-restore.ps1
```

A database-only backup can normally be taken online. A coherent database + package-storage recovery point requires controlled write quiescence so the two represent one consistent application state.

Completed backups are not considered recovery points until verification succeeds. Perform periodic restore drills against disposable database/storage targets.

See `docs/windows-backup-recovery.md` for the full procedure.

## External production actions

The repository cannot operate the Windows host by itself. Production operations must provide:

- HTTPS certificate/DNS/firewall configuration;
- Windows service supervision for Apache/MySQL/workers;
- disk-space and process-health monitoring;
- backup scheduling and independent backup storage;
- alert routing for critical failures;
- production storage sizing;
- private real-world package fixtures that cannot legally be committed.

## Known compatibility boundary

Some compatibility code remains for older scanner/unverified data paths. `migrate verify` is required before relying on a release, and runtime schema mutation should not be treated as the production migration mechanism.

Database migrations and code rollback must remain compatible. If a migration is not backward compatible, use a forward fix rather than blindly reverting PHP code against a newer schema.
