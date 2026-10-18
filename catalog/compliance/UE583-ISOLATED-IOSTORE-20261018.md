## 18 October 2026 — isolated IoStore fixture and PRD-schema restoration

**Authoritative source:** Epic UE5.8.3 public default engine; PRD MySQL `Lelly-pc:3306/hwmvlite_ut_reader_catalog` is **read-only** to DEV. Satisfactory (game ID 14) remains intentionally empty in the production `ue_files` catalog; **no Satisfactory game import was initiated**.

**Isolation:** DEV `C:/Temp/uedb5-isolated-mysql/data`, port 3339 bound to 127.0.0.1, schema `uedb5_isolated_integration`. `UEDB5_SCHEMA_SOURCE=prd` in `catalog/bin/prepare-uedb5-standalone-schema.php` reads PRD table DDL and writes only to isolated DEV MySQL, after checking server datadir and port. Initial schema: 72 tables copied, 0 failures; rerun: 0 additions, 0 failures. Only game IDs 2 and 14 and their profile rows were copied into isolated MySQL to satisfy foreign keys. This is a disposable test environment and not a production data migration.

**Passed, actual retained Satisfactory IoStore fixture:** 
- `verify-iostore-container-index-standalone.php`: 27,240 `.utoc` members counted, stable repeat indexing.
- `verify-iostore-container-retention-standalone.php`: `global.utoc` stored and hash-checked, repeated import reported existing; global container has 0 export-bundle package members, so does not prove cooked package extraction.
- `verify-iostore-missing-part-standalone.php`: missing data part rejected, SQL rows unchanged.
- `verify-iostore-worker-guards-standalone.php`: guard/failure scenarios passed.
- Six direct UE5 reader/classic/Zen/foundation/dependency tests passed separately.

**Still unverified:** `verify-iostore-package-extraction-standalone.php`, `verify-iostore-v5-source-validation-standalone.php`, `verify-iostore-container-backup-restore-standalone.php`, `verify-iostore-member-reimport-standalone.php`, and `verify-iostore-name-index-standalone.php`. These depend on a **fully retained and ready** original `FactoryGame-WindowsServer.utoc` family, matching standalone `ue_files` rows and V5 metadata. The indexing-only fixture is intentionally `indexed`, not `ready`. An `indexed` container must not be forced to `ready` merely to satisfy audit tests. Preserve their failing status until a correct isolated fixture pipeline exists.

**Closure policy:** pass/fail evidence is not a production import. Missing setup dependencies are not UnrealDB vs Epic discrepancies. Continue finite real-source branch/production-consumer reconciliation; avoid auxiliary compilers, optional internal runtime branches and invented acceptance gates.
