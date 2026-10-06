#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap.php';
require_once $root . '/lib/CatalogUE4ParserProfile.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameSourceMigrationService;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ut4SnapshotBuilder;

$options = getopt('', ['apply','summary','storage-root::','after-id::','limit::','file-id::']);
$apply = array_key_exists('apply', $options);
$afterId = max(0, (int)($options['after-id'] ?? 0));
$limit = max(1, min(1000, (int)($options['limit'] ?? 100)));
$fileId = max(0, (int)($options['file-id'] ?? 0));

$app = catalog_bootstrap();
$db = $app->db;
$config = catalog_config();
$storageOverride = trim((string)($options['storage-root'] ?? ''));
if ($storageOverride !== '') {
    $config['storage_path'] = rtrim($storageOverride, "\\/");
}
if (trim((string)($config['storage_path'] ?? '')) === '') {
    fwrite(STDERR, "Catalog storage_path is required.\n");
    exit(2);
}

$game = $db->query("SELECT id FROM ue_games WHERE slug='ut4' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!is_array($game)) {
    fwrite(STDERR, "UT4 game registration was not found.\n");
    exit(2);
}
$gameId = (int)$game['id'];
$badPolicy = Uedb5Ut4SnapshotBuilder::LEGACY_SOURCE_POLICY_V510;
$targetPolicy = Uedb5Ut4SnapshotBuilder::SOURCE_POLICY;

$sql = 'SELECT v.file_id,f.package_version,f.licensee_version '
    . 'FROM ue_uedb5_files v JOIN ue_files f ON f.id=v.file_id AND f.game_id=v.game_id '
    . 'WHERE v.game_id=? AND v.source_policy=? AND v.file_id>? ';
$args = [$gameId, $badPolicy, $afterId];
if ($fileId > 0) {
    $sql .= 'AND v.file_id=? ';
    $args[] = $fileId;
}
$sql .= 'ORDER BY v.file_id LIMIT ' . $limit;
$stmt = $db->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$nextAfterId = $rows !== [] ? (int)$rows[array_key_last($rows)]['file_id'] : $afterId;

$service = new Uedb5GameSourceMigrationService($db, $config);
$repaired = [];
$blocked = [];

foreach ($rows as $row) {
    $id = (int)$row['file_id'];
    try {
        $out = $service->runFile($gameId, $id, $apply);
        $newPolicy = (string)($out['result']['source_policy'] ?? '');
        if ($newPolicy !== $targetPolicy) {
            throw new RuntimeException(
                'Corrected Pass-1 did not produce canonical UT4 clean-master v511 source policy.'
            );
        }
        $repaired[] = [
            'file_id' => $id,
            'catalog_package_version' => (int)($row['package_version'] ?? 0),
            'catalog_licensee_version' => (int)($row['licensee_version'] ?? 0),
            'source_policy' => $newPolicy,
            'applied' => $apply,
        ];
    } catch (Throwable $e) {
        $blocked[$id] = $e->getMessage();
    }
}

$remainingStmt = $db->prepare(
    'SELECT COUNT(*) FROM ue_uedb5_files WHERE game_id=? AND source_policy=?'
);
$remainingStmt->execute([$gameId, $badPolicy]);
$remainingBadPolicyCount = (int)$remainingStmt->fetchColumn();

$result = [
    'ok' => $blocked === [],
    'apply' => $apply,
    'read_only' => !$apply,
    'game_id' => $gameId,
    'bad_source_policy' => $badPolicy,
    'target_source_policy' => $targetPolicy,
    'slice_row_count' => count($rows),
    'repaired_count' => count($repaired),
    'blocked_count' => count($blocked),
    'remaining_bad_policy_count' => $remainingBadPolicyCount,
    'after_id' => $afterId,
    'limit' => $limit,
    'next_after_id' => $nextAfterId,
];
if (!isset($options['summary'])) {
    $result['repaired'] = $repaired;
    $result['blocked'] = $blocked;
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($result['ok'] ? 0 : 2);
