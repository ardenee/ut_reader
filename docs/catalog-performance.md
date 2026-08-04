# Catalog performance model

## Current read model

### File and dependency listings

Game file lists and missing-dependency views use signed keyset cursors rather than deep SQL offsets. Stable cursor tuples always end with the row ID, so equal names, sizes, versions and timestamps cannot skip or duplicate records.

`ue_dependency_package_summaries` provides one compact row per requiring file and required package. Object-level inspection remains authoritative in the compact per-file metadata and dependency-link data. `ue_package_providers` resolves primary package names and aliases without repeatedly scanning both identity sources.

`ue_game_catalog_stats` stores per-game file, storage and dependency counters. Dashboard and game-list reads sum these compact rows instead of aggregating the largest catalogue tables on every request.

### Catalogue search

Public broad search is restricted to the selected game. Administrators may search all games for maintenance work.

GUID, MD5 and SHA-1 use direct indexed identity lookups. Package names, filenames, stored names and aliases use bounded prefix/contains queries. Import and export object matches are provided by `CatalogCompactSearchService` from:

- `ue_file_metadata`
- `ue_terms`
- `ue_export_lookup`
- `ue_dependency_links`

The retired `ue_search_documents` projection, its FULLTEXT indexer and its migration/backfill utilities are not part of the runtime or baseline schema.

The historical background-job type `catalog.rebuild_file_search_index` remains accepted so jobs queued by older deployments can finish. Its handler now refreshes package providers, dependency summaries and game statistics only.

### Package examination

Names, Imports and Exports are loaded in bounded pages. Full CSV and JSON exports stream rows in batches instead of loading complete tables into PHP memory.

Per-file compressed metadata is stored in `ue_file_metadata`, with reusable string terms and compact export/dependency lookup rows. Legacy parser-detail tables are retained only where compatibility paths still require them; new broad search does not scan them.

### Imports and maintenance

Parser rows are written in bounded batches. Dependency rebuilds, projection reconciliation, package generation and other long-running work use durable background jobs.

`catalog.reconcile_catalog_projections` receives old and new game/package context. It reconciles package providers, dependency summaries and cached game statistics, including deleted-file and game-reset cases.

### Federation and history

Federation inventory, request, transfer and log pages use signed keyset cursors. Base-game visibility and active filters are part of the cursor context, preventing a cursor from being replayed against another view.

### Source scanning

`ue_source_file_fingerprints` records path, size, modification time and sampled content fingerprints. An unchanged source file can reuse a verified catalogue match only when the cached identity still points to a valid verified file.

## Schema baseline

`catalog/install.sql` is the canonical empty-database schema through baseline `202608030001`. Historical migration PHP files through that version have been consolidated and removed.

For an existing database:

```powershell
php catalog\bin\migrate.php status
php catalog\bin\migrate.php migrate
php catalog\bin\migrate.php verify
```

Applied historical migration rows at or below the baseline are reported as archived. Future migrations must use a version greater than `202608030001`.

Do not import `catalog/install.sql` over a populated database.

## Operational validation

Measure elapsed time, SQL time, statement count and memory for:

- first, previous, next and last file-list pages for each sort;
- missing-package and requiring-file pages;
- package, filename, alias, GUID and hash searches;
- compact import/export object searches;
- large package table pages and streamed exports;
- imports with small and very large parser tables;
- dependency rebuilds with zero, few and many affected files;
- game reset/delete projection reconciliation;
- federation inventory, request, transfer and log cursors;
- first and repeated local-source scans;
- Background Jobs filtering, polling and history navigation.

Use `catalog/performance-readiness.php` to inspect required projection tables, request metrics, exact-count plans and the administrative background-job search projection.

## Remaining scale considerations

- Administrator all-game broad search is intentionally more expensive than public game-scoped search.
- Exact totals can still be costly on very large filtered views; use recorded telemetry before replacing them with cached or approximate values.
- Large future index or table migrations require adequate MySQL temporary space.
- Archive or partition strategies should be introduced only after production query telemetry identifies a specific table and access pattern.
