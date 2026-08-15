# UnrealDB scalable production system architecture

## Design position

UnrealDB should remain a **modular monolith with independently scalable web and worker roles** until measured load proves a specific subsystem must be separated.

The catalog has transactionally related package identity, parser output, compact metadata publication, dependency projection and durable job state. Turning those responsibilities into network services prematurely would replace in-process calls and database transactions with distributed failure modes without improving the current product.

The production architecture therefore optimizes for:

- stateless HTTP execution where practical;
- durable MySQL-backed job orchestration;
- one authoritative package identity model;
- bounded package parsing/metadata publication;
- horizontally scalable read traffic;
- independently scalable worker capacity;
- replaceable Infrastructure adapters behind Application ports;
- explicit health/readiness boundaries;
- external infrastructure only when a measured bottleneck justifies it.

## Target topology

```text
                         Internet
                            |
                     DNS / CDN / WAF
                            |
                    TLS load balancer
                     /             \
                    /               \
             PHP web node 1 ... PHP web node N
                    |               |
                    +-------+-------+
                            |
                  shared application data
             +--------------+--------------+
             |              |              |
          MySQL          sessions       package blobs
         primary        shared when      shared FS now,
             |          web scales       object storage later
             |
       optional replicas
       for measured reads

                durable ue_background_jobs
                            |
                +-----------+-----------+
                |                       |
          worker pool A              worker pool B
        import/dependency            storage/archive
```

The web and worker processes run the same application code but have different operational roles. Database resource classes and concurrency keys remain the workload-control authority; worker process count is only execution capacity.

## Component structure

### Edge

Responsibilities:

- TLS termination;
- request-size enforcement before PHP where possible;
- static-asset caching;
- anonymous HTML caching when safe;
- WAF/crawler/rate controls;
- load-balancer health/readiness probing.

The application must not require an edge platform to remain correct. Edge controls reduce load; application authorization, validation and durable state remain authoritative.

### Web role

Responsibilities:

- HTML/API request validation;
- authentication/session handling only for authenticated routes;
- read/query use cases;
- durable command/job submission;
- bounded upload/chunk staging;
- streaming downloads/response cache hits.

Long package parsing, archive extraction, dependency-wide rebuilds and large maintenance operations should not run as request-lifetime work.

### Application layer

Owns:

- use-case orchestration;
- ports/contracts;
- immutable request/result/value models;
- domain-neutral coordination such as readiness aggregation.

It must not know PDO, SQL, filesystem paths, package-reader classes, Apache, MySQL named locks or concrete queue tables.

### Domain layer

Owns pure business policy and invariants, including job/resource policy and package/catalog rules that do not depend on runtime infrastructure.

Domain must not depend outward on Application, Infrastructure or Presentation.

### Infrastructure layer

Owns:

- MySQL/PDO repositories and job persistence;
- package readers/parsers;
- compact metadata files/projections;
- package blob filesystem storage;
- response cache storage;
- background worker lifecycle/ownership;
- federation transport/security persistence;
- readiness probes for concrete dependencies.

Concrete graphs are created only under `Infrastructure/Composition`.

### Worker role

Workers claim durable jobs from MySQL using current admission/resource/concurrency policy.

Important invariants:

- no timeout-based theft of healthy running jobs;
- process/connection ownership determines liveness;
- one failed package does not block unrelated work;
- coordinators split large operations into durable bounded child jobs;
- saved resource limits are read at claim time;
- root affinity is preference-only;
- queue scans must continue past semantically blocked prefixes.

## Primary data flows

### Public read

```text
request
  -> edge/CDN cache
  -> application public-response cache
  -> route/controller
  -> Application query/use case
  -> Infrastructure query adapter
  -> MySQL compact projections / core tables
  -> streamed HTML/JSON response
```

Authenticated/session-bearing requests bypass anonymous response caching.

### Package import

```text
upload/staged source
  -> durable import job
  -> package inspector
       classification
       hashing
       reader selection
       names/imports/exports snapshot
  -> identity repository
       maintenance target
       duplicate/alias policy
  -> publisher
       package storage
       relational identity
       compact metadata v2 publication
       projection registration
  -> dependency coordinator
       synchronous affected rebuild
       or durable fallback job
  -> terminal import result
```

The inspected parser snapshot is transferred inward through an immutable Application model. Storage/PDO/parser implementations remain Infrastructure details.

### Durable queue

```text
HTTP/worker producer
  -> PdoJobQueue
  -> ue_background_jobs
  -> PdoJobClaimer
  -> resource/concurrency admission
  -> handler
  -> progress/result/checkpoint
  -> completed | failed | dead_letter | cancelled
```

The database queue is sufficient while MySQL remains the shared transactional coordination point. Do not add Kafka/RabbitMQ merely to replace a working durable queue. A broker becomes useful only when throughput or cross-service integration demonstrably exceeds the database queue model.

### Federation

```text
remote peer
  -> signed API
  -> peer/nonce authentication
  -> inventory/request state
  -> durable transfer/import jobs
  -> package storage + catalog publication
```

Federation stays asynchronous at transfer/import boundaries so a remote peer cannot hold a local PHP request open for large catalog work.

## API design

Keep versioned resource/command endpoints under `catalog/api/v1/`.

### Conventions

- JSON responses use the existing `JsonResponse` envelope.
- GET/HEAD/OPTIONS read routes release session locks immediately after authorization.
- machine health/readiness routes are session-free.
- mutation endpoints require explicit authentication/CSRF/recent-auth policy as appropriate.
- long-running commands return durable job identifiers rather than keeping requests open.
- pagination should move toward cursor semantics for very large ordered result sets.
- request bodies remain bounded before full materialization in PHP.

### Operational endpoints

#### `GET /catalog/api/v1/health.php`

Purpose: lightweight compatibility health check.

Success:

```json
{
  "data": {
    "status": "ok",
    "service": "unrealdb-catalog",
    "time": "2026-08-15T12:00:00+00:00"
  }
}
```

This route checks PHP/bootstrap + MySQL and preserves its historical response shape.

#### `GET /catalog/api/v1/readiness.php`

Purpose: load-balancer/service-manager readiness.

Success uses HTTP 200; a critical dependency failure uses HTTP 503.

```json
{
  "data": {
    "status": "ready",
    "service": "unrealdb-catalog",
    "time": "2026-08-15T12:00:00+00:00",
    "checks": [
      {"name":"database","status":"ready","latency_ms":0.4},
      {"name":"job_queue","status":"ready","latency_ms":0.2},
      {"name":"package_storage","status":"ready","latency_ms":0.1}
    ]
  }
}
```

The endpoint deliberately exposes no credentials, paths, SQL errors or exception messages.

### Future API evolution

When external clients need stable catalog APIs, prefer resource-oriented endpoints such as:

```text
GET  /api/v1/games
GET  /api/v1/games/{id}/files?cursor=...
GET  /api/v1/files/{id}
GET  /api/v1/files/{id}/dependencies
POST /api/v1/imports              -> job id
GET  /api/v1/jobs/{id}
POST /api/v1/jobs/{id}/cancel
```

Do not make PHP controller filenames part of a new external contract if a router/resource API is introduced later; preserve existing URLs for compatibility while versioned clients move to the resource form.

## Database schema ownership

MySQL remains the system of record for catalog identity, projections and job orchestration.

### Core catalog

- `ue_games`: logical game/catalog boundary.
- `ue_game_profiles`: engine/profile/parser compatibility policy.
- `ue_files`: canonical physical package identity and operational metadata state.
- `ue_file_package_aliases`: additional logical package identities mapped to an existing physical verified file.
- `ue_sources` / `ue_file_locations`: source provenance and locations.

`ue_files` should remain the stable catalog identity table. Large parser object payloads do not belong back in row-per-object legacy tables.

### Compact metadata

Verified parser metadata is stored as format-2 blocked compressed containers plus bounded SQL lookup/search/dependency projections.

Keep responsibilities separated:

```text
ue_files                    stable package identity/summary
ue_file_metadata            compact-container registration/counts
.uedb2 storage              detailed parser metadata payload
lookup/projection tables    indexed query/dependency/search access
```

Do not duplicate the full parser snapshot into relational tables merely to make every possible query relational.

### Durable jobs

`ue_background_jobs` is the durable execution log/state machine.

Important indexing dimensions are:

- queue + status + admission/claim ordering;
- resource class;
- concurrency key;
- parent/root workflow identity;
- cancellation;
- display/status reporting.

Operational resource limits are configuration/state read by admission at claim time; changes should never rewrite a large queued backlog.

### Telemetry

Current persistent telemetry tables such as request performance, resource performance, system errors and exact-plan/count diagnostics are appropriate for application-level operations.

High-cardinality time-series telemetry should eventually go to a metrics backend rather than growing MySQL indefinitely. MySQL should retain aggregates/operator records that are useful transactionally inside UnrealDB.

### Schema scaling rules

1. Use migrations with expand-and-contract for live schema evolution.
2. Large backfills run as bounded jobs, not one migration transaction.
3. Add indexes only for measured query shapes.
4. Prefer BIGINT IDs for high-growth durable/event-like tables.
5. Keep unique identity constraints authoritative in MySQL even if a cache/search service is introduced.
6. Read replicas are read-only accelerators; writes and correctness-sensitive reads stay on the primary unless explicit consistency semantics are introduced.
7. Partitioning/sharding is a late-stage response to measured table/index limits, not a default design choice.

## Caching strategy

Use layered caches with explicit ownership.

### Layer 1: browser/CDN

Suitable for:

- immutable static assets;
- public anonymous HTML where invalidation/staleness is acceptable;
- eventually large public package downloads when authorization semantics permit edge/object-storage delivery.

### Layer 2: application response cache

The existing bounded filesystem cache remains the single-host implementation.

Properties to preserve:

- anonymous GET only;
- route-specific short TTLs;
- bounded search-cache slots;
- identity validation for search-slot collisions;
- stale serving during writer contention;
- per-key writer locking;
- streamed HIT responses;
- bounded pruning.

When multiple web nodes are introduced, prefer CDN/reverse-proxy caching first. Replace the application file cache with Redis only if cross-node application cache coherence/hit rate justifies another operational dependency.

### Layer 3: database/query caches

Use narrowly scoped caches such as exact-count caches where the calculation is expensive and the identity/TTL are explicit.

Do not introduce a generic application object cache over mutable catalog entities; stale package/dependency identity is more dangerous than the saved query.

### Cache invalidation

Canonical data writes remain authoritative. Cache invalidation may be best-effort when TTL/stale policy guarantees eventual refresh. Never make a cache write part of the transaction required to consider a package import successful.

## Storage strategy

### Current

Shared filesystem package storage is appropriate for the current single-host deployment and remains the simplest reliable implementation.

### Horizontal web/worker scale

Before adding nodes, package storage must be available consistently to all roles that need package bytes. Options:

1. shared filesystem/NAS;
2. replicated filesystem with strong operational guarantees;
3. object storage adapter.

Object storage should be introduced behind a storage port when filesystem sharing, backup time or throughput becomes a measured bottleneck. Database rows should store stable logical object keys, not provider-specific signed URLs.

## Session strategy

Current PHP file sessions are acceptable on one host.

Before horizontal web scaling:

- move authenticated sessions to shared Redis/database/session infrastructure, or use load-balancer affinity only as a short migration step;
- keep public health/readiness/cache-hit paths session-free;
- release read-only session locks as soon as authorization is complete.

## Failure and availability model

### Web node

A web node is ready only when critical dependencies required for its current role are usable.

Current all-in-one readiness checks:

- MySQL connectivity;
- durable job queue table availability;
- readable/writable package storage.

Future role-specific readiness can swap probes through the Application `ReadinessProbe` port; for example a dedicated read-only web role may require readable storage but not writable storage.

### Worker

Workers are replaceable processes. Durable job state lives in MySQL; worker process death releases database ownership and allows explicit orphan recovery.

### MySQL

MySQL primary failure is currently a service-wide write/control-plane outage. Production architecture should provide tested backups and, when required by availability objectives, managed HA/failover. The application should not implement database failover logic itself.

### Package storage

Database/catalog identity and package blob backups must be restore-tested together. A healthy database with missing package blobs is not a healthy catalog.

## Deployment stages

### Stage A: current high-performance single host

- Apache/PHP/MySQL.
- local/shared package storage.
- bounded detached workers.
- MySQL durable queue.
- file response cache.
- `health.php` + `readiness.php`.

### Stage B: edge + independent worker service

- CDN/WAF/load balancer.
- web process no longer responsible for persistent worker lifecycle.
- supervisor/service manager runs workers independently.
- shared package/session storage as needed.

### Stage C: horizontally scaled web

- multiple immutable PHP nodes.
- shared session backend.
- shared/object package storage.
- CDN/reverse-proxy public caching.
- same MySQL primary.

### Stage D: measured data/read scale

Add only the required component:

- MySQL read replica for read pressure;
- external/n-gram search index for substring-search pressure;
- object storage/CDN for package download/storage pressure;
- Redis for shared application/session cache pressure;
- separate worker pools/hosts for independent resource classes.

### Stage E: service extraction only when justified

A component becomes a separate service only if it has an independently valuable scaling/deployment/failure boundary. Likely candidates, if ever needed, are search indexing or blob delivery. Package identity/import/dependency correctness should remain one transactional bounded context unless evidence proves otherwise.

## Minimal scale-ready implementation added

The repository now contains:

```text
catalog/src/Application/System/
  Contract/ReadinessProbe.php
  ReadinessCheck.php
  SystemReadinessReport.php
  SystemReadinessService.php

catalog/src/Infrastructure/Health/
  PdoDatabaseReadinessProbe.php
  PdoQueueReadinessProbe.php
  FilesystemStorageReadinessProbe.php

catalog/src/Infrastructure/Composition/
  CatalogSystemReadinessFactory.php

catalog/api/v1/
  health.php
  readiness.php

catalog/bin/
  verify-system-readiness-contract.php
```

`catalog_bootstrap(false)` and `catalog_api_application(false)` now genuinely suppress session startup, which keeps machine probes and existing CLI callers out of PHP session storage/locking.

## Verification

Static/syntax validation:

```text
php catalog/bin/verify-system-readiness-contract.php
```

Real deployment dependency validation:

```text
php catalog/bin/verify-system-readiness-contract.php --run
```

The runtime form exercises the configured MySQL connection, queue table and package storage without scanning catalog/queue history.

## Architectural rule of thumb

Scale **roles and measured bottlenecks**, not the number of technologies.

The desired growth path is:

```text
well-bounded modular monolith
 -> stateless web + durable workers
 -> shared edge/session/storage
 -> measured read/search/blob accelerators
 -> service extraction only where independent scaling actually pays for its operational cost
```
