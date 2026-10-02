# UEDB5 cutover readiness

Status: Step 11 verifier implemented; production cutover is not yet authorized.

## Purpose

The UEDB5 migration must finish as a complete catalogue conversion before production changes metadata formats. Production must not become a mixed runtime that tries V5 and then falls back to V4.

Step 10 remains the operational completion target while migration is in progress:

- verified files = N;
- validated UEDB5 files = N;
- missing UEDB5 = 0;
- invalid UEDB5 = 0;
- all V5 projections and dependency results are complete.

Step 11 turns those conditions into a strict read-only cutover gate implemented by `catalog/bin/verify-uedb5-cutover-ready.php`.

## Atomic-cutover boundary

Before cutover, the expected temporary state remains:

```text
original package bytes
├── live production .uedb4 + V4 registration
└── staged/validated .uedb5 + ue_uedb5_* projections
```
The pre-cutover verifier therefore does **not** require `ue_file_metadata` to have already been switched to format 5. Instead, it requires every verified file to have a current, validated staged format-5 registration and proves that the V5 read path itself has no V4 fallback.

A separate post-cutover verification step should later assert that live registrations/runtime are actually V5-only after the atomic switch.

## Source-only contract

Running the verifier without `--database` checks code boundaries only. It requires:

- the V5 reader/validator/runtime-candidate files to exist;
- no executable reference from those V5 read paths to `BlockedCompressedMetadataReader`, `BlockedCompressedMetadataContainer`, `.uedb4`, or live `ue_file_metadata`;
- the candidate production reader to resolve only the canonical UEDB5 path;
- the final validator to prove V5 from source/V5 state rather than V4 state.

Comments and documentation are stripped before the fallback scan, so explanatory references to UEDB4 do not create false failures.

Source-only success means the code contract is prepared. It never reports `cutover_ready=true` because the catalogue has not been checked.

## Whole-catalogue database gate

With `--database`, the verifier first performs cheap global blockers before any expensive per-file source reparse.
The global gate fails unless:

- every verified file has a staged `ue_uedb5_files` row;
- every staged verified registration is `format_version=5`;
- no nonverified/invalid file leaks into V5 staging;
- every verified file has a durable Step-8 status row;
- every status is `validated` under the current validator policy;
- every validated payload SHA still equals the current V5 registration SHA;
- every V5 registration has non-empty package-family/source-policy identity;
- every verified V5 file has its primary provider key;
- classic-package dependency edge count matches the source import count;
- every verified game has a registered source snapshot contract;
- every verified file is admitted by that game's active profile version range or an explicit header compatibility override.

The output also reports current live V4 registration count as information only. Live V4 remains expected before the atomic cutover.

If any global blocker exists, deep validation is skipped and the verifier fails quickly. This keeps an in-progress migration from wasting hours on a cutover audit that cannot possibly pass.

## Exhaustive deep validation

Only after all global blockers are zero does the gate instantiate `Uedb5MigrationValidator` and read-only validate every verified file again.
That deep pass re-proves, from current bytes/state:

- the `.uedb5` container exists and opens;
- the container payload SHA matches `ue_uedb5_files`;
- the manifest/file/game identity is correct;
- original verified source bytes still match catalogue size/MD5/SHA1;
- the source reparses through the same canonical engine/game reader used by Step 6;
- source-shaped UEDB5 metadata still equals that fresh parse;
- engine-specific source fields are present;
- provider/search/name/object projections exactly match authoritative UEDB5;
- dependency result count is complete;
- dependency edges and package summaries exactly match authoritative UEDB5;
- dependency readiness is true.

The verifier does not trust an earlier Step-8 `validated` row as sufficient evidence. That status is a prerequisite, but the final gate re-runs the validator so SQL projection drift or container corruption occurring after Step 8 is caught.

`cutover_ready=true` requires the exhaustive pass to check every verified file with zero failures.

## Commands

Code/source contract only:

```powershell
C:\php8.5\php.exe C:\Apache24\htdocs\unrealdb\catalog\bin\verify-uedb5-cutover-ready.php
```
Final whole-catalogue gate:

```powershell
C:\php8.5\php.exe C:\Apache24\htdocs\unrealdb\catalog\bin\verify-uedb5-cutover-ready.php --database --progress-every=500 --max-failures=50
```

Deep progress is emitted on STDERR as JSON rows so the final JSON result on STDOUT remains machine-readable.

The gate exits:

- `0` when the requested verification mode passes;
- `2` when the cutover contract fails;
- `1` for an execution/configuration error.

In source-only mode, exit `0` means only the code contract passed; `cutover_ready` remains false until `--database` succeeds.

## Non-goals

Step 11 does not:

- switch production registrations;
- delete `.uedb4` files;
- mutate V5 staging/status/projections;
- repair failed files;
- implement `try V5 else V4` compatibility;
- authorize a partial-game or partial-catalogue cutover.

The actual production switch remains a later explicit atomic cutover step after this gate is green.
