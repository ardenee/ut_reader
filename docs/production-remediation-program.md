# UnrealDB production remediation program

## Purpose

This document turns the production-readiness reviews into one ordered engineering program. Changes are delivered incrementally on `main`, with compatibility tests and small rollback boundaries. Unreal package parsing behaviour is treated as protected behaviour unless a change is backed by fixtures.

## Delivery principles

- Keep a modular monolith. Do not introduce microservices without an independently valuable boundary and measured need.
- Preserve current URLs, response shapes, package identity rules, and database data during migration.
- Fix exploitable security and data-loss risks before architecture cleanup.
- Move expensive and failure-prone work out of browser requests.
- Add a contract test with every compatibility or security boundary.
- Deploy known Git revisions with backward-compatible database migrations.
- Production remains one Windows host; scale the worker pool and host resources only from measured demand.

## Priority model

- **P0:** exploitable security, unauthorized distribution, data loss, or service-wide denial of service.
- **P1:** production reliability, recoverability, concurrency, and operational safety.
- **P2:** maintainability, performance, developer experience, and operational tooling.
- **P3:** optional optimization after production measurements exist.

## Workstream 1 — Security and authentication

### Completed

- Legacy `index.php?page=download` now delegates to the canonical download endpoint.
- Canonical storage-path containment rejects sibling-prefix escapes.
- Public downloads require a verified file and apply base-game and configured distribution policies.
- Administrator login uses layered throttling and randomized failure delay.
- Administrator sessions have idle and absolute lifetimes while the configured remember-me behaviour remains available.
- Runtime error display is disabled by the shared application bootstrap.
- HTTP federation workers require POST and a header token by default.
- Generic JSON API and federation request bodies are size-bounded.
- Federation inventory batches have payload, row, field, hash, and transaction limits.
- Redirect archive output is bounded by the configured upload limit.
- Federation peer secrets support authenticated encryption with a deployment master key and a migration CLI.
- Pairing claims are POST-only, query-string claim tokens are removed, and the one-time transition is atomic.
- Pairing secrets are no longer stored in administrative notes or returned by approval-status polling.
- Public generated-package requests have an application-side observed-IP rate limit and session-bound artifact authorization.
- CSP/proxy and generic server-error boundaries are covered by the security hardening verifier.

### Next

- Continue migration of federation identities toward Ed25519 where peer compatibility permits it.
- Keep recent-auth requirements limited to genuinely security-sensitive operations rather than routine queue administration.
- Continue endpoint-specific public search, join-request and download abuse controls.
- Keep outbound federation HTTP behind the SSRF-resistant transport boundary.
- Continue removing controller-level error-display/session-bootstrap drift.
- Add security event alerting where it provides useful operator signal.

## Workstream 2 — Database schema and migrations

### Completed

- Added `ue_schema_migrations` with ordered migration versions, checksums, batches and execution timing.
- Added a CLI migration runner with status, dry-run, migrate and verify commands.
- Added database-scoped advisory locking and checksum/orphan drift rejection.
- Converted remember-login, package-alias, dependency-metadata/asset-registry, unverified-staging, background-job reliability and resource-limit upgrades into numbered idempotent migrations.
- Added MySQL integration coverage for preview, upgrade, rerun and checksum-drift failure.
- Added deployment-host migration status/dry-run/verify checks and a Windows production deployment runbook.
- Added a database migration runbook and deployment compatibility policy.

### Next

- Remove runtime table and column creation after all supported deployments pass `migrate verify`.
- Treat former one-off upgrade SQL files as historical references and remove them after the compatibility window.
- Keep the production database account permissions as narrow as practical for the Windows deployment.
- Add a representative populated previous-schema fixture, not only the structural legacy baseline.

## Workstream 3 — Ingestion and unverified staging

### Completed

- Added one `UnverifiedFileStager` contract returning the stored queue name, physical path and unverified file ID during the writer operation.
- Added move-based staging for temporary and incoming files and copy-preserving staging for configured source libraries.
- Centralized safe queue naming, physical storage, notes, hashing, metadata parsing and database indexing.
- Converted Upload Bucket files to immediate database-backed staging and retained browser-relative identity context.
- Converted Profiled Upload failures and failed extracted PAK entries through the shared scanner staging primitive.
- Converted Local Source Scan failures to copy-preserving staging without modifying source-library files.
- Confirmed HTTP Source Scan is not a queue writer and removes its bounded temporary GUID-inspection downloads.
- Converted failed federation imports from anonymous incoming files into tracked unverified rows; failed jobs retain the staged file ID and new queue path.
- Added cleanup for successful and duplicate federation incoming files.
- Removed and deleted the shutdown-time directory snapshot hook from the application bootstrap.
- Added source-contract and MySQL integration tests for immediate identity, move/copy semantics, source preservation, queue retention and non-package handling.
- Added original synthetic UE1, UE2, UE3 and UE4 packages that exercise production summary, name, import and export readers without redistributing retail data.
- Added fixed size/SHA-256 manifests, UE2 version 83/licensee 635 compatibility coverage, UE4 versioned/unversioned parser-profile coverage and malformed packages that must fail closed without partial tables.
- Added a hardened `UNREALDB_FIXTURE_ROOT` runner for private real-world UE1–UE5 packages and optional companion files, with containment, symlink, size, hash, parser-profile and table-expectation checks.

### Next

- Add externally validated UE1–UE5 fixtures for real game-specific summaries, serialized properties and redirectors.
- Add UE3 zlib/LZO compressed fixtures and UE4 `.uasset`/`.uexp` pairs.
- Add dependency-pair, alias and duplicate-identity fixture manifests before changing package identity or dependency rules.
- Split scanner orchestration into preparation, detection, parsing, identity, storage, persistence, dependencies and result reporting.
- Add a reconciliation job for the final filesystem fallback used only when database staging is unavailable.
- Add metrics for staged files, parse failures, fallback retention and promotion outcomes.

## Workstream 4 — Background jobs and reliability

### Completed

- Preserved opaque lease-token ownership for claim, completion, failure, heartbeat and cancellation transitions.
- Added configurable ownership/heartbeat state while keeping healthy long-running work under the owning process rather than failing it by elapsed runtime.
- Added progress snapshots from maintenance handlers without changing durable result payloads.
- Added immediate queued cancellation and cooperative running cancellation at safe checkpoints.
- Added a distinct `dead_letter` terminal state for exhausted failures and unsupported handlers.
- Added explicit dead-letter retry with attempt, cancellation, progress, result and ownership reset.
- Fixed retry transitions so they clear the previous worker identity/ownership state.
- Added persisted resource classes, per-class capacity limits and target concurrency keys.
- Added advisory claim coordination so competing workers cannot overbook a resource class.
- Added fair claim selection that skips saturated classes and admits eligible work from another class.
- Added configurable limits for `dependency-heavy`, `storage-heavy`, `package-heavy`, `housekeeping` and `default` jobs.
- Split exact-file dependency refresh from affected-dependants refresh so job names match scanner behaviour.
- Moved the standalone Dependency Refresh page behind one durable game/file job instead of repeated browser requests.
- Preserved full-game start offsets, persisted progress and final dependency totals, cooperative cancellation and URL-based job resume.
- Moved single-file and full-game UE4/UE5 Source Identity Repair behind durable jobs while keeping the mismatch audit read-only and immediate.
- Preserved canonical package, original filename, export-path, source-derived alias and dependency-refresh behaviour through the existing repair library.
- Kept one game-wide dependency pass after all identity updates, bounded stored failure details, and retained the maintenance advisory lock where required.
- Converted the former source-identity step API into an enqueue-only compatibility adapter; it no longer writes progress files or mutates catalog data in HTTP requests.
- Moved exact size+MD5 unverified duplicate cleanup behind a `storage-heavy` worker job while preserving keeper selection and immediate pre-delete revalidation.
- Added hash/delete progress, cooperative cancellation and bounded durable deletion/error details for duplicate cleanup.
- Moved ZIP, UMOD-family and PAK generation out of Apache into `package-heavy` jobs using the existing package plan, format writers and validators.
- Added unique `.part` output, validation-before-publication, atomic artifact rename, post-publication cancellation cleanup, retention pruning and session-authorized download.
- Added public package request throttling, random per-job browser access tokens stored only as SHA-256 in job payloads, and bounded artifact lifetimes.
- Added bounded job-status polling by ID; completed results are omitted from general multi-job listings.
- Added CLI and secured administrator API operations for status, enqueue, cancel, retry and recovery.
- Added MySQL/filesystem integration tests for retry transitions, cancellation, progress, stale-owner rejection, orphan recovery, dead letters, simultaneous competing workers, class saturation, fairness, target-key exclusion, dependency execution, source-identity repair, duplicate cleanup and generated package publication.

### Next

1. Continue reducing any remaining monolithic package-import and PAK-extraction stages into durable bounded units where useful.
2. Add checkpoints for intake, decompression/extraction, parser selection, table parsing, physical storage, database persistence and dependency refresh where replay cost remains high.
3. Add worker termination/retry tests at filesystem-to-database transitions.
4. Use externally validated compressed and companion-file fixtures before changing UE3/UE4 archive or payload handling.
5. Keep generated-artifact pruning scheduled and observable.
6. Tune the bounded Windows worker pool and resource-class limits from live MySQL/storage pressure rather than adding distributed worker infrastructure.

## Workstream 5 — Search and database performance

### Current state

Exact hash lookups are indexed, while broad substring search issues leading-wildcard queries in some paths.

### Plan

1. Keep exact MD5, SHA1, GUID, package and object lookup paths separate from broad text search.
2. Require sensible minimum terms for broad anonymous substring searches.
3. Keep request rate limits and bounded query execution behaviour.
4. Capture slow-query baselines and representative catalogue benchmarks.
5. Add or adjust indexes only from measured query plans.
6. Introduce a derived search index only if MySQL no longer meets the agreed latency budget on the actual host.

## Workstream 6 — Presentation and frontend system

### Completed

- Reusable server-rendered UI components exist for page headers, buttons, fields, alerts, empty/loading states, pagination, progress, filters and accessible table regions.
- Component accessibility and escaping contracts exist as executable verifiers/tests.
- Responsive table and filter behaviour is documented.
- Dependency refresh, source identity repair, duplicate cleanup and generated package progress clients use external JavaScript assets rather than embedding their orchestration logic in page controllers where migration has been completed.

### Next

- Migrate remaining high-use admin pages to shared components.
- Remove duplicated inline CSS and JavaScript where shared behaviours exist.
- Continue CSP-compatible asset loading and eliminate remaining inline script dependencies incrementally.
- Add visual regression checks for critical pages after stable fixtures exist.

## Workstream 7 — Federation and API trust

### Completed

- Signed requests include method, path, timestamp, nonce and body hash.
- Replay nonces and bounded request readers exist.
- Streaming uploads verify declared length and SHA-256.
- Cron credentials are no longer placed in URLs by default.
- Symmetric peer secrets are encrypted at rest with AES-256-GCM when a deployment master key is configured.
- Existing peer rows can be migrated and verified through a CLI command before strict policy is enabled.
- Manual and automated approval flows no longer persist pairing material in notes or expose it through status polling.
- One-time pairing claims use a separate POST endpoint and compare-and-swap state transition.
- Failed federation imports now enter the common unverified staging lifecycle instead of remaining anonymous incoming files.

### Next

- Continue asymmetric-signature rollout where federation peers support it.
- Maintain peer key rotation, revocation and audit history.
- Keep every outbound peer request behind DNS-pinned, no-redirect, public-address validation.
- Add endpoint-specific quotas and idempotency where demonstrated useful.
- Keep inventory synchronization bounded/paginated.

## Workstream 8 — Deployment, monitoring and recovery

### Completed

- The production target is explicitly the existing single Windows host running Apache, PHP 8.5 and MySQL 8.4 with local package storage.
- Docker, Compose and Kubernetes deployment artifacts have been removed so they no longer create a second unsupported production path.
- `live.php`, `readiness.php` and authenticated Prometheus-format metrics provide application/process/dependency observability without requiring a container platform.
- The security and readiness verifier scripts now target the actual Windows deployment boundaries rather than container manifests.
- Windows backup readiness, backup, verification and guarded restore scripts are present under `deploy/backup/`.
- Full backup guidance treats database + package storage as one coherent recovery point when write consistency requires it.
- Background workers are designed to run independently of browser requests and use MySQL as the durable queue/control plane.

### Next

- Run workers under a persistent Windows service supervisor with boot startup, unexpected-exit restart and controlled stop/drain behaviour.
- Add scheduled backup/verification jobs through Windows Task Scheduler and expose last-success status to operations.
- Add practical host monitoring for disk space, Apache/PHP process availability, MySQL pressure, worker count, queue age and backup age.
- Add dashboards/alerts only where they improve operator response; prefer the existing System Operations/Background Jobs pages for application-specific queue state.
- Perform periodic restore drills against disposable database/storage targets.
- Keep deployment as a controlled known-Git-revision update with migration status/dry-run/verify and post-deploy smoke checks.

## Workstream 9 — Testing and engineering governance

### Completed

- Syntax, schema, architecture, UI, duplicate-cleanup, package-format, synthetic-reader, security, federation-secret, migration, explicit-staging, job-reliability, job-resource, queued-dependency, queued-source-identity and durable storage/package checks are represented by executable verifier/integration scripts.
- The production resolver and readers are exercised with deterministic UE1–UE4 package bytes, fixed SHA-256 expectations, legacy UE2 compatibility, UE4 parser profiles and malformed fail-closed behavior.
- A private fixture runner supports real UE1–UE5 packages and companion files without adding copyrighted assets to the repository.
- Clean architecture boundaries and compatibility facades are documented.
- Database/filesystem integration tests exercise migration state, unverified staging identity, move/copy semantics, scanner integration, competing job workers, resource saturation, queue fairness, dependency semantics, canonical source-path identity, export rewrites, aliases, duplicate keeper/deletion behavior and validated generated artifact publication.

### Next

- Validate external real-package manifests for every engine, compressed UE3, UE4 companion payloads, serialized properties and known game-specific edge cases.
- Add dependency-pair, package-alias, redirector and duplicate-identity fixture manifests.
- Add database integration tests for additional identity, alias and dependency-resolution edge cases.
- Add HTTP contract tests for critical public and admin endpoints.
- Add heavy-job crash and resume tests.
- Add performance budgets and baseline datasets.
- Add lightweight GitHub/Windows CI only where it provides reliable signal without duplicating deployment-host checks.
- Track temporary compatibility wrappers with owners and removal conditions.

## Ordered delivery sequence

1. Add external compressed/paired/property fixtures and dependency-pair/alias manifests.
2. Continue durable package-import and PAK-extraction checkpoint work where replay remains expensive.
3. Complete remaining security/operational hardening.
4. Optimize search and persistence from measured profiles.
5. Finish UI component migration and CSP work.
6. Add Windows production telemetry, backup scheduling and restore verification.
7. Tune worker count, resource-class limits and host hardware from measured production pressure.

## Definition of production-ready

The application is ready for general production deployment when:

- No open P0 security findings remain.
- Production secrets are configured correctly and federation rows use the required encrypted-secret policy when federation is enabled.
- Every schema change is delivered through a tested migration.
- Heavy operations are bounded or queued.
- Backup restoration has been demonstrated.
- Reader and identity behaviour is protected by fixtures.
- Required deployment-host verifier/test checks pass on the deployed commit.
- Production monitoring and alert routing are active enough to detect host, database, worker, storage and backup failures.
- Rollback compatibility with the current schema is confirmed.
