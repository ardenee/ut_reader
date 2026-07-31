# UnrealDB / ut_reader

UnrealDB is a PHP and MySQL catalogue, analysis, dependency-resolution and controlled-distribution system for Unreal Engine package files.

The project is intended for large Unreal package collections where filenames alone are not reliable enough to identify a package, remove duplicates or satisfy dependencies. It preserves package identity, original filenames and source paths while indexing package structure and relationships.

> **Development status:** UnrealDB is under active development. The public site is available so users can explore the catalogue and see what the service will provide, but some functions are incomplete, experimental or administrator-only.

## Current capabilities

UnrealDB currently provides:

- Unreal Engine package header parsing
- Names, Imports and Exports table indexing
- GUID, MD5, SHA-1 and file-size identity
- package, object and dependency search
- verified and unverified file workflows
- duplicate detection based on content identity rather than filename
- dependency resolution and missing-package reporting
- base-game distribution protection
- UE3 UPK management
- UE4 and UE5 PAK retention, indexing and supported extraction
- redirect archive handling for `.uz`, `.uz2` and `.uz3`
- large-folder browser upload with resumable transfer
- durable background jobs with a configurable worker pool
- generated dependency packages and game-specific installers
- parent/child catalogue federation
- game backup, restore, reset and deletion workflows
- public download limits, speed controls and automated-access protection
- public feedback delivery through administrator-configured SMTP
- persistent Upload Issues and System Errors review pages

## Engine and game profiles

Reader and profile support currently covers:

- Unreal Engine 1
- Unreal Engine 2
- Unreal Engine 2.5
- Unreal Engine 3
- Unreal Engine 4
- Unreal Engine 5 container workflows, with limited/experimental loose-package parsing

Common configured games include:

- Unreal and Unreal Gold
- Unreal Tournament
- Unreal II
- Unreal Tournament 2003
- Unreal Tournament 2004
- Unreal Tournament 3
- Unreal Tournament 4 Alpha

Administrators can add other games and assign reusable profiles. Profiles define the reader family, accepted extensions, package/licencee-version ranges and compatibility exceptions.

## Package information and identity

For verified packages UnrealDB can record:

- package header fields and versions
- package GUID
- MD5 and SHA-1
- original filename and logical package name
- source-relative path
- detected engine/profile
- Names, Imports and Exports
- generated object paths
- dependencies and dependency confidence
- package aliases sharing one physical identity
- local, HTTP, mirror and federation locations
- required files and files requiring the package
- source PAK archive and exact PAK entry, where applicable

Unreal object indices follow the standard rule:

- `0` — null reference
- negative — import index `(-index - 1)`
- positive — export index `(index - 1)`

Dependency paths remain slash-separated Unreal paths. Dots are retained only where Unreal object-path syntax requires an object-name suffix.

## Search and catalogue views

The public and administrator interfaces include:

- per-game public search
- administrator all-game search
- exact GUID, MD5 and SHA-1 lookup
- package, filename, Name, Import, Export and dependency search
- game-file lists
- package information and package examination
- missing-dependency and requiring-file views
- duplicate management
- unverified-file review and import
- PAK and UPK container views
- file, dependency and identity repair tools

Broad searches are bounded to prevent accidental full-database scans. Exact identity searches use indexed fields.

## Package ingestion

Files can enter UnrealDB through:

- Profiled Upload
- Upload Bucket v2
- Upload Bucket legacy uploader
- Local Source Scan
- HTTP Source Scan
- UE4/UE5 PAK Import
- federation transfer
- Game Backup restore

### Upload Bucket v2

The new Upload Bucket is designed for very large folders and keeps the browser responsive:

- uses Chrome's incremental directory picker where available
- retains the legacy folder input as a fallback
- processes one file at a time
- performs extension and header checks in a Web Worker
- calculates MD5 and SHA-1 client-side
- checks for duplicates before upload
- uploads through resumable chunks
- provides per-file and overall progress
- supports cooperative Stop during discovery, hashing, transfer and finalisation
- virtualises the status log so tens of thousands of rows do not freeze the page
- records failed validation, transfer and finalisation results in **Upload Issues**

The status line for each selected file is updated in place rather than adding unlimited DOM rows.

Redirect signatures for historic `.uz` variants using either `1234` or `5678` are accepted by browser validation. `.uz2` and `.uz3` continue to use their format-specific checks.

### Redirect-compressed files

Supported `.uz`, `.uz2` and `.uz3` files are uploaded in compressed form, decompressed server-side and scanned as their real package type. The compressed wrapper is not retained after a successful import.

Incomplete, unsupported or corrupt redirect archives fail closed and are recorded for administrator review.

### Unverified files

Valid Unreal package data that cannot yet be assigned confidently to a game/profile is retained as an unverified file.

Unverified files can be filtered, matched, imported, moved or deleted. Exact duplicates are identified by size and MD5; filename similarity alone is not treated as proof of duplication.

Unsupported extensions and invalid non-package data are rejected instead of accumulating in unverified storage.

## Storage and filenames

Verified physical storage uses hash-based filenames, while the original filename and source path remain database metadata:

```text
storage/games/<game-slug>/verified/<md5>.<extension>
```

A byte-identical package supplied under another legitimate package name can be recorded as an alias of the existing physical file. Aliases remain usable for dependency matching, display, download and Game Backup export.

For loose UE4/UE5 packages, reliable mounted source-relative paths are required to derive correct long package names. Folder upload, Local Source Scan, PAK import, source manifests and backup restore preserve this context where available.

## Dependency resolution

Dependencies are resolved from parsed package/object evidence rather than filename guessing.

Recorded outcomes include:

- resolved object
- resolved package
- package-only match
- common engine package
- missing

Administrators can rebuild:

- one file
- files affected by a change
- an entire game

Dependency rebuilds run as durable background jobs.

## UE3 UPK management

UE3 `.upk` files are managed as original package containers and appear separately from ordinary game files.

The UPK views provide:

- package identity and versions
- compression state
- Names/Imports/Exports counts
- serialized payload information
- original package download
- links to exact package-examiner rows

A UE3 UPK contains serialized UObject exports, not a normal directory of independent child packages. UnrealDB indexes those exports but does not create fake standalone `.upk` files from export payloads.

See [`docs/upk-package-management.md`](docs/upk-package-management.md).

## UE4 and UE5 PAK management

Readable, unencrypted UE4/UE5 `.pak` containers can be retained, indexed and extracted when their footer, index and compression methods are supported.

A PAK import can:

- retain the original archive independently
- verify size and SHA-256
- record footer/index metadata and mount point
- list readable index entries
- extract supported entries
- import accepted package files
- link extracted files back to the PAK and entry path
- run one dependency refresh after import

Original containers are shown separately from extracted package files and remain available as controlled downloads.

Current limitations include encrypted indexes/entries, unsupported or Oodle compression and UE5 IoStore `.utoc`/`.ucas` containers.

See [`docs/pak-archive-management.md`](docs/pak-archive-management.md).

## Game Backups

Game Backups create independent server-side copies for migration, disaster recovery and reset/reimport workflows.

Exports:

- use normal file copies, not hard links
- restore logical filenames
- preserve source-relative paths where known
- export aliases as separate logical filenames
- verify each copy by size and MD5
- write `manifest.json`, `files.csv` and `README.txt`
- continue through background workers after the browser closes

Same-name variations remain in the same directory and receive a numeric suffix:

```text
Package.utx
Package (2).utx
Package (3).utx
```

The manifest retains the original logical filename so restored variations are imported under their real catalogue identity.

The default backup location is:

```text
<storage_path>/game-backups
```

It can be overridden in `catalog/config.php`:

```php
'game_backups' => [
    'path' => 'L:/UnrealDB/game-backups',
],
```

## Game reset and deletion

Game reset removes managed game files and related catalogue rows while preserving the game definition, profile, source configuration and base-game protection.

Game deletion removes the game and its managed catalogue data after confirmation.

Both operations provide progress and run `OPTIMIZE TABLE` on affected high-churn tables after deletion. Optimisation warnings do not incorrectly reverse already completed deletion work.

## Base-game protection

Administrators can maintain official/base-game GUID lists per game.

Protected packages remain indexed for dependency analysis but are excluded from public downloads, generated packages, federation requests and other distribution paths where applicable.

Administrator Game Backups are separate from public distribution policy.

## Downloads and generated packages

Public delivery modes include:

| Mode | Behaviour |
| --- | --- |
| `local_direct` | Serve an allowed verified file from local storage. |
| `external_mirror` | Return only an active external/shared-provider link. |
| `external_mirror_preferred` | Prefer an external link and fall back to local delivery where permitted. |
| `disabled` | Disable public delivery. |

Generated package formats include:

| Target | Output |
| --- | --- |
| Generic dependency set | ZIP |
| Unreal Tournament | `.umod` |
| Unreal Tournament 2003 | `.ut2mod` |
| Unreal Tournament 2004 | `.ut4mod` |
| Unreal Tournament 3 | structured ZIP |
| Unreal Tournament 4 | uncompressed, unencrypted PAK |

Generated artifacts are controlled, validated and removed after their retention period.

## Public access controls and feedback

Administrators configure public behaviour through **Public Access & Mail**.

Current controls include:

- development-status notice shown on the landing page
- public feedback enable/disable
- SMTP host, port, encryption, credentials and From identity
- SMTP test delivery
- feedback recipient, defaulting to `info@unrealdb.com`
- per-IP individual download limit
- per-IP generated-package limit
- local transfer speed limit
- known crawler/scripted-downloader blocking
- rapid-request threshold and temporary IP block duration
- feedback submission rate limit

Default public limits are:

- 10 individual file downloads per IP per hour
- 10 generated packages per IP per hour
- a 10-minute temporary IP block after the configured rapid-request threshold is exceeded

The enabled Feedback link appears beside Search in the public header. Successful submissions return to the previous safe local page, or to the main landing page when no valid previous page is available.

## Background jobs and worker pool

UnrealDB uses a durable MySQL-backed job queue with leases, heartbeats, retry, cooperative cancellation and dead-letter handling.

The Background Jobs page supports:

- queue/status filtering
- per-job and overall progress
- cancellation and retry
- expired-lease recovery
- terminal-job cleanup
- compact filename/error review
- selectable worker count from 1 to 8

The default worker pool is four independent PHP CLI processes. Each worker has its own:

- process lock
- state file
- log file
- lease/heartbeat state

Successful jobs do not incur an artificial post-job delay. Idle workers pause briefly before polling again.

Parallelism is controlled by resource classes and per-job concurrency keys. Eight running worker processes do not guarantee eight CPU-bound jobs at once: conflicting maintenance, game-import, dependency, storage or package-generation work may be serialized deliberately, and database-heavy jobs may be limited by query/transaction behaviour rather than host CPU usage.

Upload Bucket processing uses per-file concurrency keys so different files can run concurrently while the same file cannot be processed twice.

A worker can also be run directly:

```bash
php catalog/bin/catalog-worker.php --queue=catalog --max-jobs=25 --sleep-ms=250 --lease-seconds=120
```

Useful queue commands include:

```bash
php catalog/bin/job-control.php status --queue=catalog --limit=50
php catalog/bin/job-control.php cancel --id=123 --reason="Operator requested stop"
php catalog/bin/job-control.php retry --id=123
php catalog/bin/job-control.php recover --queue=catalog
php catalog/bin/job-control.php enqueue-rebuild-game --game-id=1 --offset=0
php catalog/bin/job-control.php enqueue-rebuild-file --file-id=123
php catalog/bin/job-control.php enqueue-clean-unverified-duplicates
php catalog/bin/job-control.php enqueue-reconcile-unverified --max-files=1000
```

See [`docs/background-jobs.md`](docs/background-jobs.md).

## Upload Issues and System Errors

Two administrator review areas provide persistent error visibility:

### Upload Issues

Records failed browser inspection, extension/header validation, duplicate preflight, transfer, finalisation and Upload Bucket worker-start results. Repeated identical failures increment an occurrence count instead of creating unlimited duplicate rows.

### System Errors

Records application-visible PHP, API, browser JavaScript, failed-resource and rendered error conditions. Entries can be resolved, ignored or reopened.

Failures below PHP, such as Apache startup errors, operating-system failures or complete database unavailability, remain in the normal server/PHP error logs.

## Federation

Federation allows parent and child UnrealDB installations to exchange inventories, requests and approved files.

The parent acts as the source of truth. Child installations can report dependency needs and request approved content. Parent installations can review child inventories and transfer requirements.

Security includes:

- signed requests
- nonce replay protection
- transfer size/hash validation
- encrypted shared secrets where a master key is configured
- optional Ed25519 peer identities
- role-aware parent/child controls

Production federation should use HTTPS.

## Authentication and security

The application includes:

- administrator sessions
- rotating remember-me tokens
- login throttling
- CSRF protection
- optional TOTP MFA
- one-time recovery codes
- controlled local-storage paths
- public action rate limits
- crawler and burst protection
- encrypted stored secrets when `UNREALDB_FEDERATION_MASTER_KEY` is configured

## Requirements

- PHP 8.2 or later
- PDO MySQL
- MySQL 8 or a compatible MariaDB release
- PHP cURL
- PHP ZipArchive
- PHP Sodium for MFA, encrypted secrets and Ed25519 features
- a writable storage directory shared by web and CLI worker processes
- Apache or another compatible web server

The project is currently used primarily on Windows Apache/PHP/MySQL, but the repository also contains container and production-deployment assets.

## Installation

1. Copy `catalog/config.example.php` to `catalog/config.php`.
2. Configure database credentials and `storage_path`.
3. Import `catalog/install.sql` into a new empty database.
4. Apply and verify numbered migrations.
5. Create the first administrator from a trusted command line.
6. Ensure the web server and CLI worker account can write to the configured storage paths.

```bash
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
php catalog/bin/create-admin.php
```

## Updating an existing installation

Update the checkout actually served by Apache, then apply migrations:

```bash
git pull origin main
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
```

On the current Windows deployment the served checkout may differ from a Visual Studio/source checkout. Confirm the active Apache document root before updating.

`catalog/install.sql` is the canonical baseline. Numbered migrations are the supported upgrade path.

## Important pages

Public:

```text
catalog/index.php
catalog/games.php
catalog/index.php?page=search
catalog/feedback.php
```

Administration:

```text
catalog/dashboard.php
catalog/public-access-settings.php
catalog/download-admin.php
catalog/background-jobs.php
catalog/upload-bucket-v2.php
catalog/upload-issues.php
catalog/system-errors.php
catalog/unverified-files.php
catalog/game-backups.php
```

API/health:

```text
catalog/api/v1/live.php
catalog/api/v1/health.php
catalog/api/v1/metrics.php
catalog/api/v1/job-status.php
catalog/api/v1/job-action.php
catalog/api/v1/job-run.php
catalog/api/v1/job-worker-status.php
catalog/api/v1/job-worker-action.php
catalog/api/federation/
```

## Documentation

- [`docs/production-deployment.md`](docs/production-deployment.md)
- [`docs/database-migrations.md`](docs/database-migrations.md)
- [`docs/background-jobs.md`](docs/background-jobs.md)
- [`docs/pak-archive-management.md`](docs/pak-archive-management.md)
- [`docs/upk-package-management.md`](docs/upk-package-management.md)
- [`docs/full-review-completion.md`](docs/full-review-completion.md)
- [`docs/production-remediation-program.md`](docs/production-remediation-program.md)

## Automated checks

The repository contains PHP and JavaScript syntax checks, schema and migration checks, architecture/security contracts, queue/storage tests, package-format tests, Upload Bucket tests and PAK/UPK management tests.

GitHub Actions workflows are manual-only through `workflow_dispatch`; routine pushes to `main` do not automatically start the larger catalogue workflows.

## Current limitations

Known boundaries include:

- not every public or administrator function is complete
- UE4/UE5 serialized-property parsing is not equivalent to Epic's implementation for every package version
- encrypted PAK indexes/entries require keys and supported encryption handling
- Oodle and other unsupported compression methods cannot be extracted without the required decoder
- UE5 IoStore `.utoc`/`.ucas` containers are not currently imported by the PAK workflow
- loose UE4/UE5 files require reliable mounted source-relative paths
- UE3 UPK exports are indexed objects, not automatically reconstructed standalone packages
- worker-pool throughput is not necessarily linear because jobs can be database-bound or intentionally serialized
- very large catalogue operations should be run through the durable background queue rather than synchronous web requests

## Project direction

UnrealDB is being developed as a long-term package identity, dependency and preservation service for Unreal Engine game content. The immediate focus is reliability at large catalogue scale, accurate package relationships, safe controlled distribution, federation between installations and clear administrator diagnostics.