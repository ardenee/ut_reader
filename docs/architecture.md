# UnrealDB Architecture Baseline

This document describes the current runtime architecture and the intended refactoring direction. It is deliberately behavior-preserving: refactors should move responsibilities behind stable boundaries without changing file identity, queue semantics, import results, search results, federation behavior, or public routes.

## Runtime shape

UnrealDB is a modular monolith in transition. The namespaced `catalog/src` tree provides Domain, Application, Infrastructure and Presentation layers, while a large legacy compatibility layer remains under `catalog/lib` and top-level PHP routes.

### Presentation / entry points

- Browser pages: `catalog/*.php` and `catalog/federation/*.php`
- JSON API: `catalog/api/v1/*.php`
- CLI / workers: `catalog/bin/*.php`
- Shared request bootstrap: `catalog/lib/CatalogSupport.php`, `catalog/bootstrap.php`, `catalog/src/Presentation/Http/*`

Entry points should validate HTTP/CLI input, resolve dependencies at the composition boundary, invoke one application use case and render/serialize the result. They should not own SQL, filesystem policy, worker lifecycle or parser internals.

### Domain

`catalog/src/Domain` contains stable business concepts such as durable job types and resource policies. Domain code must not depend on Application, Infrastructure, PDO, HTTP, filesystem or process-launching implementations.

### Application

`catalog/src/Application` contains use-case orchestration and ports. Application code may depend on Domain and Application contracts. Existing direct PDO/Infrastructure dependencies are legacy debt and are being removed incrementally; the architecture dependency ratchet prevents new violations.

### Infrastructure

`catalog/src/Infrastructure` owns adapters for MySQL/PDO, filesystem storage, Unreal package readers, metadata projections, imports, job handlers, process launching and external integration.

### Legacy compatibility layer

`catalog/lib` still contains a large procedural API used by old routes and by some newer infrastructure. These global functions are compatibility facades, not the target architecture. New business logic should go into namespaced services and old functions should become thin delegates until callers can be migrated.

## Primary data flows

### Upload Bucket v2

1. `upload-bucket-v2.php` renders the canonical browser uploader.
2. Browser code performs extension policy checks and ordinary-file hashing; redirect wrappers are transferred without pretending their compressed hash is the package identity.
3. `api/v1/upload-bucket-chunk.php` writes bounded resumable chunks to durable staging.
4. `api/v1/upload-bucket-batch.php` finalizes staged files and queues durable work through `CatalogBucketBatchQueue`.
5. `ue_background_jobs` holds the durable job.
6. Detached workers claim the job and route the exact `JobType` to one handler.
7. `CatalogBucketUploadProcessor` / redirect processing calculate authoritative identities, store physical files and create/update unverified catalog metadata.
8. Unverified files are later assigned/imported into a game and post-import dependency work is queued.

### Profiled upload / staged import

1. HTTP/API validates the selected game/profile and stages the file.
2. A durable `IMPORT_STAGED_PACKAGE` or `IMPORT_STAGED_PAK` job is queued.
3. Package jobs run through the non-blocking staged-import handler; PAK jobs use the PAK import handler.
4. Unreal readers parse package metadata.
5. Catalog rows and compact metadata projections are persisted.
6. Dependency/search/projection maintenance is queued rather than extending the HTTP request.

### Local source scan

1. A configured source tree is enumerated.
2. PAK containers are queued separately from ordinary package files.
3. Package files are fingerprinted and compared with known physical/catalog identities.
4. New/changed files are scanned/imported; failures are staged for review.
5. Source-relative paths and file locations preserve the physical origin.

### Unverified promotion

1. `unverified-files.php` reads paginated unverified inventory.
2. `unverified-files-action.php` validates the action and resolves the staged row/file.
3. Existing staged hashes and metadata are reused when valid.
4. The selected game/profile and package identity are checked.
5. The file is promoted to verified catalog state.
6. Heavy dependency/projection work is queued after the foreground file operation.

### Durable background jobs

1. Producers enqueue `JobType`, payload, priority, resource policy, dedupe key and retry policy.
2. `PdoJobQueue` persists the durable job and leases work to workers.
3. `CatalogDetachedWorker` manages local worker processes/runtime state.
4. `JobWorker` performs deterministic `JobType => JobHandler` dispatch.
5. Handlers checkpoint/heartbeat while doing work.
6. Jobs complete, retry, cancel or dead-letter while retaining result/error state.

### Search

1. Public/admin search applies game visibility rules.
2. Exact identifiers (hash/GUID/package identity) are preferred.
3. Prefix and broader textual matching search file/package projections.
4. Compact metadata terms add import/export/object/path matches.
5. Results are normalized back to catalog files/packages for rendering.

### Federation

Federation uses the same catalog identities and storage model but currently contains significant page-local SQL/orchestration. Treat it as a bounded context: connection/authentication, inventory exchange, requests/transfers and reconciliation should be extracted behind application services without changing the wire protocol.

## Critical architecture debt

### 1. Legacy procedural kernel

`catalog/lib` still exposes hundreds of global functions. `CatalogSupport.php` / `CatalogSupportCore.php` behave as a broad compatibility kernel/service locator. This obscures dependencies and makes request behavior depend on include order and global state.

**Direction:** keep compatibility functions temporarily, but move implementations to namespaced services and make wrappers delegate.

### 2. Application layer depends outward

Multiple Application services still import PDO or Infrastructure adapters directly.

**Direction:** define narrow Application ports (repositories, queues, readers, clocks) and construct PDO/filesystem/process adapters only in composition roots or entry-point factories. The dependency-ratchet test must shrink over time and must never gain new exceptions.

### 3. SQL ownership is widely distributed

Core tables such as `ue_files`, `ue_games` and `ue_background_jobs` are queried from many unrelated files. Query policy, projection semantics and transaction boundaries are therefore difficult to change safely.

**Direction:** migrate one bounded context at a time to repository/query objects. Avoid a single generic repository; use intent-specific interfaces.

### 4. Worker claim scalability

`PdoJobQueue::claim()` serializes claimers with a queue-wide MySQL advisory lock and performs resource/concurrency subqueries during every claim. This is safe but limits worker-pool scaling.

**Direction:** preserve the current lease contract while adding integration coverage, then move to row-level claim concurrency (`FOR UPDATE SKIP LOCKED` where supported) and separate expired-lease recovery from every claim attempt.

### 5. Search contains queries do not scale

Broad search includes leading-wildcard `LIKE` predicates and collation/conversion expressions. Normal B-tree indexes cannot efficiently satisfy these scans as compact term volume grows.

**Direction:** build a normalized search projection suitable for indexed prefix/full-text/ngram lookup, dual-run it against current search for result equivalence, then switch reads only after measured parity.

### 6. Large orchestration classes/files

High-risk examples include `CatalogScanner.php`, `CatalogDetachedWorker.php`, `CatalogBucketUploadProcessor.php`, `CatalogUnverifiedIndex.php`, `unverified-files-action.php`, backup handlers and federation pages.

**Direction:** extract responsibilities, not arbitrary line ranges. Keep existing public functions/methods as delegates until callers migrate.

### 7. Reflection-based internal reuse

Some upload metadata/identity processors reuse private `CatalogBucketUploadProcessor` methods through reflection. This is hidden coupling and makes private implementation details an implicit API.

**Direction:** extract the shared identity hashing, storage and indexing operations into explicit collaborators injected into each processor.

### 8. Runtime schema mutation

Some legacy helpers still create/alter tables at runtime. Request/worker execution should not own schema evolution.

**Direction:** install/migration code owns DDL; runtime verifies a compatible schema version and fails with a clear administrator action when migration is required.

### 9. Source-text contract tests

A portion of the test suite asserts literal implementation fragments rather than externally observable behavior. These tests frequently become stale after safe refactors and reduce trust in the suite.

**Direction:** keep source guards only for architecture/security invariants. Convert behavior contracts to service-level or database integration tests.

## Refactoring order

1. **Architecture safety:** deterministic job routing, dependency ratchets, dead implementation removal.
2. **Application dependency inversion:** remove PDO/Infrastructure imports from use cases one service at a time.
3. **Upload/import collaborators:** eliminate reflection and duplicate identity/storage/index logic.
4. **Scanner decomposition:** separate path/name policy, reader selection, scan orchestration, persistence and dependency scheduling.
5. **Worker decomposition:** separate process launching, runtime-state storage, pool reconciliation and diagnostics.
6. **Query ownership:** introduce bounded query/repository objects for catalog, dependencies, jobs, search and federation.
7. **Performance changes with parity checks:** queue claiming and search projection changes only after integration/dual-read validation.
8. **Runtime schema cleanup:** remove request-time DDL after installation/version checks are authoritative.
9. **Thin controllers:** reduce top-level PHP routes to validation + use-case invocation + rendering.
10. **Compatibility retirement:** remove legacy global facades only after all callers have migrated.

## Non-negotiable behavior-preservation rules

- No route, API payload, job type, queue name, dedupe key, retry policy or result shape changes as part of architecture-only commits.
- No catalog identity/hash/GUID/package-name semantics change without dedicated migration and equivalence tests.
- No search-result semantic change during structural extraction.
- No automatic cancellation/timeouts for legitimate long-running package work.
- No runtime DDL removal until installed schema compatibility is proven.
- Prefer small commits with focused regression coverage and a clean full PHP syntax pass.
