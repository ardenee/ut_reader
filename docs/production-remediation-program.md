# UnrealDB production remediation program

## Purpose

This document turns the nine production-readiness reviews into one ordered engineering program. Changes are delivered incrementally on `main`, with compatibility tests and small rollback boundaries. Unreal package parsing behaviour is treated as protected behaviour unless a change is backed by fixtures.

## Delivery principles

- Keep a modular monolith. Do not introduce microservices without an independently scalable boundary and measured need.
- Preserve current URLs, response shapes, package identity rules, and database data during migration.
- Fix exploitable security and data-loss risks before architecture cleanup.
- Move expensive and failure-prone work out of browser requests.
- Add a contract test with every compatibility or security boundary.
- Use immutable production images and backward-compatible database migrations.
- Do not scale web or worker replicas until shared state and concurrency assumptions are proven.

## Priority model

- **P0:** exploitable security, unauthorized distribution, data loss, or service-wide denial of service.
- **P1:** production reliability, recoverability, concurrency, and operational safety.
- **P2:** maintainability, performance, developer experience, and planned scaling.
- **P3:** optional optimization after production measurements exist.

## Workstream 1 — Security and authentication

### Completed

- Legacy `index.php?page=download` now delegates to the canonical download endpoint.
- Canonical storage-path containment rejects sibling-prefix escapes.
- Public downloads require a verified file and apply base-game and configured distribution policies.
- Administrator login uses shared-storage throttling and randomized failure delay.
- Administrator sessions have idle and absolute lifetimes.
- Runtime error display is disabled by the shared application bootstrap.
- HTTP federation workers require POST and a header token by default.
- Generic JSON API and federation request bodies are size-bounded.
- Federation inventory batches have payload, row, field, hash, and transaction limits.
- Redirect archive output is bounded by the configured upload limit.
- Federation peer secrets support authenticated encryption with a deployment master key and a migration CLI.
- Pairing claims are POST-only, query-string claim tokens are removed, and the one-time transition is atomic.
- Pairing secrets are no longer stored in administrative notes or returned by approval-status polling.
- Security boundaries are enforced by CI tests.

### Next

- Migrate symmetric federation HMAC identities to Ed25519 peer identities.
- Add administrator MFA and reauthentication for security-sensitive operations.
- Add public search, package-generation, join-request, and download rate limits.
- Centralize outbound federation HTTP through the existing SSRF-resistant client.
- Remove every controller-level `display_errors` override and raw session bootstrap.
- Add Content Security Policy and security event alerting.

## Workstream 2 — Database schema and migrations

### Current state

The canonical clean-install schema exists, but legacy runtime DDL and separate upgrade SQL files remain.

### Plan

1. Add a schema-version table and numbered, idempotent migrations.
2. Add a CLI migration runner with advisory locking and dry-run/status commands.
3. Convert current upgrade SQL files into ordered migrations.
4. Add an integration test that upgrades a representative previous schema.
5. Remove runtime table and column creation only after supported deployments have migrated.
6. Separate web, worker, and migration database credentials where the platform permits it.

## Workstream 3 — Ingestion and unverified staging

### Current state

Upload and queue behaviour is spread across controllers, scanner helpers, shutdown indexing, and filesystem scans.

### Plan

1. Add one explicit staging use case returning the exact stored queue identity.
2. Convert upload bucket, profiled upload, local scan, HTTP scan, and federation receive paths one at a time.
3. Remove converted writers from shutdown auto-indexing.
4. Remove global before/after directory snapshots after all writers use explicit staging.
5. Split scanner orchestration into preparation, detection, parsing, identity, storage, persistence, dependencies, and result reporting.
6. Preserve duplicate and alias rules with fixture-backed tests.

## Workstream 4 — Background jobs and reliability

### Current state

A durable MySQL queue and worker exist, but several heavy tasks still run in HTTP requests and worker concurrency has not been load-tested.

### Plan

- Queue package imports, PAK extraction, dependency rebuilds, full sync, source repair, duplicate hashing, and package generation.
- Make each job idempotent, resumable, lease-protected, progress-reporting, and retryable.
- Add lease renewal, stale-job recovery, cancellation, and dead-letter handling.
- Add job-type concurrency limits and resource classes.
- Keep one worker replica until claim and lease behaviour passes concurrency tests.

## Workstream 5 — Search and database performance

### Current state

Exact hash lookups are indexed, while broad substring search issues many leading-wildcard queries.

### Plan

1. Separate exact MD5, SHA1, GUID, package, and object lookup paths.
2. Require longer terms for broad anonymous substring searches.
3. Add request rate limits and bounded query execution time.
4. Capture slow-query baselines and representative catalogue benchmarks.
5. Add or adjust indexes only from measured query plans.
6. Introduce a derived search index only when MySQL no longer meets the agreed latency budget.

## Workstream 6 — Presentation and frontend system

### Completed

- Reusable server-rendered UI components exist for page headers, buttons, fields, alerts, empty/loading states, pagination, progress, filters, and accessible table regions.
- Component accessibility and escaping contracts run in CI.
- Responsive table and filter behaviour is documented.

### Next

- Migrate remaining high-use admin pages to shared components.
- Remove duplicated inline CSS and JavaScript where shared behaviours exist.
- Add CSP-compatible asset loading and eliminate inline script dependencies incrementally.
- Add visual regression checks for critical pages after stable fixtures exist.

## Workstream 7 — Federation and API trust

### Completed

- Signed requests include method, path, timestamp, nonce, and body hash.
- Replay nonces and bounded request readers exist.
- Streaming uploads verify declared length and SHA-256.
- Cron credentials are no longer placed in URLs by default.
- Symmetric peer secrets are encrypted at rest with AES-256-GCM when a deployment master key is configured.
- Existing peer rows can be migrated and verified through a CLI command before strict policy is enabled.
- Manual and automated approval flows no longer persist pairing material in notes or expose it through status polling.
- One-time pairing claims use a separate POST endpoint and compare-and-swap state transition.

### Next

- Replace symmetric peer credentials with asymmetric signatures.
- Add peer key rotation, revocation, and audit history.
- Route every outbound peer request through DNS-pinned, no-redirect, public-address validation.
- Add endpoint-specific quotas, idempotency keys, and peer rate limits.
- Paginate inventory synchronization instead of generating one full catalogue payload.

## Workstream 8 — Deployment, monitoring, and recovery

### Completed

- Production Docker image, Compose staging stack, Kubernetes baseline, readiness/liveness probes, worker loop, GHCR release workflow, vulnerability scan, provenance, approval-gated deployment, smoke tests, and rollback are present.
- Kubernetes requires shared RWX package storage and defaults to one web and one worker replica.
- Security runtime limits are declared in Compose and Kubernetes.
- Kubernetes strict federation-secret policy is backed by a Secret-provided master key; Compose exposes the same controls for staged rollout.

### Next

- Select the production platform and replace generic kubeconfig credentials with workload identity.
- Provision managed MySQL, Redis, TLS, WAF, RWX or object storage, and central logs.
- Add application metrics or OpenTelemetry instrumentation.
- Add dashboards and alerts for latency, errors, queue age, worker failures, database contention, and storage capacity.
- Implement point-in-time database recovery, package snapshots, and quarterly restore tests.
- Add a deployment migration gate and release compatibility check.

## Workstream 9 — Testing and engineering governance

### Completed

- Syntax, schema, architecture, UI, duplicate-cleanup, package-format, container, manifest, security, and federation-secret checks are represented in CI.
- Clean architecture boundaries and compatibility facades are documented.

### Next

- Add package-reader fixtures for every supported engine and known edge case.
- Add database integration tests for identity, aliases, dependencies, and migrations.
- Add HTTP contract tests for critical public and admin endpoints.
- Add queue crash/retry/concurrency tests.
- Add performance budgets and baseline datasets.
- Protect `main` with required checks once workflow stability is confirmed.
- Track temporary compatibility wrappers with owners and removal conditions.

## Ordered delivery sequence

1. Complete remaining P0 security controls and asymmetric identity planning.
2. Establish versioned migrations and upgrade testing.
3. Replace implicit unverified indexing with explicit staging.
4. Route all heavy maintenance and generation work through durable jobs.
5. Add reader and dependency fixtures before deeper scanner refactoring.
6. Optimize search and persistence from measured profiles.
7. Finish UI component migration and CSP work.
8. Add production telemetry, backup automation, and restore verification.
9. Enable horizontal scaling only after shared-state and concurrency gates pass.

## Definition of production-ready

The application is ready for general production deployment when:

- No open P0 security findings remain.
- Production secrets are externalized, existing federation rows have been migrated, and strict encrypted-secret policy is enabled.
- Every schema change is delivered through a tested migration.
- Heavy operations are bounded or queued.
- Backup restoration has been demonstrated.
- Reader and identity behaviour is protected by fixtures.
- Required CI checks pass on the deployed commit.
- Production monitoring and alert routing are active.
- Rollback compatibility with the current schema is confirmed.
