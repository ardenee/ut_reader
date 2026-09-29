# UnrealDB

UnrealDB is a catalogue, dependency-analysis and preservation system for Unreal Engine game files.

It is designed to identify packages accurately, preserve physical and logical package identity, inspect Unreal package metadata, track dependencies, find missing requirements, reduce duplicate storage, repair catalogue state, and distribute verified files through controlled downloads and generated packages.

> **Project status — 29 September 2026:** UnrealDB is an active working catalogue/admin system. Production currently uses the UEDB4 compact-metadata runtime. Source-backed package/dependency specifications now cover the supported UE1-UE5 classic families, the isolated UEDB5 staging foundation and UE5 5.8.3 classic reader/persistence/VerifyImport resolver are implemented, and the main remaining format work is UEDB5 production migration/cutover plus UE5 Zen/IoStore (`.utoc`/`.ucas`).

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

## Installation and system prerequisites

The supported production foundation is **Windows + Apache 2.4 + PHP 8.5 + MySQL 8.4** on one host, with local durable package storage and independent PHP CLI workers.

A normal installation should enable PHP `pdo_mysql`, `mbstring`, `curl`, `openssl`, `sodium`, `zip`, `zlib` and `fileinfo`. 7z/libarchive ingestion uses PHP `ext-archive`; RAR compatibility can additionally use PECL `rar`. The current archive implementation is PHP-extension based and does not require command-line 7-Zip/UnRAR.

Apache must allow the repository `.htaccess` rules and enable at least `mod_rewrite` and `mod_headers`; production HTTPS/federation also requires `mod_ssl`.

There is no Composer, Node.js/npm, Docker, Redis, message-broker or frontend Foundation/Bootstrap build step.

Fresh-install outline:

```powershell
git clone https://github.com/ardenee/ut_reader.git
cd ut_reader
Copy-Item .\catalog\config.example.php .\catalog\config.php

# Create/import a new MySQL database, then:
php catalog/bin/migrate.php status
php catalog/bin/migrate.php migrate --dry-run
php catalog/bin/migrate.php migrate
php catalog/bin/migrate.php verify
php catalog/bin/create-admin.php --username=admin
php catalog/bin/verify-system-readiness-contract.php --run
```

Use a database-scoped MySQL application account; do not grant it global privileges such as `SUPER` or `BINLOG_ADMIN`. Keep deployment-specific storage/PHP paths in `catalog/config.php` or environment configuration rather than hard-coding them in source.

The complete clean-install, Apache/PHP/MySQL, storage, worker, GeoIP and optional federation instructions are in **[docs/installation.md](docs/installation.md)**.

## Project status by area

| Area | Status | Notes |
| --- | --- | --- |
| Public catalogue/search | Active | Browse games/files, exact identity search, broader catalogue search, dependency information and controlled downloads. |
| Administration | Active | Game/profile management, uploads, unverified files, jobs, backups, federation, maintenance and diagnostics. |
| Durable background jobs | Active | Long work is split into resumable parent/child workflows with persisted progress, bounded concurrency and explicit operator control. |
| Production metadata | UEDB4 active | Verified package metadata is stored in blocked-compressed `.uedb4` containers with compact SQL search/dependency projections. |
| UEDB5 | Staging / migration work | Format-5 container isolation, UE5 5.8.3 classic persistence and its source-parity staging resolver are implemented; production cutover has not happened. |
| UE1 / UT99 | Active / source-aligned | Audited against the authoritative UT99 source and implemented to follow its package serialization, compact-index, provider-selection and object-level dependency rules. |
| Unreal II / UE2 | Active / source-aligned | Audited against the authoritative Unreal II / UE2 source, including its source-specific private-export VerifyImport behavior and other revision differences. |
| UE2.5 / UT2003 / UT2004 | Active / source-aligned | Audited against the authoritative UE2.5, UT2003 and UT2004 sources; package/dependency behavior and game-specific revision rules are implemented to those source contracts. |
| UE3 / UT3 | Active / source-aligned | Audited against the authoritative UE3/UT3 sources. UT3 v512 dependency matching follows its VerifyImport rules, including exact outer/class identity and the 64-bit UE3 `RF_Public` flag. Historical lossy metadata requires source-byte repair/reparse. |
| UE4 4.27.2 | Active / source-aligned | Audited against the authoritative UE4 4.27.2 source. Classic package parsing/dependency behavior follows that source contract; UEDB5 migration must preserve serialized import `PackageName` rather than reconstructing it from paths. |
| UE5 5.8.3 classic | Staging / source-aligned | Audited against the authoritative UE5 5.8.3 source. The dedicated reader, source-shaped UEDB5 persistence and deterministic file-backed `VerifyImportInner` resolver follow that source contract but are not yet wired into production runtime. |
| UE5 Zen / IoStore | Pending | Authoritative `.utoc`/`.ucas`, PackageId/public-export-hash and `FPackageObjectIndex` support remain the largest UE5 format gap. |
| `.uz` / `.uz2` / `.uz3` | Active / source-aligned | UZ, UZ2 and UZ3 compression/decompression formats have been audited against the relevant source/game behavior and are implemented to those format contracts. |
| UMOD / UT2MOD / UT4MOD | Source-aligned | The mod-container formats have been audited and documented against their relevant engine/game sources; they are not an outstanding format-validation item. |
| ZIP / 7z / RAR uploads | Active | Unpack-only ingestion extracts supported Unreal files and hands each file to the normal durable package/redirect/PAK workflow. |
| Federation | Active | Parent/child inventory, dependency requests and controlled transfer workflows are supported. |
| Game Backups | Active | Durable export/restore workflows plus separate production database/storage backup tooling. |

## Source authority and format policy

UnrealDB treats the relevant Epic/game source revision as the authority for package serialization and dependency behavior. The source-backed specification library is under [`specs`](specs/) with its coverage index in [`specs/README.md`](specs/README.md).

Important rules are:

- engine/game revisions are documented separately when their behavior differs;
- serialized source identity is authoritative; normalized paths, hashes and SQL projections are accelerators;
- one physical provider package must satisfy source-defined package/import rules - UnrealDB does not combine exports from several same-name files into a synthetic provider;
- runtime/config-only behavior that cannot be reconstructed from package/container bytes is marked as unavailable/runtime-only rather than guessed;
- migration never fabricates serialized fields that an older metadata format discarded; authoritative package bytes are reparsed when required.

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

Production verified metadata currently uses **UEDB4**.

- `ue_files` is the stable physical/catalogue identity row.
- `ue_file_metadata` registers the authoritative compact metadata container.
- package metadata is stored in blocked-compressed `.uedb4` containers;
- SQL lookup/projection tables provide indexed package, object, search and dependency access without restoring the old row-per-object metadata schema;
- legacy `ue_names`, `ue_imports`, `ue_exports` and `ue_dependencies` are no longer the verified runtime metadata model;
- source-shaped fields that affect Epic load/VerifyImport behavior are retained in compact metadata whenever the format can represent them, while SQL is treated as an accelerator rather than the authority;
- compact publication is atomic and retryable database contention cannot leave a partially published package.

**UEDB5** is the next metadata format and is deliberately isolated from production UEDB4. The format-5 container/staging reader, UE5 5.8.3 classic persistence and classic `VerifyImportInner` staging resolver are implemented, but the production runtime still reads UEDB4 only. UEDB5 cutover requires source-byte reparse wherever UEDB4 did not retain required serialized identity, including historical UE3 64-bit `ObjectFlags` and UE4/UE5 import `PackageName` cases.

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

ZIP prefers PHP `ZipArchive`. 7z and general libarchive-backed decoding use PHP `ext-archive` (cataphract/libarchive), with PECL `rar` available for RAR compatibility/solid-RAR fallback. UnrealDB does not launch command-line archive tools.

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

1. reparse/reimport or repair verified files when source-owned metadata needs refresh;
2. rebuild provider/projection state;
3. rebuild dependencies in bounded durable batches;
4. publish final dependency summaries and game statistics.

Completed child work is retained, so restart resumes incomplete phases/children instead of returning to file 1. Children cancelled by stopping the parent can be resumed without replaying successful units. Source-specific maintenance paths avoid rewriting unrelated SQL projections; for example, the UT3 source pass can rewrite/register corrected UEDB4 metadata without updating every export row individually.

## Package/container support

### Classic Unreal packages: UE1 through UE5

Classic package readers preserve the engine/source-specific package summary, Names, Imports, Exports, package-index outer graph and dependency-relevant identity required by the audited revision. The project intentionally does not flatten all engine generations into one generic package rule.

UE3 `.upk` files remain packages; their internal exports can be examined without pretending that each export is an independent package file. UT3 package-version-512 dependency matching uses the January-2008 source policy, including its 64-bit `RF_Public` value and source-specific outer/class rules.

UE4 4.27.2 classic packages and UE5 5.8.3 classic packages have separate audited readers/policies. UE5 classic support is currently staged through UEDB5 rather than published into the production metadata runtime.

### PAK files

Supported unencrypted PAK files are retained as original archives and indexed/extracted when their version and compression layout are supported.

PAK import uses a durable parent workspace and independently restartable entry jobs. An unsupported or damaged entry is recorded as an entry outcome rather than preventing unrelated entries from being processed.

Encrypted PAK content and unsupported compression/container variants are not silently accepted.

### UE5 Zen / IoStore

Authoritative cooked UE5 Zen/IoStore support is not complete. Full support requires `.utoc`/`.ucas` ingestion plus package-store provenance, `FPackageId`, imported package IDs, public-export hashes, typed `FPackageObjectIndex` identity, export/dependency bundles, optional segments, redirects and other source-defined container context. These values require lossless unsigned-64 handling and are being designed for UEDB5 rather than forced into the classic package model.

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

- completing the UEDB4 -> UEDB5 migration/publication path and clean production cutover;
- reparsing authoritative package bytes where older UEDB4 metadata discarded dependency-relevant serialized identity instead of guessing the missing values;
- implementing UE5 Zen/IoStore `.utoc`/`.ucas` package-store ingestion and its PackageId/public-export-hash/object-index dependency model;
- completing UEDB5 dependency classifications for hard, optional, soft, build/cook, script, cell/Verse, load-order and runtime-derived references;
- reducing measured database/publication hotspots while keeping detailed source semantics in compact files rather than expanding MySQL back toward row-per-object metadata;
- improving worker-pool supervision so code-version recycling and recovery do not require operator intervention;
- expanding real-world fixture coverage without committing copyrighted game assets.

The source audits for UZ, UZ2, UZ3, UMOD, UT2MOD, UT4MOD, UPK and PAK format/compression/encryption behavior are complete and are not listed as ongoing validation work. Unsupported runtime/container features should be described as implementation limits, not as unaudited format behavior.

Some Epic behaviors depend on live engine/configuration state rather than serialized package/container bytes. UnrealDB records those boundaries explicitly instead of fabricating results; examples include configuration-driven remaps and selected UE5 runtime-created/dynamic import paths.

## Documentation

Technical material is under [`docs`](docs/), while source-backed Unreal format/dependency specifications are under [`specs`](specs/). Useful starting points:

- [`specs/README.md`](specs/README.md) - official-source format/dependency coverage and source-of-truth policy
- [`specs/uedb5-metadata-requirements.md`](specs/uedb5-metadata-requirements.md) - current UEDB5/UE5 migration requirements and implementation status
- [`docs/installation.md`](docs/installation.md)
- [`docs/architecture.md`](docs/architecture.md)
- [`docs/catalog-architecture.md`](docs/catalog-architecture.md)
- [`docs/background-jobs.md`](docs/background-jobs.md)
- [`docs/database-migrations.md`](docs/database-migrations.md)
- [`docs/pak-archive-management.md`](docs/pak-archive-management.md)
- [`docs/upk-package-management.md`](docs/upk-package-management.md)
- [`docs/production-deployment.md`](docs/production-deployment.md)

## Project principles

UnrealDB favors:

- official engine/game source behavior over invented compatibility rules;
- exact serialized identity over filename/path assumptions;
- durable/recoverable work over long synchronous requests;
- explicit failure over silent corruption;
- operator-visible state over hidden worker behavior;
- measured optimization over speculative complexity;
- audited migrations and clean format cutovers instead of silent compatibility guesses;
- preserving working functionality while improving architecture and maintainability.
