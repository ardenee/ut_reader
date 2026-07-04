# UnrealDB / UT Reader

UnrealDB is a PHP and MySQL/MariaDB application for cataloging Unreal Engine package files. It reads package metadata, stores package tables, resolves dependencies from imports and exports, and helps build complete game-file libraries.

The repository contains two related areas:

- **`catalog/`** is the main UnrealDB application: games, profiles, imports, exports, dependencies, uploads, sources, federation, downloads, jobs, and administration.
- **`UE1/` through `UE5/` and `new/`** contain standalone reader/viewer experiments and parser references. They are useful for local parser inspection and development, but are not the production catalog interface.

> **Project status:** active development. The catalog is the supported application path, but package parsing support and the schema continue to evolve. Test upgrades on a copy of the database and storage before production deployment.

## What UnrealDB Does

- Catalogs Unreal package files per game.
- Stores package headers, names, imports, exports, hashes, GUIDs, versions, compression state, and scan metadata.
- Detects same-game duplicates by MD5 while allowing the same package hash to exist in separate game libraries.
- Resolves imported package/object references into dependency rows.
- Distinguishes `resolved`, `missing`, `package_only`, and `common` dependency states.
- Uses editable, reusable game profiles instead of a hard-coded game/engine list.
- Supports local source scans, bounded trusted HTTP manifest scans, controlled uploads, and optional parent/child federation.
- Separates public download delivery from federation transfers.
- Provides a MySQL-backed maintenance-job queue, lease renewal during scanner progress, and a CLI-only worker.
- Provides bounded-memory federation upload/download paths with authenticated transfer metadata and upload SHA-256 verification.
- Includes GitHub Actions checks for PHP syntax and a clean canonical-schema import.

## Main Application Areas

### `catalog/` — UnrealDB Catalog

The catalog application is the main entry point:

```text
/catalog/index.php
/catalog/dashboard.php
```

| Area | Purpose |
|---|---|
| Games | Public game browser and searchable game libraries. |
| Global Search | Searches files, package names, hashes, GUIDs, imports, and exports. |
| Dashboard | Operational summary for games, files, dependencies, federation, and transfers. |
| Library | Browse and filter cataloged files. |
| Game Manager | Add/edit games and assign a reusable game profile. |
| Game Profiles | Define engine family, extensions, version ranges, compatibility, and scanner policy. |
| Profiled Upload | Validate, parse, store, and link package files to a selected game. |
| Sources | Register local folders, trusted HTTPS mirrors, or redirect-server sources per game. |
| Source Scan | Link known local files by MD5/GUID and optionally import unknown files. |
| HTTP Source Scan | Compare a trusted HTTPS manifest against the catalog, with optional bounded deep GUID inspection. |
| Missing Files | Review unresolved dependencies and repair candidates. |
| Federation | Pair parent/child catalogs, exchange inventory, request files, and run controlled transfers. |
| Downloads | Control public local downloads and external mirror links. |

## Catalog Data Model

```text
ue_games
  └── profile_id → ue_game_profiles

ue_files
  ├── ue_names
  ├── ue_imports
  ├── ue_exports
  ├── ue_dependencies
  ├── ue_file_locations
  └── public-download / external-mirror metadata

ue_background_jobs
ue_app_logs
ue_federation_*
```

Each imported file records, where available:

- original filename and normalized package name;
- stored path and file size;
- MD5 and SHA1;
- package GUID;
- detected engine, package version, licensee version, confidence, and compatibility state;
- names, imports, and exports;
- compression metadata;
- import-derived dependency rows;
- scan notes and failure information.

## Game Profiles and Package Detection

Games select a **game profile** through `ue_games.profile_id`. A profile owns the package-reading rules, so adding a game does not require adding a new hard-coded engine record. Profiles are reusable where games share the same package rules.

A profile can define:

- engine key, such as `UE1`, `UE2`, `UE3`, `UE4`, or `UE5`;
- allowed file extensions;
- package and licensee version ranges;
- compatibility rules for proven legacy package formats;
- scanner confidence policy and notes.

The scanner validates the selected profile, reads package metadata, and may classify a file as native, legacy-compatible, mismatched, or uncertain. Exact same-engine game routing is not always possible from package headers alone, so an administrator selects the target game before import.

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

Targeted dependency refresh avoids rebuilding every dependency row for the game after every normal import.

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
- bounded result sets and stage-level failure handling.

Game file lists provide filters for dependency status, type, compression, and text search. They use a paginated query path designed to avoid loading full file rows before page selection for normal sorts.

## Sources and Library Reconciliation

A source belongs to a game and can represent:

- a local server/game folder;
- an HTTPS mirror;
- a redirect-server source.

Local scans can hash files, match catalog files by MD5/GUID, record source locations, and optionally copy unknown files into the profiled import flow.

HTTP scans use a bounded cURL client and accept only trusted HTTPS sources on port 443. The scanner rejects source URLs with credentials, redirects, private or reserved network targets, and manifest entries that contain an absolute URL or path traversal. Manifest and deep-inspection downloads have entry-count and byte limits.

HTTP source scanning is an administrator operation. Use only mirrors controlled by the game/operator and validate them before enabling a deep scan.

## Federation

Federation connects multiple UnrealDB deployments in a parent/child model.

Current federation capabilities include:

- site identity and fingerprint records;
- join-request workflow;
- parent/child peer management;
- timestamp/nonce/HMAC-signed peer API requests;
- inventory exchange;
- missing dependency request generation;
- approval, denial, and cancellation workflows;
- controlled upload/download/import transfer jobs;
- bounded cURL streaming upload/download paths;
- upload SHA-256 and byte-count verification before a received file is finalized;
- temporary `.part` file cleanup after failed transfer writes or verification;
- configurable speed limits, delays, and transfer-file limits;
- transfer logs, queue, and maintenance pages.

Federation transfers and public downloads are separate paths. Parent/child transfers should be run through controlled worker operations rather than exposed as unrestricted public downloads.

Use the streaming worker path for new deployments:

```text
/catalog/federation/worker-run.php
/catalog/federation/cron-worker-streaming.php
```

The older `cron-worker.php` remains as a rollback path while paired parent/child deployments complete their transfer validation. Switch scheduled work to `cron-worker-streaming.php` only after both peers are deployed and a successful transfer, interruption, oversize, and integrity-mismatch test has been completed.

## Public Downloads and External Mirrors

| Mode | Behaviour |
|---|---|
| `local_direct` | Serve the file from local catalog storage. |
| `external_mirror` | Return only an active external/shared-provider link. |
| `external_mirror_preferred` | Use an active external link when available; otherwise allow local download and queue mirror work. |
| `disabled` | Disable public download delivery. |

External mirror links are administered through a manual provider workflow. The application can queue mirror work and reuse active links, but provider-specific upload automation is not yet a core feature.

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

The worker is deliberately blocked from HTTP execution. On shared hosting, invoke it with cron or the host scheduler. During scanner-driven maintenance jobs, the execution context renews the database lease at bounded intervals and aborts if lease ownership is lost.

Validate a multi-worker deployment before increasing worker count: run a deliberately slow rebuild with a short lease, confirm that only one worker processes it, then confirm that another worker can recover work after an interrupted worker lease expires.

## Automated Checks and Package Fixtures

GitHub Actions runs the `Catalog quality` workflow on pull requests and pushes to `main`.

Current automated checks:

- PHP 8.3 syntax lint for tracked PHP files;
- failure when `catalog/config.php` is tracked;
- import of `catalog/install.sql` into a clean MySQL 8.4 database;
- verification of core tables, seed data, and reusable game-profile assignments.

The workflow does not yet prove package-reader correctness. Package regression fixtures are documented in `tests/fixtures/README.md`; keep retail game assets outside the public repository and provide them through a private/local fixture root.

## HTTP API Foundation

```text
/catalog/api/v1/health.php
/catalog/api/v1/job-status.php
/catalog/api/federation/
```

- `health.php` checks database reachability and returns a JSON status response.
- `job-status.php` requires an authenticated administrator session and returns recent background-job status.
- Federation endpoints live under `catalog/api/federation/`.

## UI System

The catalog remains server-rendered PHP; it does not require a JavaScript framework.

```text
catalog/src/Presentation/Ui/CatalogUi.php
catalog/assets/catalog-ui.css
catalog/assets/catalog-ui.js
```

The component layer provides reusable page headers, buttons, alerts, badges, loading states, empty states, sections, responsive tables, focus styles, and reduced-motion support. JavaScript progressively enhances dismissible notices and opted-in form submission states.

## Database Schema

### Canonical new-install schema

`catalog/install.sql` is the **single canonical schema** for new UnrealDB databases. It contains the final current definitions for all catalog, source, profile, federation, external-download, log, and job tables, including indexes, foreign keys, and seed settings.

For a fresh installation:

```bash
mysql -u YOUR_USER -p YOUR_DATABASE < catalog/install.sql
```

Do **not** run `catalog/install_update_*.sql` after importing `catalog/install.sql` for a new database. The canonical installer is the only schema input for a fresh database.

### Existing databases

Do not import the consolidated baseline over a populated production catalog. Take a database and storage backup first. Existing deployments that have already applied the prior updates require no action merely because the baseline was consolidated.

Before making future schema changes:

1. update `catalog/install.sql` so a fresh database always matches the application;
2. add and test an explicit upgrade procedure only when supporting an already-populated prior schema is required;
3. test both the fresh install and the upgrade path against fixture data.

## Installation

### Requirements

- PHP 8.2 or newer.
- MySQL or MariaDB with InnoDB support and JSON support.
- PHP extension: `pdo_mysql`.
- PHP extension: `curl` for HTTP source scans and streaming federation transfers.
- A PHP-capable web server, such as Apache, nginx with PHP-FPM, Synology Web Station, or a local PHP server.
- Writable catalog storage for the PHP/web-server account.
- CLI PHP access for administrator bootstrap and the worker.
- Optional LZO support for compressed UE3 packages where required.

### Fresh setup

1. Clone the repository to the web server.
2. For local development, copy `catalog/config.example.php` to `catalog/config.php`.
3. Set database credentials, storage location, package limits, and reader configuration.
4. Create an empty MySQL/MariaDB database.
5. Import **only** `catalog/install.sql`.
6. Ensure the configured storage location is writable by PHP.
7. Create the initial administrator from a trusted shell:

   ```bash
   php catalog/bin/create-admin.php --username=admin
   ```

8. Open `/catalog/index.php` and sign in.
9. Add games and profiles, then import a small known package set before bulk ingestion.

Example development storage setup on Synology/Linux:

```bash
cd /volume1/web/ut_reader
mkdir -p catalog/storage
chown -R http:http catalog/storage
chmod -R 775 catalog/storage
```

Adjust the web-server account for the host environment.

### Production deployment notes

For a public deployment, place both runtime configuration and storage outside the web root:

```text
/private/unrealdb/config.php
/private/unrealdb/storage/
```

Point the application at the private configuration file through its environment:

```text
UNREALDB_CATALOG_CONFIG=/private/unrealdb/config.php
```

Production requirements:

- serve the catalog over HTTPS;
- keep runtime configuration, storage, uploads, and logs outside the public web root where possible;
- do not commit `catalog/config.php`, package storage, upload folders, logs, provider credentials, federation secrets, or local native libraries;
- run the CLI bootstrap command before exposing the catalog publicly;
- keep `display_errors` disabled in PHP production configuration;
- enable Apache `.htaccess` handling, or configure equivalent nginx/web-server deny rules, for `new/`, `UE1/` through `UE5/`, and catalog runtime directories;
- do not expose standalone reader/viewer upload scripts publicly;
- run maintenance and federation workers through CLI/cron, never through a public browser route;
- test login/logout, CLI admin bootstrap, HTTP-source controls, and federation transfer recovery before production use.

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

## Current Limitations / Next Engineering Backlog

These items remain after the merged security, lease-renewal, streaming-transfer, and clean-schema work. They are ordered for fixture-backed implementation and validation.

1. **Parser coverage:** not every export payload or property type is decoded across every engine generation.
2. **UE4 package completeness:** unversioned packages can require assumed versions, and `.uexp`/bulk sidecars are not yet modeled as first-class package artifacts in every import path.
3. **Exact game identification:** package headers can identify an engine family but cannot always prove the exact game within the same engine generation. The catalog needs verified fingerprints and an evidence/confidence assignment model.
4. **Package regression fixtures:** CI verifies PHP syntax and a clean schema, but parser fixtures and automated import/dependency checks are not yet comprehensive across UE1–UE5.
5. **Federation scale and recovery testing:** streaming transport exists, but controlled large-library, interruption, retry, and paired-site recovery tests are still required before broad production use.
6. **External mirror automation:** provider-specific upload, verification, expiry, and delete APIs are not implemented as a general built-in feature.
7. **Production rollout verification:** the application contains deployment safeguards, but every public host still needs HTTPS, private runtime storage, web-server deny rules, secret rotation, and operational validation.

## Architecture and Operational Documentation

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
7. Update `catalog/install.sql` for every fresh-schema change and add a tested upgrade procedure only where existing deployments are supported.
8. Do not claim a transfer, parser, or source path is production-ready until its fixture or failure-recovery test exists.

## License

No license has been specified yet. Add a license before publishing a stable release or accepting external contributions.
