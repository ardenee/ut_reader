#!/usr/bin/env php
<?php
/**
 * Offline, resumable compaction for a sparse/exhausted ue_terms INT UNSIGNED dictionary.
 *
 * The historical writer could burn AUTO_INCREMENT IDs on duplicate INSERT IGNORE
 * attempts. This utility preserves every term value while assigning dense IDs,
 * rekeys every persisted reference in bounded file_id ranges, atomically swaps the
 * compacted dictionary, and leaves the old dictionary + mapping in place until an
 * explicit verified cleanup.
 *
 * Commands:
 *   php catalog/bin/compact-ue-term-ids.php status
 *   php catalog/bin/compact-ue-term-ids.php run --offline-confirmed
 *   php catalog/bin/compact-ue-term-ids.php run --offline-confirmed --file-id-span=5000 --max-chunks=20
 *   php catalog/bin/compact-ue-term-ids.php verify
 *   php catalog/bin/compact-ue-term-ids.php cleanup --offline-confirmed
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
$command = strtolower(trim((string)($argv[1] ?? 'status')));
$offlineConfirmed = in_array('--offline-confirmed', $argv, true);
$fileIdSpan = 5000;
$maxChunks = 0;
foreach ($argv as $argument) {
    if (preg_match('/^--file-id-span=([0-9]+)$/', (string)$argument, $match) === 1) {
        $fileIdSpan = max(1, min(100000, (int)$match[1]));
    } elseif (preg_match('/^--max-chunks=([0-9]+)$/', (string)$argument, $match) === 1) {
        $maxChunks = max(0, (int)$match[1]);
    }
}

$stateTable = 'ue_term_id_compaction_state';
$mapTable = 'ue_term_id_compaction_map';
$newTermsTable = 'ue_terms_compacted';
$backupTermsTable = 'ue_terms_pre_compaction';
$uint32Max = 4294967295;
$minimumFreeBytes = 8 * 1024 * 1024 * 1024;

$referencePhases = [
    'name_lookup' => [
        'table' => 'ue_name_lookup',
        'columns' => ['name_term_id'],
        'next' => 'dependency_links',
    ],
    'dependency_links' => [
        'table' => 'ue_dependency_links',
        'columns' => [
            'required_package_term_id',
            'required_object_term_id',
            'import_class_package_term_id',
            'import_class_name_term_id',
            'import_object_term_id',
            'resolution_source_term_id',
            'resolution_confidence_term_id',
        ],
        'next' => 'export_lookup',
    ],
    'export_lookup' => [
        'table' => 'ue_export_lookup',
        'columns' => ['object_term_id', 'class_term_id', 'local_path_term_id'],
        'next' => 'swap',
    ],
];

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

$state = static function (PDO $db, string $stateTable, callable $tableExists): ?array {
    if (!$tableExists($db, $stateTable)) {
        return null;
    }
    $row = $db->query(
        'SELECT phase,cursor_file_id,term_count,original_max_id,started_at,updated_at '
        . 'FROM ' . $stateTable . ' WHERE id=1 LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
};

$runningJobs = static function (PDO $db): int {
    return max(0, (int)($db->query(
        'SELECT COUNT(*) FROM ue_background_jobs WHERE status="running"'
    )->fetchColumn() ?: 0));
};

$diskState = static function (PDO $db): array {
    $dataDir = (string)($db->query('SELECT @@datadir')->fetchColumn() ?: '');
    $free = $dataDir !== '' ? @disk_free_space($dataDir) : false;
    return [
        'path' => $dataDir,
        'free_bytes' => is_int($free) || is_float($free) ? (int)$free : null,
    ];
};

$columnType = static function (PDO $db, string $table, string $column): string {
    $statement = $db->prepare(
        'SELECT COLUMN_TYPE FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1'
    );
    $statement->execute([$table, $column]);
    return strtolower(trim((string)($statement->fetchColumn() ?: '')));
};

$emit = static function (array $payload): void {
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
};

$assertOffline = static function () use ($offlineConfirmed, $runningJobs, $db): void {
    if (!$offlineConfirmed) {
        throw new RuntimeException(
            'This operation requires the site to be offline. Stop Apache/public writes and Background Jobs workers, '
            . 'then rerun with --offline-confirmed.'
        );
    }
    $running = $runningJobs($db);
    if ($running > 0) {
        throw new RuntimeException(
            'Refusing term-ID compaction while ' . $running . ' Background Job(s) are running.'
        );
    }
};

$assertKnownReferences = static function () use ($db): void {
    $expected = [
        'ue_name_lookup.name_term_id' => true,
        'ue_export_lookup.object_term_id' => true,
        'ue_export_lookup.class_term_id' => true,
        'ue_export_lookup.local_path_term_id' => true,
        'ue_dependency_links.required_package_term_id' => true,
        'ue_dependency_links.required_object_term_id' => true,
        'ue_dependency_links.import_class_package_term_id' => true,
        'ue_dependency_links.import_class_name_term_id' => true,
        'ue_dependency_links.import_object_term_id' => true,
        'ue_dependency_links.resolution_source_term_id' => true,
        'ue_dependency_links.resolution_confidence_term_id' => true,
    ];

    $unknown = [];
    $statement = $db->query(
        'SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME LIKE "%term_id" '
        . 'ORDER BY TABLE_NAME,COLUMN_NAME'
    );
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $key = (string)$row['TABLE_NAME'] . '.' . (string)$row['COLUMN_NAME'];
        if (!isset($expected[$key])) {
            $unknown[] = $key;
        }
    }
    if ($unknown !== []) {
        throw new RuntimeException(
            'Unknown term-ID reference column(s) exist; compaction policy must be extended first: '
            . implode(', ', $unknown)
        );
    }

    $foreign = $db->query(
        'SELECT TABLE_NAME,COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE '
        . 'WHERE REFERENCED_TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME="ue_terms"'
    )->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($foreign !== []) {
        $refs = array_map(
            static fn(array $row): string => (string)$row['TABLE_NAME'] . '.' . (string)$row['COLUMN_NAME'],
            $foreign
        );
        throw new RuntimeException(
            'Unexpected foreign key(s) reference ue_terms; compaction cannot continue safely: '
            . implode(', ', $refs)
        );
    }
};

$ensureStateTable = static function () use ($db, $stateTable): void {
    $db->exec(
        'CREATE TABLE IF NOT EXISTS ' . $stateTable . ' ('
        . 'id TINYINT UNSIGNED NOT NULL,'
        . 'phase VARCHAR(32) NOT NULL,'
        . 'cursor_file_id BIGINT UNSIGNED NOT NULL DEFAULT 0,'
        . 'term_count BIGINT UNSIGNED NOT NULL,'
        . 'original_max_id BIGINT UNSIGNED NOT NULL,'
        . 'started_at DATETIME NOT NULL,'
        . 'updated_at DATETIME NOT NULL,'
        . 'PRIMARY KEY(id)'
        . ') ENGINE=InnoDB'
    );
};

$prepare = static function () use (
    $db,
    $stateTable,
    $mapTable,
    $newTermsTable,
    $backupTermsTable,
    $uint32Max,
    $minimumFreeBytes,
    $tableExists,
    $tableCount,
    $state,
    $diskState,
    $columnType,
    $assertKnownReferences,
    $ensureStateTable,
    $emit
): array {
    $existingState = $state($db, $stateTable, $tableExists);
    if (is_array($existingState)) {
        return $existingState;
    }

    if ($tableExists($db, $backupTermsTable)) {
        throw new RuntimeException(
            $backupTermsTable . ' exists without compaction state. Inspect the previous compaction before continuing.'
        );
    }

    $assertKnownReferences();

    if ($columnType($db, 'ue_terms', 'id') !== 'int unsigned') {
        throw new RuntimeException('ue_terms.id must still be INT UNSIGNED for this compaction path.');
    }

    $stats = $db->query(
        'SELECT COUNT(*) row_count,COALESCE(MAX(id),0) max_id FROM ue_terms'
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $termCount = max(0, (int)($stats['row_count'] ?? 0));
    $originalMaxId = max(0, (int)($stats['max_id'] ?? 0));
    if ($termCount < 1) {
        throw new RuntimeException('ue_terms is empty; there is nothing to compact.');
    }
    if ($termCount >= $uint32Max - 1000000) {
        throw new RuntimeException(
            'The live term cardinality itself is too close to UINT32 capacity; dense INT compaction is not appropriate.'
        );
    }

    $allocated = $db->query(
        'SELECT COALESCE(DATA_LENGTH,0)+COALESCE(INDEX_LENGTH,0) '
        . 'FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_terms"'
    )->fetchColumn();
    $allocatedBytes = max(0, (int)($allocated ?: 0));
    $disk = $diskState($db);
    $requiredFree = max($minimumFreeBytes, ($allocatedBytes * 2) + (2 * 1024 * 1024 * 1024));
    if (is_int($disk['free_bytes']) && $disk['free_bytes'] < $requiredFree) {
        throw new RuntimeException(
            'Insufficient MySQL data-volume free space to stage the compacted dictionary safely: free='
            . $disk['free_bytes'] . ', required_at_least=' . $requiredFree . '.'
        );
    }

    $ensureStateTable();

    if (!$tableExists($db, $mapTable)) {
        $db->exec(
            'CREATE TABLE ' . $mapTable . ' ('
            . 'old_id INT UNSIGNED NOT NULL,'
            . 'new_id INT UNSIGNED NOT NULL AUTO_INCREMENT,'
            . 'PRIMARY KEY(old_id),'
            . 'UNIQUE KEY uq_ue_term_id_compaction_new(new_id)'
            . ') ENGINE=InnoDB'
        );
    }
    $mapCount = $tableCount($db, $mapTable);
    if ($mapCount !== $termCount) {
        $db->exec('TRUNCATE TABLE ' . $mapTable);
        $db->exec(
            'INSERT INTO ' . $mapTable . '(old_id) SELECT id FROM ue_terms ORDER BY id'
        );
        $mapCount = $tableCount($db, $mapTable);
    }
    $maxNewId = max(0, (int)($db->query(
        'SELECT COALESCE(MAX(new_id),0) FROM ' . $mapTable
    )->fetchColumn() ?: 0));
    if ($mapCount !== $termCount || $maxNewId !== $termCount) {
        throw new RuntimeException(
            'Dense term-ID mapping is incomplete: terms=' . $termCount
            . ', map_rows=' . $mapCount . ', max_new_id=' . $maxNewId . '.'
        );
    }

    if (!$tableExists($db, $newTermsTable)) {
        $db->exec('CREATE TABLE ' . $newTermsTable . ' LIKE ue_terms');
    }
    $newCount = $tableCount($db, $newTermsTable);
    if ($newCount !== $termCount) {
        $db->exec('TRUNCATE TABLE ' . $newTermsTable);
        $db->exec(
            'INSERT INTO ' . $newTermsTable
            . '(id,value_hash,value_length,value_prefix,is_overflow) '
            . 'SELECT m.new_id,t.value_hash,t.value_length,t.value_prefix,t.is_overflow '
            . 'FROM ue_terms t JOIN ' . $mapTable . ' m ON m.old_id=t.id '
            . 'ORDER BY m.new_id'
        );
        $newCount = $tableCount($db, $newTermsTable);
    }
    $newMaxId = max(0, (int)($db->query(
        'SELECT COALESCE(MAX(id),0) FROM ' . $newTermsTable
    )->fetchColumn() ?: 0));
    if ($newCount !== $termCount || $newMaxId !== $termCount) {
        throw new RuntimeException(
            'Compacted dictionary staging is incomplete: terms=' . $termCount
            . ', staged_rows=' . $newCount . ', staged_max_id=' . $newMaxId . '.'
        );
    }

    $insert = $db->prepare(
        'INSERT INTO ' . $stateTable
        . '(id,phase,cursor_file_id,term_count,original_max_id,started_at,updated_at) '
        . 'VALUES(1,"name_lookup",0,?,?,NOW(),NOW())'
    );
    $insert->execute([$termCount, $originalMaxId]);

    $current = $state($db, $stateTable, $tableExists);
    if (!is_array($current)) {
        throw new RuntimeException('Could not persist term-ID compaction resume state.');
    }
    $emit([
        'stage' => 'prepared',
        'term_count' => $termCount,
        'original_max_id' => $originalMaxId,
        'new_max_id' => $newMaxId,
        'disk' => $disk,
    ]);
    return $current;
};

$advancePhase = static function (string $phase, int $cursor) use ($db, $stateTable): void {
    $statement = $db->prepare(
        'UPDATE ' . $stateTable . ' SET phase=?,cursor_file_id=?,updated_at=NOW() WHERE id=1'
    );
    $statement->execute([$phase, $cursor]);
};

$processReferenceChunk = static function (
    string $phase,
    array $definition,
    array $currentState
) use (
    $db,
    $stateTable,
    $mapTable,
    $fileIdSpan,
    $minimumFreeBytes,
    $diskState,
    $emit
): bool {
    $table = (string)$definition['table'];
    $columns = array_values((array)$definition['columns']);
    $cursor = max(0, (int)($currentState['cursor_file_id'] ?? 0));
    $maxFileId = max(0, (int)($db->query(
        'SELECT COALESCE(MAX(file_id),0) FROM ' . $table
    )->fetchColumn() ?: 0));

    if ($cursor >= $maxFileId) {
        $advance = $db->prepare(
            'UPDATE ' . $stateTable . ' SET phase=?,cursor_file_id=0,updated_at=NOW() WHERE id=1'
        );
        $advance->execute([(string)$definition['next']]);
        $emit([
            'stage' => 'phase_complete',
            'phase' => $phase,
            'table' => $table,
            'max_file_id' => $maxFileId,
        ]);
        return false;
    }

    $disk = $diskState($db);
    if (is_int($disk['free_bytes']) && $disk['free_bytes'] < $minimumFreeBytes) {
        throw new RuntimeException(
            'Compaction paused before the next chunk because MySQL data-volume free space fell below 8 GiB: '
            . $disk['free_bytes'] . ' bytes free.'
        );
    }

    $end = min($maxFileId, $cursor + $fileIdSpan);
    $joins = '';
    $missing = [];
    $sets = [];
    foreach ($columns as $index => $column) {
        $alias = 'm' . $index;
        $joins .= ' LEFT JOIN ' . $mapTable . ' ' . $alias
            . ' ON ' . $alias . '.old_id=t.' . $column;
        $missing[] = '(t.' . $column . ' IS NOT NULL AND ' . $alias . '.old_id IS NULL)';
        $sets[] = 't.' . $column . '=' . $alias . '.new_id';
    }

    $db->beginTransaction();
    try {
        $check = $db->prepare(
            'SELECT COUNT(*) FROM ' . $table . ' t ' . $joins
            . ' WHERE t.file_id>? AND t.file_id<=? AND (' . implode(' OR ', $missing) . ')'
        );
        $check->execute([$cursor, $end]);
        $missingCount = max(0, (int)$check->fetchColumn());
        if ($missingCount > 0) {
            throw new RuntimeException(
                'Term-ID compaction found ' . $missingCount . ' unmapped reference row(s) in '
                . $table . ' for file_id (' . $cursor . ',' . $end . '].'
            );
        }

        $update = $db->prepare(
            'UPDATE ' . $table . ' t ' . $joins
            . ' SET ' . implode(',', $sets)
            . ' WHERE t.file_id>? AND t.file_id<=?'
        );
        $update->execute([$cursor, $end]);
        $changedRows = max(0, $update->rowCount());

        if ($end >= $maxFileId) {
            $nextPhase = (string)$definition['next'];
            $nextCursor = 0;
        } else {
            $nextPhase = $phase;
            $nextCursor = $end;
        }
        $stateUpdate = $db->prepare(
            'UPDATE ' . $stateTable . ' SET phase=?,cursor_file_id=?,updated_at=NOW() WHERE id=1'
        );
        $stateUpdate->execute([$nextPhase, $nextCursor]);
        $db->commit();

        $emit([
            'stage' => 'reference_chunk',
            'phase' => $phase,
            'table' => $table,
            'file_id_after' => $cursor,
            'file_id_through' => $end,
            'max_file_id' => $maxFileId,
            'changed_rows' => $changedRows,
            'next_phase' => $nextPhase,
            'next_cursor_file_id' => $nextCursor,
            'disk_free_bytes' => $disk['free_bytes'],
        ]);
        return true;
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
};

$swapDictionary = static function (array $currentState) use (
    $db,
    $stateTable,
    $mapTable,
    $newTermsTable,
    $backupTermsTable,
    $tableExists,
    $tableCount,
    $emit
): void {
    $termCount = max(0, (int)($currentState['term_count'] ?? 0));
    if ($termCount < 1) {
        throw new RuntimeException('Compaction state has no valid term count.');
    }

    $backupExists = $tableExists($db, $backupTermsTable);
    $newExists = $tableExists($db, $newTermsTable);
    if ($backupExists && !$newExists) {
        $currentCount = $tableCount($db, 'ue_terms');
        $currentMax = max(0, (int)($db->query(
            'SELECT COALESCE(MAX(id),0) FROM ue_terms'
        )->fetchColumn() ?: 0));
        if ($currentCount !== $termCount || $currentMax !== $termCount) {
            throw new RuntimeException(
                'Dictionary swap appears partially completed but current ue_terms is not the expected dense dictionary.'
            );
        }
        $db->exec(
            'UPDATE ' . $stateTable . ' SET phase="swapped",cursor_file_id=0,updated_at=NOW() WHERE id=1'
        );
        return;
    }
    if ($backupExists) {
        throw new RuntimeException(
            $backupTermsTable . ' already exists while ' . $newTermsTable
            . ' is still present; refusing an ambiguous dictionary swap.'
        );
    }
    if (!$newExists || !$tableExists($db, $mapTable)) {
        throw new RuntimeException('Compacted dictionary staging tables are missing.');
    }

    $mapCount = $tableCount($db, $mapTable);
    $newCount = $tableCount($db, $newTermsTable);
    $newMax = max(0, (int)($db->query(
        'SELECT COALESCE(MAX(id),0) FROM ' . $newTermsTable
    )->fetchColumn() ?: 0));
    if ($mapCount !== $termCount || $newCount !== $termCount || $newMax !== $termCount) {
        throw new RuntimeException(
            'Refusing dictionary swap because staged counts are inconsistent: map=' . $mapCount
            . ', dictionary=' . $newCount . ', max=' . $newMax . ', expected=' . $termCount . '.'
        );
    }

    $db->exec(
        'RENAME TABLE ue_terms TO ' . $backupTermsTable
        . ', ' . $newTermsTable . ' TO ue_terms'
    );
    $db->exec('ALTER TABLE ue_terms AUTO_INCREMENT=' . ($termCount + 1));
    $db->exec(
        'UPDATE ' . $stateTable . ' SET phase="swapped",cursor_file_id=0,updated_at=NOW() WHERE id=1'
    );
    $emit([
        'stage' => 'dictionary_swapped',
        'term_count' => $termCount,
        'auto_increment' => $termCount + 1,
        'backup_table' => $backupTermsTable,
    ]);
};

$verifyCompaction = static function (bool $markVerified = true) use (
    $db,
    $stateTable,
    $mapTable,
    $backupTermsTable,
    $tableExists,
    $tableCount,
    $state,
    $uint32Max
): array {
    $currentState = $state($db, $stateTable, $tableExists);
    if (!is_array($currentState)
        || !in_array((string)$currentState['phase'], ['swapped', 'verified', 'cleaned'], true)) {
        throw new RuntimeException('Term-ID compaction has not reached the dictionary-swap stage.');
    }
    $termCount = max(0, (int)$currentState['term_count']);
    $currentCount = $tableCount($db, 'ue_terms');
    $currentMax = max(0, (int)($db->query(
        'SELECT COALESCE(MAX(id),0) FROM ue_terms'
    )->fetchColumn() ?: 0));
    $auto = max(0, (int)($db->query(
        'SELECT AUTO_INCREMENT FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_terms" LIMIT 1'
    )->fetchColumn() ?: 0));

    $checks = [
        'term_count_matches' => $currentCount === $termCount,
        'dense_max_id_matches_count' => $currentMax === $termCount,
        'auto_increment_is_dense_next_id' => $auto === ($termCount + 1),
        'auto_increment_has_uint32_headroom' => $auto > $currentMax && $auto <= $uint32Max,
    ];

    if ((string)$currentState['phase'] !== 'cleaned') {
        if (!$tableExists($db, $mapTable) || !$tableExists($db, $backupTermsTable)) {
            throw new RuntimeException('Compaction verification requires the retained mapping and old dictionary.');
        }
        $matched = max(0, (int)($db->query(
            'SELECT COUNT(*) FROM ' . $backupTermsTable . ' o '
            . 'JOIN ' . $mapTable . ' m ON m.old_id=o.id '
            . 'JOIN ue_terms n ON n.id=m.new_id '
            . 'WHERE n.value_hash=o.value_hash AND n.value_length=o.value_length '
            . 'AND n.value_prefix=o.value_prefix AND n.is_overflow=o.is_overflow'
        )->fetchColumn() ?: 0));
        $checks['dictionary_values_match_old_via_map'] = $matched === $termCount;
    }

    $rangeSql = [
        'ue_name_lookup' => 'SELECT COALESCE(MAX(name_term_id),0) FROM ue_name_lookup',
        'ue_dependency_links' => 'SELECT COALESCE(MAX(GREATEST('
            . 'required_package_term_id,COALESCE(required_object_term_id,0),'
            . 'COALESCE(import_class_package_term_id,0),COALESCE(import_class_name_term_id,0),'
            . 'COALESCE(import_object_term_id,0),COALESCE(resolution_source_term_id,0),'
            . 'COALESCE(resolution_confidence_term_id,0))),0) FROM ue_dependency_links',
        'ue_export_lookup' => 'SELECT COALESCE(MAX(GREATEST('
            . 'object_term_id,COALESCE(class_term_id,0),COALESCE(local_path_term_id,0))),0) '
            . 'FROM ue_export_lookup',
    ];
    $referenceMax = [];
    foreach ($rangeSql as $table => $sql) {
        $max = max(0, (int)($db->query($sql)->fetchColumn() ?: 0));
        $referenceMax[$table] = $max;
        $checks[$table . '_references_fit_dense_dictionary'] = $max <= $termCount;
    }

    $ok = !in_array(false, $checks, true);
    if ($ok && $markVerified && (string)$currentState['phase'] === 'swapped') {
        $db->exec(
            'UPDATE ' . $stateTable . ' SET phase="verified",updated_at=NOW() WHERE id=1'
        );
    }

    return [
        'ok' => $ok,
        'phase' => $ok && $markVerified && (string)$currentState['phase'] === 'swapped'
            ? 'verified'
            : (string)$currentState['phase'],
        'term_count' => $termCount,
        'current_max_id' => $currentMax,
        'auto_increment' => $auto,
        'reference_max_ids' => $referenceMax,
        'checks' => $checks,
    ];
};

$statusPayload = static function () use (
    $db,
    $stateTable,
    $mapTable,
    $newTermsTable,
    $backupTermsTable,
    $tableExists,
    $tableCount,
    $state,
    $runningJobs,
    $diskState,
    $columnType
): array {
    $stats = $db->query(
        'SELECT COUNT(*) row_count,COALESCE(MIN(id),0) min_id,COALESCE(MAX(id),0) max_id FROM ue_terms'
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    $auto = max(0, (int)($db->query(
        'SELECT AUTO_INCREMENT FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_terms" LIMIT 1'
    )->fetchColumn() ?: 0));
    $currentState = $state($db, $stateTable, $tableExists);
    return [
        'ok' => true,
        'running_jobs' => $runningJobs($db),
        'ue_terms' => [
            'rows' => max(0, (int)($stats['row_count'] ?? 0)),
            'min_id' => max(0, (int)($stats['min_id'] ?? 0)),
            'max_id' => max(0, (int)($stats['max_id'] ?? 0)),
            'auto_increment' => $auto,
            'id_type' => $columnType($db, 'ue_terms', 'id'),
        ],
        'state' => $currentState,
        'support_tables' => [
            $mapTable => $tableExists($db, $mapTable) ? $tableCount($db, $mapTable) : null,
            $newTermsTable => $tableExists($db, $newTermsTable) ? $tableCount($db, $newTermsTable) : null,
            $backupTermsTable => $tableExists($db, $backupTermsTable) ? $tableCount($db, $backupTermsTable) : null,
        ],
        'mysql_datadir' => $diskState($db),
    ];
};

if (!in_array($command, ['status', 'run', 'verify', 'cleanup'], true)) {
    fwrite(
        STDERR,
        "Usage: php catalog/bin/compact-ue-term-ids.php "
        . "[status|run|verify|cleanup] [--offline-confirmed] "
        . "[--file-id-span=N] [--max-chunks=N]\n"
    );
    exit(2);
}

if ($command === 'status') {
    echo json_encode($statusPayload(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

$lockName = 'unrealdb:compact-term-ids';
$lock = $db->prepare('SELECT GET_LOCK(?, 0)');
$lock->execute([$lockName]);
if ((int)$lock->fetchColumn() !== 1) {
    throw new RuntimeException('Another term-ID compaction process already holds the maintenance lock.');
}

try {
    if ($command === 'run') {
        $assertOffline();
        $current = $prepare();
        $chunks = 0;

        for (;;) {
            $current = $state($db, $stateTable, $tableExists);
            if (!is_array($current)) {
                throw new RuntimeException('Compaction resume state disappeared.');
            }
            $phase = (string)$current['phase'];
            if (isset($referencePhases[$phase])) {
                $didChunk = $processReferenceChunk($phase, $referencePhases[$phase], $current);
                if ($didChunk) {
                    $chunks++;
                    if ($maxChunks > 0 && $chunks >= $maxChunks) {
                        echo json_encode([
                            'ok' => true,
                            'paused' => true,
                            'processed_chunks' => $chunks,
                            'status' => $statusPayload(),
                        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
                        exit(0);
                    }
                }
                continue;
            }
            if ($phase === 'swap') {
                $swapDictionary($current);
                continue;
            }
            if (in_array($phase, ['swapped', 'verified', 'cleaned'], true)) {
                echo json_encode([
                    'ok' => true,
                    'processed_chunks' => $chunks,
                    'status' => $statusPayload(),
                    'next' => $phase === 'swapped'
                        ? 'Run: php catalog/bin/compact-ue-term-ids.php verify'
                        : ($phase === 'verified'
                            ? 'Run: php catalog/bin/compact-ue-term-ids.php cleanup --offline-confirmed'
                            : 'Compaction is complete.'),
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
                exit(0);
            }
            throw new RuntimeException('Unknown compaction phase: ' . $phase);
        }
    }

    if ($command === 'verify') {
        $result = $verifyCompaction(true);
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(!empty($result['ok']) ? 0 : 3);
    }

    if ($command === 'cleanup') {
        $assertOffline();
        $result = $verifyCompaction(false);
        if (empty($result['ok'])) {
            echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
            exit(3);
        }
        $current = $state($db, $stateTable, $tableExists);
        if (!is_array($current)
            || !in_array((string)$current['phase'], ['verified', 'cleaned'], true)) {
            throw new RuntimeException('Run the verify command successfully before cleanup.');
        }
        if ((string)$current['phase'] !== 'cleaned') {
            if ($tableExists($db, $backupTermsTable)) {
                $db->exec('DROP TABLE ' . $backupTermsTable);
            }
            if ($tableExists($db, $mapTable)) {
                $db->exec('DROP TABLE ' . $mapTable);
            }
            if ($tableExists($db, $newTermsTable)) {
                $db->exec('DROP TABLE ' . $newTermsTable);
            }
            if ($tableExists($db, 'ue_terms_pre_auto_increment_rebase')) {
                $db->exec('DROP TABLE ue_terms_pre_auto_increment_rebase');
            }
            $db->exec(
                'UPDATE ' . $stateTable . ' SET phase="cleaned",updated_at=NOW() WHERE id=1'
            );
        }
        echo json_encode([
            'ok' => true,
            'status' => $statusPayload(),
            'message' => 'Old sparse term dictionary and compaction mapping removed.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }
} finally {
    try {
        $release = $db->prepare('SELECT RELEASE_LOCK(?)');
        $release->execute([$lockName]);
    } catch (Throwable) {
    }
}
