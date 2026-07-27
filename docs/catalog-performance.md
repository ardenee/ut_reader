# Catalog performance model

## Implemented query and write model

### Game file listing

The game file list uses context-bound keyset cursors rather than deep SQL offsets. Every sort tuple ends with the file ID, so equal package names, versions, sizes, compression flags and timestamps remain stable without skipped or duplicated rows.

First and Last pages are fetched by reading the selected sort in its natural or fully reversed direction. Previous pages use the inverse cursor predicate and reverse the small returned page in PHP. Dependency sorting reads the compact package-summary table rather than grouping detailed dependency object rows.

The list query selects only the requested page of file IDs before loading visible fields and dependency counts. Scanner notes, storage paths and other unrendered text columns are not transferred for every row.

### Missing-file listing

The main Files with missing dependencies table also uses keyset cursors. Its stable tuple is missing object count, missing package count, game, package, filename and file ID. The page continues to show exact total/page counts, but retrieving a later page no longer discards all earlier aggregate rows with `OFFSET`.

Cursor tokens are HMAC-protected and bound to their active game, filters, page size and sort. Changing any of those values invalidates the old cursor and safely returns to the first page.

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

### Cached game catalogue statistics

`ue_game_catalog_stats` stores compact per-game file, storage and dependency counters. Dashboard catalogue totals, the public Games page, Library and the game missing-count API read this projection instead of repeatedly aggregating `ue_files` and detailed dependency rows.

Imports, search-index jobs, affected dependency refreshes and manual file/game dependency rebuilds proactively refresh the affected game. A five-minute read-through reconciliation repairs rows missed by rare maintenance or status-change paths. Non-blocking advisory locks prevent concurrent page requests or workers from rebuilding the same game row simultaneously.

Global catalogue counters are calculated by summing the small per-game table and adding unassigned Upload Bucket/unverified file rows directly, so files with `game_id IS NULL` are not lost.

### Federation inventories and request generation

Parent inventory matching now counts requiring files from `ue_dependency_package_summaries` rather than running a correlated detailed-dependency query for every peer file. Child request generation also groups the compact summaries, preserving missing-object and requiring-file counts.

The summary projection stores one representative missing object path per file/package so the Child request table retains its example-object column without reading detailed rows during page loads. Older code-before-migration deployments safely show an empty example until migration `202607270007` is applied.

Parent and Child inventory lists use HMAC-protected cursors bound to the selected peer, tab, base-game policy and page size. Request submission reconstructs the signed current page before accepting selected package keys, while the receiving Parent API remains authoritative for base-game policy and request lifecycle validation.

### Chunked package examination

`file-examine.php` loads only the selected Names, Imports or Exports page. Supported page sizes are 100, 250, 500 and 1,000 rows. The query uses the package table's `(file_id, table_index)` key and a bounded index range rather than loading all three tables before rendering the first tab.

Cross-reference URLs include the destination table and calculated page, so an import/export outer reference or Name usage link opens the page containing the exact target row. Name usage and dependency information are calculated only for rows visible on the current page.

Full Names, Imports and Exports downloads are available as CSV or JSON. The export endpoint walks the table in 1,000-row index batches and streams output rather than holding the complete result in PHP memory.

Stored package-header inspection remains bounded to the first MiB of the package. Raw header fields are collapsed by default and capped at 500 displayed rows.

### Local source fingerprints

`ue_source_file_fingerprints` stores one row per configured local source and relative path. It records source size, modification time, a SHA-256 fingerprint sampled from five 64 KiB windows, the normalized/decompressed work name, previous full hashes, package GUID and the last verified catalogue match.

An unchanged file can bypass full hashing and package parsing only when its path, size, timestamp and sampled fingerprint all match and the stored catalogue identity still points to a currently verified file. MD5/SHA-backed matches are checked against the authoritative file hashes; GUID-backed matches require the same unique verified GUID.

Changed or new source files follow the existing scanner path. A new catalogue identity is still accepted only after the normal importer performs full MD5 and SHA verification. Unchanged redirect archives can reuse their verified match without being decompressed again. Cache failures fall back to full scanning and are reported as counters instead of stopping the source job.

The synchronous Source Scan page and durable Source Scan worker now use the same package scanning implementation, preventing separate cache and matching behaviour.

### Upload progress

Progress persistence is throttled except for terminal states. Expired temporary progress files are cleaned separately from durable incoming job sources.

## Required migrations

Apply during a maintenance window with the worker stopped:

1. `202607270001_catalog_scale_indexes.php`
2. `202607270002_package_provider_index.php`
3. `202607270003_search_documents.php`
4. `202607270004_dependency_package_summaries.php`
5. `202607270005_game_catalog_stats.php`
6. `202607270006_keyset_pagination_indexes.php`
7. `202607270007_federation_summary_pagination.php`
8. `202607270008_source_file_fingerprints.php`

The search-document migration performs a one-time server-side backfill from verified files, aliases, Imports and Exports. It creates secondary and FULLTEXT indexes only after the data copy, avoiding row-by-row index maintenance during the backfill.

The dependency-summary migration performs one grouped backfill from `ue_dependencies`. The game-statistics migration then aggregates the compact summaries and file table once per configured game. The keyset migrations add stable file and federation inventory indexes. Migration `202607270007` also backfills one representative object path per dependency-summary row. Large catalogues can require temporary InnoDB space and substantial disk I/O.

Migration `202607270008` creates an empty source-fingerprint cache. It does not hash existing files during migration; rows are populated incrementally by later local-source scans.

The chunked package-examination phase requires no schema migration because the existing unique `(file_id, name/import/export index)` keys already support bounded range reads.

## Operational validation

Collect `EXPLAIN` plans and duration samples for:

- first, middle, previous and last game-file cursor pages for every sort;
- first, middle and last Files with missing dependencies cursor pages;
- first, middle, previous and last Parent/Child federation inventory cursor pages;
- Child request generation with the base-game policy enabled and disabled;
- first, middle and last Names, Imports and Exports pages on small and very large packages;
- direct cross-reference links to rows on later package-table pages;
- full CSV and JSON exports for very large packages;
- first and second scans of an unchanged large local source;
- unchanged redirect archives, confirming the second scan avoids decompression;
- a same-size/same-timestamp source file changed inside a sampled region;
- a cached file whose matched catalogue row was retired or deleted;
- package, file-name, import-object and export-object searches within one game;
- administrator all-game searches;
- import of packages with small and very large N/I/E tables;
- import of a package that resolves existing missing dependencies;
- affected dependency refresh jobs with zero, few, and many dependent files;
- search-index jobs for files with small and very large parser tables;
- Missing Files totals and package/file lists;
- Dashboard, Games and Library page loads before and after cache warm-up;
- alias creation for a package with many dependent files;
- full-game dependency rebuilds.

Record database statement counts as well as elapsed time. The batching change should reduce parser-table and dependency insert statements approximately from one per row to one per 250 rows. Import response time should no longer include rebuilding existing affected files or rebuilding search documents.

## Next structural phases

### 1. Remaining request/history pagination

Convert long request history, transfer history and diagnostics tables to the same stable cursor helper where telemetry shows meaningful page depth.

### 2. Maintenance reconciliation hooks

Direct maintenance operations that rewrite package identity should enqueue search-document, dependency-summary and game-statistics reconciliation consistently.

### 3. Bounded missing-object drill-down

The selected-package object detail on Missing Files should use bounded pages rather than reading every authoritative dependency object row at once.

## Scale limits that remain

- Administrator all-game wildcard fallback search remains intentionally more expensive than public game-scoped search.
- Exact total counts are still calculated for page-number display; cursor retrieval itself no longer grows with the requested page depth.
- Selected-package object drill-down on Missing Files still reads authoritative detailed rows until its dedicated bounded pass.
- Request history, transfer history and diagnostics lists may still use offsets until their dedicated conversion pass.
- Large migrations and index builds require free disk space for temporary InnoDB structures.
- The authoritative raw Names/Imports/Exports tables will continue to grow; archive or partition strategies should only be introduced after query telemetry shows a concrete need.
