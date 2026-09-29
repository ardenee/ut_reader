<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyPackageSummary;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;

$options = getopt('', [
    'apply', 'game-id:', 'after-id::', 'limit::',
    'batch-size::', 'progress-every::',
]);
$apply = array_key_exists('apply', $options);
$gameId = max(0, (int)($options['game-id'] ?? 0));
$afterId = max(0, (int)($options['after-id'] ?? 0));
$limit = max(0, (int)($options['limit'] ?? 0));
$batchSize = max(25, min(2000, (int)($options['batch-size'] ?? 1000)));
$progressEvery = max(1, (int)($options['progress-every'] ?? 1000));
if ($gameId < 1) throw new InvalidArgumentException('Specify --game-id.');
$config = catalog_config();
$db = catalog_db($config);
$game = catalog_one($db, 'SELECT id,name,slug FROM ue_games WHERE id=? LIMIT 1', [$gameId]);
if (!is_array($game)) throw new RuntimeException('Game not found: ' . $gameId);

$formatVersion = BlockedCompressedMetadataContainer::FORMAT_VERSION;
$summaryWriter = new PdoDependencyPackageSummary($db);
if (!$summaryWriter->available()) {
    throw new RuntimeException('Dependency package summary projection is unavailable.');
}

$totals = static function (PDO $db, int $gameId, int $formatVersion): array {
    $links = catalog_one(
        $db,
        'SELECT COUNT(*) dependency_count,'
        . 'COALESCE(SUM(l.status=0),0) missing_count,'
        . 'COALESCE(SUM(l.status=1),0) resolved_count,'
        . 'COALESCE(SUM(l.status=2),0) package_only_count,'
        . 'COALESCE(SUM(l.status=3),0) common_count,'
        . 'COALESCE(SUM(l.status=4),0) unresolved_count '
        . 'FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id '
        . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
        . 'WHERE f.game_id=? AND f.scan_status="verified"',
        [$formatVersion, $gameId]
    ) ?: [];
    $summaries = catalog_one(
        $db,
        'SELECT COALESCE(SUM(dependency_count),0) dependency_count,'
        . 'COALESCE(SUM(missing_count),0) missing_count,'
        . 'COALESCE(SUM(resolved_count),0) resolved_count,'
        . 'COALESCE(SUM(package_only_count),0) package_only_count,'
        . 'COALESCE(SUM(common_count),0) common_count '
        . 'FROM ue_dependency_package_summaries WHERE game_id=?',
        [$gameId]
    ) ?: [];
    $stale = catalog_one(
        $db,
        'SELECT COUNT(DISTINCT s.file_id) stale_files,COUNT(*) stale_rows '
        . 'FROM ue_dependency_package_summaries s '
        . 'LEFT JOIN ue_files f ON f.id=s.file_id AND f.game_id=s.game_id AND f.scan_status="verified" '
        . 'LEFT JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
        . 'WHERE s.game_id=? AND (f.id IS NULL OR m.file_id IS NULL)',
        [$formatVersion, $gameId]
    ) ?: [];
    return ['authoritative_links'=>$links, 'summary_projection'=>$summaries, 'stale_summary_rows'=>$stale];
};

$before = $totals($db, $gameId, $formatVersion);
$eligibleCount = (int)(catalog_one(
    $db,
    'SELECT COUNT(*) eligible_files FROM ue_files f '
    . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
    . 'WHERE f.game_id=? AND f.scan_status="verified" AND f.id>?',
    [$formatVersion, $gameId, $afterId]
)['eligible_files'] ?? 0);

$summaryDependencyCount = (int)($before['summary_projection']['dependency_count'] ?? 0);
if ($apply && $eligibleCount === 0 && $afterId === 0 && $summaryDependencyCount > 0) {
    throw new RuntimeException(
        'Refusing to reconcile: no current-format verified files were found while summary rows still exist.'
    );
}
$selected = 0;
$summaryFiles = 0;
$summaryRows = 0;
$cursor = $afterId;
$lastCompletedId = $afterId;
$failed = 0;
$failures = [];
$started = microtime(true);

fwrite(STDERR, sprintf(
    "Dependency summary reconciliation | apply=%s | game=%d | after_id=%d | limit=%s | eligible=%d\n",
    $apply ? 'yes' : 'no', $gameId, $afterId,
    $limit > 0 ? (string)$limit : 'unlimited', $eligibleCount
));

if ($apply) {
    while (true) {
        $remaining = $limit > 0 ? $limit - $selected : $batchSize;
        if ($limit > 0 && $remaining <= 0) break;
        $take = min($batchSize, $limit > 0 ? $remaining : $batchSize);
        $rows = catalog_all(
            $db,
            'SELECT f.id FROM ue_files f '
            . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
            . 'WHERE f.game_id=? AND f.scan_status="verified" AND f.id>? '
            . 'ORDER BY f.id LIMIT ' . $take,
            [$formatVersion, $gameId, $cursor]
        );
        if ($rows === []) break;
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
        try {
            $result = $summaryWriter->rebuildFiles($ids);
            $summaryFiles += (int)($result['files'] ?? 0);
            $summaryRows += (int)($result['summary_rows'] ?? 0);
            $selected += count($ids);
            $cursor = max($ids);
            $lastCompletedId = $cursor;
        } catch (Throwable $error) {
            $failed++;
            $failures[] = [
                'phase'=>'rebuild_summaries',
                'after_id'=>$cursor,
                'error'=>get_class($error).': '.$error->getMessage(),
            ];
            break;
        }

        if (($selected % $progressEvery) === 0 || count($ids) < $take) {
            $elapsed = max(0.001, microtime(true) - $started);
            fwrite(STDERR, sprintf(
                "selected=%d summary_files=%d summary_rows=%d failed=%d last_id=%d rate=%.1f files/s\n",
                $selected, $summaryFiles, $summaryRows, $failed, $lastCompletedId, $selected / $elapsed
            ));
        }
        if ($limit > 0 && $selected >= $limit) break;
    }
}
$staleRowsDeleted = 0;
$gameStatsRebuilt = 0;
$gameStatsFailed = 0;
$finalized = $apply && $limit === 0 && $failed === 0;
if ($finalized) {
    try {
        $delete = $db->prepare(
            'DELETE s FROM ue_dependency_package_summaries s '
            . 'LEFT JOIN ue_files f ON f.id=s.file_id AND f.game_id=s.game_id AND f.scan_status="verified" '
            . 'LEFT JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
            . 'WHERE s.game_id=? AND (f.id IS NULL OR m.file_id IS NULL)'
        );
        $delete->execute([$formatVersion, $gameId]);
        $staleRowsDeleted = max(0, $delete->rowCount());
    } catch (Throwable $error) {
        $failed++;
        $failures[] = ['phase'=>'delete_stale_summaries','error'=>get_class($error).': '.$error->getMessage()];
        $finalized = false;
    }
}

if ($finalized) {
    try {
        $gameStatsRebuilt = (new PdoGameCatalogStats($db))->rebuildGame($gameId, 15) !== null ? 1 : 0;
        if ($gameStatsRebuilt === 0) $gameStatsFailed = 1;
    } catch (Throwable $error) {
        $gameStatsFailed = 1;
        $failures[] = ['phase'=>'game_stats','error'=>get_class($error).': '.$error->getMessage()];
    }
}
$after = $totals($db, $gameId, $formatVersion);
$authoritativeMissing = (int)($after['authoritative_links']['missing_count'] ?? 0);
$summaryMissing = (int)($after['summary_projection']['missing_count'] ?? 0);
$parity = $authoritativeMissing === $summaryMissing;

$result = [
    'ok'=>$failed === 0 && $gameStatsFailed === 0,
    'apply'=>$apply,
    'game'=>$game,
    'metadata_format_version'=>$formatVersion,
    'after_id'=>$afterId,
    'resume_after_id'=>$lastCompletedId,
    'limit'=>$limit,
    'eligible_files_after_cursor'=>$eligibleCount,
    'selected_files'=>$selected,
    'summary_files_rebuilt'=>$summaryFiles,
    'summary_rows_written'=>$summaryRows,
    'stale_summary_rows_deleted'=>$staleRowsDeleted,
    'finalized'=>$finalized,
    'game_stats_rebuilt'=>$gameStatsRebuilt,
    'game_stats_failed'=>$gameStatsFailed,
    'missing_count_parity'=>$parity,
    'before'=>$before,
    'after'=>$after,
    'elapsed_seconds'=>round(microtime(true)-$started, 3),
    'failures'=>$failures,
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
exit($result['ok'] ? 0 : 1);
