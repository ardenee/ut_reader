# Exact-count telemetry and query plans

## Purpose

Cursor pagination keeps row retrieval bounded, but several administrator pages still calculate exact totals for page counts and summary cards. Exact Count Telemetry measures those count-query shapes against the live catalogue and captures their MySQL `EXPLAIN` plans before any total is cached, approximated, removed or given a new index.

The feature is deliberately **on demand**. Normal catalogue, Missing Files, Background Jobs and federation requests do not write telemetry during ordinary page loads.

## Storage model

Migration `202607270012_exact_count_telemetry.php` creates `ue_exact_count_telemetry`.

The table stores one aggregate row per metric and normalized context:

- sample count;
- cumulative duration;
- average duration derived from cumulative duration/sample count;
- maximum and latest duration;
- samples taking at least 100 ms;
- latest exact result count;
- first and last sample timestamps.

Migration `202607270013_exact_count_query_plans.php` creates `ue_exact_count_query_plans`.

It stores one current EXPLAIN snapshot per metric/context:

- normalized query and context hashes;
- read-only SELECT text with parameter placeholders;
- raw EXPLAIN JSON;
- access types and possible/selected keys;
- estimated and full-scan row counts;
- temporary-table/filesort flags;
- plan assessment and remediation note;
- latest capture timestamp or EXPLAIN error.

Both tables use unique `(metric_key, context_hash)` keys, so repeated timing and plan runs update bounded aggregate rows rather than appending unlimited history.

## Shared query registry

`CatalogExactCountQueryCatalog` is authoritative for both timing and EXPLAIN. The timing benchmark and plan capture therefore cannot silently measure different SQL for the same metric/context.

The representative set covers:

- each game's normal file total;
- each game's missing-dependency filter total;
- Missing Files file, object, package and resolved totals;
- object and requiring-file totals for the five largest missing packages;
- Background Jobs totals for each queue and the running, failed and completed views;
- federation identity-conflict totals globally and for up to five peers.

## Running the evidence pass

Open **Maintenance → Exact Count Telemetry**.

1. Run **Run exact-count benchmark** at least three times: cold, warm, and while the worker is active.
2. Run **Capture EXPLAIN plans** after the timing samples exist.
3. Filter to a metric or minimum average duration.
4. Investigate only contexts where timing and plan evidence agree.

The timing benchmark executes read-only catalogue queries. EXPLAIN capture only accepts shared queries beginning with `SELECT` and does not execute `ALTER TABLE`, `CREATE INDEX`, or `ADD KEY`.

## Interpreting timing

Timing contexts are classified as:

- **Normal** — average below 100 ms, maximum below 500 ms and no 100 ms samples;
- **Watch** — average at least 100 ms, maximum at least 500 ms, or any slow sample;
- **Investigate** — average at least 250 ms, maximum at least 1 second, or at least half of samples are slow.

Run the benchmark several times after the database buffer cache is warm and during representative worker activity. A single cold-cache maximum should not by itself trigger a schema or caching change.

## Interpreting EXPLAIN

Plan capture flags:

- large `ALL` full scans;
- no selected key on a large estimate;
- `Using temporary`;
- `Using filesort`;
- EXPLAIN errors.

A concerning plan alone is not enough to add an index. The same context should also show repeated timing samples of at least 100 ms. Confirm the WHERE/JOIN column order, existing composite indexes and summary-table coverage before creating a migration.

Do not create speculative indexes for low-duration counts merely because MySQL reports a scan. Compact summary tables can be faster to scan than maintaining another wide index.

## Retention

The page can:

- prune timing contexts not sampled and plans not captured for a selected number of days;
- clear timing and plan telemetry before a new measurement period.

The telemetry tables contain no package payloads or file contents. Contexts contain only bounded identifiers and labels needed to distinguish the measured query shape.
