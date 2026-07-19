<?php
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
foreach (['FileRequestRateLimiter', "catalog_check_csrf('package-generation')", 'access_token_hash', "scan_status=\"verified\""] as $fragment) {
    storage_package_expect(str_contains($packageEndpoint, $fragment), 'Package job endpoint is missing ' . $fragment);
}

$downloadEndpoint = file_get_contents(__DIR__ . '/../generated-package-download.php');
storage_package_expect(is_string($downloadEndpoint), 'generated-package-download.php could not be read.');
foreach (['generated_package_jobs', 'hash_equals', 'GeneratedPackageStore', 'expires_at', 'artifact_size'] as $fragment) {
    storage_package_expect(str_contains($downloadEndpoint, $fragment), 'Generated artifact download is missing ' . $fragment);
}

$packageHandler = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/GeneratedPackageJobHandler.php');
storage_package_expect(is_string($packageHandler), 'GeneratedPackageJobHandler.php could not be read.');
foreach (['modpkg_build(', 'publish(', 'checkpoint(', 'delete($publishedPath)', 'delete($temporaryPath)'] as $fragment) {
    storage_package_expect(str_contains($packageHandler, $fragment), 'Package handler is missing lifecycle boundary ' . $fragment);
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

echo "Durable storage and package job contract tests passed.\n";
