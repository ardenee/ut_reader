#!/usr/bin/env php
<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides the command-line utility for plan mysql space release.
 * Why: It handles administrator, migration, verification, repair, generation, or worker work that should not execute
 *      as an interactive browser request.
 * Role: CLI/maintenance entry point used from the server shell or operational scripts.
 * Audit: Operational entry point; verify scheduled/manual usage before considering removal.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupportCore.php';

/** @return string */
function mysql_space_variable(PDO $db, string $name): string
{
    $statement = $db->query('SHOW VARIABLES LIKE ' . $db->quote($name));
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? (string)($row['Value'] ?? '') : '';
}

/** @return array<string,mixed> */
function mysql_space_drive(string $path): array
{
    $path = trim($path);
    $probe = $path;
    if (preg_match('/^[A-Za-z]:/', $path) === 1) {
        $probe = strtoupper(substr($path, 0, 2)) . DIRECTORY_SEPARATOR;
    } elseif ($probe !== '' && !is_dir($probe)) {
        $probe = dirname($probe);
    }
    $free = $probe !== '' ? @disk_free_space($probe) : false;
    $total = $probe !== '' ? @disk_total_space($probe) : false;
    return [
        'path' => $path,
        'probe' => $probe,
        'available' => $free !== false && $total !== false,
        'free_bytes' => $free !== false ? (int)$free : null,
        'total_bytes' => $total !== false ? (int)$total : null,
    ];
}

/** @return list<array{file:string,size_bytes:int}> */
function mysql_space_files(string $directory, int $limit = 25): array
{
    if (!is_dir($directory) || !is_readable($directory)) {
        return [];
    }
    $rows = [];
    $iterator = new DirectoryIterator($directory);
    foreach ($iterator as $item) {
        if ($item->isDot() || !$item->isFile()) {
            continue;
        }
        $size = $item->getSize();
        $rows[] = [
            'file' => $item->getFilename(),
            'size_bytes' => max(0, (int)$size),
        ];
    }
    usort($rows, static fn(array $left, array $right): int =>
        ((int)$right['size_bytes'] <=> (int)$left['size_bytes'])
        ?: strcmp((string)$left['file'], (string)$right['file'])
    );
    return array_slice($rows, 0, max(1, $limit));
}

/** @return list<array<string,mixed>> */
function mysql_space_tables(PDO $db): array
{
    $statement = $db->query(
        'SELECT TABLE_NAME,ENGINE,TABLE_ROWS,DATA_LENGTH,INDEX_LENGTH,DATA_FREE '
        . 'FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA=DATABASE() ORDER BY (DATA_LENGTH+INDEX_LENGTH) DESC,TABLE_NAME'
    );
    $rows = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $data = (int)($row['DATA_LENGTH'] ?? 0);
        $indexes = (int)($row['INDEX_LENGTH'] ?? 0);
        $rows[] = [
            'table' => (string)($row['TABLE_NAME'] ?? ''),
            'engine' => (string)($row['ENGINE'] ?? ''),
            'estimated_rows' => (int)($row['TABLE_ROWS'] ?? 0),
            'data_bytes' => $data,
            'index_bytes' => $indexes,
            'allocated_bytes' => $data + $indexes,
            'reported_free_bytes' => (int)($row['DATA_FREE'] ?? 0),
        ];
    }
    return $rows;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $database = (string)($db->query('SELECT DATABASE()')->fetchColumn() ?: '');
    if ($database === '') {
        throw new RuntimeException('No MySQL database is selected.');
    }

    $variables = [];
    foreach ([
        'datadir',
        'tmpdir',
        'innodb_tmpdir',
        'innodb_file_per_table',
        'innodb_redo_log_capacity',
        'log_bin',
        'log_bin_basename',
        'binlog_expire_logs_seconds',
    ] as $name) {
        $variables[$name] = mysql_space_variable($db, $name);
    }

    $datadir = rtrim((string)$variables['datadir'], "\\/") . DIRECTORY_SEPARATOR;
    $schemaDirectory = $datadir . $database;
    $dataDrive = mysql_space_drive($datadir);
    $tables = mysql_space_tables($db);

    $candidates = array_values(array_filter(
        $tables,
        static fn(array $row): bool =>
            strtolower((string)$row['engine']) === 'innodb'
            && (int)$row['reported_free_bytes'] >= 134217728
    ));
    usort($candidates, static fn(array $left, array $right): int =>
        ((int)$right['reported_free_bytes'] <=> (int)$left['reported_free_bytes'])
        ?: ((int)$left['allocated_bytes'] <=> (int)$right['allocated_bytes'])
    );
    $candidates = array_slice($candidates, 0, 25);

    $binaryLogs = [
        'available' => false,
        'count' => 0,
        'total_bytes' => 0,
        'logs' => [],
        'error' => null,
    ];
    try {
        $rows = $db->query('SHOW BINARY LOGS')->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $logs = [];
        $total = 0;
        foreach ($rows as $row) {
            $size = (int)($row['File_size'] ?? 0);
            $total += $size;
            $logs[] = [
                'name' => (string)($row['Log_name'] ?? ''),
                'size_bytes' => $size,
            ];
        }
        $binaryLogs = [
            'available' => true,
            'count' => count($logs),
            'total_bytes' => $total,
            'logs' => array_slice($logs, -25),
            'error' => null,
        ];
    } catch (Throwable $error) {
        $binaryLogs['error'] = $error->getMessage();
    }

    $schemaFiles = mysql_space_files($schemaDirectory, 30);
    $topLevelFiles = mysql_space_files($datadir, 30);
    $schemaFileMap = [];
    foreach ($schemaFiles as $file) {
        $schemaFileMap[strtolower((string)$file['file'])] = (int)$file['size_bytes'];
    }

    $exports = null;
    foreach ($tables as $table) {
        if ((string)$table['table'] === 'ue_exports') {
            $exports = $table;
            break;
        }
    }
    $exportsGate = null;
    if (is_array($exports)) {
        $allocated = (int)$exports['allocated_bytes'];
        $free = is_int($dataDrive['free_bytes']) ? (int)$dataDrive['free_bytes'] : 0;
        $ibdBytes = $schemaFileMap['ue_exports.ibd'] ?? null;
        $conservative = (int)ceil($allocated * 1.20);
        $exportsGate = [
            'allocated_bytes' => $allocated,
            'reported_free_bytes' => (int)$exports['reported_free_bytes'],
            'ibd_file_bytes' => $ibdBytes,
            'data_drive_free_bytes' => $dataDrive['free_bytes'],
            'minimum_estimated_rebuild_bytes' => $allocated,
            'conservative_rebuild_bytes' => $conservative,
            'shortfall_against_allocated_bytes' => max(0, $allocated - $free),
            'shortfall_against_conservative_bytes' => max(0, $conservative - $free),
        ];
    }

    $output = [
        'verified' => true,
        'database' => $database,
        'mysql_variables' => $variables,
        'data_drive' => $dataDrive,
        'schema_directory' => $schemaDirectory,
        'ue_exports_gate' => $exportsGate,
        'binary_logs' => $binaryLogs,
        'largest_schema_files' => $schemaFiles,
        'largest_datadir_files' => $topLevelFiles,
        'largest_tables' => array_slice($tables, 0, 30),
        'reported_fragmentation_candidates' => $candidates,
        'note' => 'Read-only audit. TABLE_ROWS and DATA_FREE are InnoDB estimates; filesystem file sizes and drive free space are direct measurements.',
    ];

    fwrite(STDOUT, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'MySQL space release planning failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
