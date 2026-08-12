# UnrealDB

UnrealDB is a catalogue, dependency-analysis and preservation system for Unreal Engine game files.

Its purpose is to identify packages correctly, preserve package identity, show how files relate to each other, find and repair missing dependencies, reduce duplicate storage, and make verified files easier to manage and distribute responsibly.

> **Development status:** UnrealDB is under active development. The public catalogue can be browsed while administrative import, maintenance, federation and package-generation features continue to evolve.

## Current architecture at a glance

UnrealDB now treats large operations as durable background workflows rather than long browser requests or single PHP loops.

- Long work is split into independently restartable child jobs wherever there is a natural file, archive-entry or maintenance-unit boundary.
- A workflow restart keeps successful units and repeats only failed or incomplete units.
- Sequential work that cannot use independent children, such as source discovery/copy streams, persists an exact durable cursor or journal.
- Parent/coordinator jobs release their worker slot while waiting for children.
- Routine successful child rows are hidden from the default Background Jobs view; failed/dead-letter/cancelled children remain visible for operator action.
- Durable progress/checkpoint state is separate from optional event logging.
- Background-job event logging defaults to actionable errors; progress, successful, duplicate, skipped and cancelled event streams can be enabled independently.

The browser is not part of the recovery contract. For file uploads, recovery begins only **after a complete file exists in controlled server storage**. An interrupted browser/network transfer is not represented as a resumable background job. Once a complete file has been staged, the remaining preparation/import/dependency work is recoverable without asking the browser to resend it.

## Public site functions

### Browse games and files

Users can browse the files recorded for each supported game, including known package names, original filenames, versions, sizes, GUIDs and content hashes.

### Search the catalogue

Search can use package/file names, GUIDs, hashes and projected package metadata. This helps identify unknown files, locate another copy of a package or find the package that provides a required object.

### View package information

A verified file can expose its package header, versions, GUID/hashes, Names, Imports, Exports and dependency relationships.

### Find missing dependencies

UnrealDB records dependency links at package/object level and reports missing requirements per game. Missing dependencies can be cross-examined against verified packages in other compatible games so administrators can identify exact object-path providers and copy a verified source package into the destination game without moving/removing the source.

The Cross-Game Dependency page excludes package bytes that are already verified in the report target before candidate totals and coverage calculations are produced.

### Download verified files

Verified files can be exposed through controlled local downloads or external mirrors. Public controls exist for per-IP limits, crawler/burst protection and generated-package limits.

### Generate dependency packages

Where enabled, UnrealDB can build ZIP and game-specific package outputs such as UMOD-family installers and Unreal Tournament 4 package outputs. Generation happens in a background job and writes to a temporary artifact before publishing the validated result.

### Send feedback

When enabled, the Feedback page can send site reports and suggestions through the configured SMTP service.

## Catalogue and administration functions

### Compact metadata model

Verified package metadata uses the current **format-2 compact metadata** architecture.

- `ue_file_metadata` records the authoritative per-file compact container metadata.
- Names/Imports/Exports are stored in the format-2 `.uedb2` container and projected into compact lookup tables only where indexed access is required.
- `ue_export_lookup`, `ue_dependency_links`, `ue_terms`, package-provider projections and dependency summaries support fast catalogue/dependency queries.
- Historical format-1 `.uedb.json.gz` runtime readers/converters have been retired.
- Legacy row-per-object `ue_names`, `ue_imports`, `ue_exports` and `ue_dependencies` tables are no longer part of the verified runtime schema.

Compact metadata publication is atomic and retries retryable MySQL lock/deadlock failures as a whole operation. Interrupted verified imports can repair missing format-2 metadata in place on retry.

### File uploads and source scanning

Administrators can add content through:

- Upload Files to Game
- Upload Bucket
- Local Source Scan
- HTTP/managed source workflows
- PAK Import
- federation transfer
- Game Backup restore

#### Upload Files to Game

The browser hashes ordinary files for advisory duplicate preflight and uploads one file at a time. A successfully received file is moved into controlled server staging before its import job is created. Chunk transport is used for large files, but the recoverable background boundary starts only when the server has the complete file.

After that boundary, redirect preparation, package scanning, compact metadata publication and dependency follow-up are background work. Durable prepared redirect output can be reused after an infrastructure retry.

#### Upload Bucket

Upload Bucket is intended for large unsorted collections. Browser-side inspection/preflight avoids unnecessary transfers where possible, and only one file is active at a time.

A completed uploaded package/wrapper is handed to background processing. Package copy/hash work and redirect decompression use durable per-job preparation so a later database/storage failure can resume from the completed preparation phase rather than repeat the browser transfer or completed decompression.

Only failed validation, transfer and finalisation results need to be retained as Upload Issues; routine live status remains UI telemetry.

### Redirect archive support

Unreal redirect wrappers can be decompressed and catalogued as their real package payload:

- `.uz` supports the historical 1234 and 5678 FCodec variants.
- `.uz2` uses chunked zlib records.
- `.uz3` handling remains version/format dependent and should be validated against known-good UT3 material before relying on it for production archives.

Package identity is calculated from the decompressed package, not merely from the redirect wrapper bytes.

### Unverified files

Packages that cannot yet be assigned confidently to a game are retained in controlled unverified storage with compressed staging metadata.

Exact game-match evidence is generated in background jobs and cached in `ue_unverified_game_match_cache`, allowing the Unverified page to render without recomputing dependency/object-path matches on every request.

A package may be imported into all exact compatible games when current dependency evidence proves it supplies required object paths. Package-name-only evidence is not sufficient for automatic multi-game copying.

### Duplicate detection and aliases

Physical duplicate decisions are based on file size and content hashes, not filename similarity. Byte-identical packages can retain alternate logical package names through aliases while sharing canonical physical content where appropriate.

Upload Bucket identity checks serialize only identical size/MD5/SHA-1 identities to prevent concurrent workers from publishing the same physical package twice.

Unverified duplicate cleanup is itself a recoverable workflow: same-size candidates are hashed independently and exact duplicate deletion is revalidated and performed as one durable unit per file.

### Dependency rebuilding

Dependency work supports several scopes:

- one verified file;
- targeted files affected by a newly available provider;
- a complete game;
- Full Sync / source-identity repair workflows;
- cross-game dependency fulfilment.

Affected dependency work is split into one recoverable unit per affected file. Each child performs a targeted rebuild only for the newly available package; the parent bulk-refreshes dependency summaries/game counters after all children complete.

### Full Sync

Full Sync is a durable multi-phase workflow rather than one long PHP loop:

1. independently reimport/repair verified files;
2. rebuild provider/projection state;
3. independently rebuild dependency files;
4. publish final dependency summaries and cached game statistics.

Completed units are retained. If a Full Sync fails during finalisation, Restart resumes finalisation rather than rescanning the game from file 1. A failed child unit can be restarted without replaying successful siblings.

### Projection reconciliation

Provider/projection reconciliation also uses per-file dependency-owner children. One bad owner file does not force all previously reconciled files to be processed again.

### UE3 UPK management

UE3 `.upk` packages are retained as packages and their internal exports can be examined without pretending individual exports are standalone package files.

### UE4 and UE5 PAK management

Supported unencrypted PAK files are retained as original archives, indexed and extracted when their layout/compression is supported.

PAK import uses a durable per-parent workspace. Extraction/index selection is completed once, then each PAK entry is processed as an independently restartable child job. After entry processing, the parent invokes the normal resumable game-dependency workflow and finalises the retained PAK record.

Encrypted entries, unsupported compression methods and unsupported package payloads are recorded as entry outcomes rather than blocking unrelated entries.

### Base-game protection

Official base-game package identities are stored in `ue_base_game_files`. Missing base-game dependency counts are a subset of all missing dependencies: a missing requirement is classified as base-game when its **required package** matches the configured base-game package inventory for that game.

Protected/base-game files can be excluded from public downloads, generated packages and federation transfers while remaining available for dependency analysis.

### Game Backups

Game Backups create independent file copies plus a manifest describing package identity and intended paths.

Backup restore is per-manifest-entry and resumable, preserving canonical-before-alias ordering before invoking the normal game dependency workflow.

Backup export uses a durable immutable export plan/completion journal. A restart verifies/adopts already copied files and continues instead of deleting an incomplete backup and starting over.

### Federation

Parent/child UnrealDB installations can exchange inventories, dependency requests and approved files. Federation policy can exclude base-game packages while still exposing enough identity/dependency evidence to determine what is missing.

## Background jobs and recovery

Background jobs are durable database-backed work units executed by detached PHP workers.

The important distinction is:

- **workflow/coordinator job** — plans bounded work and waits;
- **child/unit job** — one independently retryable file/archive-entry/maintenance operation;
- **exact-cursor/journal job** — sequential work whose completed position is persisted durably;
- **atomic artifact job** — one output artifact written/validated/published as one unit.

Restart/recovery preserves `progress_json`; it does not reset the operation to zero. Parent-child identity is stored with `parent_job_id` and `workflow_unit_key`, making child creation idempotent when a coordinator itself is replayed.

Current durable workflows include Full Sync, whole-game dependency rebuild, affected dependency refresh, projection reconciliation, source-identity repair, unverified exact-match refresh, cross-game copy preparation, PAK entry import, Game Backup restore, unverified duplicate cleanup, unverified-storage reconciliation and stale-artifact cleanup.

Source scanning uses a deterministic exact file cursor. Backup export uses an immutable plan/journal. Generated download packages remain one atomic artifact unit.

Concurrency is controlled by administrator-configurable resource classes rather than only a global worker count. The Job Resource Limits page shows current limits/pressure, and applying a setting updates compatible queued jobs without collapsing per-file child concurrency keys.

See [`docs/background-jobs.md`](docs/background-jobs.md) for operational details.

## Errors-first logging and diagnostics

Durable job progress/results are always stored independently from optional event logging.

The **Job Logging** admin page controls event streams. Defaults are intentionally errors-first:

- errors: enabled;
- progress: disabled;
- success/completed: disabled;
- duplicate: disabled;
- skipped: disabled;
- cancelled: disabled;
- worker diagnostics: disabled.

Terminal background-job failures are promoted into **System Errors** so actionable problems have one operator inbox. System Errors can be filtered and exported as a diagnostic Markdown report including available exception/source/trace/context information; secret-like context values are redacted.

Upload Issues is reserved for persistent browser/upload validation/transfer/finalisation problems.

## Supported Unreal Engine generations

UnrealDB contains workflows for Unreal Engine 1, 2, 2.5, 3, 4 and 5.

Support varies by generation and package/container format. UE1–UE3 package parsing is generally more complete. UE4/UE5 package/container handling remains dependent on package version, available metadata and supported PAK layouts/compression. Encrypted PAK files and UE5 IoStore `.utoc`/`.ucas` containers are not fully supported.

## Why file identity matters

Unreal packages are frequently renamed, copied between servers or distributed with duplicate suffixes. Two files with the same filename may be different; two differently named files may contain identical bytes.

UnrealDB therefore uses hashes, package GUIDs, package structure and exact dependency/object-path evidence instead of trusting filenames alone. Exact cross-game dependency coverage proves that a candidate exports required object paths; it is dependency-fulfilment evidence, not proof that a file is the canonical retail package for another game.

## Database migrations

`catalog/install.sql` is the consolidated base schema. Immutable migrations newer than that baseline live under `catalog/migrations/` and are applied with:

```bash
php catalog/bin/migrate.php migrate
```

The current workflow recovery/logging design requires migration `202608120001`, and the unverified exact-game-match cache requires `202608110001`. Applied migration files are byte-immutable because their checksums are recorded in `ue_schema_migrations`.

## Documentation

Technical installation, migration and administration material is in [`docs`](docs/).

Useful references include:

- [`docs/background-jobs.md`](docs/background-jobs.md)
- [`docs/pak-archive-management.md`](docs/pak-archive-management.md)
- [`docs/upk-package-management.md`](docs/upk-package-management.md)
- [`docs/database-migrations.md`](docs/database-migrations.md)
- [`docs/production-deployment.md`](docs/production-deployment.md)
