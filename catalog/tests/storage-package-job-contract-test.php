<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies storage package job behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function storage_package_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$types = file_get_contents(__DIR__ . '/../src/Domain/Jobs/JobType.php');
storage_package_expect(is_string($types), 'JobType.php could not be read.');
foreach (['CLEAN_UNVERIFIED_DUPLICATES', 'GENERATE_MOD_PACKAGE'] as $constant) {
    storage_package_expect(str_contains($types, $constant), 'Durable job type is missing ' . $constant);
}

$policy = file_get_contents(__DIR__ . '/../src/Domain/Jobs/JobResourcePolicy.php');
storage_package_expect(is_string($policy), 'JobResourcePolicy.php could not be read.');
foreach (['storage-heavy', 'package-heavy', 'unverified-duplicate-cleanup', 'package:file:'] as $fragment) {
    storage_package_expect(str_contains($policy, $fragment), 'Job resource policy is missing ' . $fragment);
}

$duplicateEndpoint = file_get_contents(__DIR__ . '/../unverified-duplicates-action.php');
storage_package_expect(is_string($duplicateEndpoint), 'unverified-duplicates-action.php could not be read.');
storage_package_expect(str_contains($duplicateEndpoint, 'CLEAN_UNVERIFIED_DUPLICATES'), 'Duplicate endpoint does not enqueue the cleanup job.');
storage_package_expect(!str_contains($duplicateEndpoint, 'catalog_unverified_delete_duplicates('), 'Duplicate endpoint still executes cleanup inside HTTP.');
storage_package_expect(str_contains($duplicateEndpoint, "catalog_check_csrf('unverified-files')"), 'Duplicate job mutations lost CSRF protection.');

$duplicateClient = file_get_contents(__DIR__ . '/../assets/unverified-duplicate-cleanup.js');
storage_package_expect(is_string($duplicateClient), 'unverified-duplicate-cleanup.js could not be read.');
foreach (['job_id', 'action: \'cancel\'', 'setTimeout', 'deleted_bytes_text'] as $fragment) {
    storage_package_expect(str_contains($duplicateClient, $fragment), 'Duplicate cleanup client is missing ' . $fragment);
}

$downloadPage = file_get_contents(__DIR__ . '/../download-package.php');
storage_package_expect(is_string($downloadPage), 'download-package.php could not be read.');
storage_package_expect(str_contains($downloadPage, 'generated-package-jobs.js'), 'Package download page does not use the durable job client.');
storage_package_expect(!str_contains($downloadPage, 'modpkg_build('), 'Package archive building still runs in the browser request.');
storage_package_expect(!str_contains($downloadPage, 'set_time_limit'), 'Package download page still disables request time limits.');
storage_package_expect(!str_contains($downloadPage, 'readfile('), 'Package download page still streams a temporary synchronous build.');

$packageEndpoint = file_get_contents(__DIR__ . '/../generated-package-job.php');
storage_package_expect(is_string($packageEndpoint), 'generated-package-job.php could not be read.');
foreach (["catalog_check_csrf('package-generation')", 'access_token_hash', "scan_status=\"verified\"", 'catalog_download_audit_generation_queued'] as $fragment) {
    storage_package_expect(str_contains($packageEndpoint, $fragment), 'Package job endpoint is missing ' . $fragment);
}

$downloadEndpoint = file_get_contents(__DIR__ . '/../generated-package-download.php');
storage_package_expect(is_string($downloadEndpoint), 'generated-package-download.php could not be read.');
foreach (['generated_package_jobs', 'hash_equals', 'GeneratedPackageStore', 'expires_at', 'artifact_size', 'catalog_download_audit_start', 'catalog_download_audit_stream'] as $fragment) {
    storage_package_expect(str_contains($downloadEndpoint, $fragment), 'Generated artifact download is missing ' . $fragment);
}

$packageHandler = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/GeneratedPackageJobHandler.php');
storage_package_expect(is_string($packageHandler), 'GeneratedPackageJobHandler.php could not be read.');
foreach (['modpkg_build_generated_package(', 'publish(', 'checkpoint(', 'delete($publishedPath)', 'delete($temporaryPath)', "'completed'", "'failed'"] as $fragment) {
    storage_package_expect(str_contains($packageHandler, $fragment), 'Package handler is missing lifecycle boundary ' . $fragment);
}

$generatedBuilder = file_get_contents(__DIR__ . '/../lib/GeneratedPackageBuilder.php');
storage_package_expect(is_string($generatedBuilder), 'GeneratedPackageBuilder.php could not be read.');
foreach (['modpkg_generated_version', 'Version=', 'modpkg_write_payload_zip', 'modpkg_write_generated_umod'] as $fragment) {
    storage_package_expect(str_contains($generatedBuilder, $fragment), 'Generated package builder is missing ' . $fragment);
}
storage_package_expect(!str_contains($generatedBuilder, "addFromString('UnrealDB-Mod.json'"), 'Payload ZIP still adds UnrealDB-Mod.json.');
storage_package_expect(!str_contains($generatedBuilder, "addFromString('Readme.txt'"), 'Payload ZIP still adds Readme.txt.');

$downloadAudit = file_get_contents(__DIR__ . '/../lib/DownloadActivity.php');
storage_package_expect(is_string($downloadAudit), 'DownloadActivity.php could not be read.');
foreach (['ue_generated_package_audit', 'ue_download_audit', 'inet_pton', 'catalog_download_audit_stream'] as $fragment) {
    storage_package_expect(str_contains($downloadAudit, $fragment), 'Download audit helper is missing ' . $fragment);
}

$auditMigration = file_get_contents(__DIR__ . '/../migrations/202607310007_generated_package_download_audit.php');
storage_package_expect(is_string($auditMigration), 'Generated package audit migration could not be read.');
foreach (["'version' => '202607310007'", 'CREATE TABLE IF NOT EXISTS ue_generated_package_audit', 'CREATE TABLE IF NOT EXISTS ue_download_audit', 'VARBINARY(16)'] as $fragment) {
    storage_package_expect(str_contains($auditMigration, $fragment), 'Generated package audit migration is missing ' . $fragment);
}

$duplicateHandler = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/UnverifiedDuplicateCleanupJobHandler.php');
storage_package_expect(is_string($duplicateHandler), 'UnverifiedDuplicateCleanupJobHandler.php could not be read.');
storage_package_expect(str_contains($duplicateHandler, 'deleteDuplicates('), 'Duplicate worker no longer delegates to the exact cleanup service.');
storage_package_expect(str_contains($duplicateHandler, 'heartbeatIfDue'), 'Duplicate cleanup progress no longer renews the lease.');

$worker = file_get_contents(__DIR__ . '/../bin/catalog-worker.php');
storage_package_expect(is_string($worker), 'catalog-worker.php could not be read.');
foreach (['GeneratedPackageJobHandler', 'UnverifiedDuplicateCleanupJobHandler'] as $handler) {
    storage_package_expect(str_contains($worker, $handler), 'Worker registration is missing ' . $handler);
}

require_once __DIR__ . '/../src/Infrastructure/Import/CatalogIncomingFileStore.php';

$stagingRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-stage-contract-' . bin2hex(random_bytes(5));
storage_package_expect(mkdir($stagingRoot, 0777, true), 'Could not create durable staging contract directory.');
$sourcePath = $stagingRoot . DIRECTORY_SEPARATOR . 'source.u';
storage_package_expect(file_put_contents($sourcePath, 'durable-staged-package') !== false, 'Could not create durable staging source.');

$incomingStore = new UnrealDb\Catalog\Infrastructure\Import\CatalogIncomingFileStore([
    'storage_path' => $stagingRoot,
]);
$staged = $incomingStore->stageLocalFile($sourcePath, 'ACEv08c.u');
$resolvedPath = $incomingStore->resolve($staged['relative_path']);
$incomingStore->remove($staged['relative_path']);
storage_package_expect(is_file($resolvedPath), 'A handler completion removed its retryable staged source.');

$incomingStore->delete($staged['relative_path']);
storage_package_expect(!is_file($resolvedPath), 'Explicit staged source deletion did not remove the placeholder.');

@unlink($sourcePath);
$incomingDirectory = $stagingRoot . DIRECTORY_SEPARATOR . 'jobs' . DIRECTORY_SEPARATOR . 'incoming';
foreach (glob($incomingDirectory . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $directory) {
    @rmdir($directory);
}
@rmdir($incomingDirectory);
@rmdir(dirname($incomingDirectory));
@rmdir($stagingRoot);

echo "Durable storage and package job contract tests passed.\n";
