# UE5.8.3 archive-member production routing and nesting retry verification — 19 October 2026

Audit surface: `CatalogArchiveMemberContentRoutingJobHandler` wraps both bucket and profiled staged archive-member package routes. The regression driver `verify-archive-member-content-routing.php` was missing the normal catalog autoload bootstrap, falsely failing with `CatalogUnrealPackageTag not found` even though that production class exists. Added its standard bootstrap; actual runtime content classifier checks then executed and passed.

The resumed verifier exposed a **real production retry-policy defect**: `JobFailureRetryPolicy::isDeterministicPackageMessage` did not classify `Nested archive depth limit of N reached while content-routing ...` as deterministic. Identical jobs could replay despite no change to configured depth or source. Patched deterministic staged-package policy with the exact router diagnostic marker; deliberately did not classify it as corrupt immutable package bytes.

**Positive tests executed on DEV, exit 0 / ok=true:**
- `verify-archive-member-content-routing.php` — complete archive member classifier/routing check, including disguised nested archives and bounded depth (previously failed).
- `verify-ue5-583-nested-archive-retry.php` — both `PROCESS_BUCKET_STAGED_PACKAGE` and `IMPORT_STAGED_PACKAGE` classify a depth-limit failure as deterministic but not source-corrupt; unrelated transient filesystem failure remains retryable.
- `verify-archive-source-retention-contract.php` — source retention/reconstruction lifecycle.
- `verify-retained-archive-retry-policy-contract.php` — existing retained archive retry rules.
- Earlier same-pass tests: `verify-archive-ingestion.php`, `verify-archive-parent-lifecycle.php`, `verify-archive-reporting-contract.php`, `verify-uedb5-repair-workflow-isolated.php`, `verify-profiled-upload-ingress-isolation.php` (19 checks), and `verify-upload-worker-contracts.php` all exited 0.

**Scope:** This closes the exact archive-member content routing and deterministic depth-limit retry tests. It is not a new Epic serialized format difference and does not certify all 16 UE5 production workflows or full independent Epic output parity. No Satisfactory production import, database schema change or production data writes occurred.
