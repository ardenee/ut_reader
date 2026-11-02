# UE5.8.3 — remaining fixture and workflow acceptance reconciliation

The 19 October audited genuine Satisfactory IoStore dataset in isolated DEV MySQL `127.0.0.1:3339/uedb5_isolated_integration` is **not** the old one-off test layout.

Actual container IDs queried 19 October 2026:
- 2: source game 2, 27,240 TOC members, `indexed` (index-only original)
- 3: source game 2, 0 members, `ready` (global)
- 4: source game 2, 27,240 members, `ready` (fully retained; published)
- 5: isolated restore game 9002, 27,240 members, `ready`
- 6: worker test game 9001, 0 members, `ready`

The 27,240 verified source-game 2 catalog files occupy file IDs 1–27240. The DEV production application MySQL is a different instance on port 3306, and PRD is different again. Do not run fixture-mutating operations against either application database.

## Stale acceptance assumptions still requiring test refactoring

| Driver | Stale fixture assumption | Corrective requirement |
| --- | --- | --- |
| `verify-iostore-package-inspection-standalone.php` | Container IDs 1 and 2 and hardcoded `C:/php8.5/ext/oo2core_9_win64.dll` | Select retained `ready` data container and `global.utoc` by source identifiers; use active PHP extension directory or `UNREALDB_OODLE_LIBRARY`; verify original payloads and reject global member read |
| `verify-iostore-member-removal-sql-standalone.php` | Published member in container ID 1 | Select one published member from real isolated container 4; require transaction rollback and original `ue_files` identity |
| `verify-iostore-missing-member-repair-standalone.php` | File ID 111239 and profile ID 9001 | Pick a verified real member by container identity; use valid active isolated UE5 profile and `finally` restoration of temporary file, profile and related records |
| `verify-iostore-multipart-job-standalone.php` | Exactly one prior HTTP multipart staged `global.utoc` in a temp upload directory | Create and verify a bounded HTTP test fixture first; count partitions and require isolated queue ownership before enqueueing |
| `verify-iostore-backup-corruption-standalone.php` | Specific pre-existing archive hash and exactly two restore target containers | Discover a known-good tested isolated archive by manifest, use a unique isolated target identity and assert corruption is rejected without changing that target |

These are **test harness prerequisites**, not proven UnrealDB-vs-Epic source defects. Tests were inspected but not accepted during this pass. The batch execution attempt was blocked before it could run; therefore there are **no new passing test results** for these five drivers.

## Closure distinction

Confirmed: deployed D033 Zen dependency count fix, D034 PAK v11 fix, real 27,240/27,240 package name/V5 publication/Pass 2/validation, strict 18/18 registered Epic UE5.8.3 source operations and both DEV/PRD IoStore schema migrations.

Still open: systematic source-to-consumer coverage of the 16 workflows, error/retry branches, unsupported alternative Epic UE5 format layouts, full browser multipart path and genuine query/search parity. Keep `RECOVERY.json.scope_complete=false` until these have independently verified acceptance evidence.
