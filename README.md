# UnrealDB

UnrealDB is a catalogue, dependency-analysis and preservation system for Unreal Engine game files.

It is designed to identify packages accurately, preserve physical and logical package identity, inspect Unreal package metadata, track dependencies, find missing requirements, reduce duplicate storage, repair catalogue state, and distribute verified files through controlled downloads and generated packages.

> **Project status — August 2026:** UnrealDB is under active development and is already being used as a working catalogue/admin system. The current engineering focus is reliability, parser edge cases, queue/operator clarity, performance, and production hardening.

## Runtime model

UnrealDB is designed to run on a conventional web server:

```text
Internet
   |
Web server
   |
PHP
   |
   +-- MySQL
   +-- catalogue/package storage
   +-- durable MySQL-backed job queue
   +-- independent PHP background workers
   +-- scheduled backup/maintenance tasks
```

The application is a modular PHP monolith. Web requests submit durable work; background workers execute long-running package, dependency and maintenance operations independently of the browser.

## Project status by area

| Area | Status | Notes |
| --- | --- | --- |
| Public catalogue/search | Active | Browse games/files, exact identity search, broader catalogue search, dependency information and controlled downloads. |
| Administration | Active | Game/profile management, uploads, unverified files, jobs, backups, federation, maintenance and diagnostics. |
| Durable background jobs | Active | Long work is split into recoverable jobs/child workflows with persisted progress and explicit operator control. |
| UE1 packages | Strong support | Names, Imports, Exports, package identity and dependencies are well covered. |
| UE2 / UE2.5 packages | Strong support | Includes UT2003/UT2004-era package handling and UZ2 redirect support. |
| UE3 packages | Active validation | Core package/UPK support exists; less common package/compression variants are still being validated against source/reference material. |
| UE4 packages | Active | Package/PAK handling, source-identity repair and dependency workflows exist; engine/version-specific edge cases remain under investigation. |
| UE5 packages | Partial | Supported where the package/container layout is understood; IoStore `.utoc`/`.ucas` is not fully supported. |
| `.uz` redirects | Active | Historical 1234 and 5678 FCodec variants are supported. |
| `.uz2` redirects | Active | Chunked zlib handling exists; malformed/non-standard archives fail without blocking unrelated jobs. |
| `.uz3` redirects | Active | UT3 tag + uncompressed-size + whole-file zlib encoding/decoding is implemented and validated against real `UT3.exe Compress` output. |
| ZIP / 7z / RAR uploads | Active | Unpack-only ingestion extracts supported Unreal files and hands each file to the normal durable package/redirect/PAK workflow. |
| Federation | Active | Parent/child inventory, dependency requests and controlled transfer workflows are supported. |
| Game Backups | Active | Durable export/restore workflows plus separate production database/storage backup tooling. |

## Core architecture

### Durable jobs, not long browser requests

Large operations run through `ue_background_jobs` instead of relying on an open browser request.

Important queue rules are:

- the UI reports **jobs**, not changing internal work-unit counts;
- a parent job owns the operator-visible operation while child jobs report workflow progress back to it;
- completed child work is retained so restart does not replay successful work;
- one failed package or child job does not block unrelated queued jobs;
- healthy long-running jobs are not failed merely because they exceed a timer;
- worker/process ownership determines whether running work is still alive;
- an operator can explicitly cancel/kill genuinely stuck work;
- job runtime and last activity are available to help identify stalled work;
- resource-class limits control expensive job types independently from the total worker-process count.

The browser is not part of the recovery contract. Recovery begins once a complete uploaded/source file has reached controlled server storage.

See [`docs/background-jobs.md`](docs/background-jobs.md).

### Compact metadata

Verified package metadata uses the current format-2 compact metadata architecture.

- `ue_files` is the stable physical/catalogue identity row.
- `ue_file_metadata` registers the authoritative compact metadata container.
- Names, Imports and Exports are stored in blocked compressed `.uedb2` metadata.
- SQL lookup/projection tables provide indexed dependency/search access without restoring the old row-per-object schema.
- legacy `ue_names`, `ue_imports`, `ue_exports` and `ue_dependencies` are no longer the verified runtime metadata model.
- verified files track explicit compact-metadata publication state so incomplete publication can be identified and repaired.

Compact publication is atomic. Retryable database lock/deadlock failures retry the publication operation rather than leaving a partially published package.

## Upload and import flow

The current ingestion model is:

```text
file/source
   |
identity/hash preflight
   |
controlled staging
   |
durable import job
   |
redirect/archive preparation if required
   |
package parser / identity resolution
   |
physical storage + database publication
   |
compact metadata
   |
dependency follow-up
```

### Upload Files to Game

The browser processes one file at a time, performs advisory hashing/duplicate checks, and transfers the file to controlled server staging. Large files use chunked transport. Once the complete file is staged, the remaining import work is durable and can continue without the browser.

### Upload Bucket

Upload Bucket is intended for large unsorted collections. Browser-side checks avoid unnecessary transfers where possible. Completed uploads/wrappers are handed to the background queue for preparation, decompression, import and dependency work.

### ZIP / 7z / RAR archives

`.zip`, `.7z` and `.rar` are accepted as **unpack-only transport containers**. UnrealDB does not catalogue the archive itself as an Unreal package and does not create ZIP/7z/RAR files.

The archive is listed first and supported Unreal members are expanded one at a time into controlled staging. Each extracted file then enters the existing durable workflow:

- ordinary Unreal packages use the normal package importer;
- `.uz`, `.uz2` and `.uz3` members use the redirect decoder before package import;
- `.pak` members can enter the existing PAK workflow when the selected game/profile supports PAK files;
- one bad member is recorded without preventing unrelated members from being queued;
- nested archives are not recursively expanded;
- password-protected/encrypted archive members are not imported.

ZIP uses PHP `ZipArchive` when available and can fall back to the configured 7-Zip command-line tool. 7z/RAR extraction requires a 7-Zip-compatible command-line binary available as `7zz`, `7z` or `7za`, or configured with `UNREALDB_7ZIP_BINARY` / `archive.seven_zip_binary`.

Archive limits are configured under `archive` in `catalog/config.php`; see `catalog/config.example.php`. The archive source is removed after successful expansion, or retained when one or more members fail so the operation can be inspected/retried.

### Unverified files

Files that cannot yet be assigned confidently are retained in controlled unverified storage instead of being discarded.

Exact game-match evidence is generated in the background and cached. A file can be copied/imported into multiple compatible games only when exact dependency/object-path evidence supports the match; package-name similarity alone is not enough.

### Duplicate identity

Physical duplicate decisions use file size and content hashes, not filenames. Byte-identical packages can retain alternate logical package names through aliases while sharing canonical physical content where appropriate.

## Dependency system

UnrealDB tracks package/object requirements and providers and supports:

- single-file dependency rebuilds;
- affected-dependant refresh after a new provider appears;
- whole-game dependency rebuilds;
- Full Sync;
- source-identity repair;
- provider/projection reconciliation;
- cross-game dependency examination and fulfilment;
- base-game dependency classification/protection.

### Full Sync

Full Sync is a resumable multi-phase workflow:

1. reimport/repair verified files;
2. rebuild provider/projection state;
3. rebuild dependency files;
4. publish final dependency summaries and game statistics.

Work already completed is retained. A restart resumes incomplete phases/children instead of returning to file 1.

## Package/container support

### UE1 / UE2 / UE3 packages

Classic Unreal packages expose package header information, names, imports, exports, hashes/GUIDs and dependency relationships where supported by the engine/version parser.

UE3 `.upk` files remain packages; their internal exports can be examined without pretending that each export is an independent package file.

### UE4 / UE5 PAK files

Supported unencrypted PAK files are retained as original archives and indexed/extracted when their version and compression layout are supported.

PAK import uses a durable parent workspace and independently restartable entry jobs. An unsupported or damaged entry is recorded as an entry outcome rather than preventing unrelated entries from being processed.

Encrypted PAK content and unsupported compression/container variants are not silently accepted.

### Unreal redirect archives

- `.uz`: historical 1234 and 5678 FCodec variants.
- `.uz2`: chunked zlib redirect format used by UE2-era games.
- `.uz3`: UT3 tagged whole-file zlib format, with compression and decompression validated against real `UT3.exe Compress` output.

Catalogue identity is based on the decompressed Unreal package where a redirect wrapper is successfully decoded.

## Public site features

The public side can provide:

- game/file browsing;
- exact MD5/SHA-1/GUID lookups;
- package/file search;
- package metadata/details;
- dependency and missing-dependency information;
- verified downloads subject to policy;
- generated dependency/download packages;
- feedback submission when SMTP is configured.

Protected/base-game packages can remain available for dependency analysis while being excluded from downloads, generated packages or federation transfers.

## Administration and diagnostics

Current administration includes:

- games and game profiles;
- Upload Files to Game / Upload Bucket;
- local/managed source scans;
- unverified-file review and repair;
- PAK/UPK management;
- Background Jobs and job details;
- job resource/concurrency limits;
- System Operations/readiness;
- System Errors;
- Job Logging;
- Game Backups;
- federation management;
- dependency/source-identity repair tools;
- maintenance/reconciliation jobs.

### Job reporting

Background Jobs is intentionally job-centric. Internal child rows can exist for recoverability without making the headline queue counts jump as workflow children are created/completed.

Routine successful child rows are not the primary operator view; failures, dead letters, cancellations and parent workflow status remain actionable.

### Errors-first logging

Durable job state/progress does not depend on verbose event logging.

Default event logging is errors-first. Terminal job failures are promoted into **System Errors**, where diagnostics can be filtered/exported. Secret-like context values are redacted from diagnostic exports.

## Production deployment

A production installation needs a PHP-capable web server, MySQL, writable catalogue/package storage, and background workers that run independently from browser requests.

The application provides liveness/readiness endpoints and queue/worker diagnostics. Production operation should also monitor database health, disk capacity, web/PHP errors and backup age.

See:

- [`docs/production-deployment.md`](docs/production-deployment.md)
- [`docs/solo-maintainer-production-policy.md`](docs/solo-maintainer-production-policy.md)

## Backup and recovery

Backup/recovery tooling is kept under [`deploy/backup`](deploy/backup).

Recovery planning should cover:

- database backups;
- catalogue/package storage backups;
- integrity verification;
- guarded restore;
- post-restore schema and compact-metadata verification.

A backup should not be treated as a recovery point until verification has passed. Restore drills should be performed against disposable targets before an emergency requires the process.

## Database installation and migrations

`catalog/install.sql` is the consolidated base schema. Newer immutable migrations live under `catalog/migrations/`.

For a fresh/current database:

```text
load catalog/install.sql
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
```

For an existing database, inspect the upgrade first:

```text
php catalog/bin/migrate.php status
php catalog/bin/migrate.php migrate --dry-run
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
```

Applied migration files are byte-immutable because their SHA-256 checksums are stored in `ue_schema_migrations`.

See [`catalog/migrations/README.md`](catalog/migrations/README.md) and [`docs/database-migrations.md`](docs/database-migrations.md).

## Health and operational checks

Machine-readable endpoints include:

- `/catalog/api/v1/live.php` — lightweight PHP/process liveness;
- `/catalog/api/v1/readiness.php` — dependency-aware readiness for MySQL, queue schema and writable package storage;
- `/catalog/api/v1/metrics.php` — protected Prometheus-format application metrics when configured.

Useful deployment/runtime verification commands include:

```text
php catalog/bin/migrate.php verify
php catalog/bin/verify-system-readiness-contract.php --run
php catalog/bin/verify-queue-runtime-invariants.php
php catalog/bin/verify-archive-ingestion.php
php catalog/bin/verify-solo-maintainer-hardening.php --run
```

## Known limitations / active work

The main active areas are:

- validating UE3 package/compression edge cases against source and known-good fixtures;
- continuing UE4/UE5 package/dependency compatibility work;
- diagnosing malformed/non-standard redirect archives without weakening strict parsing;
- further reducing expensive catalogue/maintenance paths where measured performance still warrants it;
- improving operator monitoring and worker/service supervision;
- expanding real-world fixture coverage without committing copyrighted game assets.

## Documentation

Technical material is under [`docs`](docs/). Useful starting points:

- [`docs/architecture.md`](docs/architecture.md)
- [`docs/catalog-architecture.md`](docs/catalog-architecture.md)
- [`docs/background-jobs.md`](docs/background-jobs.md)
- [`docs/database-migrations.md`](docs/database-migrations.md)
- [`docs/pak-archive-management.md`](docs/pak-archive-management.md)
- [`docs/upk-package-management.md`](docs/upk-package-management.md)
- [`docs/production-deployment.md`](docs/production-deployment.md)

## Project principles

UnrealDB favors:

- exact identity over filename assumptions;
- durable/recoverable work over long synchronous requests;
- explicit failure over silent corruption;
- operator-visible state over hidden worker behavior;
- measured optimization over speculative complexity;
- backwards-compatible migrations and incremental refactoring;
- preserving working functionality while improving architecture and maintainability.
