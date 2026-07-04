# Catalog performance model

## Changes on `refactor/architecture-foundation`

### Game file listing

The file list now selects the requested page of file IDs before calculating dependency summaries. For normal sorts, dependency aggregation is restricted to the visible page rather than every file matching the filter. Dependency-count sorting remains aggregate-first because the count is part of its sort order.

The list query selects only columns rendered by the page. Scanner notes, storage paths, hashes not displayed by the page, and other text columns are no longer transferred for every row.

### Upload/import path

An imported package still builds its own dependency rows before commit. After commit, only existing files whose dependency resolution can change are refreshed:

- package-only dependencies for the imported package name;
- object dependencies for paths exported by the imported package.

A full-game dependency rebuild remains available for maintenance and repair. If the post-commit targeted refresh fails, the import remains committed and storage is retained; the failure is logged for maintenance rather than creating a database/file inconsistency.

### Upload progress

Progress persistence is throttled to 200ms except for terminal states. Expired temporary progress files are cleaned probabilistically after 24 hours.

### Dashboard

Dashboard counters are calculated with grouped aggregates per table, reducing database round trips while retaining live values.

## Required migrations

Apply during a maintenance window:

1. `install_update_018_dependency_resolution_indexes.sql`
2. `install_update_019_global_hash_lookup_indexes.sql`
3. `install_update_020_file_list_dependency_index.sql`

## Operational validation

Before merging to production, collect `EXPLAIN` plans and duration samples for:

- default game file list;
- dependency filtered file list;
- dependency-count sort;
- import of a package that resolves existing missing dependencies;
- import of a package with no affected existing files;
- global search by hash, file metadata, import object, and export object.

## Scale limits that remain by design

- Leading-wildcard text search still requires scans until a dedicated search index is introduced.
- Exact page counts and deep `OFFSET` pagination remain expensive at high page numbers. Use keyset pagination or a separate search/list index when the catalog requires deep navigation at scale.
- The Examine page intentionally renders all object rows. It should move to a tested chunked renderer or a downloadable structured export before very large packages are exposed to high concurrent traffic.
- Local source scans hash candidate files. Persisting source file size, timestamp, and a trusted fingerprint would avoid repeat hashing, but should be introduced with explicit source-consistency rules.
