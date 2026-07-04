# UnrealDB catalog architecture

## Scope

This document describes the current production behavior and the seams used for incremental refactoring. The intent is to improve reliability, scalability, and maintainability without changing public URLs, file formats, profile validation rules, or catalog results.

## Current runtime topology

- `index.php` is the public landing page.
- `catalog/index.php` is the public catalog router for the home page, search, login, redirect routes, and local file download.
- Individual files under `catalog/` are admin or public page controllers. They currently bootstrap sessions, query MySQL through PDO, process forms, and render HTML in the same request.
- `catalog/lib/` holds procedural shared services for database access, profile rules, scanning, federation authentication, uploads, and page helpers.
- UE1 through UE5 readers sit outside the catalog module. A catalog reader resolver maps a profile engine to the existing reader implementation.
- MySQL stores catalog metadata. The storage directory holds verified and unverified package bytes.

## Data flows

### Profiled upload

1. The browser sends one selected package to `catalog/profiled-upload.php`.
2. The page validates the administrator session and CSRF token, then delegates to `scanner_scan_uploaded_file()`.
3. The scanner obtains the game and assigned profile, validates the extension and package header, computes hashes, checks for a duplicate in the selected game, and selects an engine reader.
4. The reader produces the package header, names, imports, and exports.
5. The file is moved into the game's verified storage folder. Metadata and object tables are written in a database transaction.
6. Dependencies are resolved from the file's imports, then the game's dependency links are refreshed.
7. Upload progress is written to the progress store and read by the browser polling endpoint.

### Local source scan

1. `catalog/source-scan.php` walks a configured local source folder.
2. Known files are associated by hash or package GUID.
3. Unknown files are copied to a temporary path and imported through the same profiled scanner.
4. `ue_file_locations` records the source-relative location of each recognized file.

### Read paths

- Game pages filter and paginate `ue_files`, joining dependency summaries.
- Global search identifies matching files by hash, metadata, import data, or export data.
- Details and examine pages read package metadata/object tables.
- Downloads resolve `relative_path` under the configured storage root before streaming the file.

### Federation

1. Federation APIs load a peer by site ID.
2. A request signature covers the HTTP method, request path, timestamp, nonce, and body hash.
3. The peer secret verifies the signature; a nonce record prevents replay.
4. Queue and transfer pages coordinate inventory, requests, imports, and file transfers.

## Production seams

The catalog can be evolved without a rewrite by preserving the current controllers and extracting the following seams behind them:

1. **Bootstrap and request policy**: configuration, PDO setup, session, CSRF, error handling, and response helpers.
2. **Catalog repositories**: game, profile, file, dependency, source, and federation data access.
3. **Package-reader adapter**: one engine-to-reader resolution path and a formal reader contract.
4. **Ingestion service**: validation, parsing, storage, persistence, and dependency scheduling.
5. **Dependency resolution service**: batched lookups with explicit match precedence.
6. **Search service**: bounded candidate lookup now; indexed search adapter later.
7. **Job boundary**: scans, imports, full game re-links, and federation transfers should run in workers when request duration becomes material.

## Incremental roadmap

1. Keep existing procedural entry points as compatibility wrappers.
2. Move repeated SQL into repositories with typed return shapes.
3. Consolidate source scanning onto the profiled ingestion service and remove repeated import blocks.
4. Replace baseline-plus-manual-update SQL with tracked, idempotent migrations and one authoritative fresh-install schema.
5. Add package fixtures, migration integration tests, and PHP linting in continuous integration.
6. Move long-running scans and game-wide dependency refreshes into a queue-backed worker while retaining current pages as progress/status views.
7. Introduce a dedicated text-search index only after query semantics have fixture coverage.

## Performance expectations

- Dependency linking must avoid one lookup per import row.
- Global search must keep candidate sets bounded before full file rows are loaded.
- Package imports should use batched inserts when package tables become large.
- Offset pagination and leading-wildcard filtering remain acceptable for small catalogs but need cursor pagination and a search index as the catalog grows.
- Local scans should persist file fingerprints and scan checkpoints so unchanged files are not hashed repeatedly.
