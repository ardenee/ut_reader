# UnrealDB / UT Reader

UnrealDB is a PHP and MySQL/MariaDB application for cataloging Unreal Engine package files. It reads package metadata, stores package tables, resolves dependencies from imports and exports, and helps build complete game-file libraries.

The repository contains two related areas:

- **`catalog/`** is the main UnrealDB catalog application: games, profiles, imports, exports, dependencies, uploads, sources, federation, downloads, jobs, and administration.
- **`UE1/` through `UE5/` and `new/`** contain standalone reader/viewer experiments and parser references. They are useful for local parser inspection and development, but are not the production catalog interface.

> **Project status:** active development. The catalog is the supported application path, but package parsing support and the database schema continue to evolve. Test upgrades on a copy of the database and storage before production deployment.

## What UnrealDB Does

- Catalogs Unreal package files per game.
- Stores package headers, names, imports, exports, hashes, GUIDs, versions, and scan metadata.
- Detects duplicate files within the selected game by MD5.
- Resolves imported package/object references into dependency rows.
- Distinguishes `resolved`, `missing`, `package_only`, and `common` dependency states.
- Uses editable game profiles rather than a hard-coded game/engine list.
- Supports local source scans, HTTP manifest scans, controlled uploads, and optional parent/child federation.
- Separates public download delivery from federation transfers.
- Provides a MySQL-backed maintenance-job foundation and CLI worker for long-running catalog work.

## Main Application Areas

### `catalog/` — UnrealDB Catalog

The catalog application is the main entry point:

```text
/catalog/index.php
/catalog/dashboard.php
```

Key areas include:

| Area | Purpose |
|---|---|
| Games | Public game browser and searchable game libraries. |
| Global Search | Searches files, package names, hashes, GUIDs, imports, and exports. |
| Dashboard | Operational summary for games, files, dependencies, federation, and transfers. |
| Library | Browse and filter cataloged files. |
| Game Manager | Add/edit games and assign a reusable game profile. |
| Game Profiles | Define engine family, extensions, version ranges, and scanner policy. |
| Profiled Upload | Validate, parse, store, and link package files to a selected game. |
| Sources | Register local folders, HTTP mirrors, or redirect-server sources per game. |
| Source Scan | Link known local files by MD5/GUID and optionally import unknown files. |
| HTTP Source Scan | Compare a remote manifest against the catalog, with optional deep GUID inspection. |
| Missing Files | Review unresolved dependencies and repair candidates. |
| Federation | Pair parent/child catalogs, exchange inventory, request files, and run transfers. |
| Downloads | Control public local downloads and external mirror links. |

## Catalog Data Model

The catalog stores the package data needed for dependency-aware library management:

```text
ue_games
  └── ue_game_profiles

ue_files
  ├── ue_names
  ├── ue_imports
  ├── ue_exports
  ├── ue_dependencies
  ├── ue_file_locations
  └── public-download / external-mirror metadata
```

Each imported file records, where available:

- original filename and normalized package name;
- stored path and file size;
- MD5 and SHA1;
- package GUID;
- detected engine, package version, licensee version, and detection confidence;
- names, imports, and exports;
- compression metadata;
- import-derived dependency rows;
- scan notes and failure information.

## Game Profiles and Package Detection

Games select a **game profile**. The profile owns the package-reading rules, so adding a game does not require adding a new hard-coded engine record.

A profile can define:

- engine key, such as `UE1`, `UE2`, `UE3`, `UE4`, or `UE5`;
- allowed file extensions;
- package and licensee version ranges;
- compatibility/detection policy;
- profile notes.

The scanner validates the selected profile, reads package metadata, and may classify a file as native, legacy-compatible, mismatched, or uncertain. Exact same-engine game routing is not always possible from package headers alone, so the administrator selects the target game before import.

Typical target extensions include:

| Engine family | Common package extensions |
|---|---|
| UE1 | `.u`, `.unr`, `.utx`, `.umx`, `.uax` |
| UE2 / UE2.5 | `.u`, `.un2`, `.ut2`, `.utx`, `.usx`, `.ukx`, `.uax`, `.umx` |
| UE3 | `.u`, `.ut3`, `.upk` |
| UE4 | `.uasset`, `.umap` |

Reader support remains package- and version-dependent. Successfully opening a file does not mean every export payload or property type is decoded.

## Upload and Import Flow

The profiled upload scanner is the recommended import route.

```text
Upload package
  → validate file size and profile extension
  → classify engine/profile compatibility
  → hash file and check same-game duplicate MD5
  → parse header, names, imports, exports
  → store verified package and metadata
  → build dependencies for the imported file
  → refresh only existing files potentially affected by that package
```

This targeted refresh avoids rebuilding every dependency row for the game after every package upload.

Files that fail validation or parsing can be retained under unverified storage for review. A failed import rolls back database rows and removes the partially stored verified package.

## Dependency Resolution

UnrealDB resolves dependencies from package imports rather than relying only on filenames.

For each import, the catalog derives:

```text
required package
required object path
resolved catalog file, where available
resolved export, where available
resolution status
```

Dependency states:

| Status | Meaning |
|---|---|
| `resolved` | A matching catalog package/export satisfies the import. |
| `missing` | No suitable catalog package was found. |
| `package_only` | A package match exists, but the required exported object was not confirmed. |
| `common` | The reference is configured as a common engine package and is not treated as a normal missing dependency. |

The resolver uses batched database lookups so an import-heavy package does not produce one database query per import.

## Search and File Browsing

Global search supports:

- exact MD5 and SHA1 lookups;
- package GUID lookup;
- package and original filename matching;
- import and export object matching;
- result limiting and stage-level failure handling.

Game file lists provide filters for dependency status, type, compression, and text search. They use a paginated query path designed to avoid loading full file rows before page selection for normal sorts.

## Sources and Library Reconciliation

A source belongs to a game and can represent:

- a local server/game folder;
- an HTTP mirror;
- a redirect-server source.

Local scans can:

1. hash files and match known catalog files by MD5;
2. inspect package GUIDs for secondary matching;
3. record source locations for matched files;
4. optionally copy unknown files into the profiled import flow.

HTTP scans can compare manifests against catalog records and can optionally download unknown files temporarily to inspect package GUIDs.

## Federation

Federation connects multiple UnrealDB deployments in a parent/child model.

Current federation capabilities include:

- site identity and fingerprint records;
- join-request workflow;
- parent/child peer management;
- signed peer API requests using timestamp, nonce, body hash, and HMAC signature;
- inventory exchange;
- missing dependency request generation;
- approval, denial, and cancellation workflows;
- controlled upload/download/import transfer jobs;
- configurable speed limits, delays, and transfer-file limits;
- transfer logs, queue, and maintenance pages.

Federation transfers and public downloads are separate paths. Parent/child transfers should be run through controlled worker operations rather than exposed as unrestricted public downloads.

## Public Downloads and External Mirrors

Public downloads can be configured independently of federation.

| Mode | Behaviour |
|---|---|
| `local_direct` | Serve the file from local catalog storage. |
| `external_mirror` | Return only an active external/shared-provider link. |
| `external_mirror_preferred` | Use an active external link when available; otherwise allow local download and queue mirror work. |
| `disabled` | Disable public download delivery. |

External mirror links are currently administered through a manual provider workflow. The application can queue mirror work and reuse active links, but provider-specific upload automation is not yet a core feature.

## Background Jobs and Worker

The catalog includes a durable MySQL-backed job queue for maintenance work.

Current job types include:

```text
catalog.rebuild_game_dependencies
catalog.rebuild_affected_dependencies
catalog.prune_upload_progress
```

Run a worker only through CLI:

```bash
php catalog/bin/catalog-worker.php --max-jobs=25 --sleep-ms=250
```

The worker is deliberately blocked from HTTP execution. On shared hosting, use cron or the host scheduler to invoke it. Start with one worker for long dependency rebuilds until lease renewal is connected to scanner progress callbacks.

## HTTP API Foundation

The catalog includes a small versioned API foundation:

```text
/catalog/api/v1/health.php
/catalog/api/v1/job-status.php
```

- `health.php` checks database reachability and returns a JSON status response.
- `job-status.php` requires an authenticated administrator session and returns recent background-job status.

Federation endpoints live separately under:

```text
/catalog/api/federation/
```

## UI System

The catalog remains server-rendered PHP. It does not require a JavaScript framework.

Reusable UI primitives live in:

```text
catalog/src/Presentation/Ui/CatalogUi.php
catalog/assets/catalog-ui.css
catalog/assets/catalog-ui.js
```

The component layer provides reusable page headers, buttons, alerts, badges, loading states, empty states, sections, responsive tables, focus styles, and reduced-motion support. JavaScript only progressively enhances dismissible notices and opted-in form submission states.

## Installation

### Requirements

- PHP 8.2 or newer.
- MySQL or MariaDB with InnoDB support.
- PHP extension: `pdo_mysql`.
- A PHP-capable web server, such as Apache, nginx with PHP-FPM, Synology Web Station, or a local PHP server.
- Writable catalog storage for the PHP/web-server account.
- CLI PHP access for the worker is recommended.
- Optional LZO support for compressed UE3 packages where required.

### Setup

1. Clone the repository to the web server.
2. Copy `catalog/config.example.php` to `catalog/config.php`.
3. Set database credentials, storage location, package limits, and reader configuration.
4. Import `catalog/install.sql` into an empty database.
5. Apply every `catalog/install_update_*.sql` migration in numeric order that is newer than the schema currently installed.
6. For a fresh installation, that includes the current migrations through:

   ```text
   catalog/install_update_018_dependency_resolution_indexes.sql
   catalog/install_update_019_global_hash_lookup_indexes.sql
   catalog/install_update_020_file_list_dependency_index.sql
   catalog/install_update_021_background_jobs.sql
   ```

7. Ensure the configured storage location is writable by PHP.
8. Open `/catalog/index.php`.
9. Create the initial administrator only from a trusted private setup session, before exposing the site publicly.
10. Add games and profiles, then import a small known package set before bulk ingestion.

Example development storage setup on Synology/Linux:

```bash
cd /volume1/web/ut_reader
mkdir -p catalog/storage
chown -R http:http catalog/storage
chmod -R 775 catalog/storage
```

Adjust the web-server account for the host environment.

### Production deployment notes

The default example configuration keeps storage below `catalog/` for convenience. For a public deployment, place both runtime configuration and storage outside the web root:

```text
/private/unrealdb/config.php
/private/unrealdb/storage/
```

Then expose only the application code through the web server. Do not commit `catalog/config.php`, package storage, upload folders, logs, or local native libraries.

The standalone viewer directories are intended for local development and parser debugging. Do not expose their upload scripts publicly on a production host.

## Standalone Reader/Viewer Notes

The repository still includes UE1–UE5 reader/viewer code for parser inspection. These tools can display package summaries, names, imports, exports, GUIDs, flags, selected properties, and raw header data depending on engine support.

They are not a replacement for the catalog import workflow. Keep them restricted to local development or an authenticated internal environment.

## UE3 Compression and LZO

Some UE3 packages use compression. LZO-compressed packages require an available LZO implementation.

The project can use native LZO through PHP FFI where the environment permits it, with fallback handling where supported. Native LZO is preferred for performance and compatibility.

Do not commit local native libraries to Git:

```text
liblzo2.so
liblzo2.so.*
liblzo2-2.dll
lzo2.dll
```

## Current Limitations

- This is not a stable release; schema migrations and parser improvements continue.
- Not every export payload or property type is decoded.
- UE4 unversioned package parsing can require an assumed version.
- `.uexp` pairing/support remains limited in some paths.
- Exact same-engine game identification cannot always be proven from package headers alone.
- Federation transfers require controlled operational testing before use with large libraries.
- The initial background-job worker does not yet renew leases during long scanner operations; run one worker for those jobs.
- External mirror provider upload automation is not implemented as a general built-in feature.
- HTTP source scanning should only target trusted game-owned sources.

## Architecture and Operational Documentation

Additional documentation:

- [`docs/catalog-architecture.md`](docs/catalog-architecture.md) — current catalog architecture and ownership.
- [`docs/catalog-clean-architecture.md`](docs/catalog-clean-architecture.md) — namespace and compatibility-shim approach.
- [`docs/catalog-performance.md`](docs/catalog-performance.md) — query and dependency-refresh performance work.
- [`docs/catalog-system-architecture.md`](docs/catalog-system-architecture.md) — job, API, cache, and operational architecture.
- [`docs/catalog-ui-system.md`](docs/catalog-ui-system.md) — reusable server-rendered UI system.

## Development Guidelines

When adding reader/parser support:

1. Preserve known working readers unless there is a tested reason to change them.
2. Add scanner behavior through game profiles and reader resolution rather than hard-coding a game list.
3. Use real package samples and record version/compatibility observations.
4. Keep profile mismatches in unverified/review storage rather than force-importing them.
5. Add regression fixtures before moving package-reading logic.

When adding catalog behavior:

1. Keep page controllers focused on request/response handling.
2. Put reusable application behavior in `catalog/src/`.
3. Keep legacy `catalog/lib/` compatibility files thin where practical.
4. Use prepared SQL and allow-list dynamic sorting/identifiers.
5. Prefer targeted dependency refresh over full-game rebuilds after a normal import.
6. Keep long-running maintenance work in jobs/workers, not public HTTP requests.

## License

No license has been specified yet. Add a license before publishing a stable release or accepting external contributions.
