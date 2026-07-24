<?php
declare(strict_types=1);

function staging_contract_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$bucketController = file_get_contents(__DIR__ . '/../upload-bucket.php');
staging_contract_expect(is_string($bucketController), 'upload-bucket.php could not be read.');
staging_contract_expect(
    str_contains($bucketController, 'LegacyUnverifiedFileStager'),
    'Upload Bucket is not composed with the explicit unverified stager.'
);
staging_contract_expect(
    str_contains($bucketController, 'stageBucketUpload('),
    'Upload Bucket does not stage the stored file during the request.'
);
staging_contract_expect(
    str_contains($bucketController, "data.append('relative_path', shownName)"),
    'Upload Bucket no longer preserves folder-relative identity context.'
);
staging_contract_expect(
    str_contains($bucketController, "(string)(\$staged['status'] ?? '') === 'duplicate'"),
    'Upload Bucket does not handle duplicate staging results separately.'
);
staging_contract_expect(
    str_contains($bucketController, "'duplicates' => \$duplicates"),
    'Upload Bucket does not report the duplicate count.'
);
staging_contract_expect(
    str_contains($bucketController, 'Duplicate size+MD5 content already present in this bucket is reported and discarded.'),
    'Upload Bucket does not explain duplicate disposal.'
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
    substr_count($profiledUpload, 'scanner_store_failed_upload(') >= 3,
    'Profiled Upload no longer routes normal and PAK failures through the shared staging primitive.'
);

$httpScan = file_get_contents(__DIR__ . '/../http-source-scan.php');
staging_contract_expect(is_string($httpScan), 'http-source-scan.php could not be read.');
staging_contract_expect(
    !str_contains($httpScan, 'scanner_store_failed_upload('),
    'HTTP source scan unexpectedly creates unverified package files.'
);

$sourceScan = file_get_contents(__DIR__ . '/../source-scan.php');
staging_contract_expect(is_string($sourceScan), 'source-scan.php could not be read.');
staging_contract_expect(
    str_contains($sourceScan, 'stageFailedCopy('),
    'Local Source Scan does not use copy-preserving explicit staging.'
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
