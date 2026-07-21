# UnrealDB / ut_reader

UnrealDB is a PHP and MySQL catalogue, analysis and controlled-distribution system for Unreal Engine package files. It reads package headers and object tables, preserves package identity and source paths, resolves dependencies, manages verified and unverified storage, builds game-specific download packages, exchanges files between catalogue installations, and supports complete server-side game backup and restore.

The project is designed for large Unreal package libraries where filenames alone are not reliable enough to identify duplicates or satisfy dependencies.

## Engine and game profiles

Reader and profile support currently covers:

- Unreal Engine 1
- Unreal Engine 2
- Unreal Engine 2.5
- Unreal Engine 3
- Unreal Engine 4

Game profiles define the reader family, accepted extensions, package and licencee-version ranges, compatibility exceptions and detection policy. Profiles can be reused by multiple games through `ue_games.profile_id`.

Common installations include Unreal, Unreal Gold, Unreal Tournament, Unreal II, Unreal Tournament 2003, Unreal Tournament 2004, Unreal Tournament 3 and Unreal Tournament 4 Alpha. Administrators can add other games and assign an appropriate reusable profile.

## Package data and identity

For verified packages UnrealDB records:

- package header fields
- package GUID, MD5 and SHA-1
- original filename and logical package name
- recorded source-relative path
- detected engine, package version and licencee version
- Names, Imports and Exports tables
- import/export object references and generated paths
- dependency status, resolution source and confidence
- package aliases sharing a physical file identity
- local, HTTP, mirror and federation locations
- required files and files requiring the selected package

Unreal object indices follow the standard rule:

- `0` — null reference
- negative — import index `(-index - 1)`
- positive — export index `(index - 1)`

Dependency paths remain slash-separated Unreal package paths. A dot is used only where Unreal object-path syntax requires an object-name suffix.

## Search and administration

The interface includes:

- global or per-game search
- exact GUID, MD5 and SHA-1 search
- package, filename, Name, Import, Export and dependency-path search
- sortable and pageable game-file tables
- package information and raw table examination
- Missing Dependencies and requiring-file views
- Duplicate Files management
- Unverified Files filtering, matching, import, move and deletion
- base-game protection lists
- legacy data audit and normalisation tools
- source, mirror and federation administration
- durable background-job progress, cancellation, retry and cleanup

Exact identity searches use indexed queries. Broad searches require a useful term and are bounded to avoid accidental full-database scans.

## Package ingestion

Files can enter the catalogue through:

- Profiled Upload
- multi-file and folder/subfolder upload
- Upload Bucket
- Local Source Scan
- trusted HTTP source manifests
- PAK Import
- federation transfer
- Game Backup restore

Profiled Upload and PAK Import copy incoming data into durable staging and enqueue background jobs. Closing the browser does not interrupt the work.

Supported redirect-compressed `.uz`, `.uz2` and `.uz3` files are decompressed before scanning. The compressed wrapper is not retained. Incomplete or unsupported redirect variants fail closed.

Standard unencrypted UE4 PAK archives can be extracted and scanned. Encrypted PAK files and IOStore containers are not supported.

### Unverified-file policy

Valid Unreal package data that cannot be accepted under the selected game/profile is retained as a database-backed unverified file.

Unsupported extensions, non-package data and invalid uploads are discarded rather than accumulating in unverified storage.

Unverified duplicate cleanup groups by size first and calculates MD5 only for possible collisions. It retains one physical copy of each exact size-and-MD5 identity and revalidates a candidate immediately before deletion.

## Storage, aliases and filenames

Verified physical storage uses hash-based names:

```text
storage/games/<game-slug>/verified/<md5>.<extension>
```

Original filenames and source-relative paths remain metadata and are restored for display, download, backup and import.

A byte-identical package presented under another legitimate package name can be recorded as an alias of the existing physical identity. Aliases remain available for dependency matching and are exported under their own original names in a Game Backup.

For UE4 packages, mounted source-relative paths are required to derive reliable long package names. Folder upload, Local Source Scan, PAK import, source manifests and backup restore preserve this context.

## Dependency resolution

Dependencies are resolved from parsed package and exported-object evidence rather than filename guessing.

Recorded states include:

- resolved object
- resolved package
- package-only match
- common engine package
- missing

Administrators can rebuild one file, affected dependants or an entire game through durable queued jobs.

## Game Backups

Game Backups are available from:

- the main **Admin** menu
- the **Imports** menu
- the administrator Dashboard and Setup pages

`game-backups.php` creates complete independent server-side file copies for migration, disaster recovery and reset/reimport workflows.

An export:

- uses normal file copies only; hard links are never used
- restores original logical filenames
- checks `ue_files.source_relative_path` and all recorded `ue_file_locations`
- prefers a recorded location matching the logical filename
- preserves recorded game/source folder paths
- places flat UE1/UE2 packages into standard game folders such as `Maps`, `System`, `Textures`, `Sounds`, `Music`, `StaticMeshes`, `Animations` and `Prefabs`
- exports aliases as separate logical filenames
- verifies every copy by file size and MD5
- writes `manifest.json`, `files.csv`, `README.txt` and progress state
- continues through the detached worker after the browser closes

No `_Conflicts` directory is created. When two catalogue entries need the same destination filename, every variation remains in the same folder and receives a numeric suffix before the extension:

```text
Package.utx
Package (2).utx
Package (3).utx
```

The manifest retains each entry's original logical filename, requested path and final exported path. Backup restore therefore imports a renamed variation using its original catalogue filename rather than the numbered export filename.

The Game Backups page lists exports currently on the server, including status, progress, copied files, size, renamed same-name variations, recovered source paths, creation time and exact server path. Completed exports can be imported or deleted. Active exports/imports cannot be deleted.

The default backup root is:

```text
<storage_path>/game-backups
```

A separate FTP/SFTP-accessible location can be configured in `catalog/config.php`:

```php
'game_backups' => [
    'path' => 'L:/UnrealDB/game-backups',
],
```

Transfer the complete backup directory, including its manifest, to the destination installation's configured backup root. It will then appear on that site's Game Backups page.

The restore process:

1. validates the manifest
2. verifies each file's size and MD5
3. creates an independent temporary working copy
4. restores canonical files before aliases
5. preserves the original logical source filename/path from the manifest
6. optionally rebuilds all game dependencies once at the end

The backup itself is never moved, renamed, hard-linked or modified during import.

## Base-game distribution protection

Administrators can maintain base-game GUID lists per game. Protected packages remain indexed for dependency analysis but are blocked from public downloads and generated user packages.

Game Backup is an authenticated administrator operation and is separate from public distribution policy.

## Downloads and generated packages

Public delivery modes are:

| Mode | Behaviour |
| --- | --- |
| `local_direct` | Serve an allowed verified file from local storage. |
| `external_mirror` | Return only an active external/shared-provider link. |
| `external_mirror_preferred` | Prefer an active external link, otherwise permit local delivery and queue mirror work. |
| `disabled` | Disable public delivery. |

All local public downloads pass through the canonical controller, which checks verification state, base-game protection and distribution policy before streaming the preserved original filename.

Generated package output includes:

| Target | Output |
| --- | --- |
| Generic dependency set | ZIP |
| Unreal Tournament | `.umod` |
| Unreal Tournament 2003 | `.ut2mod` |
| Unreal Tournament 2004 | `.ut4mod` |
| Unreal Tournament 3 | structured ZIP |
| Unreal Tournament 4 | uncompressed, unencrypted PAK |

Generated artifacts are session-authorized, size-checked, stored under controlled generated-package storage and removed after their retention period.

## Background jobs and workers

The catalogue uses a durable MySQL-backed queue with leases, heartbeats, cooperative cancellation, retry and dead-letter handling.

Durable operations include:

- staged package import
- staged PAK extraction/import
- file, affected-file and full-game dependency rebuilds
- UE4 source-identity repair
- unverified duplicate cleanup
- unverified storage reconciliation
- generated package creation
- Game Backup export and restore
- stale artifact and upload-progress cleanup

Uploads, PAK imports, generated packages and Game Backups automatically attempt to start the detached CLI worker. An existing worker drains eligible queued jobs and exits when the queue is empty.

The Background Jobs page provides queue counts, progress, start/drain controls, cancellation, retry, expired-lease recovery, terminal-job deletion and retention cleanup.

Routine queue controls require a valid administrator session and CSRF protection. They do not require recent password/MFA reauthentication.

A worker can also be run directly:

```bash
php catalog/bin/catalog-worker.php --queue=catalog --max-jobs=25 --sleep-ms=250 --lease-seconds=120
```

Useful controls include:

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

See [`docs/background-jobs.md`](docs/background-jobs.md).

## Authentication and federation

The application includes administrator sessions, rotating remember-me tokens, login throttling, CSRF protection, optional encrypted TOTP MFA, one-time recovery codes, public request limits and strict local-storage path validation.

Federation supports signed requests, nonce replay protection, transfer size/hash verification, encrypted shared secrets and optional Ed25519 peer identities with rotation and revocation.

Production federation should use HTTPS and the strict outbound-network and secret-storage controls documented under `docs/`.

## Requirements

- PHP 8.2 or later
- PDO MySQL
- MySQL 8 or a compatible MariaDB release
- PHP cURL
- PHP ZipArchive
- PHP Sodium for MFA, encrypted secrets and Ed25519 features
- a writable storage directory shared by web and worker processes
- Apache, another compatible web server, or the supplied container deployment

The supplied container uses PHP 8.3.

## Installation and upgrades

1. Copy `catalog/config.example.php` to `catalog/config.php`.
2. Configure database credentials, `storage_path`, queue settings and optional Game Backup path.
3. Import `catalog/install.sql` into a new empty database.
4. Apply and verify numbered migrations.
5. Create the first administrator from a trusted command line.
6. Ensure storage and backup directories are writable by web and worker accounts.
7. Restrict the public document root to intended application files.

```bash
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
php catalog/bin/create-admin.php
```

For an existing checkout:

```bash
git pull origin main
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
```

`catalog/install.sql` is the canonical baseline. Historical `upgrade-*.sql` files are compatibility references rather than the supported release sequence.

## API and deployment assets

Important endpoints include:

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

The repository includes Docker, Docker Compose and Kubernetes deployment assets, health probes, migration-gated rollout support, database/storage backup scripts and Prometheus-compatible metrics.

Documentation:

- [`docs/production-deployment.md`](docs/production-deployment.md)
- [`docs/database-migrations.md`](docs/database-migrations.md)
- [`docs/background-jobs.md`](docs/background-jobs.md)
- [`docs/full-review-completion.md`](docs/full-review-completion.md)
- [`docs/production-remediation-program.md`](docs/production-remediation-program.md)

## Automated checks

The repository contains PHP and JavaScript syntax checks, architecture/security tests, schema tests, queue/storage tests, package-format contracts and synthetic reader fixtures.

GitHub Actions workflows are manual-only through `workflow_dispatch`. Routine pushes to `main` do not automatically start Catalog quality, detached-worker, administration-navigation or container-release workflows.

## Current limitations

Known boundaries include:

- arbitrary UE4 serialized-property parsing is not fully equivalent to Epic's implementation for every package version
- encrypted UE4 PAK and IOStore containers are unsupported
- UE4 loose files require reliable mounted source-relative paths
- redirect compression has multiple historical variants; unsupported payloads fail closed
- retail packages and proprietary fixtures cannot be committed to the repository
- large libraries require environment-specific MySQL and storage tuning
- production operations still require deployment-specific TLS, secrets, backup, monitoring and storage sizing
