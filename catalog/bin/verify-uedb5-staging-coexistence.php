#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5StagingIsolationContract;

$options = getopt('', ['limit::']);
$limit = max(1, min(5000, (int)($options['limit'] ?? 500)));
$app = catalog_bootstrap(false);
$db = $app->db;
$config = catalog_config();
$storage = rtrim((string)($config['storage_path'] ?? ''), "\\/");
if ($storage === '') { throw new RuntimeException('catalog storage_path is required.'); }

$checks = [];
$failures = [];
$check = static function (string $name, bool $ok, mixed $detail = null) use (&$checks, &$failures): void {
    $checks[$name] = ['ok' => $ok, 'detail' => $detail];
    if (!$ok) { $failures[] = $name; }
};

$liveFormat5 = (int)$db->query(
    'SELECT COUNT(*) FROM ue_file_metadata m JOIN ue_files f ON f.id=m.file_id '
    . 'WHERE f.scan_status="verified" AND m.format_version=5'
)->fetchColumn();
$check('verified_live_registration_has_no_format5_before_cutover', $liveFormat5 === 0, $liveFormat5);

$stagedWithoutV4 = (int)$db->query(
    'SELECT COUNT(*) FROM ue_uedb5_files v '
    . 'LEFT JOIN ue_file_metadata m ON m.file_id=v.file_id AND m.format_version=4 '
    . 'WHERE m.file_id IS NULL'
)->fetchColumn();
$check('every_staged_v5_file_still_has_live_v4_registration', $stagedWithoutV4 === 0, $stagedWithoutV4);

$badV5Version = (int)$db->query(
    'SELECT COUNT(*) FROM ue_uedb5_files WHERE format_version<>5'
)->fetchColumn();
$check('staging_registration_contains_only_format5', $badV5Version === 0, $badV5Version);

$stagedCount = (int)$db->query('SELECT COUNT(*) FROM ue_uedb5_files')->fetchColumn();
$byGame = $db->query(
    'SELECT game_id,COUNT(*) staged_count FROM ue_uedb5_files GROUP BY game_id ORDER BY game_id'
)->fetchAll(PDO::FETCH_ASSOC) ?: [];

$rows = $db->query(
    'SELECT v.file_id,v.game_id FROM ue_uedb5_files v ORDER BY v.file_id LIMIT ' . $limit
)->fetchAll(PDO::FETCH_ASSOC) ?: [];
$missingFiles = [];
foreach ($rows as $row) {
    $fileId = (int)$row['file_id'];
    $gameId = (int)$row['game_id'];
    $paths = Uedb5StagingIsolationContract::assertContainerPathIsolation($storage, $gameId, $fileId);
    if (!is_file($paths['v4']) || !is_file($paths['v5'])) {
        $missingFiles[] = [
            'file_id' => $fileId,
            'game_id' => $gameId,
            'v4_exists' => is_file($paths['v4']),
            'v5_exists' => is_file($paths['v5']),
        ];
    }
}
$check('sampled_staged_files_have_both_v4_and_v5_containers', $missingFiles === [], $missingFiles);

echo json_encode([
    'ok' => $failures === [],
    'staged_count' => $stagedCount,
    'staged_by_game' => $byGame,
    'sampled_files' => count($rows),
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures === [] ? 0 : 1);
