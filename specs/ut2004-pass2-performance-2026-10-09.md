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

## Mutating Pass 1 / Pass 2 / Step 8 isolated follow-up

Using the live V5-only source, dependency and validation services directly, each selected staged file was backed up with SHA-256 comparison before write. The test performed exactly one authoritative Pass 1 restage, Pass 2 resolution/persistence and Step 8 validation per selected file. No full-game operation or concurrent UT2004 migration was started; both Unreal Gold workers were left alone.

| File ID | Source package size | Pass 1 (s) | Pass 2 (s) | Step 8 (s) | Peak PHP memory (MB) |
|---|---:|---:|---:|---:|---:|
| 156185 | 0.5 MB | 11.9041 | 0.2102 | 0.1256 | 18 |
| 1309589 | 5 MB | 0.7612 | 0.1173 | 0.0768 | 20 |
| 139509 | 50 MB | 45.2417 | 7.9994 | 1.8879 | 244 |

All three files finished as `validated`, with original metadata snapshots preserved at `C:/Temp/uedb5-benchmark-original-{file_id}.uedb5`. This is **not** a controlled throughput comparison with the wrapper; the first file may include cold or initialization effects, and file structures vary. Pass 1 covers source identity/hash, parser read, V5 container write, registration and SQL projection publication. It is the dominant measured cost for the large file, but these results **do not yet isolate which Pass 1 suboperation** is slow. No extra 20 GiB memory allocation is justified: peak observed PHP use was 244 MB. D: free space was 41.7 GiB after the test.

## Next checkpoint
Instrument Pass 1 suboperations (source hash/parse, V5 write, registration, SQL projection publication) independently on a **small and disjoint** UT2004 sample, using existing test harnesses where possible. Investigate query contention versus cold I/O before changing SQL indexing or memory settings. Do not repeat these three validated files or interrupt the active Unreal Gold migration. Only propose a separate-pass migration strategy after measuring the whole write path with comparable workloads. Preserve Epic resolver behavior.
