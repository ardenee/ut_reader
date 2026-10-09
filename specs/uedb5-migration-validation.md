# UEDB5 Migration Validation Contract

## Purpose

Step 8 defines when a verified catalogue file may count as successfully migrated to UEDB5. A staged `.uedb5` file is not sufficient by itself.

Each verified catalogue file has one durable row in `ue_uedb5_migration_status` with exactly one state:

- `pending` — no complete V5 staging registration exists yet;
- `staged` — V5 exists and base staging is structurally valid, but final validation is incomplete or dependency Pass 2 is not complete;
- `validated` — the exact current V5 payload passed the complete Step 8 contract;
- `failed` — source staging or final validation failed and requires retry/repair.

Validation is tied to the exact V5 payload SHA-256 and `validator_policy`. A rewritten V5 payload or validator-policy change invalidates an older `validated` state and returns the file to `staged`.

## Authoritative validation path

Validation does not use UEDB4 as an input or comparison source.

```text
original verified Unreal bytes
    -> canonical game/engine reader
    -> fresh source-shaped snapshot

current staged .uedb5
    -> full container/hash verification
    -> source-shaped snapshot

fresh source snapshot == staged source snapshot
    -> SQL projection comparison
    -> dependency completeness
    -> validated
```
The fresh source snapshot uses `Uedb5SourceSnapshotFactory`, which is also used by the Step 6 reparse migration. Reader dispatch, parser-profile selection, game-specific version boundaries and source-shaped builders therefore have one implementation.

The staged comparison removes only `dependency_results` and its section-schema entry before comparing to the fresh source snapshot. Dependency results are derived resolver output, not serialized source-package state.

## Validation gates

A file reaches `validated` only when all applicable gates pass:

1. the `.uedb5` file opens and the full container verifies;
2. manifest file/game identity and format version are correct;
3. staged registration hash, size, codec, block count, package family, source policy and section counts agree with the file;
4. original verified source bytes still match catalogue size, MD5 and SHA1;
5. the original bytes reparse through the canonical game/engine reader;
6. the staged source-shaped snapshot exactly matches the fresh source snapshot;
7. name/import/export counts match both fresh source parsing and catalogue counts;
8. required engine-specific source fields and width/presence gates are retained;
9. base provider/FName/search/object SQL projections exactly match UEDB5;
10. dependency result count matches the source dependency count;
11. dependency edge and package-summary SQL projections exactly match UEDB5;
12. none of these checks reads `.uedb4`, `BlockedCompressedMetadataReader`, or `ue_file_metadata`.

Classic package families require one dependency result per serialized import. UE5 Zen/IoStore uses its source-shaped ordinary/cell import, soft-reference and load-order dependency sources rather than pretending all Zen dependencies are classic imports.
## Pass-1 versus final validation

Step 8 may run while Step 6 Pass 1 is still in progress.

If a file passes container/source/base-projection checks but its required `dependency_results` section has not yet been built, validation returns `ready=false` and the durable state remains `staged`. This is not a failure.

A classic package with zero imports may validate with zero dependency results because the required dependency count is zero.

After dependency Pass 2 rewrites the V5 payload and republishes its staged registration/projections, the payload hash changes. Any older validation automatically becomes stale and the file must pass Step 8 again.

## Resumability

`validate-uedb5-migration.php` validates only `staged` and `failed` files that have a current `ue_uedb5_files` registration. `validated` files are skipped unless their registered payload or validator policy changes.

The internal file-ID cursor advances past failures, so one bad file cannot trap a continuous batch. Running the same command again retries remaining `staged`/`failed` files without revalidating unchanged `validated` files.

`--sync-only` reconciles existing catalogue state into durable statuses. This backfills files staged before the Step 8 table existed without reparsing them merely to create status rows.

Step 6 also writes `staged` or `failed` status directly when the Step 8 table exists. Pass-1 failures without a V5 registration remain durably `failed`; they are not silently converted back to `pending` by reconciliation.

### Frozen-source UT3 validation (no repeated source reparse)

The staged V5 snapshot was produced by an earlier Pass 1 source-file size/MD5/SHA1 check followed by a source-authorized parse. For a strictly admin-frozen corpus, `run-uedb5-staged-repair.ps1 -Game ut3 -TrustStagedSource` performs Step 8 using that existing authoritative V5 snapshot **without reopening or hashing the original source package**. It still verifies the complete V5 payload against the registered SHA-256, registration/manifest identity and count consistency, engine-specific serialization requirements, provider/name/object/search SQL projections, and dependency result/edge/summary integrity. It records `source_validation_mode=trusted_staged_source` in durable Step 8 evidence; this is not equivalent to independently verifying source bytes a second time. The standard path still performs the full source reparse. The fast flag is restricted to UT3 because historical UE1/UE2 snapshots are known to need schema repairs. If an administrator changes verified source identity or bytes, invalidate/re-stage that file and all dependent projections; never treat a source modification as covered by the existing V5 proof. The final Epic-source semantic parity/cutover contract remains required. This mode is not a source-correctness audit or a reason to bypass it.

### Combined staged-file resync (Pass 1 + Pass 2 + Step 8)

Use `run-uedb5-staged-repair.ps1 -Game unreal2 -ResyncStaged -BatchSize 250` to **rebuild only still-staged V5 files**, not validated files. This is deliberately different from the original `migrate-uedb5-game.php` Pass 1 command, which selects only files lacking a V5 registration and does **not** perform dependency Pass 2 or Step 8. The combined resync skips the pre-repair full source comparison and immediately verifies original size/MD5/SHA1, parses from Epic-compatible source, writes and registers V5, publishes provider/name/object/search SQL projections, runs targeted V5 dependency Pass 2, verifies the newly written container and SQL/dependency projections against the same verified source snapshot, and marks Step 8 validated only on complete success. It keeps the original V5 backup until success and retains disk-space and disjoint worker protections. A failure is logged with its file ID and original-container backup path.

This mode is most appropriate when source snapshots are broadly outdated (UE1/UE2). For games where most staged V5 snapshots are already current (such as the observed UT3 sample), validation-only repair can do less work; a resync unconditionally rewrites the remaining staged V5 files. The V5 game summary and eventual cutover/parity requirements remain separate; do not infer that missing-dependency dashboard totals refresh automatically.

### Resumable Step 8 repair of already-staged V5 files

`catalog/bin/repair-uedb5-staged.php --game=ut2004 --limit=50 --apply` processes a bounded set of **staged V5 files only**. It validates an unchanged snapshot directly; when a known historical UE1/UE2 summary field is missing, or the authoritative source validator reports `source_snapshot_mismatch`, it backs up that one `.uedb5` file, reruns source-backed Pass 1, refreshes V5 Pass 2 dependencies, and records full Step 8 validation. Successful backups are deleted; a backup path is reported if a repair fails. No V4 container or V4 lookup table is consulted.

Omit `--apply` for a read-only diagnostic. Use `--limit=N` (maximum 500) to bound each invocation and `--after=FILE_ID` for explicit cursor navigation. Without `--after`, repeated invocations automatically select the next staged files, because validated files are excluded. An `object_projection_mismatch` caused by **zero existing SQL object-candidate rows** is now repaired from the unchanged authoritative `.uedb5` snapshot: the V5 base SQL projection is republished, Pass 2 is refreshed, and Step 8 is rerun. Existing nonzero, disagreeing object rows are not overwritten automatically. For already failed files, use `catalog/bin/repair-uedb5-object-projection.php --file-id=N --apply` after checking the V5 registration. Failure states are deliberately not automatically retried. Inspect and address those separately; a failure must never be silently marked validated. The source parser and Epic-backed validation checks remain mandatory.

For an actual Pass 1 repair, the same-process worker now hands the already MD5/SHA1-verified source parse directly into Step 8. Step 8 still independently verifies the written V5 payload, compares the source-shaped snapshot and SQL/dependency projections, and records the normal validation policy. Standalone validation and unchanged V5 snapshots still verify and reparse the original source as before. The handoff is never persisted as an alternative source of truth.

UT2004's explicit game-profile compatibility allowance for legacy UE1 texture packages uses the UE1 reader and a distinct V5 source-policy identifier, rather than forcing a UE2 parse or broadening accepted versions.

For a full game in PowerShell, run `powershell.exe -NoProfile -ExecutionPolicy Bypass -File .\catalog\bin\run-uedb5-staged-repair.ps1 -Game ut2004 -BatchSize 500` from the repository root. The wrapper launches fresh PHP processes for bounded batches, emits last-file-ID checkpoints, and checks free disk space on the MySQL D: volume before each batch (default minimum 15 GiB). Use `-MaxBatches 1` for a single bounded trial. Stop on errors; rerunning from the beginning skips files already marked validated. To parallelize CPU-heavy validation, use separate processes with `-Workers 2 -WorkerIndex 0` and `-Workers 2 -WorkerIndex 1` (each in its own PowerShell console/log). Each selects disjoint staged file IDs using `MOD(file_id, workers) = worker_index`, so cursors and writes cannot overlap. Do not run a partitioned invocation while the original unpartitioned invocation for the same game remains active. Start with two workers; greater concurrency may increase MySQL contention. The final cutover readiness verifier must still pass before any legacy metadata tables are removed.