# UnrealDB catalog system architecture

## Objective

Scale the catalog from a single PHP/web-host deployment to a production service without changing package parsing rules, game profile behaviour, public URLs, federation semantics, or the existing MySQL catalog model.

The target is a **modular monolith with explicit infrastructure boundaries**. It can scale horizontally before any component is split into a separate service.

## Target production topology

```text
                         ┌───────────────────────────┐
                         │ CDN / WAF / TLS / rate limit│
                         └─────────────┬─────────────┘
                                       │
                  ┌────────────────────┴────────────────────┐
                  │                                         │
       ┌──────────▼──────────┐                   ┌──────────▼──────────┐
       │ Stateless web/API   │                   │ Stateless web/API   │
       │ PHP application node│                   │ PHP application node│
       └───────┬─────────┬───┘                   └───────┬─────────┬───┘
               │         │                               │         │
               │         └──────────────┬────────────────┘         │
               │                        │                          │
      ┌────────▼────────┐      ┌────────▼─────────┐      ┌────────▼────────┐
      │ Redis cache      │      │ MySQL primary     │      │ Object storage  │
      │ sessions/rate    │      │ catalog + jobs    │      │ package blobs   │
      │ cache/locks      │      └───────┬──────────┘      └─────────────────┘
      └─────────────────┘              │
                                ┌───────▼──────────┐
                                │ Read replicas     │
                                │ search/list reads │
                                └───────────────────┘

                    ┌───────────────────────────────────────┐
                    │ Worker deployment                       │
                    │ package ingestion / dependency rebuild  │
                    │ source scans / federation / maintenance │
                    └───────────────────────────────────────┘
```

## What exists now

The current branch implements the smallest deployable version of this design:

- PHP web pages remain the presentation layer.
- MySQL remains the transactional catalog store.
- Local package storage remains unchanged.
- `ue_background_jobs` is a durable worker queue.
- `catalog/bin/catalog-worker.php` is a CLI worker entry point.
- `catalog/api/v1/health.php` exposes a dependency-aware health check.
- `catalog/api/v1/job-status.php` exposes authenticated job visibility.
- `CacheStore` and `FileCacheStore` create a cache boundary without making existing pages stale.

The MySQL queue is suitable for the current host and low-to-medium concurrent worker counts. At higher job volume, replace only the `JobQueue` infrastructure adapter with Redis Streams, SQS, RabbitMQ, or another broker; application handlers remain unchanged.

## Component structure

```text
Presentation
  Existing PHP pages and api/v1 endpoints
  - parse HTTP input
  - authenticate/authorize
  - render HTML or JSON

Application
  - Search, dependency resolution, upload orchestration
  - JobQueue, JobWorker, JobHandler contracts
  - CacheStore contract
  - no sessions, headers, HTML, or direct filesystem assumptions

Domain
  - durable job type identifiers
  - claimed job lease data

Infrastructure
  - PDO job queue
  - legacy scanner and reader adapters
  - file cache
  - progress-file pruning
  - object storage / Redis adapters in future
```

## Data flow

### Synchronous browser upload: current product behaviour

```text
Browser upload
  → profiled-upload.php
  → profile validation
  → legacy package reader/scanner
  → filesystem storage + MySQL transaction
  → dependency resolution
  → browser result/progress response
```

This remains synchronous today to preserve visible import completion timing.

### Deferred maintenance: implemented foundation

```text
Administrator / scheduler
  → ue_background_jobs
  → catalog-worker.php claims job lease
  → maintenance job handler
  → catalog scanner / pruner
  → completed, retried, or failed job record
```

Job leases prevent two workers from completing the same work. A crashed worker's lease expires and the job becomes eligible for retry. Terminal jobs clear their dedupe key so the same maintenance action can be scheduled again later.

### Future asynchronous ingestion

```text
Browser upload
  → validate and persist upload intent
  → object storage
  → enqueue package import job
  → immediate 202 response
  → worker parses package and writes catalog tables
  → UI polls job status or receives server-sent event
```

Do not enable this transition until upload result and progress fixtures cover the current browser contract.

### Read path

```text
Browser/API
  → CDN for static assets
  → PHP controller
  → cache lookup for explicitly cacheable read model
  → MySQL read replica or primary
  → JSON/HTML response
```

Writes, profile changes, imports, and dependency updates always use the primary. Strong-consistency read-after-write routes remain pinned to the primary.

## API design

### Implemented endpoints

| Method | Path | Auth | Purpose |
|---|---|---|---|
| `GET` | `/catalog/api/v1/health.php` | none | liveness plus database dependency check |
| `GET` | `/catalog/api/v1/job-status.php?limit=50` | admin session | recent durable job states |

Health response:

```json
{"data":{"status":"ok","service":"unrealdb-catalog","time":"2026-07-04T00:00:00+00:00"}}
```

### Planned write API contract

Write endpoints should not be public until API-token issuance, rotation, revocation, audit logging, rate limits, and per-scope authorization are implemented.

```text
POST /catalog/api/v1/jobs
Authorization: Bearer <scoped token>
Idempotency-Key: <caller-generated key>
Content-Type: application/json

{
  "type": "catalog.rebuild_game_dependencies",
  "payload": {"game_id": 42},
  "priority": 100
}
```

Response:

```text
202 Accepted
{"data":{"job_id":123,"status":"queued"}}
```

The `Idempotency-Key` maps to `dedupe_key`. Retrying the same client operation returns the existing active job rather than scheduling duplicate maintenance.

### API standards

- Version by URL path: `/api/v1`.
- JSON only; UTF-8; `Cache-Control: no-store` for authenticated responses.
- Stable error shape: `{"error":{"code":"...","message":"...","details":{}}}`.
- Cursor pagination for high-cardinality lists; retain offset pagination only for legacy pages.
- Request ID and structured logs on every API request before external exposure.
- Rate limiting at CDN/WAF and Redis-backed application middleware.

## Database schema

### Existing transactional catalog tables

```text
ue_games
ue_game_profiles
ue_files
ue_names
ue_imports
ue_exports
ue_dependencies
ue_sources / ue_file_locations
ue_federation_* 
```

The catalog is relational because package/object/dependency joins require transactional integrity.

### New durable queue table

`ue_background_jobs` is introduced by `install_update_021_background_jobs.sql`.

Key fields:

```text
id                  immutable job identifier
queue_name          worker partition
job_type            stable job contract identifier
payload_json        command data
priority            lower runs first
status              queued/running/completed/failed/cancelled
available_at        scheduled retry time
attempts            number of leases issued
max_attempts        retry ceiling
dedupe_key          unique while job is active
worker_id           worker ownership diagnostics
lease_token         optimistic completion guard
lease_expires_at    recovery point after crash
last_error          operational failure detail
result_json         handler output
created_by          audit link to user
```

Indexes support claim order, expired-lease recovery, active deduplication, and operational history.

### Future data stores

| Need | Store | Ownership |
|---|---|---|
| transactional metadata | MySQL primary | catalog application |
| high-volume read scale | MySQL replicas | read-only queries |
| package blobs | S3-compatible object storage | immutable package bytes |
| low-latency cache / sessions / locks | Redis | transient state only |
| arbitrary substring search | OpenSearch/Meilisearch or n-gram index | derived read model |
| analytics | warehouse/event pipeline | non-transactional reporting |

## Caching strategy

### Tier 0: browser and CDN

- Long immutable cache headers for hashed static assets.
- No shared caching for authenticated admin pages.
- Public file downloads should use signed object-storage URLs when external storage is adopted.

### Tier 1: request-local

- Reuse loaded game/profile/config data during one request.
- Avoid duplicate DB lookups inside render loops.

### Tier 2: shared application cache

The branch introduces `CacheStore` plus a local `FileCacheStore` adapter. It is appropriate only for the current single-host/shared-filesystem deployment.

Cache only derived, bounded-staleness data:

```text
Dashboard counter snapshots       TTL 5–15 seconds
Game/profile lookup lists         TTL 30–60 seconds
Search suggestion metadata        TTL 30 seconds
Federation capability documents   TTL 60 seconds
```

Do not cache:

```text
Upload responses
Job claim state
Permission decisions without user/session key
Dependency rebuild outcomes
Read-after-write file details
```

### Invalidation

Use explicit invalidation after:

```text
game/profile create/update/delete
file import/delete
source scan completion
dependency rebuild completion
federation transfer import
```

At multi-node scale, publish invalidation events through the outbox/job system rather than relying on filesystem cache deletion.

## Production operations

### Worker execution

Current single-run command:

```bash
php catalog/bin/catalog-worker.php --max-jobs=25 --sleep-ms=250
```

Run it under cron initially or a supervisor/systemd process on hosts that support long-running workers. Start with one worker because the current scanner contains legacy global reader classes. Add concurrency only after reader isolation and package fixture tests prove no cross-job contamination.

### Observability

Add before external API write access:

- structured JSON logs with request/job IDs;
- MySQL slow query log and query timing metrics;
- queue depth, lease-expiry, retry, and failure alerts;
- worker memory/runtime caps;
- upload and parser error rate dashboards;
- database backup and restore drills.

### Security

- Keep database credentials outside version control.
- Terminate TLS at the edge.
- Restrict worker commands to server operators.
- Store API tokens only as one-way hashes when token management is introduced.
- Separate public browsing permissions from administrative upload/federation permissions.
- Keep package uploads in non-executable storage paths and validate resolved download paths.

## Evolution path

1. **Current:** modular monolith, MySQL, local storage, durable MySQL jobs.
2. **Scale-up:** Redis cache/sessions, object storage, supervisor workers, read replicas.
3. **Scale-out:** stateless API nodes, CDN download delivery, queue broker adapter, search read model.
4. **Selective extraction:** only split workers/search/federation into separate services when independent deployment or scaling pressure is demonstrated.

Do not split the core catalog into microservices merely to match an architecture diagram. The modular contracts in `catalog/src` are the mechanism that makes later extraction low-risk.
