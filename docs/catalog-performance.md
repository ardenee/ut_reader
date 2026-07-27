# Catalog performance model

## Implemented query and write model

### Game file listing

The file list selects the requested page of file IDs before calculating dependency summaries. For normal sorts, dependency aggregation is restricted to the visible page rather than every file matching the filter. Dependency-count sorting remains aggregate-first because the count is part of its sort order.

The list query selects only columns rendered by the page. Scanner notes, storage paths, hashes not displayed by the page, and other text columns are no longer transferred for every row.

### Public and administrator search

Public broad search requires one selected game. Logged-in administrators may still search all games when maintenance or cross-game investigation requires it.

Search executes cheaper identity and prefix stages first and stops launching later stages once the result limit is satisfied. Game-scoped import/export searches are driven from verified files in the selected game. Final hydration selects only the columns rendered by the result page.

Exact GUID, MD5 and SHA1 queries remain direct indexed lookups. Leading-wildcard object/path queries remain bounded but still require scans until the dedicated search-document phase is implemented.

### Upload/import writes

Names, Imports, Exports and Dependencies are written in bounded 250-row multi-value inserts. This preserves the existing transaction and rollback boundary while replacing one database round trip per parser row with one round trip per batch.

The imported package still builds its own dependency rows before commit. After commit, only existing files whose dependency resolution can change are refreshed. A full-game dependency rebuild remains available for maintenance and repair.

### Package-provider identity index

`ue_package_providers` is a compact materialized lookup of verified primary package names and package aliases. It is keyed by game and package name and maintained by database triggers when files or aliases are inserted, updated or deleted.

Package-only dependency resolution now uses one provider lookup rather than separate primary-file and alias scans. Exact object resolution remains against serialized export paths, using bounded `IN` and package/local-path predicates instead of generated `UNION ALL` value tables.

Primary package identities outrank aliases with the same package name. Resolver map keys are case-normalized so package casing differences do not create false missing dependencies.

### Upload progress

Progress persistence is throttled except for terminal states. Expired temporary progress files are cleaned separately from durable incoming job sources.

## Required migrations

Apply during a maintenance window with the worker stopped:

1. `202607270001_catalog_scale_indexes.php`
2. `202607270002_package_provider_index.php`

The package-provider migration backfills the materialized lookup before creating maintenance triggers. Index creation and backfill can generate substantial disk I/O on a large existing database.

## Operational validation

Collect `EXPLAIN` plans and duration samples for:

- default and deeply paged game file lists;
- package, file-name, import-object and export-object searches within one game;
- administrator all-game searches;
- import of packages with small and very large N/I/E tables;
- import of a package that resolves existing missing dependencies;
- alias creation for a package with many dependent files;
- full-game dependency rebuilds.

Record database statement counts as well as elapsed time. The batching change should reduce parser-table and dependency insert statements approximately from one per row to one per 250 rows.

## Next structural phases

### 1. Asynchronous affected-dependency refresh

Keep the new package's own dependency rows inside the import transaction, but enqueue refreshes of existing affected files after commit. Deduplicate jobs by game and package identity. This shortens the visible import operation without sacrificing eventual dependency correctness.

### 2. Dedicated search documents

Create a game-scoped search-document table maintained asynchronously from file, alias, import and export changes. Use FULLTEXT where supported, with a deterministic fallback index for MariaDB/MySQL variants. Public search should query this table rather than raw parser tables.

Do not add triggers for every import/export object row: that would move search cost back into the import transaction. Search documents should be built by jobs after import.

### 3. Dependency package summaries

Add one summary row per requiring file and required package, including import count and resolved/missing counts. Missing-package pages, federation requirement generation and affected-file discovery can then query summaries instead of the full dependency row set.

The detailed `ue_dependencies` rows remain the source of truth for object-level inspection.

### 4. Cached game counters

Maintain file count, storage size, unresolved dependency count and parser-row totals per game. Dashboard and home pages should read the cache. Reconciliation jobs should periodically compare cached totals with authoritative tables.

### 5. Keyset pagination

Replace deep `OFFSET` pagination on large file, dependency and search lists with stable keyset cursors based on the selected sort plus file ID. Keep exact total counts optional on public pages because full counts can be more expensive than retrieving the page itself.

### 6. Chunked Examine views

Names, Imports and Exports pages should load bounded chunks rather than rendering every parser row. Provide downloadable JSON/CSV exports for full-table inspection.

### 7. Source fingerprint cache

Persist source path, size, modification time and a trusted quick fingerprint. Re-hash a source file only when those signals change, while retaining full MD5/SHA verification before accepting a new catalogue identity.

## Scale limits that remain

- Administrator all-game wildcard search remains intentionally more expensive than public game-scoped search.
- Exact counts and dependency-count sorting can still require broad aggregation.
- Large migrations and index builds require free disk space for temporary InnoDB structures.
- The authoritative raw Names/Imports/Exports tables will continue to grow; archive or partition strategies should only be introduced after query telemetry shows a concrete need.
