# Upload Bucket v2 retirement boundary

This document records the compatibility boundary for retiring the historical Upload Bucket implementation without changing upload, import, package identity, or queue behaviour.

## Canonical route

`catalog/upload-bucket-v2.php` is the only Upload Bucket implementation. `catalog/upload-bucket.php` is a compatibility redirect only and must not regain POST handling, hashing, file placement, duplicate detection, queue SQL, or worker orchestration.

The administrator navigation already targets `upload-bucket-v2.php`. The redirect remains only for old bookmarks or external links and may be removed later when that compatibility is no longer useful.

## Canonical v2 flow

The supported browser/server ownership is:

```text
browser selection
  -> browser inspection and ordinary-file hashing
  -> server preflight duplicate check
  -> resumable durable chunk staging
  -> completed upload verification
  -> per-file durable finalization/deduplication
  -> PROCESS_BUCKET_UPLOAD queue row
  -> configured detached worker pool
  -> package processing / unverified indexing
  -> later import promotion
  -> durable dependency work
```

The browser coordinator intentionally queues completed files while Upload Bucket processing is paused, then starts processing after the batch has finished transferring. That keeps transfer state deterministic and avoids a worker consuming files while the browser is still preparing the same batch.

## Single owners

The following responsibilities must not be copied back into pages or API endpoints:

- `CatalogUploadBucketFilePolicy`: allowed Upload Bucket file/profile policy.
- `CatalogBucketUploadTransferStoreFactory` / `CatalogChunkedUploadStore`: resumable transfer storage.
- `CatalogBucketBatchQueue`: completed-source validation, physical duplicate detection, active-source dedupe and durable enqueue.
- `CatalogBucketBatchFinalizer`: batch preparation, orphan recovery and optional worker start.
- `CatalogBucketUploadJobHandler` / `CatalogBucketIdentityProcessor`: queued package processing and package identity path.
- `PdoJobQueue`: durable job lifecycle.

## What remains "legacy"

`LegacyUnverifiedFileStager` is not the retired browser uploader. It is still an active compatibility adapter used by source scanning, federation, PAK import and staged import paths. It must not be deleted merely because `upload-bucket.php` is retired. Its callers should be migrated separately by bounded context before that adapter is removed.

## Worker-pool contract

Any code that starts follow-up work must reconcile toward the configured detached-worker count rather than merely checking whether at least one worker exists. A partially active pool (for example 1/4 or 2/4) is operationally incomplete when independent runnable jobs exist.

Live Background Jobs reporting must derive queue depth/running counts from durable database rows. Per-process `processed` counters are diagnostic runtime counters only; retained state from stopped worker slots must not be included in the live total.

Worker-pool reconciliation belongs in `CatalogWorkerPoolReconciler`. The `job-run.php` endpoint owns only request validation and response translation.

## Regression guard

Run:

```text
php catalog/bin/verify-upload-worker-contracts.php
php catalog/bin/verify-architecture-refactor.php
```

The first command specifically prevents the compatibility redirect from becoming a second uploader, guards the v2 browser/server phase boundaries, checks explicit configured-pool starts and verifies active-only worker processed reporting.

## Deferred redirect-format work

UZ3 format verification is deliberately outside this retirement/refactor pass. No UZ3 codec, signature, compression or decompression behaviour is changed by these architecture changes.
