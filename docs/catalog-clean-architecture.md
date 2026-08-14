# UnrealDB catalog clean architecture

## Goal

The catalog is refactored incrementally while preserving public URLs, HTML/JSON contracts, Unreal parsing rules, file identity, storage layout, job types and durable workflow semantics.

`catalog/lib` is a compatibility surface. New behaviour belongs under `catalog/src`; a legacy facade should become a thin delegate and be removed only after its callers have migrated.

## Active structure

```text
catalog/
├── bootstrap.php
├── src/
│   ├── Domain/                       # pure durable-job/domain policy and value objects
│   ├── Application/                  # persistence-free use cases and ports
│   │   ├── Dashboard/
│   │   ├── Jobs/
│   │   ├── Search/
│   │   ├── Unverified/
│   │   └── Upload/
│   ├── Infrastructure/
│   │   ├── Composition/              # concrete dependency wiring
│   │   ├── Import/                   # package import/storage adapters
│   │   ├── Jobs/                     # job handlers and host worker supervisor adapters
│   │   ├── Metadata/                 # format-2 compact metadata publication/read/write
│   │   ├── Persistence/              # PDO repositories and durable queue
│   │   ├── Readers/                  # engine reader resolution
│   │   ├── Search/                   # PDO search repository
│   │   └── Unverified/               # queue/import adapters
│   └── Presentation/
│       └── Http/                     # request/session/response compatibility
├── lib/                              # transitional global compatibility facades
├── parsers/
├── migrations/
└── *.php                             # existing page controllers, migrated incrementally
```

## Dependency direction

```text
Presentation / entry points
        ↓
Application use cases and ports
        ↓
Infrastructure adapters
        ↓
PDO / filesystem / readers / processes / MySQL
```

Domain and Application code must not inspect request globals, open PDO connections, own SQL/filesystem persistence, launch processes or render HTML.

Concrete adapters are composed in Infrastructure composition roots or at an explicit legacy compatibility boundary. Presentation must not mutate Domain static state to install infrastructure behaviour.

## Current ownership

### Durable jobs

- `JobResourcePolicy` is pure Domain policy: job type/payload → default resource profile and concurrency key.
- Runtime environment/database resource limits are resolved by Infrastructure at claim time; changing a saved limit does not rewrite queued rows.
- `PdoJobClaimer` selects queue rows with `FOR UPDATE SKIP LOCKED` and uses `PdoJobAdmissionGuard` to serialize resource-class/concurrency-key admission.
- Root workflow affinity is preference-only. A worker falls back to unrelated runnable work when its preferred workflow is blocked.
- `PdoWorkerOwnership` holds a connection-scoped MySQL named lock for process ownership. Recovery uses process/database ownership, not elapsed lease time, to decide whether a running job is orphaned.
- `lease_token` remains the fencing token for running-row lifecycle writes.
- `PdoWorkflowChildStateQuery` is the canonical child status-count read model; coordinators should not reimplement `status/COUNT(*)` semantics.
- `BackgroundJobDisplaySql` is the canonical operator status expression used by both list rows and counts.

### Upload/import

- Upload Bucket v2 remains the only upload implementation; the old upload route is a compatibility redirect.
- `ProfiledUploadService` owns upload-batch application policy and passes user identity explicitly to failed-upload retention.
- `FailedUploadPreserver` is the Application port; the filesystem retention implementation lives in Infrastructure.
- `scanner_scan_uploaded_file()` is a thin global compatibility delegate to the namespaced package importer.
- The package importer remains a deliberately conservative compatibility adapter over reader, persistence, storage and dependency collaborators. Further decomposition must be driven by behavioural tests because Unreal package import semantics are tightly coupled and a folder-only rewrite would increase risk without improving the runtime model.

### Unverified files

- `Application/Unverified/CatalogUnverifiedActionService` owns move/import/delete use-case sequencing and result semantics.
- `CatalogUnverifiedQueueMutation` and `CatalogUnverifiedImporter` are Application ports.
- Infrastructure supplies filesystem/PDO/import adapters; the historical Infrastructure action service is now a thin composition compatibility adapter.

### Compact metadata

- Format-2 compact metadata remains authoritative for verified package metadata.
- `ue_files.metadata_status` explicitly records `pending`, `ready` or `failed` publication state.
- `metadata_error` and `metadata_updated_at` expose partial publication failures for repair/operations instead of requiring inference from missing side data.
- `VerifiedFileCompactMetadataFinalizer` marks publication pending before work, ready after verification, and failed with the publication error on failure.

### Search

- `Application/Search` depends on `CatalogSearchRepository`, not PDO.
- `Infrastructure/Search/PdoCatalogSearchRepository` owns identity, prefix, alias, compact metadata and bounded contains SQL.
- The legacy global `CatalogSearchService::findFiles(PDO, ...)` remains only as a composition facade for existing pages.
- Broad global contains search stays bounded per game. Do not add Elasticsearch or another service until production measurements justify it.

### Host-local workers

`CatalogDetachedWorker` is the stable public supervisor facade. Its previous mixed responsibilities are split into:

- `CatalogWorkerRuntimeStateStore` — runtime files, locks, state and log tails;
- `CatalogWorkerProcessLauncher` — PHP executable resolution and Windows/POSIX process launch;
- `CatalogWorkerCodeVersion` — stale-code fingerprinting.

Durable correctness does **not** depend on those host-local files; MySQL queue state and `PdoWorkerOwnership` remain authoritative across Windows, Docker and Kubernetes.

### Readers

- UE1/UE2 continue using memory-bounded readers.
- UE3 uses the strict Epic reader. Compressed UE3 packages release the full compressed buffer before logical reconstruction and read compressed chunks from disk one at a time, reducing peak memory without changing serialized layout handling.
- UE4/UE5 use their configured readers.

## Compatibility strategy

1. Keep an existing public/global contract while active callers require it.
2. Move policy to Application or implementation to Infrastructure as appropriate.
3. Make the compatibility surface a thin delegate/composition boundary.
4. Migrate callers when doing so does not change behaviour.
5. Delete a facade only after repository search and runtime verification show it is unused.

Do not keep two independent implementations as fallbacks.

## Validation policy

There is deliberately no required GitHub Actions application-quality workflow. Validation is local/manual so repository changes do not create an email-heavy CI gate.

Before deployment:

```text
php catalog/bin/migrate.php status
php catalog/bin/migrate.php verify
php catalog/bin/verify-queue-runtime-invariants.php
php catalog/bin/verify-job-root-affinity-contract.php
php catalog/bin/verify-job-claim-concurrency.php --run
```

Also run `php -l` on changed PHP files and exercise the affected HTTP/job workflow on the deployment host. The real-MySQL concurrency verifier uses uniquely named temporary queue/resource rows and cleans them up when it exits.

Architecture marker scripts are secondary checks only. They must not be treated as substitutes for real queue/database behaviour tests.

## Rules for new code

- New product behaviour goes in `catalog/src`, not `catalog/lib`.
- Domain code is pure: no PDO, environment reads, sessions, filesystem or logging side effects.
- Application code owns use-case sequencing and contracts but no SQL/PDO/filesystem/process details.
- Infrastructure implements database, filesystem, reader, process and external-service ports.
- Presentation owns request/session/CSRF/response behaviour.
- Do not read `$_POST`, `$_FILES` or `$_SESSION` below Presentation/legacy compatibility boundaries.
- New workflow coordinators use `PdoWorkflowChildStateQuery` rather than duplicating status-count SQL.
- New operator job reporting uses `BackgroundJobDisplaySql` rather than duplicating parent/child display semantics.
- Preserve queue job types, unit keys, dedupe keys, result shapes, retry semantics and package identity during architecture-only refactors.
- Add a new external service only after measurement demonstrates that the modular monolith/MySQL design is the bottleneck.
