#!/usr/bin/env php
<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies federation worker architecture and failure-path contracts without performing network transfers.
 * Why: Federation worker code spans queue claims, signed HTTP, storage and import recovery; accidental coupling can break rare failure paths.
 * Role: Read-only CLI architecture/regression verifier.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

$catalogRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$repoRoot = dirname($catalogRoot);
require_once $catalogRoot . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationTransferStorage;

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};
$read = static function (string $relative) use ($repoRoot): string {
    $path = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $content = @file_get_contents($path);
    return is_string($content) ? $content : '';
};
$filePath = static function (string $relative) use ($repoRoot): string {
    return $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
};

$record(
    'safe_incoming_filename_contract',
    CatalogFederationTransferStorage::safeName('../bad name?.ut2') === 'bad_name_.ut2'
        && CatalogFederationTransferStorage::safeName('DM-Test.ut2') === 'DM-Test.ut2',
    'incoming filenames must remain basename-only and filesystem-safe'
);

$diagnostics = $read('catalog/federation/diagnostics.php');
$transferWorker = $read('catalog/src/Infrastructure/Federation/CatalogFederationTransferWorker.php');
$importWorker = $read('catalog/src/Infrastructure/Federation/CatalogFederationImportWorker.php');
$packageImport = $read('catalog/src/Infrastructure/Import/CatalogPackageImportService.php');
$transport = $read('catalog/src/Infrastructure/Federation/CatalogFederationTransferClient.php');
$jobStore = $read('catalog/src/Infrastructure/Federation/PdoFederationTransferJobStore.php');
$cron = $read('catalog/federation/cron-worker-streaming.php');
$legacyWorkerFacade = $filePath('catalog/lib/FederationWorker.php');
$legacyStreamingFacade = $filePath('catalog/lib/FederationStreamingWorker.php');
$legacyCatalogImport = $filePath('catalog/lib/CatalogImport.php');

$record(
    'legacy_worker_facades_retired',
    !is_file($legacyWorkerFacade) && !is_file($legacyStreamingFacade),
    'FederationWorker.php and FederationStreamingWorker.php must not return after production/diagnostic callers moved to namespaced workers'
);
$record(
    'diagnostics_uses_namespaced_workers',
    str_contains($diagnostics, 'CatalogFederationTransferWorker')
        && str_contains($diagnostics, 'CatalogFederationImportWorker')
        && str_contains($diagnostics, '$transferWorker->runOne()')
        && str_contains($diagnostics, '$importWorker->runOne()')
        && !str_contains($diagnostics, 'FederationWorker.php')
        && !str_contains($diagnostics, 'FederationStreamingWorker.php')
        && !str_contains($diagnostics, 'federation_worker_run_one_import(')
        && !str_contains($diagnostics, 'federation_streaming_run_one_transfer('),
    'manual diagnostics worker must use the same namespaced transfer/import workers as production'
);
$record(
    'production_cron_uses_namespaced_workers',
    str_contains($cron, 'CatalogFederationTransferWorker')
        && str_contains($cron, 'CatalogFederationImportWorker')
        && !str_contains($cron, 'FederationWorker.php')
        && !str_contains($cron, 'FederationStreamingWorker.php'),
    'production streaming cron must use namespaced workers directly'
);
$record(
    'job_store_owns_claim_and_progress',
    str_contains($jobStore, 'FOR UPDATE')
        && str_contains($jobStore, 'status="queued"')
        && str_contains($jobStore, 'status="downloaded"')
        && str_contains($jobStore, 'bytes_done=?')
        && str_contains($jobStore, 'markDownloaded')
        && str_contains($jobStore, 'markUploaded')
        && str_contains($jobStore, 'markImportResult'),
    'durable federation status/progress SQL must remain centralized'
);
$record(
    'transport_contract_preserved',
    str_contains($transport, 'X-Signature-Algorithm: ')
        && str_contains($transport, 'X-UE-SHA256: ')
        && str_contains($transport, '/api/federation/download-file.php')
        && str_contains($transport, '/api/federation/download-approved-file.php')
        && str_contains($transport, '/api/federation/upload-file.php')
        && str_contains($transport, 'PdoDependencyReadSource::sql')
        && str_contains($transport, '3600')
        && str_contains($transport, '7200'),
    'signed federation endpoints, compact dependency exception and transfer timeouts must remain unchanged'
);
$record(
    'failed_import_staging_uses_namespaced_storage_relative',
    str_contains($importWorker, 'CatalogUnverifiedStagingIndex::storageRelative')
        && !str_contains($importWorker, 'catalog_unverified_storage_relative('),
    'failed federation imports must not call the retired unverified-index helper'
);
$record(
    'transfer_runner_separates_transport_from_import',
    str_contains($transferWorker, 'CatalogFederationTransferClient')
        && str_contains($transferWorker, 'CatalogFederationTransferStorage')
        && str_contains($transferWorker, 'PdoFederationTransferJobStore')
        && !str_contains($transferWorker, 'catalog_import_file(')
        && !str_contains($transferWorker, 'CatalogPackageImportService'),
    'transfer worker should download/upload only; package import belongs to CatalogFederationImportWorker'
);
$record(
    'package_import_uses_namespaced_service',
    str_contains($importWorker, 'CatalogPackageImportService')
        && str_contains($importWorker, '$this->imports->importFile(')
        && str_contains($importWorker, '$this->imports->detectGame(')
        && !str_contains($importWorker, 'catalog_import_file(')
        && !str_contains($importWorker, 'catalog_import_detect_game(')
        && !is_file($legacyCatalogImport),
    'federation import must use CatalogPackageImportService and the retired CatalogImport.php file must remain absent'
);
$record(
    'package_import_contract_preserved',
    str_contains($packageImport, 'SELECT id,original_name FROM ue_files WHERE md5=? AND scan_status="verified" ORDER BY id LIMIT 1')
        && str_contains($packageImport, 'FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 ORDER BY g.id')
        && str_contains($packageImport, 'scanner_scan_uploaded_file(')
        && str_contains($packageImport, "'source_relative_path' => \$sourceRelativePath")
        && str_contains($packageImport, "if (\$status === 'duplicate')")
        && str_contains($packageImport, "\$status = 'duplicate_md5';")
        && str_contains($packageImport, "elseif (\$status === 'alias')")
        && str_contains($packageImport, "\$status = 'verified';"),
    'MD5 duplicate detection, game selection, scanner call and historical status normalization must remain unchanged'
);

$staleHelperFound = false;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($catalogRoot, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $entry) {
    if (!$entry->isFile() || strtolower($entry->getExtension()) !== 'php') {
        continue;
    }
    if ($entry->getPathname() === __FILE__) {
        continue;
    }
    $content = @file_get_contents($entry->getPathname());
    if (is_string($content) && str_contains($content, 'catalog_unverified_storage_relative(')) {
        $staleHelperFound = true;
        break;
    }
}
$record(
    'retired_unverified_storage_helper_absent',
    !$staleHelperFound,
    'catalog_unverified_storage_relative() was retired with CatalogUnverifiedIndex.php'
);

$syntaxFiles = [
    'catalog/federation/cron-worker-streaming.php',
    'catalog/federation/diagnostics.php',
    'catalog/src/Infrastructure/Federation/CatalogFederationImportWorker.php',
    'catalog/src/Infrastructure/Federation/CatalogFederationTransferClient.php',
    'catalog/src/Infrastructure/Federation/CatalogFederationTransferStorage.php',
    'catalog/src/Infrastructure/Federation/CatalogFederationTransferWorker.php',
    'catalog/src/Infrastructure/Federation/PdoFederationTransferJobStore.php',
    'catalog/src/Infrastructure/Import/CatalogPackageImportService.php',
];
foreach ($syntaxFiles as $relative) {
    $path = $filePath($relative);
    $process = proc_open(
        [PHP_BINARY, '-l', $path],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $output = '';
    $exit = 1;
    if (is_resource($process)) {
        $output = trim((string)stream_get_contents($pipes[1]) . ' ' . (string)stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
    }
    $record(
        'php_syntax_' . str_replace(['/', '.php'], ['_', ''], $relative),
        $exit === 0,
        $exit === 0 ? '' : $output
    );
}

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
