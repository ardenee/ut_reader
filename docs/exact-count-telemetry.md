# Exact-count telemetry

## Purpose

Cursor pagination keeps row retrieval bounded, but several administrator pages still calculate exact totals for page counts and summary cards. Exact Count Telemetry measures those count-query shapes against the live catalogue before any total is cached, approximated or removed.

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

The unique `(metric_key, context_hash)` key prevents one row being added for every benchmark run. Context JSON is capped and hashed if it exceeds the configured bound.

## Benchmark coverage

The Maintenance → Exact Count Telemetry benchmark samples:

- each game's normal file total;
- each game's missing-dependency filter total;
- Missing Files file, object, package and resolved totals;
- object and requiring-file totals for the five largest missing packages;
- Background Jobs totals for each queue and the running, failed and completed views;
- federation identity-conflict totals globally and for up to five peers.

The benchmark executes read-only catalogue queries. Its only writes are aggregate telemetry upserts.

## Interpreting results

The report classifies contexts as:

- **Normal** — average below 100 ms, maximum below 500 ms and no 100 ms samples;
- **Watch** — average at least 100 ms, maximum at least 500 ms, or any slow sample;
- **Investigate** — average at least 250 ms, maximum at least 1 second, or at least half of samples are slow.

Run the benchmark several times after the database buffer cache is warm and during representative worker activity. A single cold-cache maximum should not by itself trigger a schema or caching change.

Only consider cached or approximate totals when the same context remains slow across repeated samples and its count query cannot be corrected with an index or compact materialized projection.

## Retention

The page can:

- prune contexts not sampled for a selected number of days;
- clear all telemetry before a new measurement period.

The telemetry table contains no package payloads or file contents. Contexts contain only bounded identifiers and labels needed to distinguish the measured query shape.
