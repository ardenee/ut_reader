# UT2004 UEDB5 Pass 2 benchmark — 2026-10-09

## Scope
Read-only provider selection benchmarks, no changes to the production DB or V5 containers. Existing Unreal Gold workers were left running. PHP 8.5 and the real production MariaDB were used. This is a performance experiment, **not** an Epic semantic certification or an end-to-end Pass 1/Pass 2 comparison.

## Baseline
MariaDB already has a 32 GiB InnoDB buffer pool, 8 GiB redo log capacity and indexed provider keys (`game_id, package_key_kind, package_key, file_id`). Allocating 20 GiB additional PHP cache without profiling is not justified.

For three UT2004 files of about 0.5, 5 and 50 MB, read-only Pass 1 source parse took 0.080, 0.115 and 0.584 seconds respectively. Pass 2 read-only provider selection took 1.223, 0.036 and 11.832 seconds, but the 50 MB test was evidently sensitive to cold-cache reads. Isolating a staged V5 snapshot plus provider-free baseline resolver on the 50 MB map took 0.228 + 0.105 seconds.

## Batched candidate experiment (NOT retained)
Changed only `PdoUedb5PhysicalProviderSelector::selectClassic` in the local working copy, replacing individual candidate SQL queries with 32-key `IN` batches. Original filtering, order, invalid identity rejection, FName key handling, provider de-duplication and UnrealI fallback were maintained.

A/B tests on 3 initial and 18 additional real UT2004 files produced **identical serialized provider-selection output hashes** for each corresponding old/new result (18 of 18 on broader sample). Warmed-up results showed only a modest benefit:

| Sequence | Sample count | Aggregate selector time |
|---|---:|---:|
| Batched first run | 18 | 13.773 s |
| Original second run | 18 | 2.076 s |
| Batched warmed third run | 18 | 1.745 s |

The run-order dependency makes speedups inconclusive. The batch method increased first-run time and does not justify a production change at this checkpoint. **Local code was reverted to the original selector, production never changed, and Git working tree was clean.**

## Next checkpoint
Measure authoritative *mutating* Pass 1 restage, Pass 2 resolution/persistence and Step 8 separately on a small, disjoint UT2004 file subset with exact IDs. Preserve existing backups and status, do not start game-wide workers, and compare separate-pass elapsed time with combined repair. Investigate cold V5 reads, disk I/O and SQL candidate-lookup metrics before adding caches or substantially increasing concurrency. Reconcile outputs against Epic-source semantics; never treat a speedup as permission to relax resolver behavior.
