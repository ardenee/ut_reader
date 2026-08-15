#!/usr/bin/env php
<?php
/**
 * Read-only clean-architecture dependency-direction verifier.
 *
 * Application owns use cases and ports. Domain owns pure policy. PDO, SQL,
 * filesystem, parser loading and durable queue persistence live in Infrastructure.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$repoRoot = dirname($root);
$failures = [];
$checks = [];

$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$withoutComments = static function (string $source): string {
    $result = '';
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $result .= is_array($token) ? $token[1] : $token;
    }
    return $result;
};

$phpFiles = static function (string $directory): array {
    if (!is_dir($directory)) {
        return [];
    }
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)
    );
    foreach ($iterator as $item) {
        if ($item instanceof SplFileInfo && $item->isFile() && strtolower($item->getExtension()) === 'php') {
            $files[] = $item->getPathname();
        }
    }
    sort($files, SORT_STRING);
    return $files;
};

$retired = [
    'catalog/src/Application/Dependency/CatalogDependencyReadSource.php',
    'catalog/src/Application/Dependency/CatalogMissingFileListService.php',
    'catalog/src/Application/Dependency/CatalogMissingDetailListService.php',
    'catalog/src/Application/Dependency/CatalogPostImportDependencyQueue.php',
    'catalog/src/Application/Maintenance/CatalogProjectionReconciliationQueue.php',
];
foreach ($retired as $relative) {
    $record(
        'retired:' . $relative,
        !is_file($repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)),
        'PDO/queue pass-through facade must stay retired'
    );
}

$activeInfrastructure = [
    'catalog/src/Infrastructure/Jobs/CatalogPostImportDependencyQueue.php',
    'catalog/src/Infrastructure/Jobs/CatalogProjectionReconciliationQueue.php',
    'catalog/src/Infrastructure/Persistence/PdoDependencyReadSource.php',
    'catalog/src/Infrastructure/Persistence/PdoMissingFileListQuery.php',
    'catalog/src/Infrastructure/Persistence/PdoMissingDetailListQuery.php',
];
foreach ($activeInfrastructure as $relative) {
    $record(
        'active:' . $relative,
        is_file($repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative)),
        'current infrastructure adapter is required'
    );
}

$applicationViolations = [];
foreach ($phpFiles($root . '/src/Application') as $path) {
    $source = $withoutComments((string)file_get_contents($path));
    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
    $markers = [
        'UnrealDb\\Catalog\\Infrastructure\\',
        'use PDO;',
        'use \\PDO;',
        '\\PDO $',
        'PDO $',
    ];
    foreach ($markers as $marker) {
        if (str_contains($source, $marker)) {
            $applicationViolations[] = $relative . ' contains ' . $marker;
        }
    }
}
$record(
    'application_dependency_direction',
    $applicationViolations === [],
    $applicationViolations === []
        ? 'Application contains no PDO or Infrastructure dependencies'
        : implode(' | ', $applicationViolations)
);

$domainViolations = [];
foreach ($phpFiles($root . '/src/Domain') as $path) {
    $source = $withoutComments((string)file_get_contents($path));
    $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
    foreach ([
        'UnrealDb\\Catalog\\Application\\',
        'UnrealDb\\Catalog\\Infrastructure\\',
        'UnrealDb\\Catalog\\Presentation\\',
        'use PDO;',
        '\\PDO',
    ] as $marker) {
        if (str_contains($source, $marker)) {
            $domainViolations[] = $relative . ' contains ' . $marker;
        }
    }
}
$record(
    'domain_dependency_direction',
    $domainViolations === [],
    $domainViolations === []
        ? 'Domain has no outward layer dependencies'
        : implode(' | ', $domainViolations)
);

$read = static function (string $relative) use ($root): string {
    $source = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($source) ? $source : '';
};

$missingPage = $read('missing.php');
$record(
    'missing_page_composes_infrastructure_queries',
    str_contains($missingPage, 'PdoMissingFileListQuery')
        && str_contains($missingPage, 'PdoMissingDetailListQuery')
        && !str_contains($missingPage, 'CatalogMissingFileListService')
        && !str_contains($missingPage, 'CatalogMissingDetailListService'),
    'HTTP entry point must compose concrete read adapters without a fake Application PDO facade'
);

$downloadPage = $read('download-info.php');
$gameMissingPage = $read('game-missing.php');
$gameMissingApi = $read('api/v1/game-missing-counts.php');
$record(
    'dependency_read_source_is_infrastructure_owned',
    str_contains($downloadPage, 'PdoDependencyReadSource::sql(')
        && str_contains($gameMissingPage, 'PdoDependencyReadSource::sql(')
        && str_contains($gameMissingApi, 'PdoDependencyReadSource::sql(')
        && !str_contains($downloadPage . $gameMissingPage . $gameMissingApi, 'CatalogDependencyReadSource'),
    'SQL read-source selection must remain outside Application'
);

$importer = $read('src/Infrastructure/Import/PdoCatalogPackageImporter.php');
$unverifiedRecovery = $read('src/Infrastructure/Unverified/CatalogUnverifiedDependencyRecovery.php');
$record(
    'durable_dependency_queue_is_infrastructure_owned',
    str_contains($importer, 'Infrastructure\\Jobs\\CatalogPostImportDependencyQueue')
        && str_contains($unverifiedRecovery, 'Infrastructure\\Jobs\\CatalogPostImportDependencyQueue'),
    'durable job persistence must not leak into Application'
);

$projectionCallers = [
    'src/Infrastructure/Persistence/PdoPackageAliasRepository.php',
    'src/Infrastructure/Maintenance/CatalogZeroGuidRepairService.php',
    'src/Infrastructure/Maintenance/CatalogFileMaintenanceRemovalService.php',
    'src/Infrastructure/Games/CatalogGameLifecycleService.php',
    'src/Infrastructure/Identity/CatalogSourceIdentityRebuilder.php',
    'src/Infrastructure/Maintenance/CatalogDuplicateRetirementService.php',
    'src/Infrastructure/Maintenance/CatalogFileMaintenanceReimportService.php',
    'src/Infrastructure/Maintenance/CatalogLegacyPackageNormalizationService.php',
];
$projectionViolations = [];
foreach ($projectionCallers as $relative) {
    $source = $read($relative);
    if (!str_contains($source, 'Infrastructure\\Jobs\\CatalogProjectionReconciliationQueue')) {
        $projectionViolations[] = $relative;
    }
}
$record(
    'projection_queue_is_infrastructure_owned',
    $projectionViolations === [],
    $projectionViolations === [] ? '' : implode(', ', $projectionViolations)
);

$syntaxTargets = array_merge(
    ['bin/verify-clean-architecture.php', 'missing.php', 'download-info.php', 'game-missing.php', 'api/v1/game-missing-counts.php'],
    array_map(static fn(string $path): string => substr($path, strlen($root) + 1), $phpFiles($root . '/src/Application')),
    $activeInfrastructure === [] ? [] : array_map(
        static fn(string $relative): string => substr($relative, strlen('catalog/')),
        $activeInfrastructure
    )
);
$syntaxTargets = array_values(array_unique($syntaxTargets));
if (function_exists('proc_open')) {
    $syntaxFailures = [];
    foreach ($syntaxTargets as $relative) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($path)) {
            $syntaxFailures[] = $relative . ' missing';
            continue;
        }
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
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

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
