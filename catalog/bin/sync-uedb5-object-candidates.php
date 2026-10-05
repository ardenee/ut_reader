<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\PdoUedb5ObjectCandidateSynchronizer;

$options = getopt('', [
    'game:', 'apply', 'continuous', 'limit::', 'after-id::', 'progress-every::', 'max-details::'
]);
$slug = trim((string)($options['game'] ?? ''));
if ($slug === '') {
    fwrite(STDERR,
        "Usage: php catalog/bin/sync-uedb5-object-candidates.php --game=ut2003 "
        . "[--apply --continuous] [--limit=500] [--after-id=0]\n"
    );
    exit(1);
}

$app = catalog_bootstrap();
$db = $app->db;
$gameStmt = $db->prepare('SELECT id,name,slug FROM ue_games WHERE slug=? LIMIT 1');
$gameStmt->execute([$slug]);
$game = $gameStmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($game)) { fwrite(STDERR, "Unknown game slug.\n"); exit(1); }

$gameId = (int)$game['id'];
$apply = isset($options['apply']);
$continuous = isset($options['continuous']);
$limit = max(1, min(5000, (int)($options['limit'] ?? 500)));
$cursor = max(0, (int)($options['after-id'] ?? 0));
$progressEvery = max(1, (int)($options['progress-every'] ?? 100));
$maxDetails = max(1, min(100, (int)($options['max-details'] ?? 25)));
$storage = rtrim((string)($app->config['storage_path'] ?? ''), "\\/");
if ($storage === '') { fwrite(STDERR, "Catalog storage path is not configured.\n"); exit(1); }

$sync = new PdoUedb5ObjectCandidateSynchronizer($db, $storage);
$scanned = $mismatched = $repaired = $failed = 0;
$failures = [];
$mismatchDetails = [];

try {
    do {
        $statement = $db->prepare(
            'SELECT file_id FROM ue_uedb5_files WHERE game_id=? AND file_id>? '
            . 'ORDER BY file_id LIMIT ' . $limit
        );
        $statement->execute([$gameId, $cursor]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($rows === []) { break; }

        foreach ($rows as $row) {
            $fileId = (int)$row['file_id'];
            $cursor = $fileId;
            $scanned++;
            try {
                $result = $sync->reconcile($fileId, $apply);
                if (empty($result['matches'])) {
                    $mismatched++;
                    if (!empty($result['repaired'])) { $repaired++; }
                    if (count($mismatchDetails) < $maxDetails) { $mismatchDetails[] = $result; }
                }
                if ($scanned % $progressEvery === 0 || (!$continuous && count($rows) < $limit)) {
                    echo json_encode([
                        'status' => 'progress', 'game' => $slug, 'scanned' => $scanned,
                        'mismatched' => $mismatched, 'repaired' => $repaired,
                        'failed' => $failed, 'last_file_id' => $cursor,
                    ], JSON_UNESCAPED_SLASHES), PHP_EOL;
                }
            } catch (Throwable $error) {
                $failed++;
                if (count($failures) < 50) {
                    $failures[] = ['file_id' => $fileId, 'error' => $error->getMessage()];
                }
                echo json_encode([
                    'status' => 'failed', 'file_id' => $fileId, 'error' => $error->getMessage(),
                ], JSON_UNESCAPED_SLASHES), PHP_EOL;
            }
        }
    } while ($continuous && count($rows) === $limit);

    $remainingStmt = $db->prepare(
        'SELECT COUNT(*) FROM ue_uedb5_files WHERE game_id=? AND file_id>?'
    );
    $remainingStmt->execute([$gameId, $cursor]);
    $remaining = (int)$remainingStmt->fetchColumn();
} catch (Throwable $error) {
    fwrite(STDERR, json_encode(['ok'=>false,'error'=>$error->getMessage()], JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}
echo json_encode([
    'ok' => $failed === 0,
    'summary' => [
        'game' => $game,
        'apply' => $apply,
        'scanned' => $scanned,
        'mismatched' => $mismatched,
        'repaired' => $repaired,
        'failed' => $failed,
        'last_file_id' => $cursor,
        'remaining_scan' => $remaining,
        'mismatch_details' => $mismatchDetails,
        'failures' => $failures,
        'source' => 'authoritative UEDB5 exports/cell_exports sections',
        'writes' => $apply ? ['ue_uedb5_search_keys','ue_uedb5_object_candidates'] : [],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failed === 0 ? 0 : 2);
