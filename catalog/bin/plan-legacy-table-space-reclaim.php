#!/usr/bin/env php
<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides the command-line utility for plan legacy table space reclaim.
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

const LEGACY_RECLAIM_TABLES = [
    'ue_dependencies',
    'ue_imports',
    'ue_names',
    'ue_exports',
];

/** @return array<string,string> */
function legacy_reclaim_variables(PDO $db): array
{
    $wanted = [
        'datadir',
        'tmpdir',
        'innodb_tmpdir',
        'innodb_file_per_table',
        'version',
        'version_comment',
    ];
    $placeholders = implode(',', array_fill(0, count($wanted), '?'));
    $statement = $db->prepare(
        'SELECT VARIABLE_NAME,VARIABLE_VALUE FROM performance_schema.global_variables '
        . 'WHERE VARIABLE_NAME IN (' . $placeholders . ')'
    );
    try {
        $statement->execute($wanted);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable) {
        $rows = [];
        foreach ($wanted as $name) {
            try {
                $row = $db->query('SHOW VARIABLES LIKE ' . $db->quote($name))->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    $rows[] = [
                        'VARIABLE_NAME' => (string)($row['Variable_name'] ?? $name),
                        'VARIABLE_VALUE' => (string)($row['Value'] ?? ''),
                    ];
                }
            } catch (Throwable) {
                // Missing variables are reported as empty below.
            }
        }
    }

    $out = [];
    foreach ($rows as $row) {
        $name = strtolower((string)($row['VARIABLE_NAME'] ?? $row['Variable_name'] ?? ''));
        if ($name !== '') {
            $out[$name] = (string)($row['VARIABLE_VALUE'] ?? $row['Value'] ?? '');
        }
    }
    foreach ($wanted as $name) {
        $out[$name] ??= '';
    }
    return $out;
}

/** @return array<string,mixed> */
function legacy_reclaim_drive_info(string $path): array
{
    $path = trim($path);
    if ($path === '') {
        return ['path' => '', 'available' => false, 'free_bytes' => null, 'total_bytes' => null];
    }

    $probe = $path;
    if (preg_match('/^[A-Za-z]:/', $path) === 1) {
        $probe = strtoupper(substr($path, 0, 2)) . DIRECTORY_SEPARATOR;
    } elseif (!is_dir($probe)) {
        $probe = dirname($probe);
    }

    $free = @disk_free_space($probe);
    $total = @disk_total_space($probe);
    return [
        'path' => $path,
        'probe' => $probe,
        'available' => $free !== false && $total !== false,
        'free_bytes' => $free !== false ? (int)$free : null,
        'total_bytes' => $total !== false ? (int)$total : null,
    ];
}

/** @return array<string,mixed> */
function legacy_reclaim_table(PDO $db, string $table): array
{
    $statement = $db->prepare(
        'SELECT TABLE_NAME,ENGINE,TABLE_ROWS,AVG_ROW_LENGTH,DATA_LENGTH,MAX_DATA_LENGTH,'
        . 'INDEX_LENGTH,DATA_FREE,CREATE_OPTIONS,TABLE_COLLATION '
        . 'FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
    );
    $statement->execute([$table]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Required table is missing: ' . $table . '.');
    }

    $data = (int)($row['DATA_LENGTH'] ?? 0);
    $indexes = (int)($row['INDEX_LENGTH'] ?? 0);
    $allocated = $data + $indexes;
    $dataFree = (int)($row['DATA_FREE'] ?? 0);

    return [
        'table' => $table,
        'engine' => (string)($row['ENGINE'] ?? ''),
        'estimated_rows' => (int)($row['TABLE_ROWS'] ?? 0),
        'average_row_length' => (int)($row['AVG_ROW_LENGTH'] ?? 0),
        'data_bytes' => $data,
        'index_bytes' => $indexes,
        'allocated_bytes' => $allocated,
        'reported_free_bytes' => $dataFree,
        'reported_reclaimable_percent' => $allocated > 0
            ? round(($dataFree / $allocated) * 100, 2)
            : 0.0,
        'conservative_rebuild_free_space_bytes' => (int)ceil($allocated * 1.20),
        'create_options' => (string)($row['CREATE_OPTIONS'] ?? ''),
        'collation' => (string)($row['TABLE_COLLATION'] ?? ''),
    ];
}

/** @return int */
function legacy_reclaim_scalar(PDO $db, string $sql): int
{
    return (int)($db->query($sql)->fetchColumn() ?: 0);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $variables = legacy_reclaim_variables($db);

    $tables = [];
    foreach (LEGACY_RECLAIM_TABLES as $table) {
        $tables[] = legacy_reclaim_table($db, $table);
    }

    $verifiedLegacyRows = [];
    foreach (LEGACY_RECLAIM_TABLES as $table) {
        $verifiedLegacyRows[$table] = legacy_reclaim_scalar(
            $db,
            'SELECT COUNT(*) FROM ' . $table . ' legacy '
            . 'JOIN ue_files f ON f.id=legacy.file_id AND f.scan_status="verified" '
            . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2'
        );
    }

    $runningJobs = 0;
    try {
        $runningJobs = legacy_reclaim_scalar(
            $db,
            'SELECT COUNT(*) FROM ue_background_jobs WHERE status="running"'
        );
    } catch (Throwable) {
        // Durable job table may not exist on an older installation.
    }

    $dataDrive = legacy_reclaim_drive_info((string)$variables['datadir']);
    $tmpPath = trim((string)$variables['innodb_tmpdir']) !== ''
        ? (string)$variables['innodb_tmpdir']
        : (string)$variables['tmpdir'];
    $tmpDrive = legacy_reclaim_drive_info($tmpPath);

    $order = $tables;
    usort($order, static fn(array $left, array $right): int =>
        ((int)$left['allocated_bytes'] <=> (int)$right['allocated_bytes'])
        ?: strcmp((string)$left['table'], (string)$right['table'])
    );

    $dataFree = is_int($dataDrive['free_bytes']) ? (int)$dataDrive['free_bytes'] : 0;
    $recommendations = [];
    foreach ($order as $table) {
        $required = (int)$table['conservative_rebuild_free_space_bytes'];
        $recommendations[] = [
            'table' => (string)$table['table'],
            'allocated_bytes' => (int)$table['allocated_bytes'],
            'reported_free_bytes' => (int)$table['reported_free_bytes'],
            'conservative_rebuild_free_space_bytes' => $required,
            'data_drive_free_bytes' => $dataDrive['free_bytes'],
            'space_gate_passes' => $dataFree > 0 && $dataFree >= $required,
        ];
    }

    $blockers = [];
    if (array_sum($verifiedLegacyRows) !== 0) {
        $blockers[] = 'Verified format-2 legacy rows still remain.';
    }
    if ($runningJobs !== 0) {
        $blockers[] = $runningJobs . ' background job(s) are still running.';
    }
    if (strtolower(trim((string)$variables['innodb_file_per_table'])) !== 'on') {
        $blockers[] = 'innodb_file_per_table is not ON; rebuilding may not return table files to the filesystem.';
    }
    if (empty($dataDrive['available'])) {
        $blockers[] = 'Could not determine free space for the MySQL data drive.';
    }

    $output = [
        'verified' => $blockers === [],
        'mysql' => [
            'version' => (string)$variables['version'],
            'version_comment' => (string)$variables['version_comment'],
            'datadir' => (string)$variables['datadir'],
            'tmpdir' => (string)$variables['tmpdir'],
            'innodb_tmpdir' => (string)$variables['innodb_tmpdir'],
            'innodb_file_per_table' => (string)$variables['innodb_file_per_table'],
        ],
        'drives' => [
            'data' => $dataDrive,
            'temporary' => $tmpDrive,
        ],
        'running_jobs' => $runningJobs,
        'verified_format2_legacy_rows' => $verifiedLegacyRows,
        'tables' => $tables,
        'recommended_smallest_first' => $recommendations,
        'blockers' => $blockers,
        'note' => 'No table was changed. DATA_FREE and TABLE_ROWS are InnoDB estimates; the conservative space gate uses 120% of the currently allocated table size.',
    ];

    fwrite(STDOUT, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($blockers === [] ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR, 'Legacy table space reclaim planning failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
