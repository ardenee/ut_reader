# Catalog performance model

## Implemented query and write model

### Game file listing

The file list selects the requested page of file IDs before calculating dependency summaries. For normal sorts, dependency aggregation is restricted to the visible page rather than every file matching the filter. Dependency-count sorting remains aggregate-first because the count is part of its sort order.

The list query selects only columns rendered by the page. Scanner notes, storage paths, hashes not displayed by the page, and other text columns are no longer transferred for every row.

### Public and administrator search

Public broad search requires one selected game. Logged-in administrators may still search all games when maintenance or cross-game investigation requires it.

Exact GUID, MD5 and SHA1 searches remain direct indexed lookups. Package, alias and filename prefixes also remain direct B-tree lookups so common searches finish without entering the broad-search stages.

`ue_search_documents` stores one compact row per verified file, alias, import or export. Each row has a primary and secondary searchable value, so object names and full paths remain distinguishable without storing two document rows for every parser row. Alias-export combinations are deliberately not materialized because multiplying every alias by every export would create unbounded index growth.

Broad search uses the search-document FULLTEXT index first. If FULLTEXT does not provide enough candidates, the fallback scans only the selected game's compact search documents rather than joining and wildcard-scanning the raw Imports and Exports tables. Exact alias export paths use a targeted alias-package plus local-export-path query.

Search documents are rebuilt after imports and alias creation by deduplicated single-concurrency background jobs. The migration backfills all existing verified files. Final result hydration still reads only the columns rendered by the page.

### Upload/import writes

Names, Imports, Exports and Dependencies are written in bounded 250-row multi-value inserts. This preserves the existing transaction and rollback boundary while replacing one database round trip per parser row with one round trip per batch.

The imported package still builds its own dependency rows before commit. After commit, refreshes of existing files affected by the newly available package are enqueued as deduplicated durable jobs. This removes the potentially long affected-file rebuild from the visible import while preserving eventual dependency correctness.

The affected-refresh worker checks for actual dependent files before queueing, automatically starts the detached worker, continues past individual file failures, and records processed/failure counts in the job result. If queueing is unavailable, the service falls back to the previous synchronous refresh.

### Package-provider identity index

`ue_package_providers` is a compact materialized lookup of verified primary package names and package aliases. It is keyed by game and package name, backfilled by migration, and maintained from normal application file/alias writes without requiring database trigger privileges.

Package-only dependency resolution now uses one provider lookup rather than separate primary-file and alias scans. Exact authoritative-table fallbacks preserve correctness if a provider row is missing or stale. Exact object resolution remains against serialized export paths, using bounded `IN` and package/local-path predicates instead of generated `UNION ALL` value tables.

Primary package identities outrank aliases with the same package name. Resolver map keys are case-normalized so package casing differences do not create false missing dependencies.

### Dependency package summaries

`ue_dependency_package_summaries` stores one row per requiring file and required package. It retains dependency, resolved, missing, package-only and common counts, a summary status and the single resolved provider file when one exists.

The detailed `ue_dependencies` table remains authoritative for object-level inspection. Missing Files totals, package lists, requiring-file lists and affected-package discovery use the compact summary table, with detailed-query fallbacks while the migration is pending.

Normal imports refresh the imported file's summary through the existing search-index job. Affected dependency workers and manual file/game dependency jobs rebuild summaries inline, avoiding a second large job fan-out during maintenance.

### Upload progress

Progress persistence is throttled except for terminal states. Expired temporary progress files are cleaned separately from durable incoming job sources.

## Required migrations

Apply during a maintenance window with the worker stopped:

1. `202607270001_catalog_scale_indexes.php`
2. `202607270002_package_provider_index.php`
3. `202607270003_search_documents.php`
4. `202607270004_dependency_package_summaries.php`

The search-document migration performs a one-time server-side backfill from verified files, aliases, Imports and Exports. It creates secondary and FULLTEXT indexes only after the data copy, avoiding row-by-row index maintenance during the backfill.

The dependency-summary migration performs one grouped backfill from `ue_dependencies`. Both migrations can require temporary InnoDB space and substantial disk I/O on a large catalogue.

## Operational validation

Collect `EXPLAIN` plans and duration samples for:

- default and deeply paged game file lists;
- package, file-name, import-object and export-object searches within one game;
- administrator all-game searches;
- import of packages with small and very large N/I/E tables;
- import of a package that resolves existing missing dependencies;
- affected dependency refresh jobs with zero, few, and many dependent files;
- search-index jobs for files with small and very large parser tables;
- Missing Files totals and package/file lists;
- alias creation for a package with many dependent files;
- full-game dependency rebuilds.

Record database statement counts as well as elapsed time. The batching change should reduce parser-table and dependency insert statements approximately from one per row to one per 250 rows. Import response time should no longer include rebuilding existing affected files or rebuilding search documents.

## Next structural phases

### 1. Cached game counters

Maintain file count, storage size, unresolved dependency count and parser-row totals per game. Dashboard and home pages should read the cache. Reconciliation jobs should periodically compare cached totals with authoritative tables.

### 2. Keyset pagination

Replace deep `OFFSET` pagination on large file, dependency and search lists with stable keyset cursors based on the selected sort plus file ID. Keep exact total counts optional on public pages because full counts can be more expensive than retrieving the page itself.

### 3. Chunked Examine views

Names, Imports and Exports pages should load bounded chunks rather than rendering every parser row. Provide downloadable JSON/CSV exports for full-table inspection.

### 4. Source fingerprint cache

Persist source path, size, modification time and a trusted quick fingerprint. Re-hash a source file only when those signals change, while retaining full MD5/SHA verification before accepting a new catalogue identity.

## Scale limits that remain

- Administrator all-game wildcard fallback search remains intentionally more expensive than public game-scoped search.
- Exact counts and dependency-count sorting can still require broad aggregation.
- Some federation inventory/request pages still use detailed dependency rows until their base-game-policy queries are converted in a separate pass.
- Direct maintenance operations that rewrite package identity should enqueue a search-index reconciliation in a later maintenance-hook pass.
- Large migrations and index builds require free disk space for temporary InnoDB structures.
- The authoritative raw Names/Imports/Exports tables will continue to grow; archive or partition strategies should only be introduced after query telemetry shows a concrete need.
