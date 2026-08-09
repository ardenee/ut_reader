# Legacy controller boundary cleanup

This phase continues the August 2026 modular-monolith refactor after the Upload Bucket and worker-pool work. It is behavior-preserving: routes, API fields, job types, queue names, dedupe keys, retry semantics, file identity and federation protocol are unchanged.

## Durable job reads

Feature pages no longer need ad-hoc `ue_background_jobs` SELECT statements for exact typed lookups and small typed lists.

`PdoBackgroundJobLookupQuery` now owns:

- exact lookup by job ID + type;
- exact lookup by job ID + queue;
- recent typed job lists;
- active typed job lists;
- active typed existence checks.

The first migrated callers are generated-package status/download, duplicate cleanup, game backups and retained-PAK rerun.

## Worker bootstrap after enqueue

Feature controllers that create durable jobs now reuse `CatalogQueueWorkerStarter` rather than launching `CatalogDetachedWorker` directly.

The starter performs orphan recovery and delegates configured-pool reconciliation to `CatalogWorkerPoolReconciler`. This prevents older enqueue paths from preserving an accidentally undersized persisted pool such as 1/4 or 2/4 when the configured pool is larger.

Migrated paths include:

- profiled uploads;
- direct PAK imports;
- game backup export/import;
- unverified metadata repair;
- generated-package jobs;
- selected terminal-job retry.

Worker-start failure remains non-destructive: already-enqueued jobs remain durable. Callers that historically treated launch failure as a request failure continue to do so; callers that historically returned a `worker_error` continue to return it.

## Exact job retry semantics

`job-retry.php` historically restarts only `cancelled`, `failed` and `dead_letter` rows. That contract is narrower than the Background Jobs bulk restart action, which can also restart some completed rows whose display result is failed/rejected/unverified.

`PdoBackgroundJobRetryAction` therefore preserves the compatibility endpoint's exact narrower status set instead of reusing the broader bulk operation.

## Manual recovery semantics

Administrator-triggered `recover` is intentionally distinct from automatic crash recovery.

`CatalogManualJobRecovery` preserves the existing rule that a detached orphan requeued manually does **not** consume another attempt: `attempts` is reduced before the row is returned to `queued`. Automatic orphan/crash recovery may still count the failed attempt according to its own policy.

The HTTP endpoint no longer owns orphan-recovery SQL.

## Generated package authorization

`CatalogGeneratedPackageJobAccess` centralizes the existing per-browser authorization contract:

- the random browser token remains stored in the PHP session;
- only its SHA-256 hash is stored in the durable job payload;
- comparisons still use `hash_equals`;
- the download endpoint still distinguishes “job is not ready” from “job exists but this browser is not authorized.”

## Unverified promotion

`unverified-files-action.php` is now a transport/session/progress controller rather than the owner of the import transaction.

The extracted flow is:

```text
unverified-files-action.php
  -> CatalogUnverifiedActionSourceResolver
  -> CatalogUnverifiedImportService
       -> CatalogUnverifiedPromotion
       -> CatalogUnverifiedDependencyRecovery
```

`CatalogUnverifiedPromotion` owns:

- staged row reuse and the filesystem-only compatibility fallback;
- game-profile classification;
- UE4/UE5 mounted-path validation;
- staged MD5/SHA-1 reuse or fallback recalculation;
- same-game duplicate / package-alias handling;
- verified storage placement and rollback;
- the `ue_files` promotion transaction;
- post-promotion dependency queue handoff.

`CatalogUnverifiedImportService` preserves the important partial-commit recovery case: if the package has already become verified but dependency job creation then fails, a retry does not promote or move the file again.

Unverified Names/Imports/Exports now live in the dedicated compressed `ue_unverified_metadata` staging record until promotion. Verified promotion publishes format-2 compact metadata and current dependency/lookup projections. `CatalogUnverifiedDependencyRecovery` is limited to promotion/recovery coordination; row-per-table metadata is migration/retirement history rather than a runtime staging contract.

## Validation

Run:

```text
php catalog/bin/verify-controller-boundaries.php
php catalog/bin/verify-upload-worker-contracts.php
php catalog/bin/verify-architecture-refactor.php
php catalog/bin/audit-legacy-runtime-references.php
```

Then exercise generated-package creation/download, profiled upload, PAK import, game backup export/import, duplicate cleanup and one Unverified Files import.

## Remaining controller debt

The next bounded-context cleanup targets are:

1. Federation Connections and Inventories: large pages still mix protocol orchestration, persistence and rendering.
2. The resumable profiled-upload chunk endpoint still contains its older direct detached-worker launch path; an attempted behavior-preserving migration in this phase was not published and should be revisited separately.
3. Older maintenance pages with substantial page-local SQL should move to intent-specific query/use-case objects as their hot paths are validated.
4. Procedural `catalog/lib` scanner and federation compatibility modules should continue shrinking only after their active callers are migrated.

Do not combine those changes with parser/file-format behavior changes. In particular, UZ3 remains deferred pending independent format confirmation.
