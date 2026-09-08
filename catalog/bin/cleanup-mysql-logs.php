#!/usr/bin/env php
<?php
/**
 * Audit and optionally bound MySQL server log growth.
 *
 * The primary target is binary logs: UnrealDB performs many large imports and
 * migrations, so ROW binlogging can consume tens of GB quickly if expiry is not
 * configured. This command is dry-run by default.
 *
 * Usage:
 *   php catalog/bin/cleanup-mysql-logs.php
 *   php catalog/bin/cleanup-mysql-logs.php --keep-days=7
 *   php catalog/bin/cleanup-mysql-logs.php --keep-days=7 --apply
 *   php catalog/bin/cleanup-mysql-logs.php --keep-days=7 --apply --force
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from PHP CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupportCore.php';

$options = getopt('', ['keep-days::', 'apply', 'force', 'no-persist', 'help']);
if (isset($options['help'])) {
    echo "Usage: php catalog/bin/cleanup-mysql-logs.php [--keep-days=7] [--apply] [--force] [--no-persist]\n";
    echo "Dry-run is the default. --apply purges expired binary logs and persists the expiry window when possible.\n";
    exit(0);
}

$keepDays = isset($options['keep-days']) ? (int)$options['keep-days'] : 7;
$keepDays = max(1, min(3650, $keepDays));
$apply = isset($options['apply']);
$force = isset($options['force']);
$persist = !isset($options['no-persist']);

/** @return string */
function mysql_log_variable(PDO $db, string $name): string
{
    $statement = $db->query('SHOW VARIABLES LIKE ' . $db->quote($name));
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? (string)($row['Value'] ?? '') : '';
}

/** @return list<array{name:string,size_bytes:int}> */
function mysql_binary_logs(PDO $db): array
{
    $rows = $db->query('SHOW BINARY LOGS')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $logs = [];
    foreach ($rows as $row) {
        $logs[] = [
            'name' => (string)($row['Log_name'] ?? ''),
            'size_bytes' => max(0, (int)($row['File_size'] ?? 0)),
        ];
    }
    return $logs;
}

/** @return array{detected:bool,detail:list<string>} */
function mysql_replication_state(PDO $db): array
{
    $detail = [];

    foreach ([
        'SHOW REPLICA STATUS',
        'SHOW SLAVE STATUS',
    ] as $sql) {
        try {
            $statement = $db->query($sql);
            $row = $statement !== false ? $statement->fetch(PDO::FETCH_ASSOC) : false;
            if (is_array($row) && $row !== []) {
                $detail[] = $sql . ': this server has replication state.';
                break;
            }
        } catch (Throwable) {
        }
    }

    foreach ([
        'SHOW REPLICAS',
        'SHOW SLAVE HOSTS',
    ] as $sql) {
        try {
            $statement = $db->query($sql);
            $rows = $statement !== false ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
            if (is_array($rows) && $rows !== []) {
                $detail[] = $sql . ': ' . count($rows) . ' downstream replica(s) reported.';
                break;
            }
        } catch (Throwable) {
        }
    }

    return [
        'detected' => $detail !== [],
        'detail' => $detail,
    ];
}

/** @return int|null */
function mysql_file_size(string $path): ?int
{
    $path = trim($path);
    if ($path === '' || !is_file($path)) {
        return null;
    }
    $size = @filesize($path);
    return $size === false ? null : max(0, (int)$size);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    $variables = [];
    foreach ([
        'log_bin',
        'log_bin_basename',
        'binlog_format',
        'binlog_expire_logs_seconds',
        'general_log',
        'general_log_file',
        'slow_query_log',
        'slow_query_log_file',
        'log_output',
    ] as $name) {
        $variables[$name] = mysql_log_variable($db, $name);
    }

    $logs = [];
    $binaryLogError = null;
    if (strtoupper((string)$variables['log_bin']) === 'ON') {
        try {
            $logs = mysql_binary_logs($db);
        } catch (Throwable $error) {
            $binaryLogError = $error->getMessage();
        }
    }

    $totalBytes = array_sum(array_column($logs, 'size_bytes'));
    $replication = mysql_replication_state($db);

    $serverCutoff = (string)$db->query(
        'SELECT DATE_FORMAT(DATE_SUB(NOW(),INTERVAL ' . $keepDays . ' DAY),"%Y-%m-%d %H:%i:%s")'
    )->fetchColumn();
    if ($serverCutoff === '') {
        throw new RuntimeException('Could not calculate the MySQL binary-log cutoff.');
    }

    $generalFile = trim((string)$variables['general_log_file']);
    $slowFile = trim((string)$variables['slow_query_log_file']);

    $before = [
        'binary_log_count' => count($logs),
        'binary_log_total_bytes' => $totalBytes,
        'oldest_binary_log' => $logs[0]['name'] ?? null,
        'current_binary_log' => $logs !== [] ? $logs[array_key_last($logs)]['name'] : null,
    ];

    $actions = [];
    $warnings = [];

    if ($binaryLogError !== null) {
        $warnings[] = 'Could not inspect binary logs: ' . $binaryLogError;
    }
    if ($replication['detected']) {
        foreach ($replication['detail'] as $detail) {
            $warnings[] = $detail;
        }
        if ($apply && !$force) {
            throw new RuntimeException(
                'Replication was detected. Refusing to purge binary logs without --force. '
                . 'Confirm every replica no longer requires logs older than the chosen retention window.'
            );
        }
    }

    if ($apply && strtoupper((string)$variables['log_bin']) === 'ON') {
        $db->exec('PURGE BINARY LOGS BEFORE ' . $db->quote($serverCutoff));
        $actions[] = 'Purged binary logs older than ' . $serverCutoff . ' (server local time).';

        if ($persist) {
            $seconds = $keepDays * 86400;
            try {
                $db->exec('SET PERSIST binlog_expire_logs_seconds=' . $seconds);
                $actions[] = 'Persisted binlog_expire_logs_seconds=' . $seconds . '.';
            } catch (Throwable $persistError) {
                try {
                    $db->exec('SET GLOBAL binlog_expire_logs_seconds=' . $seconds);
                    $actions[] = 'Set runtime binlog_expire_logs_seconds=' . $seconds . '.';
                    $warnings[] = 'SET PERSIST was unavailable: ' . $persistError->getMessage()
                        . '. The runtime setting may revert after MySQL restarts; set binlog_expire_logs_seconds='
                        . $seconds . ' in my.ini.';
                } catch (Throwable $globalError) {
                    $warnings[] = 'Could not set automatic binary-log expiry: ' . $globalError->getMessage()
                        . '. Set binlog_expire_logs_seconds=' . $seconds . ' in my.ini to prevent regrowth.';
                }
            }
        }
    }

    $afterLogs = $logs;
    if ($apply && strtoupper((string)$variables['log_bin']) === 'ON') {
        try {
            $afterLogs = mysql_binary_logs($db);
        } catch (Throwable) {
        }
    }

    $after = [
        'binary_log_count' => count($afterLogs),
        'binary_log_total_bytes' => array_sum(array_column($afterLogs, 'size_bytes')),
        'oldest_binary_log' => $afterLogs[0]['name'] ?? null,
        'current_binary_log' => $afterLogs !== [] ? $afterLogs[array_key_last($afterLogs)]['name'] : null,
    ];

    $output = [
        'ok' => true,
        'mode' => $apply ? 'apply' : 'dry_run',
        'keep_days' => $keepDays,
        'cutoff' => $serverCutoff,
        'mysql' => [
            'log_bin' => $variables['log_bin'],
            'binlog_format' => $variables['binlog_format'],
            'binlog_expire_logs_seconds' => $variables['binlog_expire_logs_seconds'],
            'log_bin_basename' => $variables['log_bin_basename'],
            'general_log' => $variables['general_log'],
            'general_log_file' => $generalFile,
            'general_log_file_bytes' => mysql_file_size($generalFile),
            'slow_query_log' => $variables['slow_query_log'],
            'slow_query_log_file' => $slowFile,
            'slow_query_log_file_bytes' => mysql_file_size($slowFile),
            'log_output' => $variables['log_output'],
        ],
        'replication' => $replication,
        'before' => $before,
        'after' => $after,
        'actions' => $actions,
        'warnings' => $warnings,
        'note' => $apply
            ? 'Binary-log history older than the retention window has been discarded. This can reduce point-in-time recovery history.'
            : 'Dry run only. Re-run with --apply after reviewing replication and recovery requirements.',
    ];

    fwrite(STDOUT, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, 'MySQL log cleanup failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
