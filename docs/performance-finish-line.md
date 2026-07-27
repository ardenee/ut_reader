# Performance finish-line phase

This phase turns the production-scale query evidence into bounded runtime protections and closes the remaining known performance gaps.

## What changed

### Evidence-driven exact-count cache

`catalog_count()` now uses a short-lived cache for exact aggregate queries on the catalogue's largest tables. Cache eligibility is limited to known large-table counts or query shapes already represented by exact-count telemetry. Counts remain authoritative on a cache miss, inside transactions, and whenever `UNREALDB_COUNT_CACHE_DISABLED=1` is set.

Default maximum staleness is deliberately short:

- Background Jobs: 10 seconds;
- federation: 30 seconds;
- dependency summaries/details: 60 seconds;
- files: 120 seconds.

The cache is a performance layer, not a source of truth. Maintenance → Performance Readiness can prune or clear it immediately.

### Background Jobs search projection

Migration `202607270014_performance_finish_line.php` creates `ue_background_job_search`, a compact FULLTEXT projection of the administrator-visible job identity, payload, error and result text. The cursor endpoint synchronises changed rows in bounded batches and searches the projection rather than wildcard-scanning multiple JSON/TEXT columns in `ue_background_jobs`.

Searches shorter than the database FULLTEXT word threshold use a LIKE fallback against the compact projection. Queue and display-status filtering remains authoritative against the live job row.

### Bounded administrator all-game search

Exact GUID, MD5 and SHA1 searches remain direct global indexed lookups. Broad administrator searches without a selected game are divided into game-scoped searches with a bounded per-game quota and a maximum of 64 games. This prevents one request from launching an unbounded global wildcard fallback across all parser/search-document rows.

### Page-time diagnostics

The shared database helpers now record aggregate application time, SQL time and query count per route. No individual request body, query parameter or package data is retained. Responses expose `Server-Timing`, `X-UnrealDB-Query-Count` and count-cache hit/miss headers when headers are still writable. Slow requests are also written to the normal PHP/Apache error log.

Set `UNREALDB_SLOW_REQUEST_MS` to change the slow-request threshold; the default is 1000 ms.

### Deployment readiness

Maintenance → Performance Readiness checks the required projections and migrations, displays exact-count queries where timing and EXPLAIN evidence agree, shows aggregate page/application versus SQL time, and provides bounded reconciliation actions.

## Migration and rollout

1. Stop the background worker.
2. Ensure normal MySQL/InnoDB temporary space is available. The new job-search backfill is significantly smaller than the raw parser-table backfills from earlier phases.
3. Run the normal migration command and confirm migration `202607270014` is applied.
4. Open Maintenance → Performance Readiness.
5. Confirm all required tables are marked ready.
6. Run **Synchronise job search** until projected and authoritative job counts match and stale rows are zero.
7. Browse representative pages and worker views, then review Page-time diagnostics.
8. Re-run Exact Count Telemetry cold, warm and while the worker is active. Only rows meeting both the timing and plan thresholds appear in the confirmed remediation table.
9. Restart the worker.

## Completion criteria

The speed programme is complete when:

- all required performance tables are present;
- job-search source/projected counts match and stale rows are zero;
- there are no unexplained confirmed slow exact-count rows;
- common pages have acceptable average application and SQL time;
- no ordinary page performs an unbounded all-game broad search;
- the worker and imports remain responsive under representative load.

Raw Names, Imports, Exports and Dependencies remain authoritative. Archiving or partitioning them is still deferred until aggregate page timing and query telemetry show a concrete need.
