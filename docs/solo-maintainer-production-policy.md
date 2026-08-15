# Solo-maintainer production engineering policy

## Supported operating model

UnrealDB is intentionally optimized for its real deployment:

- one production Windows host;
- Apache + PHP;
- one MySQL authority;
- local low-terabyte package storage;
- multiple local background-worker processes;
- hundreds of public users today, potentially low thousands later;
- a relatively small federation;
- several minutes of planned maintenance downtime is acceptable;
- one primary maintainer.

The system is a **modular monolith**, not a distributed platform. Internal boundaries exist to protect maintainability and correctness, not to create independently deployed services.

## Decisions

### Keep one authoritative database

MySQL owns catalog identity, projections, jobs, configuration, federation state and transactional control. Do not add a second authoritative datastore merely for architectural fashion.

### Keep the durable MySQL job queue

The job queue remains transactional with application state. Resource-class limits and concurrency keys are claim-time admission rules. Runtime age is operator information; it is not a lease timeout that steals or fails a live job.

### Keep package storage local

Package bytes remain on local filesystem storage for this deployment. Application workflows access them through the package-storage boundary so path validation, canonical placement, rollback and health checks are centralized.

Do not implement S3/NAS providers until the real deployment needs them.

### Prefer recovery over high availability

Several minutes of planned downtime is acceptable. Engineering effort therefore favors verified backup/restore and straightforward recovery over replicas, distributed filesystems, zero-downtime deployment and automatic failover.

### Keep the server-rendered frontend

Use PHP presentation components and progressive JavaScript. Do not introduce a SPA framework unless a real product interaction becomes materially harder to maintain with the current model.

### Direct read models are allowed

For read-heavy screens this is acceptable:

```text
Presentation -> Infrastructure Query -> MySQL
```

Do not add pass-through Application query services solely to satisfy textbook layering. Writes and important workflows continue to use stronger Application ports/use-case boundaries.

## P0 — Architecture guardrails

Automated maintainability contracts protect:

- Domain dependency purity;
- Application independence from Infrastructure/PDO;
- no new direct Application filesystem I/O;
- render-only reusable UI components;
- bounded/lock-aware queue claiming;
- explicit package-storage boundary;
- in-product operational diagnostics.

Two historical filesystem probes are explicitly baselined rather than hidden by the verifier:

- `Application/Catalog/CatalogPackageHeaderInspector.php` performs a bounded first-MiB package-header read;
- `Application/Upload/ProfiledUploadService.php` checks the temporary upload file and reads its size for result metadata.

Those exceptions are exact. They do not permit new Application filesystem access elsewhere, and they can be removed later when those workflows are next changed for functional reasons.

## P1 — Operational visibility

`system-operations.php` is the read-only operational console for the production host. It reports:

- configured vs active worker processes;
- queued/running jobs;
- oldest queued age;
- longest running age;
- resource-class blocking;
- concurrency-key blocking;
- actionable failed/dead-letter queue pressure;
- database version/size/file counts;
- package-storage availability/capacity/free space.

The page deliberately excludes completed/cancelled history from operational queue aggregation so diagnostics do not become a historical-table scan.

## P2 — Selective read-model extraction

Extract a page/API query when it has meaningful JOINs, aggregation, filtering, pagination, filesystem traversal, reusable semantics or performance-plan concerns.

Do not wrap every trivial lookup in a class.

Current reference extractions include:

- public game catalogue;
- metrics database snapshot;
- operational storage metrics;
- system operations snapshot.

## P3 — Package-storage boundary

The stable contract is:

```text
Application/Storage/Contract/PackageStoragePort
                    |
                    v
Infrastructure/Storage/LocalFilesystemPackageStorage
```

Verified imports and public downloads now share the same local path-security/storage boundary. The canonical verified package layout is unchanged.

## P4 — Backup and restore

The Windows production recovery tooling is documented in `windows-backup-recovery.md`.

Principles:

- DB-only backup may run online using a consistent transactional dump;
- coherent DB+storage backup requires explicit maintenance confirmation;
- incomplete backups are staged separately;
- checksums and full archive verification are mandatory before promotion;
- destructive restore requires exact target confirmation;
- schema and compact-metadata verification run after restore.

## P5 — Incremental JavaScript modules

Browser modularization is evolutionary. Shared infrastructure is extracted when a real feature uses it.

`assets/js/core/http.js` centralizes JSON transport/error/request-ID handling and is consumed by Game Manager missing-count loading.

Do not rewrite the stable Background Jobs polling/state controller solely to rearrange files. Decompose its API/state/render/selection/polling responsibilities when substantive functional changes make that work pay for itself.

## Explicitly deferred infrastructure

Do not introduce these without a measured trigger:

- microservices;
- Kafka/RabbitMQ;
- Redis;
- MySQL read replicas;
- Kubernetes as a production requirement;
- object storage;
- dedicated search infrastructure;
- multi-host distributed sessions/storage.

Potential triggers:

```text
public response load -> CDN/reverse proxy first
search dominates DB -> query/index work, then dedicated search if still required
single-host storage no longer fits -> shared/object storage
second web host becomes necessary -> shared sessions/storage
job claim contention becomes measurable -> reconsider queue technology
```

## Change policy

Architecture changes must answer at least one concrete question:

1. What correctness problem does this solve?
2. What duplicated responsibility does this remove?
3. What dependency does this invert or isolate?
4. What measured bottleneck does this address?
5. What recovery/operational task does this make materially safer?

“Cleaner” by itself is not enough justification for another broad refactor.

Risk classes:

- low: UI, docs, pure refactor, focused reads;
- medium: reporting, cache, search, admin settings;
- high: import, parser, metadata publication, storage mutation, dependencies, queue claiming;
- critical: schema, metadata format, destructive cleanup/restore.

Higher-risk changes require progressively stronger runtime/integrity validation.
