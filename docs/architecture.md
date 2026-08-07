# UnrealDB Architecture Baseline

This document describes the current runtime architecture and the intended refactoring direction. Refactors are behavior-preserving unless explicitly stated: file identity, queue semantics, import results, search results, federation behaviour and public routes must remain stable.

## Runtime shape

UnrealDB is a modular monolith in transition. The namespaced `catalog/src` tree provides Domain, Application, Infrastructure and Presentation layers. Top-level PHP routes and a compatibility API remain under `catalog/lib`, but migrated implementations should not be duplicated there as backup code.

### Presentation / entry points

- Browser pages: `catalog/*.php` and `catalog/federation/*.php`
- JSON API: `catalog/api/v1/*.php`
- CLI / workers: `catalog/bin/*.php`
- Shared request bootstrap: `catalog/lib/CatalogSupport.php`, `catalog/bootstrap.php`, `catalog/src/Presentation/Http/*`

Entry points should validate HTTP/CLI input, resolve dependencies at the composition boundary, invoke the appropriate use case/query and render/serialize the result. They should not own SQL, filesystem policy, worker lifecycle or parser internals.

### Domain

`catalog/src/Domain` contains stable business concepts such as durable job types and resource policies. Domain code must not depend on Application, Infrastructure, PDO, HTTP, filesystem or process-launching implementations.

### Application

`catalog/src/Application` contains use-case orchestration and contracts. It should depend on Domain/Application abstractions rather than constructing PDO/filesystem/process implementations. Remaining direct persistence dependencies are migration debt, not examples for new code.

### Infrastructure

`catalog/src/Infrastructure` owns MySQL/PDO queries and repositories, filesystem storage, Unreal package readers, compact metadata, imports, job handlers, process launching and external integrations.

Dependency resolution/rebuilding is now explicitly Infrastructure-owned:

- `Persistence/PdoDependencyResolver.php`
- `Persistence/PdoCatalogDependencyRebuilder.php`
- `Persistence/PdoDependencyReadSource.php`
- `Jobs/CatalogAffectedDependencyRefreshCoordinator.php`
- `Metadata/CompactDependencyRebuilder.php`

### Legacy compatibility layer

`catalog/lib` still exposes a procedural API used by older routes and some current workers. Compatibility functions should delegate to current namespaced implementations. Once all callers are migrated, the compatibility function/file should be deleted rather than retained as a fallback implementation.

`CatalogScanner.php` is no longer a monolith. It is a thin include manifest for focused scanner compatibility modules:

- `lib/Scanner/CatalogScannerPath.php`
- `lib/Scanner/CatalogScannerSupport.php`
- `lib/Scanner/CatalogScannerDependencies.php`
- `lib/Scanner/CatalogScannerImport.php`

The public `scanner_*` contracts remain stable while their implementations are migrated further.

## Primary data flows

### Upload Bucket v2

1. `upload-bucket-v2.php` renders the canonical browser uploader.
2. Browser code performs extension policy checks and ordinary-file hashing; redirect wrappers are transferred without pretending their compressed hash is package identity.
3. `api/v1/upload-bucket-chunk.php` writes bounded resumable chunks to durable staging.
4. `api/v1/upload-bucket-batch.php` finalizes staged files and queues durable work through `CatalogBucketBatchQueue`.
5. `ue_background_jobs` holds durable work.
6. Detached workers claim jobs and route each exact `JobType` to one handler.
7. Upload/redirect processors calculate authoritative identities, store physical files and create/update unverified catalog metadata.
8. Unverified files are later assigned/imported into a game and post-import dependency work is queued.

### Profiled upload / staged import

1. HTTP/API validates the selected game/profile and stages the file.
2. A durable `IMPORT_STAGED_PACKAGE` or `IMPORT_STAGED_PAK` job is queued.
3. Package jobs run through staged-import handlers; PAK jobs use the PAK import handler.
4. Unreal readers parse package metadata.
5. Catalog rows and compact metadata projections are persisted.
6. Dependency/search/projection maintenance runs through durable jobs rather than extending foreground requests unnecessarily.

### Local source scan

1. A configured source tree is enumerated.
2. PAK containers are queued separately from ordinary package files.
3. Package files are fingerprinted and compared with known physical/catalog identities.
4. New/changed files are scanned/imported; failures are staged for review.
5. Source-relative paths and file locations preserve physical origin and UE4/UE5 long-package identity.

### Scanner package import

1. `CatalogScanner.php` loads the focused scanner compatibility modules.
2. Path/name policy determines physical filename and logical package identity.
3. Reader/profile detection validates the package and reads Names/Imports/Exports.
4. Physical storage and catalog rows are written using the existing import transaction semantics.
5. `PdoCatalogDependencyRebuilder` performs dependency resolution through `PdoDependencyResolver`.
6. Compact metadata finalization becomes authoritative for verified files.
7. Affected-provider refresh work is coordinated through `CatalogAffectedDependencyRefreshCoordinator` unless deliberately deferred by Full Sync.

### Unverified promotion

1. `unverified-files.php` reads paginated unverified inventory.
2. `unverified-files-action.php` validates the action and resolves the staged row/file.
3. Existing staged hashes and metadata are reused when valid.
4. The selected game/profile and package identity are checked.
5. The file is promoted to verified catalog state.
6. Heavy dependency/projection work is queued after the foreground operation.

### Durable background jobs

1. Producers enqueue `JobType`, payload, priority, resource policy, dedupe key and retry policy.
2. `PdoJobQueue` persists durable jobs and leases work to workers.
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

Federation uses the same catalog identities and storage model but still contains page-local orchestration and SQL in older areas. Treat connection/authentication, inventory exchange, requests/transfers and reconciliation as a bounded context and migrate them behind stable query/use-case boundaries without changing the wire protocol.

## Critical architecture debt

### 1. Legacy procedural compatibility API

`catalog/lib` still exposes many global functions. Include order/global state can obscure dependencies.

**Direction:** move real implementations into namespaced services; preserve only thin delegates while callers require them, then delete the delegates.

### 2. Remaining outward dependencies from Application

Some Application services still directly depend on PDO or Infrastructure.

**Direction:** remove these one service at a time. Persistence/query implementations belong in Infrastructure; Application should retain only genuine use-case policy/contracts.

### 3. SQL ownership is still distributed

Core tables such as `ue_files`, `ue_games` and `ue_background_jobs` are queried from many older routes/helpers.

**Direction:** migrate bounded contexts to intent-specific query/repository objects rather than a single generic repository.

### 4. Worker claim scalability

`PdoJobQueue::claim()` still serializes claimers with a queue-wide MySQL advisory lock and performs resource/concurrency work during claim. This protects current semantics but can limit pool scaling.

**Direction:** preserve the lease/concurrency contract while measuring through the Background Jobs UI and worker runtime state. Move toward row-level claim concurrency (`FOR UPDATE SKIP LOCKED` where supported) only when equivalent behaviour can be demonstrated on the production-style MySQL setup.

### 5. Search contains queries do not scale

Broad search still includes leading-wildcard `LIKE` predicates and collation/conversion expressions that normal B-tree indexes cannot efficiently satisfy.

**Direction:** build an indexed normalized search projection and compare its results against the current implementation before switching reads.

### 6. Remaining large orchestration files

`CatalogScanner.php` itself has been decomposed, but large responsibilities remain in `CatalogScannerImport.php`, `CatalogDetachedWorker.php`, `CatalogBucketUploadProcessor.php`, `CatalogUnverifiedIndex.php`, `unverified-files-action.php`, backup handlers and federation pages.

**Direction:** extract real responsibilities rather than arbitrary line ranges. Delete obsolete parallel implementations when current callers have migrated.

### 7. Reflection-based upload reuse

Some upload metadata/identity paths still rely on a legacy bridge around private `CatalogBucketUploadProcessor` operations.

**Direction:** extract identity hashing, physical storage and indexing into explicit collaborators, then delete the reflection bridge.

### 8. Runtime schema mutation / compatibility probing

Some legacy areas still contain schema compatibility behaviour. Runtime request/worker code should not evolve schema.

**Direction:** install/migration code owns DDL; runtime may verify required schema and fail with a clear administrator action.

### 9. Manual validation discipline

The project currently has no automated PHP test suite by design; application behaviour is validated through the real web/admin workflows.

**Direction:** every structural change should receive a full PHP syntax pass on changed files, precise diff/blob verification for large replacements, and focused web validation of the affected route/job flow. Do not add GitHub test workflows unless explicitly requested.

## Refactoring order

1. **Remove dead/parallel implementations** once current routes/jobs have authoritative replacements.
2. **Application dependency inversion:** continue moving PDO/Infrastructure implementation out of use-case classes.
3. **Scanner migration:** move `CatalogScannerImport.php` and path/name policy into namespaced collaborators while keeping current `scanner_*` signatures until callers migrate.
4. **Upload/import collaborators:** eliminate reflection/private-operation coupling and duplicate identity/storage/index logic.
5. **Worker decomposition:** separate process launching, runtime-state storage, pool reconciliation and diagnostics while preserving Windows behaviour.
6. **Query ownership:** continue bounded query/repository objects for catalog, dependencies, jobs, search and federation.
7. **Performance changes with parity checks:** change queue claiming/search projections only after measured production-style equivalence.
8. **Runtime schema cleanup:** remove request-time DDL/legacy compatibility paths once installation/version checks are authoritative.
9. **Thin controllers:** reduce top-level PHP routes to validation + use-case/query invocation + rendering.
10. **Compatibility retirement:** delete global facades when no active caller needs them.

## Non-negotiable behavior-preservation rules

- No route, API payload, job type, queue name, dedupe key, retry policy or result-shape changes as part of architecture-only commits.
- No catalog identity/hash/GUID/package-name semantic change without an explicit behavioural migration.
- No search-result semantic change during structural extraction.
- No automatic cancellation/timeouts for legitimate long-running package work.
- Preserve Windows detached-worker behaviour exactly during worker refactors.
- No runtime DDL removal until installed schema compatibility is proven.
- Validate changed PHP with syntax checks and exercise the affected web/job workflow manually.
