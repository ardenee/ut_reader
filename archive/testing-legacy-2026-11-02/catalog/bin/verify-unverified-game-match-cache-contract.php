<?php
/**
 * Static regression contract for background-cached Unverified Files game matching.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [
    'cache migration exists' => [
        $root . '/migrations/202608110001_unverified_game_match_cache.php',
        'ue_unverified_game_match_cache',
    ],
    'page query reads cache' => [
        $root . '/src/Infrastructure/Unverified/PdoUnverifiedFilesPageQuery.php',
        'PdoUnverifiedGameMatchCache',
    ],
    'bucket staging queues cache refresh' => [
        $root . '/src/Infrastructure/Import/CatalogBucketIdentityProcessor.php',
        'enqueueFile($fileId, $uploadedBy)',
    ],
    'refresh job type registered' => [
        $root . '/src/Domain/Jobs/JobType.php',
        'REFRESH_UNVERIFIED_GAME_MATCHES',
    ],
    'refresh jobs have bounded resource class' => [
        $root . '/src/Domain/Jobs/JobResourcePolicy.php',
        "UNVERIFIED_MATCHES = 'unverified-matches'",
    ],
    'manual refresh endpoint queues bucket rebuild' => [
        $root . '/unverified-game-matches-refresh.php',
        'enqueueBucket($userId)',
    ],
    'unverified page exposes refresh button' => [
        $root . '/unverified-files.php',
        'Refresh bucket matches',
    ],
];

$failed = [];
foreach ($checks as $label => [$path, $needle]) {
    $content = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($content) || !str_contains($content, $needle)) {
        $failed[] = $label;
    }
}

$workerFactoryPath = $root . '/src/Infrastructure/Jobs/CatalogJobWorkerFactory.php';
$workerFactoryContent = is_file($workerFactoryPath) ? file_get_contents($workerFactoryPath) : false;
if (
    !is_string($workerFactoryContent)
    || !str_contains($workerFactoryContent, 'JobType::REFRESH_UNVERIFIED_GAME_MATCHES')
    || !str_contains($workerFactoryContent, 'new CatalogUnverifiedGameMatchRefreshJobHandler(')
) {
    $failed[] = 'worker routes refresh job';
}

$pageQuery = $root . '/src/Infrastructure/Unverified/PdoUnverifiedFilesPageQuery.php';
$pageContent = is_file($pageQuery) ? file_get_contents($pageQuery) : false;
if (!is_string($pageContent) || str_contains($pageContent, 'PdoUnverifiedGameMatchQuery')) {
    $failed[] = 'page query must not calculate exact game matches synchronously';
}
if (!is_string($pageContent) || str_contains($pageContent, 'PdoUnverifiedReferenceMatchQuery')) {
    $failed[] = 'page query must not run the old package-reference projection separately';
}

$refreshPath = $root . '/src/Infrastructure/Jobs/CatalogUnverifiedGameMatchRefreshJobHandler.php';
$refreshContent = is_file($refreshPath) ? file_get_contents($refreshPath) : false;
if (!is_string($refreshContent) || str_contains($refreshContent, 'unverified_queue_game_id=0')) {
    $failed[] = 'bucket refresh must include unverified files assigned to physical game queues';
}
if (!is_string($refreshContent) || !str_contains($refreshContent, 'private const WORKFLOW_VERSION = 3;')) {
    $failed[] = 'bucket refresh workflow version must invalidate old queue-zero-only plans';
}

$cachePath = $root . '/src/Infrastructure/Unverified/PdoUnverifiedGameMatchCache.php';
$cacheContent = is_file($cachePath) ? file_get_contents($cachePath) : false;
if (!is_string($cacheContent) || str_contains($cacheContent, 'unverified_queue_game_id=0')) {
    $failed[] = 'bucket cache summary must include unverified files assigned to physical game queues';
}

if ($failed !== []) {
    fwrite(STDERR, "Unverified game-match cache contract FAILED:\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}

echo "Unverified game-match cache contract passed (" . (count($checks) + 6) . " checks).\n";
