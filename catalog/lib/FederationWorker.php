<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Compatibility facade for federation transfer/import worker helpers.
 * Why: Existing cron/diagnostic callers retain stable function names while storage, transport, job persistence and import recovery live under src/.
 * Role: Transitional legacy facade; new code should use the namespaced Federation infrastructure services directly.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/CatalogImport.php';

use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationImportWorker;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationTransferClient;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationTransferStorage;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationTransferWorker;
use UnrealDb\Catalog\Infrastructure\Federation\PdoFederationTransferJobStore;
use UnrealDb\Catalog\Infrastructure\Legacy\LegacyUnverifiedFileStager;

function federation_worker_incoming_dir(array $config): string
{
    return (new CatalogFederationTransferStorage($config))->incomingDirectory();
}

function federation_worker_safe_name(string $name): string
{
    return CatalogFederationTransferStorage::safeName($name);
}

function federation_worker_file_path(array $config, array $file): string
{
    return (new CatalogFederationTransferStorage($config))->verifiedFilePath($file);
}

/** @return list<string> */
function federation_worker_json_headers(PDO $db, array $job, string $url, string $body): array
{
    return (new CatalogFederationTransferClient($db))->jsonHeaders($job, $url, $body);
}

/** @return list<string> */
function federation_worker_upload_headers(
    PDO $db,
    array $job,
    string $url,
    array $file,
    string $sha256,
    int $bytes
): array {
    return (new CatalogFederationTransferClient($db))->uploadHeaders(
        $job,
        $url,
        $file,
        $sha256,
        $bytes
    );
}

function federation_worker_progress_callback(PDO $db, int $jobId): callable
{
    return (new PdoFederationTransferJobStore($db))->progressCallback($jobId);
}

function federation_worker_parent_pull_dependency_exception(PDO $db, array $job): bool
{
    return (new CatalogFederationTransferClient($db))->parentPullDependencyException($job);
}

/** @return array{0:string,1:array<string,mixed>,2:string} */
function federation_worker_download_info(PDO $db, array $job): array
{
    return (new CatalogFederationTransferClient($db))->downloadInfo($job);
}

function federation_worker_run_one_download(PDO $db, array $config, array $job): array
{
    return (new CatalogFederationTransferWorker($db, $config))->download($job);
}

function federation_worker_run_one_upload(PDO $db, array $config, array $job): array
{
    return (new CatalogFederationTransferWorker($db, $config))->upload($job);
}

function federation_worker_run_one_transfer(PDO $db, array $config): array
{
    return (new CatalogFederationTransferWorker($db, $config))->runOne();
}

function federation_worker_resolve_incoming_path(array $config, string $relativePath): string
{
    return (new CatalogFederationTransferStorage($config))->incomingPath($relativePath);
}

function federation_worker_original_name(PDO $db, array $job): string
{
    return (new CatalogFederationImportWorker($db, []))->originalName($job);
}

function federation_worker_game_id_for_profile_engine(PDO $db, string $engineKey): ?int
{
    return (new CatalogFederationImportWorker($db, []))->gameIdForEngine($engineKey);
}

function federation_worker_preferred_game_id(PDO $db, array $job): ?int
{
    return (new CatalogFederationImportWorker($db, []))->preferredGameId($job);
}

function federation_worker_notify_parent(PDO $db, array $job, array $result, string $status): void
{
    (new CatalogFederationImportWorker($db, []))->notifyParent($job, $result, $status);
}

function federation_worker_stage_failed_import(
    PDO $db,
    array $config,
    array $job,
    string $incoming,
    string $originalName,
    ?int $preferredGameId,
    Throwable $error
): ?array {
    if (!is_file($incoming)) {
        return null;
    }
    $importWorker = new CatalogFederationImportWorker($db, $config);
    $queueGameId = $preferredGameId ?? $importWorker->preferredGameId($job);
    if ($queueGameId === null) {
        $detected = catalog_import_detect_game(
            $db,
            (string)pathinfo($originalName, PATHINFO_EXTENSION)
        );
        $queueGameId = $detected ? (int)$detected['id'] : null;
    }
    if ($queueGameId === null) {
        return null;
    }
    $reason = 'Federation import job ' . (int)$job['id']
        . ' failed for ' . $originalName . ': ' . $error->getMessage();
    return (new LegacyUnverifiedFileStager($db, $config))->stageFailedUpload(
        $queueGameId,
        $incoming,
        $originalName,
        $reason,
        null,
        ''
    );
}

function federation_worker_run_one_import(PDO $db, array $config): array
{
    return (new CatalogFederationImportWorker($db, $config))->runOne();
}
