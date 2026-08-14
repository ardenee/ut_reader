<?php
/**
 * Quiet/manual architecture regression check for the August 2026 refactor.
 *
 * This is deliberately a source-boundary guard rather than a GitHub Actions
 * workflow. Behavioural queue correctness is covered separately by the real
 * MySQL verifier.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__);
$repoRoot = dirname($root);
$failures = [];

/** @return string */
function sourceFile(string $root, string $relative, array &$failures): string
{
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $source = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($source)) {
        $failures[] = $relative . ': missing/unreadable';
        return '';
    }
    return $source;
}

/**
 * Return PHP source without comments/docblocks so boundary assertions inspect
 * executable code and string literals rather than audit prose describing what
 * a class deliberately does not depend on.
 */
function sourceWithoutComments(string $source): string
{
    $result = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $result .= $token[1];
            continue;
        }
        $result .= $token;
    }
    return $result;
}

function requireMarkers(string $relative, string $source, array $markers, array &$failures): void
{
    foreach ($markers as $marker) {
        if (!str_contains($source, $marker)) {
            $failures[] = $relative . ': missing ' . var_export($marker, true);
        }
    }
}

function forbidMarkers(string $relative, string $source, array $markers, array &$failures): void
{
    foreach ($markers as $marker) {
        if (str_contains($source, $marker)) {
            $failures[] = $relative . ': forbidden ' . var_export($marker, true);
        }
    }
}

foreach ([
    'catalog/upload-bucket.php',
    'catalog/admin.php',
    'catalog/download-bundle.php',
    'catalog/src/Application/Search/CatalogCompactSearchService.php',
    'catalog/src/Presentation/Http/LegacySupportHooks.php',
    'catalog/src/Presentation/Http/CatalogTableSortAssets.php',
    'catalog/src/Infrastructure/Unverified/CatalogUnverifiedActionService.php',
    'catalog/src/Infrastructure/Logging/LegacyUploadFailureLogger.php',
    'UE1/UE1.php',
    'UE1/UnrealPackageReader.php',
    'UE2/UE2.php',
    'UE2/UnrealPackageReader.php',
    'UE3/UE3.php',
    'UE3/TUnrealPackage.php',
    'UE3/UnrealPackageReader.php',
    'UE3/View.php',
    'UE4/UE4.php',
    'UE5/UE5.php',
    'UE5/UnrealPackageReader.php',
    'standalone/StandalonePackageUi.php',
] as $retiredPath) {
    if (is_file($repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $retiredPath))) {
        $failures[] = $retiredPath . ': retired compatibility path must stay removed';
    }
}

foreach ([
    'UE4/UnrealPackageReader.php',
    'UE4/.htaccess',
] as $requiredRootPath) {
    if (!is_file($repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $requiredRootPath))) {
        $failures[] = $requiredRootPath . ': active UE4 reader boundary is missing';
    }
}

$checks = [
    'config.example.php' => [
        'require' => [
            "'UE4' => ['reader' => '../UE4/UnrealPackageReader.php'",
        ],
        'forbid' => [
            "'UE1' => ['reader' => '../UE1/UnrealPackageReader.php'",
            "'UE2' => ['reader' => '../UE2/UnrealPackageReader.php'",
            "'UE3' => ['reader' => '../UE3/UnrealPackageReader.php'",
            "'UE5' => ['reader' => '../UE5/UnrealPackageReader.php'",
        ],
    ],
    'src/Infrastructure/Readers/CatalogReaderResolver.php' => [
        'require' => [
            'CatalogLegacyPackageReader.php',
            'UE3CatalogReader.php',
            "return 'CatalogUE3PackageReader';",
        ],
        'forbid' => [
            "../UE1/UnrealPackageReader.php",
            "../UE2/UnrealPackageReader.php",
            "../UE3/UnrealPackageReader.php",
            "../UE5/UnrealPackageReader.php",
        ],
    ],
    'lib/CatalogSupport.php' => [
        'require' => [
            'CatalogPageResponseTransform::register()',
            'CatalogFileInfoRouteGuard::register()',
            'CatalogFederationInventoryFailureHandler::register()',
        ],
        'forbid' => ['LegacySupportHooks', 'CatalogTableSortAssets'],
    ],
    'src/Presentation/Http/CatalogPageResponseTransform.php' => [
        'require' => ['final class CatalogPageResponseTransform', 'catalog-table-sort.js', 'ob_start('],
        'forbid' => [
            'LegacySupportHooks',
            'request-generate.php',
            'request-status.php',
            'approved-downloads.php',
            'peer-inventory.php',
            'federation/conflicts.php',
            'strtr($html',
        ],
    ],
    'missing.php' => [
        'require' => [
            'federation/inventories.php',
            'federation/requests.php',
            'federation/diagnostics.php?tab=conflicts',
        ],
        'forbid' => [
            'federation/request-generate.php',
            'federation/request-status.php',
            'federation/approved-downloads.php',
            'federation/peer-inventory.php',
            'federation/conflicts.php',
        ],
    ],
    'src/Presentation/Http/CatalogFileInfoRouteGuard.php' => [
        'require' => ['final class CatalogFileInfoRouteGuard', 'unverified-file-details.php'],
        'forbid' => [],
    ],
    'src/Presentation/Http/CatalogFederationInventoryFailureHandler.php' => [
        'require' => [
            'final class CatalogFederationInventoryFailureHandler',
            'set_exception_handler',
            'register_shutdown_function',
        ],
        'forbid' => [],
    ],
    'src/Infrastructure/Logging/CatalogUploadFailureLogger.php' => [
        'require' => ['final class CatalogUploadFailureLogger', 'implements UploadFailureLogger'],
        'forbid' => ['LegacyUploadFailureLogger'],
    ],
    'src/Infrastructure/Composition/CatalogServiceFactory.php' => [
        'require' => ['CatalogUploadFailureLogger', 'new CatalogUploadFailureLogger($this->db)'],
        'forbid' => ['LegacyUploadFailureLogger'],
    ],
    'src/Domain/Jobs/JobResourcePolicy.php' => [
        'require' => ['final class JobResourcePolicy', 'public static function for('],
        'forbid' => ['getenv(', 'PDO', '$_SESSION', 'setLimitResolver', 'error_log('],
    ],
    'src/Application/Search/CatalogSearchService.php' => [
        'require' => ['CatalogSearchRepository', 'private readonly CatalogSearchRepository $repository'],
        'forbid' => ['PDO', 'SELECT ', 'information_schema', '$_SESSION'],
    ],
    'src/Application/Unverified/CatalogUnverifiedActionService.php' => [
        'require' => ['CatalogUnverifiedQueueMutation', 'CatalogUnverifiedImporter'],
        'forbid' => ['PDO', '$_POST', '$_SESSION', 'Infrastructure\\'],
    ],
    'unverified-files-action.php' => [
        'require' => [
            'CatalogUnverifiedActionService',
            'CatalogUnverifiedImporterAdapter',
            'CatalogUnverifiedQueueMutationService',
        ],
        'forbid' => ['Infrastructure\\Unverified\\CatalogUnverifiedActionService'],
    ],
    'src/Application/Upload/ProfiledUploadService.php' => [
        'require' => ['FailedUploadPreserver', '$userId'],
        'forbid' => ['$_SESSION', 'PDO'],
    ],
    'lib/Scanner/CatalogScannerImport.php' => [
        'require' => ['PdoCatalogPackageImporter', 'scannerOptions'],
        'forbid' => ['$_POST', '$_SESSION'],
    ],
    'lib/Scanner/CatalogScannerSupport.php' => [
        'require' => ['?int $uploadedBy = null'],
        'forbid' => ['$_SESSION'],
    ],
    'src/Infrastructure/Persistence/PdoJobClaimer.php' => [
        'require' => ['FOR UPDATE SKIP LOCKED', 'PdoJobAdmissionGuard', 'Root affinity is preference-only'],
        'forbid' => ['requirePreferredRoot', 'SELECT COUNT(*) FROM ue_background_jobs rr'],
    ],
    'src/Infrastructure/Persistence/PdoWorkerOwnership.php' => [
        'require' => ['GET_LOCK', 'IS_USED_LOCK', 'RELEASE_LOCK'],
        'forbid' => [],
    ],
    'src/Infrastructure/Jobs/CatalogDetachedWorker.php' => [
        'require' => [
            'CatalogWorkerRuntimeStateStore',
            'CatalogWorkerProcessLauncher',
            'CatalogWorkerCodeVersion',
        ],
        'forbid' => ['Start-Process -FilePath', 'private function tailFile('],
    ],
    'src/Infrastructure/Metadata/VerifiedFileCompactMetadataFinalizer.php' => [
        'require' => [
            'VerifiedMetadataPublicationState::pending',
            'VerifiedMetadataPublicationState::ready',
            'VerifiedMetadataPublicationState::failed',
        ],
        'forbid' => [],
    ],
    'src/Infrastructure/Persistence/BackgroundJobDisplaySql.php' => [
        'require' => ['operatorStatus', 'operatorStartedAt'],
        'forbid' => [],
    ],
    'src/Infrastructure/Persistence/PdoWorkflowChildStateQuery.php' => [
        'require' => ['final class PdoWorkflowChildStateQuery', 'public function fetch('],
        'forbid' => [],
    ],
    'parsers/EpicUE3PackageReader.php' => [
        'require' => ['readFileRange(', 'fopen($this->path', '$this->physical=\'\''],
        'forbid' => ['substr_replace($logical'],
    ],
];

foreach ($checks as $relative => $rules) {
    $source = sourceFile($root, $relative, $failures);
    if ($source === '') {
        continue;
    }
    requireMarkers($relative, $source, $rules['require'], $failures);
    forbidMarkers($relative, sourceWithoutComments($source), $rules['forbid'], $failures);
}

// Application/Search must remain persistence-free as the directory evolves.
$searchDir = $root . '/src/Application/Search';
foreach (glob($searchDir . '/*.php') ?: [] as $path) {
    $source = (string)file_get_contents($path);
    $relative = 'src/Application/Search/' . basename($path);
    forbidMarkers(
        $relative,
        sourceWithoutComments($source),
        ['use PDO;', 'PDO $', '->prepare(', '->query('],
        $failures
    );
}

// Workflow coordinators that have been migrated must route status counts through
// the shared query even when they retain a private convenience method.
foreach ([
    'src/Infrastructure/Jobs/CatalogFullSyncJobHandler.php',
    'src/Infrastructure/Jobs/CatalogMaintenanceJobHandler.php',
    'src/Infrastructure/Jobs/CatalogDependencyRefreshJobHandler.php',
    'src/Infrastructure/Jobs/CatalogProjectionReconciliationJobHandler.php',
    'src/Infrastructure/Jobs/CatalogCrossGameCopyBatchJobHandler.php',
    'src/Infrastructure/Jobs/CatalogUnverifiedGameMatchRefreshJobHandler.php',
    'src/Infrastructure/Jobs/CatalogStorageMaintenanceJobHandler.php',
    'src/Infrastructure/Jobs/UnverifiedDuplicateCleanupJobHandler.php',
    'src/Infrastructure/Jobs/GameBackupImportJobHandler.php',
] as $relative) {
    $source = sourceFile($root, $relative, $failures);
    if ($source !== '') {
        requireMarkers($relative, $source, ['PdoWorkflowChildStateQuery'], $failures);
        forbidMarkers(
            $relative,
            sourceWithoutComments($source),
            ['SELECT status,COUNT(*) c FROM ue_background_jobs WHERE parent_job_id=?'],
            $failures
        );
    }
}

// Syntax-check the files directly changed by this retirement pass. This keeps
// the manual verifier useful on the production PHP version without adding CI.
if (function_exists('proc_open')) {
    $syntaxTargets = [
        'config.example.php',
        'missing.php',
        'lib/CatalogSupport.php',
        'src/Infrastructure/Readers/CatalogReaderResolver.php',
        'src/Infrastructure/Logging/CatalogUploadFailureLogger.php',
        'src/Infrastructure/Composition/CatalogServiceFactory.php',
        'src/Presentation/Http/CatalogPageResponseTransform.php',
        'src/Presentation/Http/CatalogFileInfoRouteGuard.php',
        'src/Presentation/Http/CatalogFederationInventoryFailureHandler.php',
        'unverified-files-action.php',
    ];
    foreach ($syntaxTargets as $relative) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-l', $path],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            $failures[] = $relative . ': could not launch PHP syntax check';
            continue;
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            $failures[] = $relative . ': syntax check failed: ' . trim((string)$stderr . ' ' . (string)$stdout);
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Refactor boundary verification FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Refactor boundary verification passed.\n");
