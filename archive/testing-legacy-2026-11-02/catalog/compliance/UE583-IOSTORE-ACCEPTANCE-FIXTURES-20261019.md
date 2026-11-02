# UE5.8.3 — Resolved real-fixture acceptance tests

On 19 October 2026, ran against **isolated DEV MySQL at 127.0.0.1:3339**, never PRD:

- `verify-iostore-member-removal-sql-standalone.php`: **passed**. Uses published container 4, real file ID 3. The member is detached while inside a transaction; rollback restores its original `published` state and file ID. No persisted removal.
- `verify-iostore-package-inspection-standalone.php`: **passed**. Uses ready container 4 and active `D:/php8.5/ext/oo2core_9_win64.dll` (or `UNREALDB_OODLE_LIBRARY`). Read 5 real Zen members, names/import/export/size returned; global container 3 correctly refuses nonexistent member.
- `verify-iostore-backup-corruption-standalone.php`: **passed**. Discovers existing tested backup manifest; copies its real TOC, UCAS and manifest into a unique disposable directory; flips one byte of the copied UCAS; restore raises `IoStore backup component changed: FactoryGame-WindowsServer.ucas`; game 9002 remains at 1 container before/after. Test files removed in `finally`.

The underlying production code was **not changed** by these three fixes. These are acceptance harness corrections and evidence of existing behavior.

**Remaining two earlier fixture blockers:**
- `verify-iostore-missing-member-repair-standalone.php` uses obsolete file ID 111239 and alters an existing member. Before exercising destructive recovery, use a separately created disposable published-member fixture so the already-validated 27,240 V5/Pass 2 dataset is not invalidated.
- `verify-iostore-multipart-job-standalone.php` requires a previously staged HTTP multipart upload `global.utoc`. That fixture is currently absent; stage/upload independently and then exercise the queue worker rather than asserting an unrun test passed.

Broader UE5 source-to-production workflow callgraph and alternative layouts remain open; `RECOVERY.json.scope_complete` must remain false. PRD Satisfactory import was not started.
