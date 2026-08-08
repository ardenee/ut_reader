# UnrealDB Architecture Baseline

This document describes the current runtime architecture after the August 2026 queue/upload refactor. Architecture-only changes preserve file identity, package parsing rules, import outcomes, routes, API payloads, job types, queue names, dedupe keys, retry policy, federation semantics and public behaviour.

## Runtime shape

UnrealDB is a **modular monolith**. The namespaced `catalog/src` tree is the primary implementation surface:

```text
Presentation
    browser pages / api/v1 / CLI entry points
              │
              ▼
Application
    use cases, policies, contracts, worker orchestration
              │
              ▼
Domain
    stable job types, claimed-job data, resource policy
              │
              ▼
Infrastructure
    PDO queries, queue persistence, filesystem storage,
    package readers/adapters, imports, workers, federation
```

The intended direction remains a modular monolith with explicit boundaries, not microservices for their own sake.

## Layer responsibilities

### Presentation

- `catalog/*.php`
- `catalog/federation/*.php`
- `catalog/api/v1/*.php`
- `catalog/bin/*.php`
- `catalog/src/Presentation/*`

Entry points authenticate/authorize, validate transport input, invoke a use case/query and serialize/render the result. New entry points must not contain persistence SQL, worker scheduling policy, filesystem placement policy or package-reader internals.

The Background Jobs APIs now follow this rule:

- `job-worker-status.php` delegates queue counts to `PdoBackgroundJobOperationalQuery` and state policy to `CatalogWorkerStatusPolicy`.
- `job-status-cursor.php` delegates search/count/page SQL to `PdoBackgroundJobBrowserQuery` and result mapping to `CatalogBackgroundJobResultHydrator`.
- `job-status.php` preserves the compatibility offset contract through `PdoBackgroundJobOffsetQuery` and the same shared hydrator.
- `job-bulk.php` delegates mutation SQL to `PdoBackgroundJobBulkAction`.

### Application

Application code owns orchestration and policy, not PDO/filesystem implementations. Important job components include:

- `Application/Jobs/JobQueue.php`
- `Application/Jobs/JobWorker.php`
- `Application/Jobs/JobExecutionContext.php`
- `Application/Jobs/CatalogWorkerStatusPolicy.php`

`CatalogWorkerStatusPolicy` is deliberately pure: it derives administrator-visible worker state from process state and durable queue counts without HTTP, PDO or filesystem dependencies.

### Domain

Domain code contains stable business concepts such as `JobType`, `ClaimedJob` and `JobResourcePolicy`. It must not depend on PDO, HTTP, filesystem or process-launching implementations.

### Infrastructure

Infrastructure owns persistence, filesystem, package parsing/adapters and process management.

## Durable background jobs

There is now **one authoritative JobQueue implementation**: `PdoJobQueue`. The former worker-specific queue implementation has been removed.

`PdoJobQueue` is a façade over focused persistence collaborators:

```text
PdoJobQueue
    ├── PdoJobEnqueuer
    │     durable inserts + active deduplication
    ├── PdoJobClaimer
    │     parallel claim + resource/concurrency admission
    ├── PdoJobLeaseStore
    │     heartbeat + completion + failure + cancellation
    └── PdoJobRecovery
          expired-lease recovery + explicit retry
```

Shared validation/time/JSON rules live in `PdoJobQueueSupport` rather than being duplicated across implementations.

### Claim flow

The old queue-wide claim mutex has been removed.

Current flow:

```text
worker
  │
  ├─ opportunistic expired-lease recovery coordinator
  │
  ▼
SELECT next ready row
FOR UPDATE SKIP LOCKED
  │
  ▼
short resource-class coordination lock
  │
  ├─ indexed running count < resource_limit
  │
  └─ optional short concurrency-key coordination lock
          │
          └─ indexed check that same key is not already running
  │
  ▼
lease selected row
  │
  ▼
commit
```

Workers assigned to unrelated resource classes can claim work concurrently. Jobs sharing a resource class are admitted atomically against the configured class limit, and jobs sharing a `concurrency_key` remain mutually exclusive.

The existing queue indexes `(queue_name,status,resource_class)` and `(queue_name,status,concurrency_key)` support the admission reads; no duplicate indexes are required.

### Lease lifecycle

Completion, heartbeat, failure and cancellation now use `PdoJobLeaseStore` for every worker/API caller. The former divergence between one-hour and six-hour lease caps is gone; the authoritative persistence layer supports the existing long-running six-hour package-reader lease contract.

### Display status read model

Administrator display status is no longer repeatedly derived from `result_json` during live list/count queries.

Migration `202608080001_background_job_display_status.php` adds a **stored generated** `ue_background_jobs.display_status` column derived atomically from `status` + `result_json`, plus indexes for queue/display filtering.

This avoids a second mutable source of truth while making Failed/Completed counters and filters indexable.

## Upload Bucket v2

Canonical flow:

```text
browser
  │ extension checks + ordinary-file MD5/SHA-1
  ▼
upload-bucket-chunk.php
  │ bounded resumable staging
  ▼
upload-bucket-batch.php
  │ CatalogBucketBatchQueue
  ▼
ue_background_jobs
  │ PROCESS_BUCKET_UPLOAD
  ▼
CatalogBucketUploadJobHandler
  │
  ├─ ordinary package: copy + verify browser identity
  └─ redirect wrapper: decompress + calculate package identity
  │
  ▼
CatalogBucketIdentityProcessor
  │
  ├─ CatalogUploadDuplicateDetector
  └─ CatalogBucketPackageOperations
          │
          └─ CatalogBucketPackageOperationsService
                 ├─ CatalogPackageIdentityHasher
                 ├─ CatalogUploadBucketStorage
                 └─ CatalogUnverifiedPackageIndexer
```

The old `CatalogBucketUploadProcessor` monolith and `LegacyCatalogBucketPackageOperations` Reflection bridge have been deleted. No Upload Bucket production path reaches private methods through `ReflectionMethod`.

### Procedural reader compatibility boundary

The Unreal reader/scanner ecosystem still exposes stable procedural `scanner_*`, `catalog_*` and `uvf_*` contracts used elsewhere in the application. The new Upload Bucket services no longer scatter those calls through business logic; `CatalogUnverifiedPackageRuntime` is the explicit Infrastructure adapter for the package-reader/unverified-storage operations required by this path.

That adapter is a compatibility boundary, **not** a parallel implementation. As scanner internals become namespaced, its methods can be replaced one at a time without changing Upload Bucket orchestration.

## Verified metadata and dependencies

Verified package metadata uses the compact per-file metadata/lookup model. Retained raw Names/Imports/Exports compatibility access is audited; new verified reads must not bypass compact compatibility sources.

Dependency resolution/rebuilding is Infrastructure-owned through:

- `Persistence/PdoDependencyResolver.php`
- `Persistence/PdoCatalogDependencyRebuilder.php`
- `Persistence/PdoDependencyReadSource.php`
- `Jobs/CatalogAffectedDependencyRefreshCoordinator.php`
- `Metadata/CompactDependencyRebuilder.php`

`php catalog/bin/audit-legacy-runtime-references.php` guards this boundary.

## Other primary flows

### Profiled upload / staged import

1. HTTP/API validates the game/profile and stages bytes.
2. `IMPORT_STAGED_PACKAGE` or `IMPORT_STAGED_PAK` is enqueued.
3. Worker handlers read package/container metadata.
4. Catalog rows + compact projections are persisted.
5. Dependency/search/projection maintenance remains durable background work.

### Local source scan

1. Enumerate configured source tree.
2. PAK containers are queued separately.
3. Package fingerprints are compared with known physical/catalog identities.
4. New/changed files are scanned/imported; failures enter unverified review.
5. Source-relative identity is retained.

### Unverified promotion

1. Read paginated unverified inventory.
2. Resolve physical staged row/file.
3. Reuse valid staged identity/metadata.
4. Validate selected game/profile/package identity.
5. Promote to verified catalog state.
6. Queue heavy dependency/projection work.

### Search

Exact hashes/GUIDs/package identities remain direct indexed lookups. Broad textual search uses bounded/search-projection paths where available. Leading-wildcard compatibility searches remain a known scaling concern in older non-job areas and should continue moving to explicit projections without changing result semantics.

### Federation

Federation remains a bounded context sharing catalog identities/storage. Some older federation pages still contain page-local orchestration/SQL and should migrate behind intent-specific query/use-case boundaries without changing the wire protocol.

## Resolved architecture debt in this refactor

- Removed duplicate `WorkerJobQueue` lifecycle implementation.
- Split queue enqueue/claim/lease/recovery responsibilities.
- Removed queue-wide claim serialization.
- Removed correlated candidate resource/concurrency subqueries.
- Materialized/indexed Background Jobs display status without creating mutable duplicate state.
- Removed Upload Bucket Reflection/private-method bridge.
- Removed superseded Upload Bucket monolith.
- Centralized Upload Bucket hashing/storage/unverified indexing.
- Centralized Background Jobs result hydration.
- Removed SQL from the worker-status and bulk job endpoints.
- Moved live cursor/offset job query construction to Infrastructure.
- Isolated job-search procedural compatibility behind one adapter.
- Updated legacy metadata audit ownership for the new indexer.

## Remaining architectural debt

These are repo-wide areas outside the job/upload refactor, not reasons to restore the removed implementations:

1. **Procedural `catalog/lib` compatibility surface.** Migrate callers by bounded context; delete delegates when no active caller remains.
2. **Scanner compatibility modules.** Continue moving parser/path/import responsibilities behind namespaced collaborators while retaining stable parser behaviour.
3. **Large older controllers.** `unverified-files-action.php`, some federation pages, backup paths and maintenance pages still own orchestration/SQL.
4. **Broad wildcard search outside the job subsystem.** Continue replacing proven hot paths with parity-tested indexed projections.
5. **`CatalogDetachedWorker` size.** Process launch, runtime-state storage, pool reconciliation and diagnostics can be separated further after the new queue path has production validation.
6. **Runtime schema verification in older compatibility paths.** DDL belongs only to install/migrations; runtime may verify schema and report an administrator action.

## Validation discipline

The repository intentionally does not rely on a broad automated PHP test suite. Architecture changes therefore require:

- `php -l` on every changed PHP file;
- `php catalog/bin/migrate.php migrate --dry-run` before applying schema changes;
- `php catalog/bin/migrate.php migrate` then `verify`;
- `php catalog/bin/audit-legacy-runtime-references.php`;
- architecture-boundary verification;
- real Background Jobs / Upload Bucket / unverified promotion testing under worker load.

## Non-negotiable behaviour-preservation rules

- Do not change routes/API payloads/job types/queue names/dedupe keys/retry semantics during architecture cleanup.
- Do not change MD5/SHA/GUID/package-name identity semantics without an explicit behavioural migration.
- Do not change search-result semantics merely to optimize a query.
- Do not impose automatic short timeouts on legitimate long-running package work.
- Preserve Windows detached-worker behaviour.
- Runtime code must not silently mutate schema.
- Delete superseded implementations rather than retaining backup copies after callers migrate.
