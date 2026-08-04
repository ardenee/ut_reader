#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Application\Maintenance\LegacyMetadataRuntimeAudit;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupportCore.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

const LEGACY_RECLAIM_CONFIRMATION = 'REBUILD_LEGACY_TABLE_SPACE';
const LEGACY_RECLAIM_LOCK = 'unrealdb_legacy_table_space_reclaim_v1';
const LEGACY_RECLAIM_TABLES = [
    'ue_dependencies',
    'ue_imports',
    'ue_names',
    'ue_exports',
];

/** @return int */
function reclaim_scalar(PDO $db, string $sql, array $args = []): int
{
    $statement = $db->prepare($sql);
    $statement->execute($args);
    return (int)($statement->fetchColumn() ?: 0);
}

/** @return array<string,mixed> */
function reclaim_table_stats(PDO $db, string $table): array
{
    $statement = $db->prepare(
        'SELECT TABLE_NAME,ENGINE,TABLE_ROWS,DATA_LENGTH,INDEX_LENGTH,DATA_FREE '
        . 'FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?'
    );
    $statement->execute([$table]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('Required table is missing: ' . $table . '.');
    }

    $dataBytes = (int)($row['DATA_LENGTH'] ?? 0);
    $indexBytes = (int)($row['INDEX_LENGTH'] ?? 0);
    return [
        'table' => $table,
        'engine' => (string)($row['ENGINE'] ?? ''),
        'estimated_rows' => (int)($row['TABLE_ROWS'] ?? 0),
        'data_bytes' => $dataBytes,
        'index_bytes' => $indexBytes,
        'allocated_bytes' => $dataBytes + $indexBytes,
        'reported_free_bytes' => (int)($row['DATA_FREE'] ?? 0),
    ];
}

/** @return array<string,string> */
function reclaim_variables(PDO $db): array
{
    $names = ['datadir', 'tmpdir', 'innodb_tmpdir', 'innodb_file_per_table'];
    $values = [];
    foreach ($names as $name) {
        $statement = $db->query('SHOW VARIABLES LIKE ' . $db->quote($name));
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $values[$name] = is_array($row) ? (string)($row['Value'] ?? '') : '';
    }
    return $values;
}

/** @return array{path:string,probe:string,available:bool,free_bytes:?int,total_bytes:?int} */
function reclaim_drive(string $path): array
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

function reclaim_same_drive(string $left, string $right): bool
{
    if (preg_match('/^([A-Za-z]):/', $left, $leftMatch) === 1
        && preg_match('/^([A-Za-z]):/', $right, $rightMatch) === 1) {
        return strcasecmp($leftMatch[1], $rightMatch[1]) === 0;
    }
    return realpath($left) !== false
        && realpath($right) !== false
        && strcasecmp((string)realpath($left), (string)realpath($right)) === 0;
}

/** @return array<string,mixed> */
function reclaim_preflight(PDO $db, string $table, string $tmpOverride): array
{
    $variables = reclaim_variables($db);
    $stats = reclaim_table_stats($db, $table);
    $exactRows = reclaim_scalar($db, 'SELECT COUNT(*) FROM ' . $table);
    $verifiedRows = reclaim_scalar(
        $db,
        'SELECT COUNT(*) FROM ' . $table . ' legacy '
        . 'JOIN ue_files f ON f.id=legacy.file_id AND f.scan_status="verified" '
        . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=2'
    );

    $runningJobs = 0;
    try {
        $runningJobs = reclaim_scalar(
            $db,
            'SELECT COUNT(*) FROM ue_background_jobs WHERE status="running"'
        );
    } catch (Throwable) {
        // Older installations may not have the durable jobs table.
    }

    $dataDrive = reclaim_drive((string)$variables['datadir']);
    $effectiveTmp = $tmpOverride !== ''
        ? $tmpOverride
        : (trim((string)$variables['innodb_tmpdir']) !== ''
            ? (string)$variables['innodb_tmpdir']
            : (string)$variables['tmpdir']);
    $tmpDrive = reclaim_drive($effectiveTmp);
    $sameDrive = reclaim_same_drive((string)$variables['datadir'], $effectiveTmp);

    $allocated = (int)$stats['allocated_bytes'];
    $dataRequired = (int)ceil($allocated * 1.20);
    $tmpRequired = (int)ceil($allocated * 1.20);
    $combinedRequired = (int)ceil($allocated * 2.20);
    $dataFree = is_int($dataDrive['free_bytes']) ? (int)$dataDrive['free_bytes'] : 0;
    $tmpFree = is_int($tmpDrive['free_bytes']) ? (int)$tmpDrive['free_bytes'] : 0;

    $blockers = [];
    if (strtolower((string)$stats['engine']) !== 'innodb') {
        $blockers[] = $table . ' is not an InnoDB table.';
    }
    if (strtolower(trim((string)$variables['innodb_file_per_table'])) !== 'on') {
        $blockers[] = 'innodb_file_per_table is not ON.';
    }
    if ($verifiedRows !== 0) {
        $blockers[] = $verifiedRows . ' verified format-2 legacy row(s) still remain in ' . $table . '.';
    }
    if ($runningJobs !== 0) {
        $blockers[] = $runningJobs . ' background job(s) are running.';
    }
    if ((int)(LegacyMetadataRuntimeAudit::scan(dirname(__DIR__))['references'] ?? 0) !== 0) {
        $blockers[] = 'Unapproved runtime legacy-table references remain.';
    }
    if (!$dataDrive['available']) {
        $blockers[] = 'Could not determine free space on the MySQL data drive.';
    }
    if (!$tmpDrive['available']) {
        $blockers[] = 'Could not determine free space on the effective temporary drive.';
    }
    if ($tmpOverride !== '' && !is_dir($tmpOverride)) {
        $blockers[] = 'The requested innodb_tmpdir does not exist: ' . $tmpOverride . '.';
    }
    if ($sameDrive) {
        if ($dataFree < $combinedRequired) {
            $blockers[] = 'The shared data/temp drive needs at least ' . $combinedRequired
                . ' free bytes; only ' . $dataFree . ' are available.';
        }
    } else {
        if ($dataFree < $dataRequired) {
            $blockers[] = 'The data drive needs at least ' . $dataRequired
                . ' free bytes; only ' . $dataFree . ' are available.';
        }
        if ($tmpFree < $tmpRequired) {
            $blockers[] = 'The temporary drive needs at least ' . $tmpRequired
                . ' free bytes; only ' . $tmpFree . ' are available.';
        }
    }

    return [
        'safe_to_apply' => $blockers === [],
        'blockers' => $blockers,
        'table' => $table,
        'exact_rows' => $exactRows,
        'verified_format2_rows' => $verifiedRows,
        'table_stats' => $stats,
        'mysql' => $variables,
        'effective_innodb_tmpdir' => $effectiveTmp,
        'data_and_temp_share_drive' => $sameDrive,
        'data_drive' => $dataDrive,
        'temporary_drive' => $tmpDrive,
        'space_requirements' => [
            'data_drive_bytes' => $dataRequired,
            'temporary_drive_bytes' => $tmpRequired,
            'shared_drive_bytes' => $combinedRequired,
        ],
        'running_jobs' => $runningJobs,
    ];
}

try {
    set_time_limit(0);
    $options = getopt('', ['table:', 'apply::', 'innodb-tmpdir::']);
    $table = trim((string)($options['table'] ?? ''));
    $apply = trim((string)($options['apply'] ?? ''));
    $tmpOverride = trim((string)($options['innodb-tmpdir'] ?? ''));

    if (!in_array($table, LEGACY_RECLAIM_TABLES, true)) {
        throw new InvalidArgumentException(
            'Use --table with one of: ' . implode(', ', LEGACY_RECLAIM_TABLES) . '.'
        );
    }

    $config = catalog_config();
    $db = catalog_db($config);
    $preflight = reclaim_preflight($db, $table, $tmpOverride);

    $baseCommand = 'php catalog/bin/reclaim-legacy-table-space.php --table=' . $table;
    if ($tmpOverride !== '') {
        $baseCommand .= ' --innodb-tmpdir=' . escapeshellarg($tmpOverride);
    }

    if ($apply === '') {
        fwrite(STDOUT, json_encode([
            'mode' => 'dry-run',
            'confirmation_token' => LEGACY_RECLAIM_CONFIRMATION,
            'apply_command' => $baseCommand . ' --apply=' . LEGACY_RECLAIM_CONFIRMATION,
        ] + $preflight, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(!empty($preflight['safe_to_apply']) ? 0 : 2);
    }

    if ($apply !== LEGACY_RECLAIM_CONFIRMATION) {
        throw new RuntimeException('Incorrect apply token. No table was changed.');
    }
    if (empty($preflight['safe_to_apply'])) {
        throw new RuntimeException('Preflight failed: ' . implode(' ', (array)$preflight['blockers']));
    }

    $lock = $db->prepare('SELECT GET_LOCK(?,0)');
    $lock->execute([LEGACY_RECLAIM_LOCK]);
    if ((int)$lock->fetchColumn() !== 1) {
        throw new RuntimeException('Another legacy table reclaim operation is already running.');
    }

    $started = microtime(true);
    $beforeRows = (int)$preflight['exact_rows'];
    $beforeStats = (array)$preflight['table_stats'];
    $beforeDrive = reclaim_drive((string)$preflight['mysql']['datadir']);

    try {
        if ($tmpOverride !== '') {
            $statement = $db->prepare('SET SESSION innodb_tmpdir=?');
            $statement->execute([$tmpOverride]);
        }
        $db->exec('SET SESSION lock_wait_timeout=60');

        $statement = $db->query('OPTIMIZE TABLE ' . $table);
        $optimizeMessages = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($optimizeMessages as $message) {
            if (strtolower((string)($message['Msg_type'] ?? '')) === 'error') {
                throw new RuntimeException((string)($message['Msg_text'] ?? 'OPTIMIZE TABLE failed.'));
            }
        }

        $checkStatement = $db->query('CHECK TABLE ' . $table . ' MEDIUM');
        $checkMessages = $checkStatement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($checkMessages as $message) {
            if (strtolower((string)($message['Msg_type'] ?? '')) === 'error') {
                throw new RuntimeException((string)($message['Msg_text'] ?? 'CHECK TABLE failed.'));
            }
        }

        $afterRows = reclaim_scalar($db, 'SELECT COUNT(*) FROM ' . $table);
        if ($afterRows !== $beforeRows) {
            throw new RuntimeException(
                'Exact row count changed during rebuild: before=' . $beforeRows . ', after=' . $afterRows . '.'
            );
        }

        $afterStats = reclaim_table_stats($db, $table);
        $afterDrive = reclaim_drive((string)$preflight['mysql']['datadir']);
        $driveDelta = is_int($beforeDrive['free_bytes']) && is_int($afterDrive['free_bytes'])
            ? (int)$afterDrive['free_bytes'] - (int)$beforeDrive['free_bytes']
            : null;

        fwrite(STDOUT, json_encode([
            'mode' => 'apply',
            'verified' => true,
            'table' => $table,
            'row_count_before' => $beforeRows,
            'row_count_after' => $afterRows,
            'row_count_unchanged' => true,
            'before' => $beforeStats,
            'after' => $afterStats,
            'data_drive_before' => $beforeDrive,
            'data_drive_after' => $afterDrive,
            'data_drive_free_byte_delta' => $driveDelta,
            'optimize_messages' => $optimizeMessages,
            'check_messages' => $checkMessages,
            'elapsed_seconds' => round(microtime(true) - $started, 2),
            'next_step' => 'Run plan-legacy-table-space-reclaim.php again before rebuilding another table.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    } finally {
        try {
            $release = $db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([LEGACY_RECLAIM_LOCK]);
        } catch (Throwable) {
            // Closing the connection also releases the advisory lock.
        }
    }

    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Legacy table space reclaim failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
