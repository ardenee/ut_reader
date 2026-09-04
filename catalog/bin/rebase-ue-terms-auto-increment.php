#!/usr/bin/env php
<?php
/**
 * Rebuild the already-dense ue_terms dictionary with a fresh InnoDB
 * AUTO_INCREMENT counter after sparse-ID compaction.
 *
 * MySQL 8 persists the highest AUTO_INCREMENT counter that was ever allocated.
 * The compacted staging table created with CREATE TABLE ... LIKE can inherit that
 * historical counter even though its live IDs are dense. This utility creates a
 * fresh schema without the inherited AUTO_INCREMENT table option, copies the
 * dense dictionary, verifies the next allocator value, and swaps it atomically.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

set_time_limit(0);

$root = dirname(__DIR__);
require_once $root . '/lib/CatalogSupport.php';

$config = catalog_config();
$db = catalog_db($config);

if (!in_array('--offline-confirmed', $argv, true)) {
    throw new RuntimeException(
        'This operation requires the site and workers to remain offline. '
        . 'Stop Apache/public writes and Background Jobs workers, then rerun with --offline-confirmed.'
    );
}

$runningJobs = max(0, (int)($db->query(
    'SELECT COUNT(*) FROM ue_background_jobs WHERE status="running"'
)->fetchColumn() ?: 0));
if ($runningJobs > 0) {
    throw new RuntimeException(
        'Refusing ue_terms AUTO_INCREMENT rebase while '
        . $runningJobs . ' Background Job(s) are running.'
    );
}

$tableExists = static function (PDO $db, string $table): bool {
    $statement = $db->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
    );
    $statement->execute([$table]);
    return (int)$statement->fetchColumn() > 0;
};

$tableCount = static function (PDO $db, string $table): int {
    return max(0, (int)($db->query('SELECT COUNT(*) FROM ' . $table)->fetchColumn() ?: 0));
};

$tableAutoIncrement = static function (PDO $db, string $table): int {
    $statement = $db->prepare(
        'SELECT AUTO_INCREMENT FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1'
    );
    $statement->execute([$table]);
    return max(0, (int)($statement->fetchColumn() ?: 0));
};

$stateTable = 'ue_term_id_compaction_state';
$rebaseTable = 'ue_terms_rebased';
$backupTable = 'ue_terms_pre_auto_increment_rebase';

if (!$tableExists($db, $stateTable)) {
    throw new RuntimeException('Term-ID compaction state table is missing.');
}
$state = $db->query(
    'SELECT phase,term_count FROM ' . $stateTable . ' WHERE id=1 LIMIT 1'
)->fetch(PDO::FETCH_ASSOC);
if (!is_array($state)
    || !in_array((string)($state['phase'] ?? ''), ['swapped', 'verified'], true)) {
    throw new RuntimeException(
        'AUTO_INCREMENT rebase requires term-ID compaction phase swapped or verified.'
    );
}

$termCount = max(0, (int)($state['term_count'] ?? 0));
$stats = $db->query(
    'SELECT COUNT(*) row_count,COALESCE(MIN(id),0) min_id,COALESCE(MAX(id),0) max_id '
    . 'FROM ue_terms'
)->fetch(PDO::FETCH_ASSOC) ?: [];
$currentCount = max(0, (int)($stats['row_count'] ?? 0));
$currentMin = max(0, (int)($stats['min_id'] ?? 0));
$currentMax = max(0, (int)($stats['max_id'] ?? 0));
if ($termCount < 1
    || $currentCount !== $termCount
    || $currentMin !== 1
    || $currentMax !== $termCount) {
    throw new RuntimeException(
        'Refusing AUTO_INCREMENT rebase because active ue_terms is not the expected dense dictionary: '
        . 'term_count=' . $termCount
        . ', rows=' . $currentCount
        . ', min_id=' . $currentMin
        . ', max_id=' . $currentMax . '.'
    );
}

$expectedAutoIncrement = $termCount + 1;
$currentAutoIncrement = $tableAutoIncrement($db, 'ue_terms');
if ($currentAutoIncrement === $expectedAutoIncrement) {
    echo json_encode([
        'ok' => true,
        'changed' => false,
        'term_count' => $termCount,
        'auto_increment' => $currentAutoIncrement,
        'message' => 'ue_terms AUTO_INCREMENT is already the dense next ID.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$foreignKeys = max(0, (int)($db->query(
    'SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE '
    . 'WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME="ue_terms"'
)->fetchColumn() ?: 0));
if ($foreignKeys > 0) {
    throw new RuntimeException(
        'Unexpected foreign key(s) reference ue_terms; refusing allocator rebase.'
    );
}

if ($tableExists($db, $backupTable)) {
    throw new RuntimeException(
        $backupTable . ' already exists; inspect the previous allocator rebase before continuing.'
    );
}
if ($tableExists($db, $rebaseTable)) {
    $db->exec('DROP TABLE ' . $rebaseTable);
}

$allocatedBytes = max(0, (int)($db->query(
    'SELECT COALESCE(DATA_LENGTH,0)+COALESCE(INDEX_LENGTH,0) '
    . 'FROM information_schema.TABLES '
    . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_terms"'
)->fetchColumn() ?: 0));
$dataDir = (string)($db->query('SELECT @@datadir')->fetchColumn() ?: '');
$freeBytesRaw = $dataDir !== '' ? @disk_free_space($dataDir) : false;
$freeBytes = is_int($freeBytesRaw) || is_float($freeBytesRaw)
    ? (int)$freeBytesRaw
    : null;
$minimumFreeBytes = max(
    8 * 1024 * 1024 * 1024,
    $allocatedBytes + (2 * 1024 * 1024 * 1024)
);
if (is_int($freeBytes) && $freeBytes < $minimumFreeBytes) {
    throw new RuntimeException(
        'Insufficient MySQL data-volume free space for ue_terms allocator rebase: free='
        . $freeBytes . ', required_at_least=' . $minimumFreeBytes . '.'
    );
}

$showCreate = $db->query('SHOW CREATE TABLE ue_terms')->fetch(PDO::FETCH_NUM);
$createSql = is_array($showCreate) ? (string)($showCreate[1] ?? '') : '';
$createSql = preg_replace(
    '/^CREATE TABLE `ue_terms`/i',
    'CREATE TABLE `' . $rebaseTable . '`',
    $createSql,
    1
);
$createSql = preg_replace('/\sAUTO_INCREMENT=\d+\b/i', '', (string)$createSql);
if (!is_string($createSql)
    || $createSql === ''
    || !str_contains($createSql, 'CREATE TABLE')) {
    throw new RuntimeException('Could not derive a fresh ue_terms table definition.');
}

$db->exec($createSql);
$db->exec(
    'INSERT INTO ' . $rebaseTable
    . '(id,value_hash,value_length,value_prefix,is_overflow) '
    . 'SELECT id,value_hash,value_length,value_prefix,is_overflow '
    . 'FROM ue_terms ORDER BY id'
);

$rebasedCount = $tableCount($db, $rebaseTable);
$rebasedMax = max(0, (int)($db->query(
    'SELECT COALESCE(MAX(id),0) FROM ' . $rebaseTable
)->fetchColumn() ?: 0));
$rebasedAutoIncrement = $tableAutoIncrement($db, $rebaseTable);
if ($rebasedCount !== $termCount
    || $rebasedMax !== $termCount
    || $rebasedAutoIncrement !== $expectedAutoIncrement) {
    $db->exec('DROP TABLE ' . $rebaseTable);
    throw new RuntimeException(
        'Fresh ue_terms allocator table did not validate: rows=' . $rebasedCount
        . ', max_id=' . $rebasedMax
        . ', auto_increment=' . $rebasedAutoIncrement
        . ', expected_auto_increment=' . $expectedAutoIncrement . '.'
    );
}

$db->exec(
    'RENAME TABLE ue_terms TO ' . $backupTable
    . ', ' . $rebaseTable . ' TO ue_terms'
);

$activeAutoIncrement = $tableAutoIncrement($db, 'ue_terms');
if ($activeAutoIncrement !== $expectedAutoIncrement) {
    $db->exec(
        'RENAME TABLE ue_terms TO ' . $rebaseTable
        . ', ' . $backupTable . ' TO ue_terms'
    );
    throw new RuntimeException(
        'Allocator rebase swap did not preserve the expected AUTO_INCREMENT; original active table restored.'
    );
}

echo json_encode([
    'ok' => true,
    'changed' => true,
    'term_count' => $termCount,
    'auto_increment_before' => $currentAutoIncrement,
    'auto_increment_after' => $activeAutoIncrement,
    'retained_backup_table' => $backupTable,
    'mysql_datadir' => [
        'path' => $dataDir,
        'free_bytes_before' => $freeBytes,
    ],
    'next' => 'Run: php catalog/bin/compact-ue-term-ids.php verify',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
