# UnrealDB catalog system architecture

## Status

The authoritative current implementation detail is documented in [`docs/architecture.md`](architecture.md). This document keeps the higher-level production topology and evolution strategy only; it must not duplicate stale runtime details.

## Objective

Scale UnrealDB without changing package parsing rules, game-profile behaviour, public URLs, federation semantics, catalog identities or the MySQL transactional model.

The system remains a **modular monolith with explicit Infrastructure boundaries**. Components should be extracted into separate services only when independent deployment/scaling pressure justifies that operational cost.

## Current production shape

```text
Browser / API / CLI
        │
        ▼
Presentation
        │
        ▼
Application policies / use cases
        │
        ▼
Domain contracts
        │
        ▼
Infrastructure
 ┌──────────────┬────────────────┬──────────────────┐
 │ MySQL        │ filesystem     │ process/runtime  │
 │ catalog/jobs │ package bytes  │ detached workers │
 └──────────────┴────────────────┴──────────────────┘
```

Current host assumptions:

- PHP web/API remains stateless apart from configured session storage.
- MySQL is the transactional catalog and durable-job store.
- Local filesystem package storage remains authoritative for physical bytes in the current deployment.
- `ue_background_jobs` is the durable queue.
- Detached PHP worker processes execute exact `JobType => JobHandler` routes.
- Verified package metadata uses the compact metadata/lookup architecture.

## Durable worker architecture

`PdoJobQueue` is the single application-facing durable queue implementation. It delegates to focused PDO collaborators for enqueue, parallel claim, lease lifecycle and crash recovery.

Workers claim ready rows with `FOR UPDATE SKIP LOCKED`; resource-class and concurrency-key admission is coordinated only for the relevant class/key rather than by a queue-wide mutex. This allows independent work to claim concurrently while preserving resource limits.

Administrator-visible job status is a generated/indexed read model (`ue_background_jobs.display_status`) rather than JSON parsing across historical rows on each live poll.

## Upload/import architecture

Upload Bucket v2 uses durable browser staging and background processing:

```text
browser hash/chunk upload
  → durable chunk staging
  → CatalogBucketBatchFinalizer / CatalogBucketBatchQueue
  → PROCESS_BUCKET_UPLOAD
  → CatalogBucketUploadJobHandler
  → CatalogBucketIdentityProcessor
  → CatalogBucketPackageOperationsService
      ├─ CatalogPackageIdentityHasher
      ├─ CatalogUploadBucketStorage
      └─ CatalogUnverifiedPackageIndexer
```

The former Upload Bucket processor monolith and Reflection bridge are removed. Remaining procedural Unreal-reader/scanner functions are treated as an explicit compatibility boundary, not duplicated implementations.

## Read architecture

Intent-specific query objects own SQL. Presentation pages/APIs should validate transport input, call a query/use case and render/serialize its result.

High-cardinality operational reads use:

- keyset pagination where practical;
- indexed exact identity lookups;
- compact search projections for broad job search;
- short caches only for bounded-staleness aggregates that do not drive worker scheduling.

Live `queued`, `running` and `ready` worker counts remain exact rather than cached because operators use those values to diagnose queue behaviour.

## Target scale-up topology

```text
                         ┌──────────────────────────┐
                         │ CDN / WAF / TLS / limits│
                         └────────────┬─────────────┘
                                      │
                     ┌────────────────┴───────────────┐
                     │                                │
              ┌──────▼───────┐                 ┌──────▼───────┐
              │ PHP web/API  │                 │ PHP web/API  │
              │ node         │                 │ node         │
              └──────┬───────┘                 └──────┬───────┘
                     │                                │
            ┌────────┴──────────┬─────────────────────┘
            │                   │
     ┌──────▼──────┐    ┌───────▼────────┐
     │ Redis       │    │ MySQL primary  │
     │ cache/session│   │ catalog + jobs │
     └─────────────┘    └───────┬────────┘
                                │
                         ┌──────▼───────┐
                         │ read replicas│
                         └──────────────┘

                 ┌──────────────────────────────┐
                 │ separately supervised workers│
                 └──────────────────────────────┘
```

Object storage, Redis, read replicas or an external queue broker are future adapters, not prerequisites for the current single-host deployment.

## Evolution rules

1. Measure a real bottleneck before introducing another datastore/service.
2. Preserve Application/Domain contracts while replacing Infrastructure adapters.
3. Keep writes and read-after-write paths on the primary transactional store.
4. Keep physical package identity immutable through storage migrations.
5. Do not split the core catalog merely to match a microservice diagram.
6. Delete superseded implementations after callers migrate; do not retain backup code paths.
7. Treat procedural parser compatibility as migration debt behind explicit adapters, not as an excuse to create a second parser implementation.

## Deployment validation

After schema/application changes:

```text
php catalog/bin/migrate.php status
php catalog/bin/migrate.php migrate --dry-run
php catalog/bin/migrate.php migrate --lock-timeout=60
php catalog/bin/migrate.php verify
php catalog/bin/audit-legacy-runtime-references.php
php catalog/bin/verify-architecture-refactor.php --database
```

Then validate real Upload Bucket, staged import, unverified promotion, dependency refresh and worker-pool concurrency under representative load.
