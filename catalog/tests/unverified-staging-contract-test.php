<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies unverified staging behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function staging_contract_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$bucketController = file_get_contents(__DIR__ . '/../upload-bucket-v2.php');
staging_contract_expect(is_string($bucketController), 'upload-bucket-v2.php could not be read.');
staging_contract_expect(
    !str_contains($bucketController, 'LegacyUnverifiedFileStager')
        && str_contains($bucketController, 'data-chunk-url="api/v1/upload-bucket-chunk.php"')
        && str_contains($bucketController, 'data-batch-url="api/v1/upload-bucket-batch.php"'),
    'Canonical Upload Bucket no longer delegates durable staging and queue finalisation to its API workflow.'
);

staging_contract_expect(
    !is_file(__DIR__ . '/../lib/CatalogUnverifiedAutoIndex.php'),
    'The shutdown-time directory snapshot hook still exists.'
);
$support = file_get_contents(__DIR__ . '/../lib/CatalogSupport.php');
staging_contract_expect(is_string($support), 'CatalogSupport.php could not be read.');
staging_contract_expect(
    !str_contains($support, 'CatalogUnverifiedAutoIndex'),
    'Catalog bootstrap still registers shutdown-time unverified indexing.'
);

$scanner = file_get_contents(__DIR__ . '/../lib/CatalogScanner.php');
staging_contract_expect(is_string($scanner), 'CatalogScanner.php could not be read.');
staging_contract_expect(
    str_contains($scanner, 'LegacyUnverifiedFileStager'),
    'Scanner failures are not composed with the explicit unverified stager.'
);
staging_contract_expect(
    str_contains($scanner, 'stageFailedUpload('),
    'Scanner failures do not create the unverified row synchronously.'
);
staging_contract_expect(
    str_contains($scanner, 'Database staging was unavailable; run unverified queue reconciliation.'),
    'Scanner failures no longer retain a recoverable fallback when the database is unavailable.'
);

$profiledUpload = file_get_contents(__DIR__ . '/../profiled-upload.php');
staging_contract_expect(is_string($profiledUpload), 'profiled-upload.php could not be read.');
staging_contract_expect(
    str_contains($profiledUpload, 'CatalogIncomingFileStore')
        && str_contains($profiledUpload, 'CatalogProfiledUploadQueue')
        && str_contains($profiledUpload, 'enqueueStaged('),
    'Profiled Upload no longer stages incoming files before handing them to background import jobs.'
);

$httpScan = file_get_contents(__DIR__ . '/../http-source-scan.php');
staging_contract_expect(is_string($httpScan), 'http-source-scan.php could not be read.');
staging_contract_expect(
    !str_contains($httpScan, 'scanner_store_failed_upload('),
    'HTTP source scan unexpectedly creates unverified package files.'
);

$sourceScan = file_get_contents(__DIR__ . '/../source-scan.php');
$sourceScanShared = file_get_contents(__DIR__ . '/../lib/CatalogSourceScanNoContainers.php');
staging_contract_expect(is_string($sourceScan) && is_string($sourceScanShared), 'Source scan sources could not be read.');
staging_contract_expect(
    str_contains($sourceScan, 'catalog_source_scan_run_without_containers(')
        && str_contains($sourceScanShared, 'catalog_source_scan_stage_failed('),
    'Local Source Scan no longer delegates failed-package staging to the shared scan implementation.'
);
staging_contract_expect(
    !str_contains($sourceScan, 'catalog_unverified_index_path(')
        && !str_contains($sourceScan, 'uvf_unverified_dir('),
    'Local Source Scan still duplicates queue storage or database-indexing logic.'
);

$federationWorker = file_get_contents(__DIR__ . '/../lib/FederationWorker.php');
staging_contract_expect(is_string($federationWorker), 'FederationWorker.php could not be read.');
staging_contract_expect(
    str_contains($federationWorker, 'federation_worker_stage_failed_import(')
        && str_contains($federationWorker, 'stageFailedUpload('),
    'Failed federation imports are not routed into explicit unverified staging.'
);
staging_contract_expect(
    str_contains($federationWorker, 'incoming_path=NULL')
        && str_contains($federationWorker, "@unlink(\$incoming)"),
    'Successful or duplicate federation imports no longer clean their incoming file reference.'
);
staging_contract_expect(
    str_contains($federationWorker, 'FEDERATION_STAGE_FAIL')
        && str_contains($federationWorker, 'Staged as unverified file #'),
    'Federation jobs no longer record failed staging or the resulting unverified identity.'
);

$stager = file_get_contents(__DIR__ . '/../src/Infrastructure/Legacy/LegacyUnverifiedFileStager.php');
staging_contract_expect(is_string($stager), 'LegacyUnverifiedFileStager.php could not be read.');
staging_contract_expect(
    str_contains($stager, 'catalog_unverified_index_path('),
    'The explicit staging service no longer creates the database row.'
);
staging_contract_expect(
    str_contains($stager, 'stageFailedCopy(')
        && str_contains($stager, 'stageFailedPath('),
    'Move and copy staging are no longer implemented through one shared path.'
);
staging_contract_expect(
    str_contains($stager, 'Database staging failed:'),
    'The explicit staging service no longer records recoverable database failures beside retained files.'
);
staging_contract_expect(
    str_contains($stager, 'md5_file($temporaryPath)')
        && str_contains($stager, 'unverified_queue_game_id=0')
        && str_contains($stager, "'status' => 'duplicate'"),
    'The upload-bucket stager does not reject an existing size+MD5 identity.'
);
staging_contract_expect(
    str_contains($stager, 'SELECT GET_LOCK(?, 30) acquired')
        && str_contains($stager, 'SELECT RELEASE_LOCK(?) released'),
    'Concurrent upload-bucket duplicate checks are not serialized.'
);
staging_contract_expect(
    str_contains($stager, 'Uploaded copy discarded.'),
    'Duplicate staging does not clearly report that the incoming copy was discarded.'
);

echo "Explicit unverified staging contract tests passed.\n";
