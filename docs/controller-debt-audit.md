# Presentation controller-debt audit

`php catalog/bin/audit-controller-debt.php` is the read-only baseline for the remaining broad architecture cleanup.

It scans browser pages, `api/v1` endpoints and federation UI entry points for concrete boundary debt instead of ranking files only by size. Current rule categories are:

- detached-worker lifecycle/recovery in Presentation;
- direct durable-job table SQL;
- transactions in Presentation;
- signed/remote federation protocol calls;
- direct federation mutation SQL;
- runtime schema DDL outside install/migrations.

The audit intentionally reports findings without failing. A finding is a refactor candidate that still needs behavior review; it is not proof that the code is wrong.

The resumable profiled-upload completion path has now migrated to `CatalogQueueWorkerStarter`, so it uses the same orphan recovery and configured-pool reconciliation as the other upload/job entry points.

Use the audit together with the regression guards:

```text
php catalog/bin/audit-controller-debt.php
php catalog/bin/verify-general-controller-boundaries.php
php catalog/bin/verify-controller-boundaries.php
php catalog/bin/verify-federation-boundaries.php
php catalog/bin/verify-upload-worker-contracts.php
php catalog/bin/verify-architecture-refactor.php
```

Future broad cleanup should take one bounded context/rule family at a time. Do not combine controller extraction with parser/file-format changes, identity changes, search-result semantic changes, or schema migrations.
