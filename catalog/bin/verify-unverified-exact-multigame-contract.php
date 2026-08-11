<?php
/**
 * Static regression contract for exact dependency evidence, multi-game import and cross-game dependency repair.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [
    'unverified page uses detailed per-file game matches' => [
        $root . '/unverified-files.php',
        '$gameMatches[(int)$item[\'id\']]'
    ],
    'unverified page exposes all exact compatible target' => [
        $root . '/unverified-files.php',
        'All exact compatible games'
    ],
    'unverified page explains package-name-only evidence' => [
        $root . '/unverified-files.php',
        'Package-name-only suggestions are never auto-imported.'
    ],
    'page query calculates exact game matches' => [
        $root . '/src/Infrastructure/Unverified/PdoUnverifiedFilesPageQuery.php',
        '$this->gameMatches->bulk('
    ],
    'action endpoint preserves -1 multi-game target sentinel' => [
        $root . '/unverified-files-action.php',
        '$targetGameId !== -1'
    ],
    'action service dispatches exact multi-game import' => [
        $root . '/src/Infrastructure/Unverified/CatalogUnverifiedActionService.php',
        'importExactCompatibleGames($source, $userId, $emit)'
    ],
    'multi-game importer requires exact object matches' => [
        $root . '/src/Infrastructure/Unverified/CatalogUnverifiedImportService.php',
        "(int)(\$match['exact_object_matches'] ?? 0) < 1"
    ],
    'multi-game importer queues secondary verified imports' => [
        $root . '/src/Infrastructure/Unverified/CatalogUnverifiedImportService.php',
        'enqueueStaged('
    ],
    'cross-examine query requires missing dependencies' => [
        $root . '/src/Infrastructure/Unverified/PdoGameDependencyCrossExamineQuery.php',
        'd.status="missing"'
    ],
    'cross-examine query verifies exported object paths' => [
        $root . '/src/Infrastructure/Unverified/PdoGameDependencyCrossExamineQuery.php',
        "['required_object_path']"
    ],
    'cross-game copy queues a real profiled import' => [
        $root . '/src/Infrastructure/Unverified/CatalogCrossGamePackageCopyService.php',
        'CatalogProfiledUploadQueue'
    ],
];

$failed = [];
foreach ($checks as $label => [$path, $needle]) {
    $content = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($content) || !str_contains($content, $needle)) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "Exact multi-game contract FAILED:\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}

echo "Exact multi-game contract passed (" . count($checks) . " checks).\n";
