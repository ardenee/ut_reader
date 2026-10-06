#!/usr/bin/env php
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap.php';
require_once $root . '/lib/CatalogUE4ParserProfile.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameSourceMigrationService;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
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
$storage = rtrim((string)($config['storage_path'] ?? ''), "\\/");
if ($storage === '') {
    fwrite(STDERR, "Catalog storage_path is required.\n");
    exit(2);
}

$game = $db->query("SELECT id FROM ue_games WHERE slug='ut4' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if (!is_array($game)) {
    fwrite(STDERR, "UT4 game registration was not found.\n");
    exit(2);
}
$gameId = (int)$game['id'];
$legacyPolicy = 'ue4-4.27.2-release-classic-package';
$gateVersions = [325,335,364,383,443,458,484,503,506,507,509,510];
$gateSql = implode(',', array_map('intval', $gateVersions));

$sql = 'SELECT v.file_id,v.source_policy,f.package_version,f.licensee_version '
    . 'FROM ue_uedb5_files v JOIN ue_files f ON f.id=v.file_id AND f.game_id=v.game_id '
    . 'WHERE v.game_id=? AND v.source_policy=? AND f.licensee_version=0 '
    . 'AND (f.package_version IN (' . $gateSql . ') OR f.package_version=511) '
    . 'AND v.file_id>? ';
$args = [$gameId, $legacyPolicy, $afterId];
if ($fileId > 0) {
    $sql .= 'AND v.file_id=? ';
    $args[] = $fileId;
}
$sql .= 'ORDER BY v.file_id LIMIT ' . $limit;
$stmt = $db->prepare($sql);
$stmt->execute($args);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$nextAfterId = $rows !== [] ? (int)$rows[array_key_last($rows)]['file_id'] : $afterId;

$metadata = new Uedb5MetadataReader($storage);
$service = new Uedb5GameSourceMigrationService($db, $config);
$selected = [];
$skippedExplicitV511 = [];
$blocked = [];

foreach ($rows as $row) {
    $id = (int)$row['file_id'];
    $version = (int)$row['package_version'];
    $reason = null;

    if (in_array($version, $gateVersions, true)) {
        $reason = 'corrected_reader_gate';
    } elseif ($version === 511) {
        try {
            $summary = $metadata->page($gameId, $id, 'summary', 0, 1)[0] ?? null;
            if (!is_array($summary)) {
                throw new RuntimeException('missing_uedb5_summary');
            }
        } catch (Throwable $e) {
            $blocked[$id] = 'staged_container_unavailable: ' . $e->getMessage();
            continue;
        }
        $profile = (array)($summary['parser_profile'] ?? []);
        $assumed = (int)($profile['assumed_unversioned_parser_version'] ?? 0);
        $effective = (int)($summary['package_version'] ?? 0);
        if (!empty($summary['unversioned']) && ($assumed !== 510 || $effective !== 510)) {
            $reason = 'old_unversioned_assumption';
        } elseif (empty($summary['unversioned'])) {
            $skippedExplicitV511[] = $id;
            continue;
        } else {
            // Already source-correct unversioned staged metadata under a legacy
            // registration is not reparsed again by this tool.
            continue;
        }
    }

    if ($reason === null) {
        continue;
    }

    try {
        $out = $service->runFile($gameId, $id, $apply);
        $newPolicy = (string)($out['result']['source_policy'] ?? '');
        if ($newPolicy !== Uedb5Ut4SnapshotBuilder::SOURCE_POLICY) {
            throw new RuntimeException('Pass-1 result did not produce the UT4 clean-master source policy.');
        }
        $selected[] = [
            'file_id' => $id,
            'reason' => $reason,
            'source_policy' => $newPolicy,
            'applied' => $apply,
        ];
    } catch (Throwable $e) {
        $blocked[$id] = $e->getMessage();
    }
}

$result = [
    'ok' => $blocked === [],
    'apply' => $apply,
    'read_only' => !$apply,
    'game_id' => $gameId,
    'legacy_source_policy' => $legacyPolicy,
    'target_source_policy' => Uedb5Ut4SnapshotBuilder::SOURCE_POLICY,
    'reader_gate_versions' => $gateVersions,
    'slice_row_count' => count($rows),
    'selected_pass1_count' => count($selected),
    'skipped_explicit_v511_count' => count($skippedExplicitV511),
    'blocked_count' => count($blocked),
    'after_id' => $afterId,
    'limit' => $limit,
    'next_after_id' => $nextAfterId,
];
if (!isset($options['summary'])) {
    $result['selected'] = $selected;
    $result['skipped_explicit_v511_file_ids'] = $skippedExplicitV511;
    $result['blocked'] = $blocked;
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($result['ok'] ? 0 : 2);
