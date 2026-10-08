#!/usr/bin/env php
<?php
/** Explicit, bounded, read-only UEDB5 Step 8 source-parity diagnostics. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { exit(1); }
$root = dirname(__DIR__);
require_once $root . '/bootstrap.php';
$options = getopt('', ['file-id::','ids-file::','game::']);
$ids = [];
if (isset($options['file-id'])) {
    $ids[] = (int)$options['file-id'];
}
if (isset($options['ids-file'])) {
    $path = (string)$options['ids-file'];
    if (!is_file($path)) { fwrite(STDERR, "IDs file not found.\n"); exit(1); }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if (!ctype_digit($line)) { fwrite(STDERR, "Invalid file ID: $line\n"); exit(1); }
        $ids[] = (int)$line;
    }
}
$ids = array_values(array_unique($ids));
if ($ids === [] || count($ids) > 1000 || min($ids) < 1) {
    fwrite(STDERR, "Specify --file-id=N or --ids-file=PATH (1 to 1000 explicit IDs).\n");
    exit(1);
}
$app = catalog_bootstrap();
$db = $app->db;
$statement = $db->prepare(
    'SELECT f.game_id,g.slug,s.status FROM ue_files f '
    . 'JOIN ue_games g ON g.id=f.game_id '
    . 'LEFT JOIN ue_uedb5_migration_status s ON s.file_id=f.id WHERE f.id=? LIMIT 1'
);
$validator = new \UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MigrationValidator($db, catalog_config());
$expectedGame = trim((string)($options['game'] ?? ''));
$passed = $failed = $skipped = 0;
foreach ($ids as $fileId) {
    $statement->execute([$fileId]);
    $file = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($file) || ($expectedGame !== '' && (string)$file['slug'] !== $expectedGame)) {
        $skipped++;
        echo json_encode(['file_id'=>$fileId,'result'=>'skipped','reason'=>'unknown_file_or_game_mismatch']), PHP_EOL;
        continue;
    }
    $start = microtime(true);
    try {
        $result = $validator->validate($fileId);
        $passed++;
        echo json_encode([
            'file_id'=>$fileId,'game'=>(string)$file['slug'],
            'existing_status'=>(string)($file['status'] ?? ''),
            'result'=>'pass','dependency_ready'=>(bool)$result['ready'],
            'elapsed_s'=>round(microtime(true)-$start,3),
        ], JSON_UNESCAPED_SLASHES), PHP_EOL;
    } catch (Throwable $error) {
        $failed++;
        $reason = $error instanceof \UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ValidationException
            ? $error->reasonCode : 'validator_exception';
        echo json_encode([
            'file_id'=>$fileId,'game'=>(string)$file['slug'],
            'existing_status'=>(string)($file['status'] ?? ''),
            'result'=>'failed','reason'=>$reason,'error'=>$error->getMessage(),
            'elapsed_s'=>round(microtime(true)-$start,3),
        ], JSON_UNESCAPED_SLASHES), PHP_EOL;
    }
}
echo json_encode(['summary'=>['read_only'=>true,'selected'=>count($ids),'passed'=>$passed,'failed'=>$failed,'skipped'=>$skipped]], JSON_UNESCAPED_SLASHES), PHP_EOL;
exit(($failed || $skipped) ? 2 : 0);
