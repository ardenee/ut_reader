#!/usr/bin/env php
<?php
/**
 * Read-only maintainability guardrails for the single-host modular monolith.
 *
 * These checks protect high-value boundaries without pretending historical code
 * is already perfect. Two pre-existing Application classes still perform small
 * filesystem probes; those exact calls are baselined explicitly so any added
 * Application filesystem I/O fails this contract.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
};
$phpFiles = static function (string $directory): array {
    if (!is_dir($directory)) return [];
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $item) {
        if ($item instanceof SplFileInfo && $item->isFile() && strtolower($item->getExtension()) === 'php') $files[] = $item->getPathname();
    }
    sort($files, SORT_STRING);
    return $files;
};
$executable = static function (string $source): string {
    $result = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) continue;
        $result .= is_array($token) ? $token[1] : $token;
    }
    return $result;
};
$scan = static function (array $paths, array $markers, array $allowed = []) use ($root, $executable): array {
    $violations = [];
    foreach ($paths as $path) {
        $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
        $source = $executable((string)file_get_contents($path));
        foreach ($markers as $marker) {
            if (!str_contains($source, $marker)) continue;
            if (isset($allowed[$relative]) && in_array($marker, $allowed[$relative], true)) continue;
            $violations[] = $relative . ' contains ' . $marker;
        }
    }
    return $violations;
};
$read = static function (string $relative) use ($root): string {
    $source = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($source) ? $source : '';
};

$applicationFiles = $phpFiles($root . '/src/Application');
$applicationArchitecture = $scan($applicationFiles, [
    'UnrealDb\\Catalog\\Infrastructure\\', 'use PDO;', 'use \\PDO;', '->prepare(', '->query(',
]);
$record(
    'application_has_no_infrastructure_or_sql_dependencies',
    $applicationArchitecture === [],
    $applicationArchitecture === [] ? 'Application remains independent of PDO/Infrastructure.' : implode(' | ', $applicationArchitecture)
);

$filesystemMarkers = [
    'file_get_contents(', 'file_put_contents(', 'fopen(', 'rename(', 'unlink(', 'mkdir(', 'scandir(', 'glob(',
    'is_file(', 'is_dir(', 'filesize(', 'RecursiveDirectoryIterator',
];
$knownApplicationFilesystem = [
    'src/Application/Catalog/CatalogPackageHeaderInspector.php' => ['file_get_contents(', 'is_file('],
    'src/Application/Upload/ProfiledUploadService.php' => ['is_file(', 'filesize('],
];
$applicationFilesystem = $scan($applicationFiles, $filesystemMarkers, $knownApplicationFilesystem);
$record(
    'no_new_application_filesystem_io',
    $applicationFilesystem === [],
    $applicationFilesystem === []
        ? 'Only the explicitly baselined package-header/upload-size probes remain; new Application filesystem I/O is rejected.'
        : implode(' | ', $applicationFilesystem)
);

$legacyPolicy = $read('src/Application/Maintenance/LegacyMetadataRuntimeAudit.php');
$legacyScanner = $read('src/Infrastructure/Maintenance/LegacyMetadataRuntimeScanner.php');
$legacyCli = $read('bin/audit-legacy-runtime-references.php');
$record(
    'legacy_runtime_scan_is_infrastructure_owned',
    $legacyPolicy !== ''
        && $legacyScanner !== ''
        && $legacyCli !== ''
        && !str_contains($legacyPolicy, 'RecursiveDirectoryIterator')
        && !str_contains($legacyPolicy, 'is_dir(')
        && str_contains($legacyScanner, 'RecursiveDirectoryIterator')
        && str_contains($legacyScanner, 'LegacyMetadataRuntimeAudit::retiredTables()')
        && str_contains($legacyCli, 'LegacyMetadataRuntimeScanner::scan('),
    'Source-tree traversal belongs in Infrastructure while Application owns only the retired-table policy.'
);

$domain = $scan($phpFiles($root . '/src/Domain'), [
    'UnrealDb\\Catalog\\Application\\', 'UnrealDb\\Catalog\\Infrastructure\\', 'UnrealDb\\Catalog\\Presentation\\',
    'use PDO;', '\\PDO', 'file_get_contents(', 'file_put_contents(', 'fopen(', 'is_file(', 'filesize(',
]);
$record('domain_has_no_outward_or_io_dependencies', $domain === [], $domain === [] ? 'Domain remains pure policy/data.' : implode(' | ', $domain));

/*
 * Presentation/Ui/CatalogUi.php is intentionally an HTTP-aware compatibility
 * facade: it injects a versioned asset and preserves legacy game-page actions.
 * The reusable component layer underneath it is the render-only boundary.
 */
$uiComponents = $scan($phpFiles($root . '/src/Presentation/Ui/Component'), [
    'use PDO;', '\\PDO', 'catalog_db(', '->prepare(', '->query(', '$_GET', '$_POST', '$_REQUEST', '$_SERVER',
    'file_get_contents(', 'file_put_contents(', 'is_file(', 'is_dir(', 'filesize(',
]);
$record(
    'ui_components_are_render_only',
    $uiComponents === [],
    $uiComponents === [] ? 'Reusable UI components remain escaped render primitives; HTTP compatibility stays in the CatalogUi facade.' : implode(' | ', $uiComponents)
);

$claimerPath = $root . '/src/Infrastructure/Persistence/PdoJobClaimer.php';
$claimer = is_file($claimerPath) ? $executable((string)file_get_contents($claimerPath)) : '';
$record(
    'job_claim_path_remains_bounded_and_lock_aware',
    $claimer !== '' && str_contains($claimer, 'SKIP LOCKED') && preg_match('/\bLIMIT\b/i', $claimer) === 1,
    'The worker hot path must not regress to an unbounded locked queue scan.'
);

$storagePort = $root . '/src/Application/Storage/Contract/PackageStoragePort.php';
$record('package_storage_is_an_explicit_boundary', is_file($storagePort), 'Physical package access must remain isolated behind a stable port.');
$record('operations_are_visible_in_product', is_file($root . '/system-operations.php'), 'Queue/worker/database/storage health must remain available in the admin UI.');

$syntaxTargets = [
    __FILE__,
    $storagePort,
    $root . '/src/Application/Maintenance/LegacyMetadataRuntimeAudit.php',
    $root . '/src/Infrastructure/Maintenance/LegacyMetadataRuntimeScanner.php',
    $root . '/bin/audit-legacy-runtime-references.php',
];
$syntaxFailures = [];
if (function_exists('proc_open')) {
    foreach ($syntaxTargets as $path) {
        if (!is_file($path)) { $syntaxFailures[] = basename($path) . ' missing'; continue; }
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) { $syntaxFailures[] = basename($path) . ' could not be linted'; continue; }
        $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        if (proc_close($process) !== 0) $syntaxFailures[] = basename($path) . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
