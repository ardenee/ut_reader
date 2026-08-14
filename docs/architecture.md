# UnrealDB Architecture Baseline

This document describes the current runtime architecture after the August 2026 queue/upload refactor. Architecture-only changes preserve file identity, package parsing rules, import outcomes, routes, API payloads, job types, queue names, dedupe keys, retry policy, federation semantics and public behaviour.

## Runtime shape

UnrealDB is a **modular monolith**. The namespaced `catalog/src` tree is the primary implementation surface:

```text
Presentation / HTTP / CLI
              │
              ▼
Application use cases + ports
              │
              ▼
Domain policy + value objects
              │
              ▼
Infrastructure adapters
    PDO / filesystem / package readers / workers / federation
```

`catalog/lib` remains a compatibility surface for older global callers. New implementation code belongs under `catalog/src`.

## Layer responsibilities

### Presentation

Entry points authenticate/authorize, validate transport input, invoke a use case/query and serialize/render the result. Request/session/CSRF state belongs here or in an explicit legacy compatibility facade.

`LegacySupportHooks` still provides limited page compatibility, but all HTML response rewrites now share one output buffer instead of stacking several full-response copies.

### Application

Application owns use-case sequencing and contracts, not PDO/filesystem/process implementations. Current examples include:

- durable job execution and worker status policy;
- profile upload policy;
- unverified-file move/import/delete orchestration;
- search repository contracts and search use case.

### Domain

Domain contains stable concepts such as `JobType`, `ClaimedJob` and `JobResourcePolicy`. `JobResourcePolicy` is pure default classification/concurrency policy; it does not resolve PDO/environment settings.

### Infrastructure

Infrastructure owns persistence, filesystem, package readers/adapters, compact metadata publication, search SQL, process launch and federation/external integrations.

## Durable background jobs

There is one authoritative `JobQueue` implementation: `PdoJobQueue`, backed by focused collaborators:

```text
PdoJobQueue
    ├── PdoJobEnqueuer
    ├── PdoJobClaimer
    ├── PdoJobLeaseStore
    └── PdoJobRecovery
```

### Claim/admission flow

1. Select a ready candidate with `FOR UPDATE SKIP LOCKED`.
2. If a worker has preferred root affinity, try that root first. Affinity is preference-only.
3. Acquire short MySQL admission locks for the resource class and optional concurrency key.
4. Resolve the **current** resource limit from saved settings, with environment/persisted values as compatibility fallback.
5. Re-check running resource/concurrency ownership while the admission locks are held.
6. Transition the selected row to `running`, assign `worker_id` and a fresh `lease_token`, then commit.
7. If the preferred root is blocked or empty, fall back immediately to unrelated runnable work.

This makes resource/concurrency admission atomic without restoring one queue-wide claim mutex. Administrator resource-limit changes are constant-time settings writes; current queued jobs use the new limit on their next claim and are not mass-rewritten.

### Worker ownership and recovery

A worker process acquires a connection-scoped MySQL named lock through `PdoWorkerOwnership` for `(queue, worker_id)`.

That lock is the process-liveness authority:

- healthy long-running jobs retain ownership regardless of elapsed time;
- when the process/database connection dies, MySQL releases the ownership lock automatically;
- orphan recovery can then recover rows belonging to a worker whose ownership lock no longer exists;
- `lease_token` remains the fencing token for complete/fail/defer/cancel writes.

`PdoJobRecovery::recoverExpiredLeases()` deliberately does not implement timeout-based job theft.

Host-local detached-worker state files are supervisory only. The same database-visible ownership model applies to Windows detached workers, ordinary CLI workers, Docker and Kubernetes workers.

### Workflow coordination

Large operations use durable root coordinators plus bounded/idempotent child units. Completed child units remain durable and are not replayed after coordinator retries.

`PdoWorkflowChildStateQuery` is the canonical child status-count read model. Coordinators should use it instead of repeating `status/COUNT(*)` SQL.

`BackgroundJobDisplaySql` is the canonical operator-status expression used by both row and count queries, preventing Background Jobs reporting drift.

### Host-local detached workers

`CatalogDetachedWorker` remains the stable supervisor facade but delegates its former mixed responsibilities to:

- `CatalogWorkerRuntimeStateStore` — runtime JSON/files, file locks and log tails;
- `CatalogWorkerProcessLauncher` — PHP CLI resolution and Windows/POSIX process launch;
- `CatalogWorkerCodeVersion` — stale-code fingerprinting.

`CatalogJobWorkerFactory` registers handlers lazily, so a worker constructs only the handler graph it actually uses.

## Upload Bucket v2

Canonical flow:

```text
browser
  │ client-side inspection/hash where applicable
  ▼
resumable staging
  ▼
durable Upload Bucket jobs
  ▼
unverified metadata/indexing
  ▼
administrator promotion/import
  ▼
verified package + compact metadata
  ▼
durable dependency/search/projection maintenance
```

The former upload page is only a compatibility redirect; Upload Bucket v2 is the implementation.

### Profiled upload and failed retention

`ProfiledUploadService` owns upload-batch Application policy and passes the authenticated user identity explicitly to `FailedUploadPreserver`. Infrastructure retains failed files without reading session state.

### Package import

`scanner_scan_uploaded_file()` is a thin compatibility delegate to the namespaced importer. The importer is intentionally being decomposed conservatively through focused persistence/storage/metadata/dependency collaborators because parser selection, source identity, duplicate policy and maintenance replacement are tightly coupled Unreal package semantics. A folder-only rewrite is not considered an architecture improvement.

## Unverified-file actions

`Application/Unverified/CatalogUnverifiedActionService` owns move/import/delete sequencing and result semantics.

Application ports:

- `CatalogUnverifiedQueueMutation`
- `CatalogUnverifiedImporter`

Infrastructure supplies filesystem/PDO/import adapters. The historical Infrastructure action service is now a thin compatibility/composition adapter so existing endpoints retain their public API.

## Verified compact metadata

Verified package metadata uses the format-2 compact container/projection model.

Publication state is explicit on `ue_files`:

- `metadata_status = pending` — publication/verification is in progress or still requires repair;
- `ready` — format-2 metadata publication verified successfully;
- `failed` — publication failed; `metadata_error` and `metadata_updated_at` record the failure.

`VerifiedFileCompactMetadataFinalizer` marks pending before work, ready on successful verification, and failed on publication errors. A verified primary row can therefore no longer silently appear fully healthy when compact publication is incomplete.

## Dependency projections

Dependency resolution/rebuilding is Infrastructure-owned through focused persistence/metadata collaborators and durable job coordinators. Full Sync uses independently recoverable per-file reimport/dependency units followed by bounded final projection/stat publication.

## Search

Application search is persistence-free:

- `Application/Search/CatalogSearchRepository` is the port;
- `Application/Search/CatalogSearchService` owns the use-case call;
- `Infrastructure/Search/PdoCatalogSearchRepository` owns PDO/SQL;
- `catalog/lib/CatalogSearchService.php` is the existing global compatibility/composition facade.

Search order remains exact identity → prefix → aliases → compact object/path matches → bounded contains fallback. Broad global contains searches are additionally partitioned/capped by game. Leading-wildcard fallback remains a potential large-data cost; measure production latency/query plans before adding an n-gram projection or external search service.

## Reader architecture

- UE1/UE2 use memory-bounded legacy readers.
- UE3 uses the strict Epic serialized-layout reader. For compressed packages, the complete compressed package buffer is released before logical reconstruction and chunks are reread from disk one at a time, reducing peak memory without changing the serialized format rules.
- UE4/UE5 use their configured generation-specific readers.

Legacy/reference readers are not made canonical merely because they expose a unified API.

## Federation

Federation remains a bounded context sharing catalog identities/storage. Existing protocol/wire semantics must be preserved while older page-local orchestration is migrated behind explicit query/use-case boundaries.

## Validation discipline

There is deliberately no required application GitHub Actions quality workflow. Validation is local/manual so repository changes do not create a noisy email gate.

Before deployment or after this class of refactor, run:

```text
php -l <changed PHP files>
php catalog/bin/migrate.php status
php catalog/bin/migrate.php migrate --dry-run
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
php catalog/bin/verify-queue-runtime-invariants.php
php catalog/bin/verify-job-root-affinity-contract.php
php catalog/bin/verify-job-claim-concurrency.php --run
php catalog/bin/audit-legacy-runtime-references.php
```

The real-MySQL concurrency verifier uses uniquely named temporary queue/resource rows, launches concurrent PHP claimers, verifies resource limits/concurrency-key exclusion/preferred-root fallback/worker ownership, and cleans up afterward.

Architecture marker scripts are secondary guardrails only; they are not substitutes for real queue/database behaviour tests.

## Remaining optimization rule

Do not add Redis/RabbitMQ queueing, Elasticsearch, microservices or new data projections merely to make the design look more distributed. Add infrastructure only after measurement identifies a real bottleneck and the new component has a clear operational benefit.

## Non-negotiable behaviour-preservation rules

- Do not change routes/API payloads/job types/queue names/dedupe keys/retry semantics during architecture cleanup.
- Do not change MD5/SHA/GUID/package-name identity semantics without an explicit behavioural migration.
- Do not change search-result semantics merely to optimize a query.
- Do not steal legitimate running jobs because a timer elapsed.
- Preserve Windows detached-worker behaviour as well as container/CLI workers.
- Runtime code must not silently mutate schema; install/migrations own DDL.
- Delete superseded implementations only after callers have migrated and compatibility is proven unnecessary.
