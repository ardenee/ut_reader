# UnrealDB / ut_reader

UnrealDB is a PHP and MySQL catalogue for Unreal Engine package files. It reads package headers and tables, records package identities and aliases, resolves imports to candidate files and exports, tracks local and remote source locations, and supports controlled file distribution and federation between catalogue installations.

## Supported package families

The catalogue currently includes reader profiles for:

- Unreal Engine 1
- Unreal Engine 2 and 2.5
- Unreal Engine 3
- Unreal Engine 4

Game profiles define the active reader family, allowed package extensions, package-version ranges and compatibility exceptions. Profiles may be reused by multiple games through `ue_games.profile_id`.

## Requirements

- PHP 8.2 or later; PHP 8.3 is used in CI and the production container
- MySQL 8 or a compatible MariaDB release
- PDO MySQL
- PHP cURL for federation streaming and trusted HTTP source scans
- PHP ZipArchive for ZIP package exports
- A writable catalogue storage directory shared by web and worker processes
- Apache, another compatible web server, or the supplied container deployment

## Installation

1. Copy `catalog/config.example.php` to `catalog/config.php` outside source control.
2. Configure the database and storage path.
3. Import `catalog/install.sql` into a new empty database.
4. Apply and verify numbered migrations.
5. Create the first administrator from a trusted command line.
6. Make the configured storage directory writable by the web and worker processes.

Run the migration commands after the initial schema import and before every application rollout:

```bash
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
```

Create the initial administrator through CLI:

```bash
php catalog/bin/create-admin.php
```

Do not expose development package viewers, runtime configuration, storage folders, source code or CLI commands through the public document root.

## Package ingestion

Files may enter the catalogue through:

- Profiled Upload for a selected game
- Upload Bucket for unsorted files
- Local Source Scan
- Trusted HTTP source manifests
- Federation transfers

Redirect-compressed `.uz`, `.uz2` and `.uz3` files are decompressed before package scanning. The compressed wrapper is not retained.

Upload Bucket and failed valid package imports enter database-backed unverified staging immediately. Non-package failures are deleted rather than accumulating in unverified storage. Local Source Scan uses copy-preserving staging so the configured source library is not modified. Failed federation imports are moved from incoming transfer storage into the same tracked unverified lifecycle.

## Package identity and duplicate handling

Identity matching does not depend on a cleaned browser filename. The catalogue uses package GUID, file hashes, package tables and source-relative package identity where appropriate.

A byte-identical package with another valid package name may be recorded as an alias of the existing file identity. Physical verified storage uses hash-based names; the original package name remains metadata and is used for controlled download naming.

The unverified duplicate cleanup is a durable `storage-heavy` job. It inventories every physical unverified queue, groups by size, calculates MD5 only for size collisions, retains one exact size+MD5 copy and revalidates a duplicate immediately before deletion.

For UE4 and UE5-style packages, source-relative paths are required to derive mounted package identity consistently with Unreal package naming rules. Folder uploads, local sources, PAK entries and source manifests provide that context.

## Dependency resolution

Imports are resolved through package and exported-object evidence. The reference rule is:

- `0`: null reference
- negative: import index `(-index - 1)`
- positive: export index `(index - 1)`

Dependency paths retain Unreal-style slash-separated package paths. A dot is used only where Unreal object-path syntax requires an object-name suffix.

The catalogue records resolution source and confidence metadata, including resolved object, package-only, common package and missing states.

## Base-game distribution protection

Administrators can maintain base-game GUID lists per game. Protected files may remain indexed for dependency analysis but are blocked from public download and generated package distribution.

All public delivery must pass through the canonical download controller. Files must be verified and must satisfy the active distribution policy.

## Public download modes

| Mode | Behaviour |
| --- | --- |
| `local_direct` | Serve the permitted file from local catalogue storage. |
| `external_mirror` | Return only an active external/shared-provider link. |
| `external_mirror_preferred` | Prefer an active external link; otherwise allow local delivery and queue mirror work. |
| `disabled` | Disable public file delivery. |

External mirror links are administered through a provider workflow. The application can queue mirror work and reuse active links, but provider-specific upload automation is not yet a core feature.

Generated dependency ZIP, UMOD-family, UT3 ZIP and UT4 PAK output is built by the background worker. A completed artifact is authorized to the initiating browser session, stored under controlled generated-package storage and expires after a bounded retention period.

## Background jobs and worker

The catalogue includes a durable MySQL-backed job queue for maintenance and generation work. Current job types include:

- exact file, full game and affected-dependants dependency rebuilds
- UE4/UE5 file and game source-identity repair
- exact unverified size+MD5 duplicate cleanup
- generated mod/dependency package builds
- upload-progress pruning

The Dependency Refresh, Source Identity Repair, duplicate cleanup and generated-package pages enqueue durable work, poll persisted progress and support cooperative cancellation. Closing a page does not stop its worker job. Dependency, identity and generated-package pages retain the active job ID in the URL so status can resume after reload in the authorized session.

Run workers only through CLI:

```bash
php catalog/bin/catalog-worker.php --queue=catalog --max-jobs=25 --sleep-ms=250 --lease-seconds=120
```

Worker leases use an opaque token. Completion, failure, progress, cancellation and recovery transitions require that token, preventing an expired worker from overwriting a later claim. Progress callbacks renew the lease and persist an operational snapshot.

Queued jobs may be cancelled immediately. Running jobs stop cooperatively at safe handler checkpoints. Expired leases are either requeued, finalized as cancelled, or moved to `dead_letter` after the final permitted attempt. Retried and recovered jobs clear the previous worker identity and lease timestamps.

Persisted resource classes and target concurrency keys prevent conflicting heavy jobs from overlapping while allowing eligible work in other classes to continue. Dependency/identity, storage cleanup and package generation each default to one concurrent slot.

Operator controls are available through the trusted CLI or the authenticated, CSRF-protected administrator API:

```bash
php catalog/bin/job-control.php status --queue=catalog --limit=50
php catalog/bin/job-control.php cancel --id=123 --reason="Operator requested stop"
php catalog/bin/job-control.php retry --id=123
php catalog/bin/job-control.php recover --queue=catalog
php catalog/bin/job-control.php enqueue-rebuild-game --game-id=1 --offset=0
php catalog/bin/job-control.php enqueue-rebuild-file --file-id=123
php catalog/bin/job-control.php enqueue-source-identity-file --file-id=123
php catalog/bin/job-control.php enqueue-source-identity-game --game-id=1
php catalog/bin/job-control.php enqueue-clean-unverified-duplicates
```

Public package generation is deliberately initiated through its browser-session endpoint rather than CLI, because the completed artifact is bound to the requesting browser's random access token.

See `docs/background-jobs.md` for state transitions, cancellation semantics, artifact retention, source-identity repair behavior, dead-letter handling, alerts and scaling gates. Keep one production worker replica until the resource and idempotency behaviour of each heavy job type has been validated.

## Federation

Federation requests are signed over method, path, timestamp, nonce and body hash. Incoming nonces prevent replay, and transfer endpoints enforce request and file-size limits.

Peer shared secrets support AES-256-GCM encryption at rest when a deployment master key is configured. Existing plaintext peer rows must be migrated before strict encrypted-secret policy is enabled. Pairing claims are POST-only, one-time and atomically consumed.

Streaming transfers verify declared length and SHA-256. Production deployments should require HTTPS and use the strict federation-secret mode documented in the deployment guide.

## Automated checks and package fixtures

GitHub Actions runs the `Catalog quality` workflow on pull requests and pushes to `main`.

Current automated checks include:

- PHP 8.3 syntax lint and JavaScript client syntax checks
- rejection of tracked runtime configuration
- architecture and security boundary tests
- database migration lifecycle tests
- explicit unverified-staging integration tests
- background-job lease, retry, cancellation, dead-letter, resource-limit and competing-worker tests
- queued dependency and source-identity execution tests
- durable duplicate-cleanup and generated-package execution tests
- UI, duplicate-cleanup and generated package-format contracts
- clean MySQL schema and seed verification

The workflow does not yet prove package-reader correctness for every supported engine and edge case. Package regression fixtures are documented in `tests/fixtures/README.md`; retail game assets must remain outside the public repository.

## HTTP API foundation

```text
/catalog/api/v1/health.php
/catalog/api/v1/live.php
/catalog/api/v1/job-status.php
/catalog/api/v1/job-action.php
/catalog/api/federation/
```

- `live.php` confirms the application process responds.
- `health.php` checks database-backed readiness.
- `job-status.php` requires an administrator session and reports queue, progress, result, heartbeat, cancellation, recovery and dead-letter metadata. General lists omit result payloads; a specific `job_id` includes the durable result.
- `job-action.php` requires an administrator session, POST and a valid CSRF header for supported enqueue, cancel, retry and recovery actions.
- Public generated-package status and download use separate session-bound controllers; arbitrary job records and artifact names are not public credentials.
- Federation endpoints live under `catalog/api/federation/`.

## UI system

The catalogue remains server-rendered PHP and does not require a JavaScript framework. Reusable components and shared assets are under:

```text
catalog/src/Presentation/Ui/
catalog/assets/catalog-ui.css
catalog/assets/catalog-ui.js
```

The component layer provides page headers, buttons, alerts, badges, loading and empty states, sections, responsive tables, focus styles and reduced-motion support.

## Database schema

`catalog/install.sql` is the canonical baseline for a new empty database. Existing deployments and fresh installs must also run the ordered migration command. Applied migration versions and checksums are stored in `ue_schema_migrations`; changed or missing applied migrations block deployment verification.

Do not run historical `upgrade-*.sql` files as a release sequence. They remain only as compatibility references while numbered migrations are the supported upgrade path.

## Production deployment

Production assets are included for:

- Docker image construction with PHP ZipArchive support
- Docker Compose integration and single-host staging
- Kubernetes web and worker deployments
- readiness, liveness and startup probes
- immutable GHCR image publication and vulnerability scanning
- migration-gated production rollout and rollback

See:

- `docs/production-deployment.md`
- `docs/database-migrations.md`
- `docs/background-jobs.md`
- `docs/production-remediation-program.md`

Production deployments should use managed or separately protected MySQL, Redis-backed sessions, centralized logs, TLS, a WAF or equivalent request controls, durable shared package storage, database point-in-time recovery and tested package-storage snapshots.

## Known work remaining

Major planned work includes:

- complete reader fixtures for all supported engines and known edge cases
- evaluating package import and PAK extraction job boundaries after reader fixtures exist
- adding crash/retry fixtures for partially completed scanner and archive stages
- asymmetric federation identities and hardened outbound peer networking
- broader public endpoint rate limits and administrator MFA
- measured search optimization and production telemetry

The ordered implementation status is maintained in `docs/production-remediation-program.md`.
