#!/usr/bin/env php
<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies controller boundaries introduced by the broader August 2026 architecture cleanup.
 * Why: Direct durable-job SQL, detached-worker policy and unverified promotion persistence are easy to reintroduce in feature pages.
 * Role: Read-only CLI architecture/regression verification; never mutates schema or application data.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

$catalogRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $catalogRoot . '/src/Application/Maintenance/LegacyMetadataRuntimeAudit.php';

$checks = [];
$failures = [];

$read = static function (string $relative) use ($catalogRoot): string {
    $path = $catalogRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $content = @file_get_contents($path);
    return is_string($content) ? $content : '';
};

$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$criticalPhp = [
    'bin/verify-controller-boundaries.php',
    'src/Infrastructure/Persistence/PdoBackgroundJobLookupQuery.php',
    'src/Infrastructure/Persistence/PdoBackgroundJobRetryAction.php',
    'src/Infrastructure/Jobs/CatalogQueueWorkerStarter.php',
    'src/Infrastructure/Jobs/CatalogGeneratedPackageJobAccess.php',
    'src/Infrastructure/Jobs/CatalogManualJobRecovery.php',
    'src/Infrastructure/Unverified/CatalogUnverifiedActionService.php',
    'src/Infrastructure/Unverified/CatalogUnverifiedActionSourceResolver.php',
    'src/Infrastructure/Unverified/CatalogUnverifiedMetadataStore.php',
    'src/Infrastructure/Unverified/CatalogUnverifiedCompactMetadataFinalizer.php',
    'src/Infrastructure/Unverified/CatalogUnverifiedDependencyRecovery.php',
    'src/Infrastructure/Unverified/CatalogUnverifiedPromotion.php',
    'src/Infrastructure/Unverified/CatalogUnverifiedImportService.php',
    'unverified-files-action.php',
    'unverified-database-import-action.php',
    'unverified-duplicates-action.php',
    'generated-package-job.php',
    'generated-package-download.php',
    'profiled-upload.php',
    'pak-import.php',
    'game-backups.php',
    'api/v1/job-retry.php',
    'api/v1/job-action.php',
    'api/v1/job-rerun-pak.php',
];

if (!function_exists('proc_open')) {
    $record('php_syntax', false, 'proc_open is unavailable; run php -l manually on the listed files.');
} else {
    $syntaxFailures = [];
    foreach ($criticalPhp as $relative) {
        $path = $catalogRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($path)) {
            $syntaxFailures[] = $relative . ' is missing';
            continue;
        }
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-l', $path],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            $syntaxFailures[] = $relative . ' could not be linted';
            continue;
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            $syntaxFailures[] = $relative . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
        }
    }
    $record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));
}

$workerControllers = [
    'unverified-database-import-action.php',
    'generated-package-job.php',
    'profiled-upload.php',
    'pak-import.php',
    'game-backups.php',
    'api/v1/job-retry.php',
];
foreach ($workerControllers as $relative) {
    $content = $read($relative);
    $record(
        'shared_worker_start:' . $relative,
        $content !== ''
            && str_contains($content, 'CatalogQueueWorkerStarter')
            && !str_contains($content, 'CatalogDetachedWorker'),
        'feature controllers must use the shared queue worker bootstrap'
    );
}

$jobReadControllers = [
    'unverified-duplicates-action.php',
    'generated-package-job.php',
    'generated-package-download.php',
    'game-backups.php',
    'api/v1/job-rerun-pak.php',
];
foreach ($jobReadControllers as $relative) {
    $content = $read($relative);
    $record(
        'no_direct_job_sql:' . $relative,
        $content !== '' && !str_contains($content, 'ue_background_jobs'),
        'feature controllers must delegate durable-job reads to Infrastructure'
    );
}

$lookup = $read('src/Infrastructure/Persistence/PdoBackgroundJobLookupQuery.php');
$record(
    'typed_job_lookup_boundary',
    str_contains($lookup, 'findByIdAndType')
        && str_contains($lookup, 'findByIdAndQueue')
        && str_contains($lookup, 'recentByTypes')
        && str_contains($lookup, 'activeByTypes')
        && str_contains($lookup, 'hasActiveByTypes'),
    'bounded feature job reads must remain centralized'
);

$starter = $read('src/Infrastructure/Jobs/CatalogQueueWorkerStarter.php');
$record(
    'worker_bootstrap_boundary',
    str_contains($starter, 'CatalogOrphanedJobRecovery')
        && str_contains($starter, 'CatalogWorkerPoolReconciler')
        && !str_contains($starter, 'ue_background_jobs'),
    'feature worker bootstrap must reuse authoritative recovery/reconciliation'
);

$retryEndpoint = $read('api/v1/job-retry.php');
$retryAction = $read('src/Infrastructure/Persistence/PdoBackgroundJobRetryAction.php');
$record(
    'exact_retry_boundary',
    str_contains($retryEndpoint, 'PdoBackgroundJobRetryAction')
        && str_contains($retryEndpoint, 'CatalogQueueWorkerStarter')
        && !str_contains($retryEndpoint, 'ue_background_jobs')
        && str_contains($retryAction, 'status IN ("cancelled","failed","dead_letter")')
        && !str_contains($retryAction, 'display_status'),
    'compatibility retry must preserve its narrower terminal-status contract'
);

$jobAction = $read('api/v1/job-action.php');
$manualRecovery = $read('src/Infrastructure/Jobs/CatalogManualJobRecovery.php');
$record(
    'manual_recovery_boundary',
    str_contains($jobAction, 'CatalogManualJobRecovery')
        && !str_contains($jobAction, 'ue_background_jobs')
        && str_contains($manualRecovery, 'attempts=GREATEST(attempts-1,0)')
        && str_contains($manualRecovery, 'recoverExpiredLeases'),
    'administrator recovery must remain a free retry and stay distinct from automatic orphan recovery'
);

$generatedAccess = $read('src/Infrastructure/Jobs/CatalogGeneratedPackageJobAccess.php');
$record(
    'generated_package_access_boundary',
    str_contains($generatedAccess, 'PdoBackgroundJobLookupQuery')
        && str_contains($generatedAccess, 'findAuthorized')
        && str_contains($generatedAccess, 'isAuthorized')
        && str_contains($generatedAccess, "hash('sha256', \$token)"),
    'generated package endpoints must share the existing browser-token hash contract'
);

$unverifiedController = $read('unverified-files-action.php');
$unverifiedAction = $read('src/Infrastructure/Unverified/CatalogUnverifiedActionService.php');
$unverifiedImport = $read('src/Infrastructure/Unverified/CatalogUnverifiedImportService.php');
$unverifiedPromotion = $read('src/Infrastructure/Unverified/CatalogUnverifiedPromotion.php');
$unverifiedRecovery = $read('src/Infrastructure/Unverified/CatalogUnverifiedDependencyRecovery.php');
$unverifiedControllerLegacy = \UnrealDb\Catalog\Application\Maintenance\LegacyMetadataRuntimeAudit::retiredMetadataReferences($unverifiedController);
$unverifiedRecoveryLegacy = \UnrealDb\Catalog\Application\Maintenance\LegacyMetadataRuntimeAudit::retiredMetadataReferences($unverifiedRecovery);
$record(
    'unverified_promotion_boundary',
    $unverifiedController !== ''
        && str_contains($unverifiedController, 'CatalogUnverifiedActionSourceResolver')
        && str_contains($unverifiedController, 'CatalogUnverifiedActionService')
        && !str_contains($unverifiedController, 'CatalogUnverifiedImportService')
        && !str_contains($unverifiedController, 'beginTransaction()')
        && !str_contains($unverifiedController, 'md5_file(')
        && $unverifiedControllerLegacy === []
        && str_contains($unverifiedAction, 'CatalogUnverifiedImportService')
        && str_contains($unverifiedAction, 'CatalogUnverifiedQueueMutationService')
        && str_contains($unverifiedImport, 'CatalogUnverifiedPromotion')
        && str_contains($unverifiedImport, 'CatalogUnverifiedDependencyRecovery')
        && str_contains($unverifiedPromotion, 'beginTransaction()')
        && str_contains($unverifiedPromotion, 'packageIdentity')
        && str_contains($unverifiedPromotion, 'CatalogUnverifiedCompactMetadataFinalizer')
        && str_contains($unverifiedRecovery, 'CatalogUnverifiedMetadataStore')
        && str_contains($unverifiedRecovery, 'CatalogUnverifiedCompactMetadataFinalizer')
        && $unverifiedRecoveryLegacy === [],
    'HTTP action must delegate through action/import services; promotion/recovery must use compressed staging and compact publication only'
);

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
