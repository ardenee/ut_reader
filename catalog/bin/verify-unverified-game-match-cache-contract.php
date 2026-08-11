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
    'worker routes refresh job' => [
        $root . '/src/Infrastructure/Jobs/CatalogJobWorkerFactory.php',
        'JobType::REFRESH_UNVERIFIED_GAME_MATCHES => $unverifiedMatchRefresh',
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

$pageQuery = $root . '/src/Infrastructure/Unverified/PdoUnverifiedFilesPageQuery.php';
$pageContent = is_file($pageQuery) ? file_get_contents($pageQuery) : false;
if (!is_string($pageContent) || str_contains($pageContent, 'PdoUnverifiedGameMatchQuery')) {
    $failed[] = 'page query must not calculate exact game matches synchronously';
}
if (!is_string($pageContent) || str_contains($pageContent, 'PdoUnverifiedReferenceMatchQuery')) {
    $failed[] = 'page query must not run the old package-reference projection separately';
}

if ($failed !== []) {
    fwrite(STDERR, "Unverified game-match cache contract FAILED:\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}

echo "Unverified game-match cache contract passed (" . (count($checks) + 2) . " checks).\n";
