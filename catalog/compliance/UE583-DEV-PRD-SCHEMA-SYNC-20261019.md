# UE5.8.3 — DEV/PRD migration parity and upload entrypoint regression (19 October 2026)

## Environment reconciliation

DEV is `game-amd` (MySQL localhost:3306 `hwmvlite_ut_reader_catalog`); PRD is `Lelly-pc` (MySQL localhost:3306 same logical schema name). They are physically **different databases**; copying live PRD data, credentials, and untracked files is not part of this audit. Both application Git trees matched exact commit `d3b75f71` before the schema update, with zero tracked-file differences. PRD checkout has separate untracked content that must not be overwritten.

DEV official `catalog/bin/migrate.php migrate --dry-run` reported only migrations `202610150001` and `202610150002` pending. DEV identity check confirmed `@@hostname=game-amd`, `@@port=3306`, `@@datadir=C:/ProgramData/MySQL/MySQL Server 8.0/Data`. The normal official runner applied **both** in batch **46**, and `migrate.php verify` returned `Database migration state verified`. PRD had independently applied the same migrations as batch **2** (operator-run) and was rechecked read-only through the DEV-to-PRD link: three IoStore tables exist; all seven `ue_iostore_packages` metadata fields exist; expected name/status indexes exist.

DEV's dedicated `127.0.0.1:3339/uedb5_isolated_integration` remains a **distinct disposable test instance**, not the default application catalog.

## Production upload callsite and regression evidence

`CatalogIoStoreUploadStager` admits a complete ordered TOC/UCAS family, validates multipart upload fields and partition cardinality, preserves the TOC SHA256 and retains uploads in private `jobs/incoming/iostore` storage; `CatalogIoStoreImportJobHandler` queues names and member publication. The member job requires an active UE5 profile and retained matching TOC hash, publishes bounded batches and defers remaining work. Existing source-backed tests and the 27,240-member real Satisfactory isolated data sweep are documented in `UE583-WORKFLOW-REACHABILITY-20261019.md`.

Reran directly against DEV current code (all exit 0):
- `verify-upload-worker-contracts.php`
- `verify-upload-ingress-policy-contract.php`
- `verify-profiled-upload-ingress-isolation.php`
- `verify-ue5-583-zen-dependency-count.php`
- `verify-install-schema-baseline.php`

These tests do not certify a browser's full large-container HTTP upload or untested Epic source layouts. Preserve `RECOVERY.json.scope_complete=false` until independently verified end-to-end workflow coverage.

## Production import

No Satisfactory package import was triggered in PRD. The PRD migration blocker is closed; production workflow/source completeness remains distinct.
