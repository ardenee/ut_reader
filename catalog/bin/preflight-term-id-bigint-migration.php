#!/usr/bin/env php
<?php
/**
 * Read-only capacity/space preflight for migration 202609040001.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/lib/CatalogSupport.php';

$config = catalog_config();
$db = catalog_db($config);

$tables = ['ue_terms', 'ue_name_lookup', 'ue_export_lookup', 'ue_dependency_links'];
$placeholders = implode(',', array_fill(0, count($tables), '?'));
$statement = $db->prepare(
    'SELECT TABLE_NAME,TABLE_ROWS,DATA_LENGTH,INDEX_LENGTH,DATA_FREE '
    . 'FROM information_schema.TABLES '
    . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN (' . $placeholders . ')'
);
$statement->execute($tables);
$tableRows = [];
$largestBytes = 0;
$totalBytes = 0;
foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $bytes = max(0, (int)($row['DATA_LENGTH'] ?? 0)) + max(0, (int)($row['INDEX_LENGTH'] ?? 0));
    $largestBytes = max($largestBytes, $bytes);
    $totalBytes += $bytes;
    $tableRows[(string)$row['TABLE_NAME']] = [
        'estimated_rows' => max(0, (int)($row['TABLE_ROWS'] ?? 0)),
        'data_bytes' => max(0, (int)($row['DATA_LENGTH'] ?? 0)),
        'index_bytes' => max(0, (int)($row['INDEX_LENGTH'] ?? 0)),
        'allocated_bytes' => $bytes,
        'data_free_bytes' => max(0, (int)($row['DATA_FREE'] ?? 0)),
    ];
}

$termStats = $db->query(
    'SELECT COUNT(*) row_count,COALESCE(MIN(id),0) min_id,COALESCE(MAX(id),0) max_id FROM ue_terms'
)->fetch(PDO::FETCH_ASSOC) ?: [];

$columns = [];
foreach ([
    'ue_terms' => ['id'],
    'ue_name_lookup' => ['name_term_id'],
    'ue_export_lookup' => ['object_term_id','class_term_id','local_path_term_id'],
    'ue_dependency_links' => [
        'required_package_term_id','required_object_term_id',
        'import_class_package_term_id','import_class_name_term_id','import_object_term_id',
        'resolution_source_term_id','resolution_confidence_term_id',
    ],
] as $table => $names) {
    foreach ($names as $name) {
        $column = $db->prepare(
            'SELECT COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1'
        );
        $column->execute([$table, $name]);
        $row = $column->fetch(PDO::FETCH_ASSOC);
        $columns[$table . '.' . $name] = is_array($row)
            ? [
                'type' => (string)$row['COLUMN_TYPE'],
                'nullable' => (string)$row['IS_NULLABLE'],
            ]
            : ['type' => 'missing', 'nullable' => ''];
    }
}

$running = (int)($db->query(
    'SELECT COUNT(*) FROM ue_background_jobs WHERE status="running"'
)->fetchColumn() ?: 0);

$dataDir = (string)($db->query('SELECT @@datadir')->fetchColumn() ?: '');
$tmpDir = (string)($db->query('SELECT @@tmpdir')->fetchColumn() ?: '');
$disk = static function (string $path): array {
    $free = $path !== '' ? @disk_free_space($path) : false;
    $total = $path !== '' ? @disk_total_space($path) : false;
    return [
        'path' => $path,
        'free_bytes' => is_float($free) || is_int($free) ? (int)$free : null,
        'total_bytes' => is_float($total) || is_int($total) ? (int)$total : null,
    ];
};

$result = [
    'ok' => true,
    'migration' => '202609040001',
    'running_jobs' => $running,
    'ue_terms' => [
        'rows' => max(0, (int)($termStats['row_count'] ?? 0)),
        'min_id' => max(0, (int)($termStats['min_id'] ?? 0)),
        'max_id' => max(0, (int)($termStats['max_id'] ?? 0)),
    ],
    'affected_tables' => $tableRows,
    'affected_allocated_bytes' => $totalBytes,
    'largest_affected_table_bytes' => $largestBytes,
    'columns' => $columns,
    'mysql_datadir' => $disk($dataDir),
    'mysql_tmpdir' => $disk($tmpDir),
    'notes' => [
        'ALTER TABLE may rebuild each affected table and can temporarily require substantial additional disk space.',
        'The migration alters one table at a time; the largest affected table is the most important minimum working-space signal.',
        'Stop Background Jobs workers and keep them stopped until migrate + verify complete.',
        'Take a database backup before applying this migration.',
    ],
];

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(0);
