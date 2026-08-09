#!/usr/bin/env php
<?php
/**
 * Purpose: Read-only exact accounting for the final physical retirement of legacy metadata tables.
 * Role: Verifies that verified metadata is fully format-2 and classifies every remaining legacy row before tables are dropped.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$withDatabase = in_array('--database', array_slice($argv, 1), true);
if (!$withDatabase) {
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'database_checked' => false,
        'detail' => 'Run with --database for exact legacy-table retirement accounting.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
}

require_once __DIR__ . '/../lib/CatalogSupportCore.php';

const LEGACY_RETIREMENT_TABLES = [
    'ue_dependencies',
    'ue_imports',
    'ue_exports',
    'ue_names',
];

/** @return int */
function retirement_scalar(PDO $db, string $sql, array $args = []): int
{
    $statement = $db->prepare($sql);
    $statement->execute($args);
    return (int)($statement->fetchColumn() ?: 0);
}

/** @return bool */
function retirement_table_exists(PDO $db, string $table): bool
{
    $statement = $db->prepare(
        'SELECT 1 FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1'
    );
    $statement->execute([$table]);
    return (bool)$statement->fetchColumn();
}

/** @return array<string,int> */
function retirement_rows_by_status(PDO $db, string $table): array
{
    $statement = $db->query(
        'SELECT f.scan_status,COUNT(*) row_count FROM ' . $table . ' legacy '
        . 'JOIN ue_files f ON f.id=legacy.file_id '
        . 'GROUP BY f.scan_status ORDER BY f.scan_status'
    );
    $out = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[(string)$row['scan_status']] = (int)$row['row_count'];
    }
    return $out;
}

/** @return array<string,mixed> */
function retirement_table_accounting(PDO $db, string $table): array
{
    if (!retirement_table_exists($db, $table)) {
        return [
            'table' => $table,
            'exists' => false,
            'total_rows' => 0,
            'distinct_file_ids' => 0,
            'verified_rows' => 0,
            'unverified_rows' => 0,
            'other_file_status_rows' => 0,
            'orphan_rows' => 0,
            'rows_by_status' => [],
            'empty' => true,
        ];
    }

    $totalRows = retirement_scalar($db, 'SELECT COUNT(*) FROM ' . $table);
    $distinctFileIds = $totalRows === 0
        ? 0
        : retirement_scalar($db, 'SELECT COUNT(DISTINCT file_id) FROM ' . $table);
    $verifiedRows = $totalRows === 0
        ? 0
        : retirement_scalar(
            $db,
            'SELECT COUNT(*) FROM ' . $table . ' legacy '
            . 'JOIN ue_files f ON f.id=legacy.file_id '
            . 'WHERE f.scan_status="verified"'
        );
    $unverifiedRows = $totalRows === 0
        ? 0
        : retirement_scalar(
            $db,
            'SELECT COUNT(*) FROM ' . $table . ' legacy '
            . 'JOIN ue_files f ON f.id=legacy.file_id '
            . 'WHERE f.scan_status="unverified"'
        );
    $otherRows = $totalRows === 0
        ? 0
        : retirement_scalar(
            $db,
            'SELECT COUNT(*) FROM ' . $table . ' legacy '
            . 'JOIN ue_files f ON f.id=legacy.file_id '
            . 'WHERE f.scan_status NOT IN ("verified","unverified")'
        );
    $orphanRows = $totalRows === 0
        ? 0
        : retirement_scalar(
            $db,
            'SELECT COUNT(*) FROM ' . $table . ' legacy '
            . 'LEFT JOIN ue_files f ON f.id=legacy.file_id '
            . 'WHERE f.id IS NULL'
        );

    return [
        'table' => $table,
        'exists' => true,
        'total_rows' => $totalRows,
        'distinct_file_ids' => $distinctFileIds,
        'verified_rows' => $verifiedRows,
        'unverified_rows' => $unverifiedRows,
        'other_file_status_rows' => $otherRows,
        'orphan_rows' => $orphanRows,
        'rows_by_status' => $totalRows === 0 ? [] : retirement_rows_by_status($db, $table),
        'classified_rows' => $verifiedRows + $unverifiedRows + $otherRows + $orphanRows,
        'classification_matches_total' => $totalRows === ($verifiedRows + $unverifiedRows + $otherRows + $orphanRows),
        'empty' => $totalRows === 0,
    ];
}

function retirement_legacy_file_source_sql(): string
{
    $selects = [];
    foreach (LEGACY_RETIREMENT_TABLES as $table) {
        $selects[] = 'SELECT file_id FROM ' . $table;
    }
    return '(' . implode(' UNION ', $selects) . ')';
}

/** @return array<string,array<string,int>> */
function retirement_file_status_summary(PDO $db): array
{
    $source = retirement_legacy_file_source_sql();
    $statement = $db->query(
        'SELECT f.scan_status,COUNT(*) file_count,'
        . 'SUM(CASE WHEN m.format_version=2 THEN 1 ELSE 0 END) format2_files,'
        . 'SUM(CASE WHEN m.file_id IS NULL OR m.format_version<>2 THEN 1 ELSE 0 END) without_format2 '
        . 'FROM ' . $source . ' legacy '
        . 'JOIN ue_files f ON f.id=legacy.file_id '
        . 'LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
        . 'GROUP BY f.scan_status ORDER BY f.scan_status'
    );

    $out = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $out[(string)$row['scan_status']] = [
            'files' => (int)$row['file_count'],
            'format2_files' => (int)$row['format2_files'],
            'without_format2' => (int)$row['without_format2'],
        ];
    }
    return $out;
}

/** @return list<array<string,mixed>> */
function retirement_file_samples(PDO $db, int $limit = 25): array
{
    $source = retirement_legacy_file_source_sql();
    $statement = $db->query(
        'SELECT f.id,f.scan_status,f.game_id,f.package_name,f.original_name,'
        . 'f.name_count,f.import_count,f.export_count,m.format_version,'
        . 'm.name_count metadata_name_count,m.import_count metadata_import_count,'
        . 'm.export_count metadata_export_count '
        . 'FROM ' . $source . ' legacy '
        . 'JOIN ue_files f ON f.id=legacy.file_id '
        . 'LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
        . 'ORDER BY f.scan_status,f.id LIMIT ' . max(1, $limit)
    );

    $out = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $formatVersion = $row['format_version'] === null ? null : (int)$row['format_version'];
        $countParity = $formatVersion === 2
            && (int)$row['name_count'] === (int)$row['metadata_name_count']
            && (int)$row['import_count'] === (int)$row['metadata_import_count']
            && (int)$row['export_count'] === (int)$row['metadata_export_count'];
        $out[] = [
            'file_id' => (int)$row['id'],
            'scan_status' => (string)$row['scan_status'],
            'game_id' => (int)$row['game_id'],
            'package_name' => (string)$row['package_name'],
            'original_name' => (string)$row['original_name'],
            'format_version' => $formatVersion,
            'format2_count_parity' => $countParity,
            'file_counts' => [
                'names' => (int)$row['name_count'],
                'imports' => (int)$row['import_count'],
                'exports' => (int)$row['export_count'],
            ],
        ];
    }
    return $out;
}

try {
    set_time_limit(0);
    $config = catalog_config();
    $db = catalog_db($config);

    $verifiedWithoutFormat2 = retirement_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_files f '
        . 'LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
        . 'WHERE f.scan_status="verified" '
        . 'AND (m.file_id IS NULL OR m.format_version<>2)'
    );
    $verifiedCountMismatches = retirement_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_files f '
        . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2 '
        . 'WHERE f.scan_status="verified" AND ('
        . 'm.name_count<>f.name_count OR m.import_count<>f.import_count OR m.export_count<>f.export_count)'
    );

    $unverifiedFiles = retirement_scalar(
        $db,
        'SELECT COUNT(*) FROM ue_files WHERE scan_status="unverified"'
    );

    $tables = [];
    $remainingRows = 0;
    foreach (LEGACY_RETIREMENT_TABLES as $table) {
        $accounting = retirement_table_accounting($db, $table);
        $tables[$table] = $accounting;
        $remainingRows += (int)$accounting['total_rows'];
    }

    $legacyFileStatusSummary = $remainingRows === 0 ? [] : retirement_file_status_summary($db);
    $legacyFileSamples = $remainingRows === 0 ? [] : retirement_file_samples($db);

    $runningJobs = 0;
    try {
        $runningJobs = retirement_scalar(
            $db,
            'SELECT COUNT(*) FROM ue_background_jobs WHERE status="running"'
        );
    } catch (Throwable) {
        // Older or partially installed environments may not have the durable jobs table.
    }

    $dataReady = $verifiedWithoutFormat2 === 0
        && $verifiedCountMismatches === 0
        && $remainingRows === 0
        && $runningJobs === 0;

    $result = [
        'ok' => $dataReady,
        'database_checked' => true,
        'verified_without_format2' => $verifiedWithoutFormat2,
        'verified_count_mismatches' => $verifiedCountMismatches,
        'unverified_files' => $unverifiedFiles,
        'running_background_jobs' => $runningJobs,
        'legacy_rows_total' => $remainingRows,
        'tables' => $tables,
        'legacy_file_status_summary' => $legacyFileStatusSummary,
        'legacy_file_samples' => $legacyFileSamples,
        'legacy_data_empty' => $remainingRows === 0,
        'data_ready_for_source_retirement' => $dataReady,
        'next_step' => $remainingRows === 0
            ? 'Legacy data is empty. Remove remaining executable legacy-table code paths before creating the destructive table-drop migration.'
            : 'Do not drop legacy tables. Use legacy_file_status_summary and legacy_file_samples to decide whether the remaining duplicate/failed records need migration or intentional purge.',
    ];

    fwrite(STDOUT, json_encode(
        $result,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
    ) . PHP_EOL);
    exit($dataReady ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR, 'Legacy table retirement verification failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
