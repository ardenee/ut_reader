<?php
declare(strict_types=1);

function review_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__, 2);
$read = static function (string $path) use ($root): string {
    $content = file_get_contents($root . '/' . $path);
    if (!is_string($content)) {
        throw new RuntimeException('Could not read ' . $path);
    }
    return $content;
};

$jobTypes = $read('catalog/src/Domain/Jobs/JobType.php');
foreach (['IMPORT_STAGED_PACKAGE', 'IMPORT_STAGED_PAK', 'RECONCILE_UNVERIFIED_STORAGE', 'PRUNE_STALE_ARTIFACTS'] as $constant) {
    review_expect(str_contains($jobTypes, $constant), 'Missing durable job type ' . $constant);
}

$worker = $read('catalog/bin/catalog-worker.php');
review_expect(str_contains($worker, 'CatalogStagedImportJobHandler'), 'Worker does not register staged imports.');
review_expect(str_contains($worker, 'CatalogStorageMaintenanceJobHandler'), 'Worker does not register storage maintenance.');

$profiled = $read('catalog/profiled-upload.php');
review_expect(str_contains($profiled, 'CatalogIncomingFileStore'), 'Profiled Upload does not use controlled staging.');
review_expect(str_contains($profiled, 'JobType::IMPORT_STAGED_PACKAGE'), 'Profiled Upload does not enqueue package imports.');
review_expect(!str_contains($profiled, 'scanner_scan_uploaded_file('), 'Profiled Upload still scans packages in HTTP.');
review_expect(!str_contains($profiled, 'catalog_pak_archive_extract_to_temp('), 'Profiled Upload still extracts PAKs in HTTP.');

$pak = $read('catalog/pak-import.php');
review_expect(str_contains($pak, 'JobType::IMPORT_STAGED_PAK'), 'PAK Import does not enqueue a worker job.');
review_expect(!str_contains($pak, 'catalog_pak_archive_extract_to_temp('), 'PAK Import still extracts archives in HTTP.');

$search = $read('catalog/src/Application/Search/CatalogSearchService.php');
review_expect(str_contains($search, 'MIN_BROAD_QUERY_LENGTH = 3'), 'Broad search minimum is not three characters.');
review_expect(str_contains($search, 'identityQueries'), 'Indexed identity fast path is missing.');
review_expect(str_contains($search, "['hash_md5', 'md5'"), 'Exact MD5 search is missing.');
review_expect(str_contains($search, "['guid_compact', 'package_guid'"), 'Compact GUID search is missing.');

$index = $read('catalog/index.php');
review_expect(str_contains($index, 'catalog_mfa_verify'), 'Login does not enforce MFA.');
review_expect(str_contains($index, 'catalog_public_search_rate_limit'), 'Public search rate limit is missing.');
review_expect(str_contains($index, 'at least three characters'), 'Search UI does not describe its minimum.');

$remember = $read('catalog/lib/CatalogRememberMe.php');
review_expect(str_contains($remember, 'mfa_enabled_at'), 'Remember-me does not check MFA state.');
review_expect(!str_contains($remember, 'CREATE TABLE IF NOT EXISTS'), 'Remember-me still creates schema at runtime.');

$federationAuth = $read('catalog/lib/FederationAuth.php');
review_expect(str_contains($federationAuth, 'fed_sign_request_ed25519'), 'Ed25519 federation signing is missing.');
review_expect(str_contains($federationAuth, 'TrustedHttpSourceClient::postJson'), 'Federation JSON does not use the pinned HTTP client.');
review_expect(!str_contains($federationAuth, 'file_get_contents($url'), 'Federation JSON still uses URL streams.');

$streaming = $read('catalog/lib/FederationStreamingWorker.php');
review_expect(str_contains($streaming, 'FOR UPDATE'), 'Federation streaming claim is not serialized.');
review_expect(str_contains($streaming, 'federation_worker_run_one_upload'), 'Streaming worker does not use the secure shared upload path.');

$transfer = $read('catalog/lib/TrustedHttpSourceClient.php');
review_expect(str_contains($transfer, "CURLOPT_CUSTOMREQUEST => 'PUT'"), 'Federation upload is not PUT streaming.');
review_expect(str_contains($transfer, 'CURLOPT_RESOLVE'), 'Outbound HTTPS is not DNS-pinned.');
review_expect(str_contains($transfer, 'CURLOPT_FOLLOWLOCATION => false'), 'Outbound HTTPS permits redirects.');

$metrics = $read('catalog/api/v1/metrics.php');
review_expect(str_contains($metrics, 'UNREALDB_METRICS_TOKEN'), 'Metrics bearer authentication is missing.');
review_expect(str_contains($metrics, 'unrealdb_oldest_queued_job_age_seconds'), 'Queue age metric is missing.');

$apache = $read('deploy/docker/apache-vhost.conf');
review_expect(str_contains($apache, 'php_admin_flag display_errors Off'), 'Production PHP error display is not non-overridable.');
review_expect(str_contains($apache, 'Content-Security-Policy'), 'Production CSP header is missing.');

foreach (['202607180007_federation_signing_keys.php', '202607180008_administrator_mfa.php'] as $migration) {
    review_expect(is_file($root . '/catalog/migrations/' . $migration), 'Missing migration ' . $migration);
}
foreach (['deploy/backup/unrealdb-backup.sh', 'deploy/backup/unrealdb-restore.sh', 'deploy/docker/maintenance-loop.sh'] as $script) {
    review_expect(is_file($root . '/' . $script), 'Missing operations script ' . $script);
}
review_expect(!is_file($root . '/.github/workflows/one-time-complete-review-runtime.yml'), 'Temporary one-time workflow remains tracked.');

fwrite(STDOUT, "Full production review completion contracts passed.\n");
