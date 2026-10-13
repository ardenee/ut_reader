# UEDB5 isolated InnoDB name-candidate benchmark gate

Checkpoint 2026-10-13. Script: `catalog/bin/benchmark-uedb5-isolated-innodb.php`.

- `--fixtures` was executed on PRD (PHP 8.5) with **8 cases passing** and a synthetic MD5+length collision detected; no SQL writes.
- `--measure` was invoked with no dedicated database configuration and correctly **refused**, preserving the existing production database.
- PRD currently runs MySQL 8.4.11, with `innodb_file_per_table=ON`. Catalogue account `hwmvlite_unrealdb@localhost` has privileges scoped only to `hwmvlite_ut_reader_catalog`; no CREATE DATABASE privilege or separate connected DEV database has been established. **No physical InnoDB table/index size measurement has occurred**.
- The script requires an explicitly provisioned, **empty**, separate schema named `uedb5_shadow_*` and environment values `UEDB5_SHADOW_DSN`, `UEDB5_SHADOW_USER`, and optionally `UEDB5_SHADOW_PASSWORD`. It refuses to operate on the production schema and drops its three sample tables at the end.
- The measured corpus is capped at 10,000 name-candidate rows, accessed by fixed file IDs through existing indexes. Its physical size measurements may be subject to minimum InnoDB extent allocation and cannot be extrapolated linearly to hundreds of millions of rows.
- The object-name proof must use full normalized text and SHA-256 fingerprint whenever hash+length is ambiguous; a synthetic collision assertion is not proof that all future production object identities can safely be assigned using MD5 alone. No production candidate indexes or rows should be changed until the shadow schema is benchmarked and its full behavioural parity proved.

**Status:** fixtures complete; physical measurements awaiting an isolated MySQL schema. No production SQL modifications.
