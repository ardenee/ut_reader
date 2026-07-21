# UnrealDB / ut_reader

UnrealDB is a PHP and MySQL catalogue, analysis and controlled-distribution system for Unreal Engine package files. It reads package headers and object tables, preserves package identity and source paths, resolves dependencies, manages verified and unverified storage, builds game-specific download packages, exchanges files between catalogue installations, and supports complete server-side game backup and restore.

The project is intended for large Unreal package libraries where filenames alone are not reliable enough to identify duplicates or satisfy dependencies.

## Current engine support

The catalogue includes reader and game-profile support for:

- Unreal Engine 1
- Unreal Engine 2
- Unreal Engine 2.5
- Unreal Engine 3
- Unreal Engine 4

Game profiles define the reader family, accepted extensions, package-version and licencee-version ranges, detection rules and compatibility exceptions. Profiles can be reused by multiple games through `ue_games.profile_id`.

Commonly configured games include Unreal, Unreal Gold, Unreal Tournament, Unreal II, Unreal Tournament 2003, Unreal Tournament 2004, Unreal Tournament 3 and Unreal Tournament 4 Alpha. Administrators can add other games and assign an appropriate reusable profile.

## What the catalogue records

For verified packages UnrealDB records and exposes:

- raw package-header fields
- package GUID, MD5 and SHA-1 identity
- original filename, logical package name and recorded source-relative path
- detected engine, package version, licencee version and compatibility status
- complete Names, Imports and Exports tables
- import/export object references and generated object paths
- dependency status, resolution source and confidence
- package aliases that share one physical file identity
- local source locations, HTTP sources, mirrors and federation locations
- required files and files that require the selected package

The package examination pages cross-link valid name, import and export references. Unreal object indices follow the standard rule:

- `0` — null reference
- negative — import index `(-index - 1)`
- positive — export index `(index - 1)`

Dependency paths retain slash-separated Unreal package paths. A dot is used only where Unreal object-path syntax requires an object-name suffix.

## Search and catalogue administration

The web interface includes:

- global search across all games or a selected game
- exact GUID, MD5 and SHA-1 identity search
- package, original filename, Name, Import, Export and dependency-path search
- game file browsing with sortable and pageable results
- detailed package information and raw table examination
- Missing Dependencies and requiring-file views
- Duplicate Files and canonical/retire management
- Unverified Files filtering, matching, import, move and deletion workflows
- base-game protection lists
- legacy data audit and normalisation tools
- game, profile, source, mirror and federation administration
- background-job controls, progress, retry, cancellation and cleanup

Exact identity searches use indexed queries. Broad searches require a useful search term and are bounded to avoid accidental full-database scans.

## Package ingestion

Files can enter the catalogue through:

- Profiled Upload for a selected game
- multi-file and folder/subfolder upload
- Upload Bucket for unsorted files
- Local Source Scan
- trusted HTTP source manifests
- PAK Import
- federation transfers
- Game Backup restore

Profiled Upload and PAK Import copy incoming data into durable controlled staging and create background jobs. Closing the browser does not interrupt the import.

Supported redirect-compressed `.uz`, `.uz2` and `.uz3` files are decompressed before package scanning. The compressed wrapper is not retained. The redirect decoder validates the completed payload and rejects incomplete or unsupported archives instead of storing corrupt output.

Standard unencrypted UE4 PAK archives can be extracted and scanned. Encrypted PAK files and IOStore containers are not supported.

### Unverified-file policy

Valid Unreal package data that cannot be accepted under the selected game/profile is retained as a database-backed unverified file so it can be examined, reassigned or imported later.

Unsupported extensions, non-package data and invalid uploads are discarded rather than filling the unverified directory with files that cannot be used.

The unverified duplicate cleanup compares file size first and calculates MD5 only for possible collisions. It retains one physical copy of each exact size-and-MD5 identity and revalidates a duplicate immediately before deletion.

## Identity, filenames and aliases

Duplicate and dependency matching do not rely on a cleaned browser filename. UnrealDB uses package GUIDs, hashes, source-relative package identity and parsed object tables.

Verified storage uses hash-based physical filenames:

```text
storage/games/<game-slug>/verified/<md5>.<extension>
```

The original filename and source-relative path remain database metadata and are used when displaying, downloading, exporting or restoring the file.

A byte-identical package presented under another legitimate package name can be recorded as an alias of the existing physical file. Aliases remain available to dependency matching and are reproduced as their own original filenames in a Game Backup export.

For UE4 packages, a mounted source-relative path is required to derive a reliable long package name. Folder upload, Local Source Scan, PAK import, source manifests and Game Backup restore preserve this context.

## Dependency resolution

UnrealDB resolves imports using parsed package and exported-object evidence rather than filename guessing. Each dependency is recorded as one of the following:

- resolved object
- resolved package
- package-only match
- common engine package
- missing

The database also records the resolution source and confidence. Administrators can rebuild one file, affected dependants or an entire game through queued maintenance jobs.

## Game Backups

`Admin → Imports → Game Backups` creates complete independent server-side copies of a selected game.

Game Backups are intended for catalogue migration, disaster recovery and reset/reimport workflows rather than public user downloads.

An export:

- uses normal file copies only — never hard links
- restores original filenames
- recreates recorded game/source folder structures
- places files with no known source path under `_Unsorted`
- preserves aliases as separate logical filenames
- moves same-path/different-content conflicts under `_Conflicts`
- verifies every copied file by size and MD5
- writes `manifest.json`, `files.csv`, `README.txt` and progress state
- continues in the detached background worker after the browser closes

The Game Backups page lists every export currently present on the server, including game, status, progress, file count, copied size, conflicts, creation time and exact server path. Completed exports can be imported into a selected game or deleted from the same page. Active exports/imports cannot be deleted.

The restore process verifies each backup entry, creates a temporary working copy, imports canonical files before aliases and optionally rebuilds all game dependencies once after the restore. It never moves, renames or modifies files inside the backup.

The default backup root is:

```text
<storage_path>/game-backups
```

A separate FTP/SFTP-accessible path can be configured in `catalog/config.php`:

```php
'game_backups' => [
    'path' => 'L:/UnrealDB/game-backups',
],
```

Transfer the complete backup directory, including its manifest, to the destination server's configured backup root. It will then appear on that site's Game Backups page for import.

## Base-game distribution protection

Administrators can maintain base-game GUID lists per game. Protected packages remain indexed for dependency analysis but are blocked from public download and generated user-package distribution.

Game Backup is an authenticated administrator backup operation and is separate from public distribution policy.

## Downloads and generated packages

Public file delivery supports these modes:

| Mode | Behaviour |
| --- | --- |
| `local_direct` | Serve an allowed verified file from local catalogue storage. |
| `external_mirror` | Return only an active external/shared-provider link. |
| `external_mirror_preferred` | Prefer an active external link; otherwise permit local delivery and queue mirror work. |
| `disabled` | Disable public file delivery. |

All local public downloads pass through the canonical download controller, which checks verification state, base-game protection and active distribution policy before streaming the file with its preserved original name.

Generated dependency or installation packages are built in the background worker. Available formats include:

| Target | Output |
| --- | --- |
| Generic dependency set | ZIP |
| Unreal Tournament | `.umod` |
| Unreal Tournament 2003 | `.ut2mod` |
| Unreal Tournament 2004 | `.ut4mod` |
| Unreal Tournament 3 | structured ZIP |
| Unreal Tournament 4 | uncompressed, unencrypted PAK |

Generated artifacts are session-authorized, size-checked, stored under controlled generated-package storage and removed after their retention period.

## Durable background jobs

The catalogue uses a MySQL-backed queue with leases, heartbeats, cooperative cancellation, retry and dead-letter handling.

Current durable work includes:

- staged package import
- staged PAK extraction/import
- exact file, affected-file and full-game dependency rebuilds
- UE4 source-identity repair
- unverified duplicate cleanup
- unverified storage reconciliation
- generated package creation
- Game Backup export and restore
- stale artifact and upload-progress cleanup

Uploads, PAK imports, generated packages and Game Backups automatically attempt to start the detached CLI worker. An already-running worker drains eligible queued jobs and exits when the queue is empty.

The Background Jobs page provides:

- queued, running, completed, failed and cancelled counts
- persisted progress and error details
- start-next and drain-queue controls
- cooperative stop and cancellation
- retry and expired-lease recovery
- deletion of individual terminal jobs
- cleanup of terminal jobs older than a selected retention period

Routine queue controls require a valid logged-in administrator session and CSRF protection. They do not force a recent password/MFA reauthentication that could interrupt the worker being controlled.

A worker can also be run directly:

```bash
php catalog/bin/catalog-worker.php --queue=catalog --max-jobs=25 --sleep-ms=250 --lease-seconds=120
```

Useful CLI controls include:

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
php catalog/bin/job-control.php enqueue-reconcile-unverified --max-files=1000
php catalog/bin/job-control.php enqueue-prune-artifacts --incoming-max-age-seconds=172800
```

See [`docs/background-jobs.md`](docs/background-jobs.md) for job states, leases, cancellation semantics, cleanup and recovery behavior.

## Authentication and security

The application includes:

- administrator sessions and rotating remember-me tokens
- login throttling
- CSRF protection for state-changing browser and API operations
- optional encrypted TOTP MFA and one-time recovery codes
- protected runtime configuration and storage paths
- public search, download, package-generation and federation request limits
- strict local-storage path validation
- federation request signing, nonce replay protection and transfer hashing
- optional encrypted federation shared secrets and Ed25519 peer identities
- production security headers and restricted development paths

Create the first administrator from a trusted command line. Do not expose administrator-creation commands, runtime configuration, storage, source code or development package viewers through the public document root.

## Federation

Catalogue installations can exchange inventory and package files through authenticated federation endpoints.

Federation requests are signed over the method, path, timestamp, nonce and body hash. Incoming nonces prevent replay. Binary transfers validate declared size and SHA-256 before import.

Peer secrets can be encrypted at rest when a deployment master key is configured. Ed25519 public-key identities, key rotation and revocation are available while HMAC-SHA256 remains available for compatibility during upgrades.

Production federation should use HTTPS and the strict outbound-network and secret-storage controls documented under `docs/`.

## Requirements

- PHP 8.2 or later
- PDO MySQL
- MySQL 8 or a compatible MariaDB release
- PHP cURL for HTTP sources and federation
- PHP ZipArchive for ZIP output
- PHP Sodium for encrypted secrets, MFA and Ed25519 features
- a writable storage directory shared by web and worker processes
- Apache, another compatible web server, or the supplied container deployment

The supplied container uses PHP 8.3.

## Installation

1. Copy `catalog/config.example.php` to `catalog/config.php`.
2. Configure database credentials, `storage_path`, queue settings and optional Game Backup path.
3. Import `catalog/install.sql` into a new empty database.
4. Apply and verify numbered migrations.
5. Create the first administrator from a trusted command line.
6. Ensure the storage and backup directories are writable by the web and CLI worker accounts.
7. Configure the web server so only intended public application files are reachable.

Run migrations after the baseline import and before each rollout:

```bash
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
```

Create the first administrator:

```bash
php catalog/bin/create-admin.php
```

For an existing Git checkout:

```bash
git pull origin main
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
```

## Database schema

`catalog/install.sql` is the canonical baseline for a new empty database. Existing deployments and fresh installs must also run the ordered migrations.

Applied migration versions and checksums are stored in `ue_schema_migrations`. Modified or missing applied migrations fail verification.

Historical `upgrade-*.sql` files are retained as compatibility references; they are not the supported deployment sequence.

## HTTP/API endpoints

Important health and job endpoints include:

```text
/catalog/api/v1/live.php
/catalog/api/v1/health.php
/catalog/api/v1/metrics.php
/catalog/api/v1/job-status.php
/catalog/api/v1/job-action.php
/catalog/api/v1/job-run.php
/catalog/api/v1/job-worker-status.php
/catalog/api/v1/job-worker-action.php
/catalog/api/federation/
```

- `live.php` confirms that the PHP application responds.
- `health.php` performs database-backed readiness checks.
- `metrics.php` exposes protected Prometheus metrics when configured.
- job endpoints require an administrator session and use persisted queue state.
- federation endpoints live under `catalog/api/federation/`.

## Deployment assets

The repository includes:

- Docker image construction
- Docker Compose deployment
- Kubernetes web, worker, migration and maintenance resources
- readiness, liveness and startup probes
- migration-gated rollout and rollback support
- database and storage backup/restore scripts
- Prometheus-compatible operational metrics
- optional GHCR image publication and vulnerability scanning

Relevant documentation:

- [`docs/production-deployment.md`](docs/production-deployment.md)
- [`docs/database-migrations.md`](docs/database-migrations.md)
- [`docs/background-jobs.md`](docs/background-jobs.md)
- [`docs/full-review-completion.md`](docs/full-review-completion.md)
- [`docs/production-remediation-program.md`](docs/production-remediation-program.md)

Production deployments should use protected database credentials, TLS, durable package storage, tested database and storage backups, centralized logging and an appropriate session store.

## Automated checks

The repository contains PHP syntax, JavaScript syntax, architecture, security, schema, queue, storage, package-format and synthetic reader-fixture checks.

GitHub Actions workflows are **manual-only** through `workflow_dispatch`. Normal commits and pushes to `main` do not automatically start Catalog quality, detached-worker, administration-navigation or container-release workflows. This prevents routine repository updates from generating repeated failed-run email notifications on deployments that do not use the supplied CI/container environment.

The checks can still be started manually from the repository's **Actions** page when required.

Synthetic package fixtures contain no retail assets. Private real-world fixtures can be tested through `UNREALDB_FIXTURE_ROOT` without committing those files to Git.

## Current limitations and remaining work

Known boundaries include:

- arbitrary UE4 serialized-property parsing is not yet fully equivalent to Epic's implementation for every game and package version
- UE4 encrypted PAK and IOStore containers are not supported
- UE4 loose-file imports require reliable mounted source-relative paths
- redirect compression has multiple historical variants; unsupported or incomplete payloads fail closed
- retail game packages and proprietary test fixtures cannot be included in the repository
- large-library search and dependency maintenance still require environment-specific MySQL and storage tuning
- provider-specific external-mirror upload automation is not a general core feature
- production operations still require deployment-specific TLS, secrets, database backup, storage sizing and monitoring

The ordered production-review status and remaining engineering work are maintained in [`docs/production-remediation-program.md`](docs/production-remediation-program.md).