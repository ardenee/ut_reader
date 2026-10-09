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

## Pass 1 breakdown — exact production components, two additional files

A disposable local benchmark `C:/Temp/ut2004-pass1-breakdown.php` invoked the existing source verification, source parsing, V5 writer, staging registration, SQL base projection publisher, Pass 2 and Step 8 in their normal order, measuring each component independently. No code was modified in the actual migration services. Each affected V5 file had a SHA-256-verified backup saved before modification, and each was fully validated afterwards. The active Unreal Gold workers continued uninterrupted.

| Operation | File 1314511, 5 MB | File 1312145, 20 MB |
|---|---:|---:|
| Source size/MD5/SHA1 verification | 0.1462 s | 0.2095 s |
| Source package parse | 0.0228 s | 0.0360 s |
| V5 write/compression/integrity verification | 0.0361 s | 0.0722 s |
| Staging registration SQL | 0.0174 s | 1.5966 s |
| **Base SQL projection build + publication** | **1.4553 s** | **28.0092 s** |
| Pass 1 status transition | 0.0041 s | 0.0025 s |
| Pass 2 dependency resolution + publication | 0.2198 s | 0.6735 s |
| Step 8 verification | 0.1259 s | 0.3541 s |
| Total | 2.0292 s | 30.9548 s |
| Peak PHP memory | 20 MB | 54 MB |

The 5 MB file generated 1,332 search keys, 1,331 name candidates and 1,098 object candidates; the 20 MB file generated 3,790 search keys, 3,789 name candidates and 3,473 object candidates. Source and V5 write cost is low for both. For the 20 MB file, base SQL projection processing accounts for approximately 90% of end-to-end time. These observations **do not yet distinguish** projection building/sorting from search-dictionary publication, provider/name/object SQL inserts, lock waiting and commit time.

Existing `PdoUedb5BaseProjectionPublisher` already batches insert rows in groups of 250, publishes immutable search dictionary keys outside the per-file transaction to avoid lock cycles, sorts rows deterministically, and commits each file's own candidate/projection changes atomically. The adjacent per-file V5 writer is not part of that SQL transaction. Buffering tens of files in one transaction must preserve these lock-order, per-file recovery and V5/SQL consistency guarantees; larger transactions may worsen contention. Neither disk spin-up nor PHP memory use explains these SQL timings by itself, but SQL waits versus actual execution time remain to be measured.

## Next checkpoint
Instrument `PdoUedb5BaseProjectionPublisher` (build, sort, dictionary insert, transaction begin, deletion, provider publish, name/object inserts, commit) **without changing SQL semantics** on one or two additional UT2004 staged files; also inspect InnoDB lock-wait metrics before and after. Compare warm and cold runs. Do not rerun the five now-validated benchmark files or interrupt current Unreal Gold workers. Only after confirming where SQL time is spent, prototype bounded multi-file publication (e.g. 10–50 files with memory and SQL size caps) versus the existing per-file transaction and 250-row inserts. Preserve Epic source semantics and durable per-file recovery.

## SQL projection internals — isolated benchmark checkpoint

Dev-only timing instrumentation was temporarily added to `PdoUedb5BaseProjectionPublisher`, with production unchanged, and removed after testing. Actual production Pass 1, Pass 2 and Step 8 components were invoked on separate UT2004 staged files; each original V5 container was SHA-256 backed up before writes. All files ended validated. The existing Unreal Gold migration continued.

**Two projection-stage benchmarks:**

| Stage | File 1308795 (~5 MB) | File 1305067 (~20 MB) |
|---|---:|---:|
| Build SQL rows | 0.0082 s | 0.0526 s |
| Sort | 0.0145 s | 0.1557 s |
| Search dictionary INSERT | 0.0244 s | 0.1408 s |
| Transaction start | 0.0002 s | 0.0002 s |
| **Four per-file DELETEs combined** | **7.3088 s** | **7.6244 s** |
| Provider publication | 0.0030 s | 0.0027 s |
| Name INSERTs | 0.0578 s | 0.2249 s |
| Object INSERTs | 0.0568 s | 0.2313 s |
| Commit | 0.0051 s | 0.0075 s |
| Total projection stage | 7.4806 s | 8.4503 s |

The further per-table measurement of file 1329406 (~5 MB) isolated:

| DELETE table | Deleted rows | Measured seconds |
|---|---:|---:|
| `ue_uedb5_dependency_packages` | 47 | 0.5656 |
| `ue_uedb5_dependency_edges` | 388 | 1.8905 |
| `ue_uedb5_object_candidates` | 3,374 | **31.5073** |
| `ue_uedb5_name_candidates` | 3,965 | 2.1659 |

The four deletes consumed ~36.13 of 36.48 seconds in the projection stage on that sample. Its Pass 1 SQL registration independently took ~1.30 seconds. This strongly indicates DELETE/row-replacement overhead, not source parsing, compression, sorting or INSERT batching. Existing single-file `DELETE ... WHERE file_id=?` uses the `file_id` prefix of a primary key for all four tables (verified using `EXPLAIN DELETE`). Each table has secondary indexes and FKs to `ue_uedb5_files`; referential effects and lock waits are **possibilities, not yet proven causes**. Timings on a live DB can vary considerably.

**Important next checkpoint:** Investigate the expensive `ue_uedb5_object_candidates` delete with MySQL Performance Schema statement/lock/I/O metrics and index/foreign-key cost, without repeating production scans. Do not replace indexed deletes with table scans, disable FK checks, widen transactions or buffer 20 GiB of PHP data on the basis of these benchmarks. Test an equivalent safe deletion/upsert strategy on bounded disjoint files only after identifying the mechanism. Production source was not modified; the dev-only instrumentation was reverted. Original V5 backups remain in `C:\Temp\uedb5-benchmark-original-<file-id>.uedb5`.
