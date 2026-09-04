#!/usr/bin/env php
<?php
/**
 * Inspect or safely reset the ue_terms AUTO_INCREMENT high-water mark.
 *
 * Duplicate-heavy historical INSERT IGNORE term priming could consume IDs without
 * creating rows. This tool resets the next value to MAX(id)+1 when UINT32 headroom
 * still exists. It never changes the term schema or existing IDs.
 *
 * Usage:
 *   php catalog/bin/repair-ue-terms-auto-increment.php
 *   php catalog/bin/repair-ue-terms-auto-increment.php --apply
 *   php catalog/bin/repair-ue-terms-auto-increment.php --apply --force
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/lib/CatalogSupport.php';

$options = getopt('', ['apply', 'force']);
$apply = array_key_exists('apply', $options);
$force = array_key_exists('force', $options);

$config = catalog_config();
$db = catalog_db($config);
$queueName = trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog';
$uint32Max = 4294967295;

$stats = $db->query(
    'SELECT COUNT(*) row_count,COALESCE(MAX(id),0) max_id FROM ue_terms'
)->fetch(PDO::FETCH_ASSOC) ?: [];
$rowCount = max(0, (int)($stats['row_count'] ?? 0));
$maxId = max(0, (int)($stats['max_id'] ?? 0));

$autoStatement = $db->prepare(
    'SELECT AUTO_INCREMENT FROM information_schema.TABLES '
    . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_terms" LIMIT 1'
);
$autoStatement->execute();
$currentAuto = max(0, (int)($autoStatement->fetchColumn() ?: 0));
$nextId = $maxId + 1;
$headroom = max(0, $uint32Max - $maxId);

$running = $db->prepare(
    'SELECT COUNT(*) FROM ue_background_jobs WHERE queue_name=? AND status="running"'
);
$running->execute([$queueName]);
$runningJobs = max(0, (int)$running->fetchColumn());

$result = [
    'ok' => true,
    'applied' => false,
    'queue' => $queueName,
    'running_jobs' => $runningJobs,
    'ue_terms_rows' => $rowCount,
    'ue_terms_max_id' => $maxId,
    'ue_terms_auto_increment' => $currentAuto,
    'recommended_auto_increment' => $nextId,
    'uint32_max' => $uint32Max,
    'id_headroom_after_reset' => $headroom,
    'burned_or_reserved_gap' => max(0, $currentAuto - $nextId),
];

if ($nextId > $uint32Max) {
    $result['ok'] = false;
    $result['error'] = 'ue_terms has no UINT32 ID headroom left; migration 202609040001 must widen the term dictionary and all term-reference columns to BIGINT UNSIGNED.';
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(3);
}

if (!$apply) {
    $result['message'] = $currentAuto > $nextId
        ? 'Dry run only. Stop workers, deploy the dictionary fix, then rerun with --apply.'
        : 'Dry run only. AUTO_INCREMENT is not ahead of MAX(id)+1.';
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

if ($runningJobs > 0 && !$force) {
    $result['ok'] = false;
    $result['error'] = 'Refusing AUTO_INCREMENT repair while background jobs are running. Stop workers first or use --force.';
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(4);
}

if ($currentAuto <= $nextId) {
    $result['message'] = 'No AUTO_INCREMENT reset is required.';
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$db->exec('ALTER TABLE ue_terms AUTO_INCREMENT=' . $nextId);
$autoStatement->execute();
$after = max(0, (int)($autoStatement->fetchColumn() ?: 0));

$result['applied'] = true;
$result['ue_terms_auto_increment_after'] = $after;
$result['ok'] = $after >= $nextId && $after < $currentAuto;
$result['message'] = $result['ok']
    ? 'ue_terms AUTO_INCREMENT reset to the live dictionary high-water mark.'
    : 'ALTER completed, but the resulting AUTO_INCREMENT was not lower than the previous high-water mark.';

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($result['ok'] ? 0 : 5);
