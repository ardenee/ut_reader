#!/usr/bin/env php
<?php
/**
 * Backfills the UE3 VerifyImport identity columns added to ue_export_path_lookup.
 *
 * Reads the authoritative existing .uedb4 metadata containers and updates only
 * the four UE3 identity columns. It does not rewrite metadata containers,
 * search projections, dependency rows, or non-UE3 files.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\CompressedMetadataLookupWriter;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoContention;

$options = getopt('', [
    'dry-run',
    'apply',
    'after-id::',
    'limit::',
    'continuous',
    'workers::',
    'worker-index::',
    'worker-count::',
    'storage-root::',
]);

$apply = array_key_exists('apply', $options);
$continuous = array_key_exists('continuous', $options);
$afterId = isset($options['after-id']) ? max(0, (int)$options['after-id']) : 0;
$limit = isset($options['limit']) ? max(1, min(10000, (int)$options['limit'])) : 100;
$workers = isset($options['workers']) ? max(1, min(8, (int)$options['workers'])) : 1;
$workerIndex = isset($options['worker-index']) ? (int)$options['worker-index'] : -1;
$workerCount = isset($options['worker-count']) ? max(1, (int)$options['worker-count']) : 1;

if ($workerIndex >= $workerCount || $workerIndex < -1) {
    throw new InvalidArgumentException('Invalid worker index/count.');
}

if ($workers > 1 && $workerIndex < 0) {
    $children = [];
    $exitCode = 0;
    fwrite(
        STDERR,
        'Starting ' . $workers . ' UE3 identity backfill workers'
        . ($continuous ? ' in continuous mode' : '')
        . ' | batch_limit=' . $limit . PHP_EOL
    );
    for ($index = 0; $index < $workers; $index++) {
        $command = [
            PHP_BINARY,
            __FILE__,
            '--worker-index=' . $index,
            '--worker-count=' . $workers,
            '--after-id=' . $afterId,
            '--limit=' . $limit,
        ];
        if ($apply) {
            $command[] = '--apply';
        } else {
            $command[] = '--dry-run';
        }
        if ($continuous) {
            $command[] = '--continuous';
        }
        if (isset($options['storage-root'])) {
            $command[] = '--storage-root=' . (string)$options['storage-root'];
        }

        $process = proc_open(
            $command,
            [
                0 => ['file', 'php://stdin', 'r'],
                1 => ['file', 'php://stdout', 'w'],
                2 => ['file', 'php://stderr', 'w'],
            ],
            $pipes
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Could not launch UE3 backfill worker ' . $index . '.');
        }
        $status = proc_get_status($process);
        fwrite(
            STDERR,
            'Launched worker ' . ($index + 1) . '/' . $workers
            . ' | pid=' . (int)($status['pid'] ?? 0) . PHP_EOL
        );
        $children[$index] = ['process' => $process, 'closed' => false];
    }

    while (true) {
        $running = 0;
        foreach ($children as $index => &$child) {
            if ($child['closed']) {
                continue;
            }
            $status = proc_get_status($child['process']);
            if ((bool)($status['running'] ?? false)) {
                $running++;
                continue;
            }
            $observed = (int)($status['exitcode'] ?? -1);
            $closed = proc_close($child['process']);
            $code = $observed >= 0 ? $observed : $closed;
            $child['closed'] = true;
            if ($code !== 0) {
                $exitCode = 2;
                fwrite(STDERR, 'Worker ' . ($index + 1) . ' exited with code ' . $code . PHP_EOL);
            }
        }
        unset($child);
        if ($running === 0) {
            break;
        }
        usleep(100000);
    }
    exit($exitCode);
}

if ($workerIndex < 0) {
    $workerIndex = 0;
    $workerCount = 1;
}

$config = catalog_config();
$db = catalog_db($config);
$configuredStorageRoot = trim((string)($config['storage_path'] ?? ''));
$storageRootInput = rtrim(trim((string)($options['storage-root'] ?? $configuredStorageRoot)), "\\/");
if ($storageRootInput === '') {
    throw new RuntimeException('Metadata storage root is required via catalog.storage_path or --storage-root.');
}
$storageRoot = strcasecmp(basename(str_replace('\\', '/', $storageRootInput)), 'metadata') === 0
    ? dirname($storageRootInput)
    : $storageRootInput;

$schemaColumns = [];
$columnStatement = $db->query('SHOW COLUMNS FROM ue_export_path_lookup');
while (($column = $columnStatement->fetch(PDO::FETCH_ASSOC)) !== false) {
    $schemaColumns[(string)$column['Field']] = true;
}
foreach (['class_package_term_id', 'class_name_term_id', 'object_flags', 'outer_index'] as $requiredColumn) {
    if (!isset($schemaColumns[$requiredColumn])) {
        throw new RuntimeException(
            'Schema migration 202609250001 is not applied; missing ue_export_path_lookup.'
            . $requiredColumn . '. Run php catalog/bin/migrate.php first.'
        );
    }
}

$reader = new BlockedCompressedMetadataReader($db, $storageRoot);
$writer = new CompressedMetadataLookupWriter($db);

$countSql =
    'SELECT COUNT(*) files,COALESCE(SUM(q.incomplete_rows),0) incomplete_rows,'
    . 'COALESCE(SUM(GREATEST(q.export_count-q.projected_rows,0)),0) missing_rows'
    . ' FROM (SELECT f.id,m.export_count,COUNT(l.export_index) projected_rows,'
    . 'SUM(CASE WHEN l.export_index IS NOT NULL AND (l.object_flags IS NULL OR l.outer_index IS NULL)'
    . ' THEN 1 ELSE 0 END) incomplete_rows'
    . ' FROM ue_files f'
    . ' JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=4'
    . ' JOIN ue_games g ON g.id=f.game_id'
    . ' JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1'
    . ' LEFT JOIN ue_export_path_lookup l ON l.file_id=f.id'
    . ' WHERE f.scan_status="verified"'
    . ' AND UPPER(TRIM(p.engine_key))="UE3"'
$countArgs = [];
if ($workerCount > 1) {
    $countSql .= ' AND MOD(f.id,?)=?';
    $countArgs[] = $workerCount;
    $countArgs[] = $workerIndex;
}
$countSql .= ' GROUP BY f.id,m.export_count'
    . ' HAVING projected_rows<>m.export_count OR incomplete_rows>0) q';
$countStatement = $db->prepare($countSql);
$countStatement->execute($countArgs);
$counts = $countStatement->fetch(PDO::FETCH_ASSOC) ?: [];

if (!$apply) {
    echo json_encode([
        'ok' => true,
        'apply' => false,
        'dry_run' => true,
        'engine' => 'UE3',
        'candidate_files' => (int)($counts['files'] ?? 0),
        'rows_requiring_backfill' => (int)($counts['incomplete_rows'] ?? 0),
        'missing_projection_rows' => (int)($counts['missing_rows'] ?? 0),
        'after_id' => $afterId,
        'worker_index' => $workerIndex,
        'worker_count' => $workerCount,
        'storage_root' => $storageRoot,
        'note' => 'No rows were changed.',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

fwrite(
    STDERR,
    '[worker ' . ($workerIndex + 1) . '/' . $workerCount . '] UE3 identity backfill started'
    . ' | pid=' . getmypid()
    . ' | candidate_files=' . (int)($counts['files'] ?? 0)
    . ' | incomplete_rows=' . (int)($counts['incomplete_rows'] ?? 0)
    . ' | missing_projection_rows=' . (int)($counts['missing_rows'] ?? 0)
    . ' | after_id=' . $afterId
    . ' | limit=' . $limit
    . PHP_EOL
);

$totalSelected = 0;
$totalBackfilled = 0;
$totalRows = 0;
$totalFailed = 0;
$currentAfterId = $afterId;
$lastId = $afterId;
$failures = [];
$batchNumber = 0;

do {
    $batchNumber++;
    $sql =
        'SELECT f.id,f.package_name,m.import_count,m.export_count'
        . ' FROM ue_files f'
        . ' JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=4'
        . ' JOIN ue_games g ON g.id=f.game_id'
        . ' JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1'
        . ' WHERE f.scan_status="verified" AND UPPER(TRIM(p.engine_key))="UE3" AND f.id>?'
        . ' AND (EXISTS (SELECT 1 FROM ue_export_path_lookup l'
        . ' WHERE l.file_id=f.id AND (l.object_flags IS NULL OR l.outer_index IS NULL))'
        . ' OR (SELECT COUNT(*) FROM ue_export_path_lookup l2 WHERE l2.file_id=f.id)<>m.export_count)';
    $args = [$currentAfterId];
    if ($workerCount > 1) {
        $sql .= ' AND MOD(f.id,?)=?';
        $args[] = $workerCount;
        $args[] = $workerIndex;
    }
    $sql .= ' ORDER BY f.id LIMIT ' . $limit;

    $statement = $db->prepare($sql);
    $statement->execute($args);
    $files = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($files === []) {
        break;
    }

    $totalSelected += count($files);
    foreach ($files as $position => $file) {
        $fileId = (int)$file['id'];
        $lastId = max($lastId, $fileId);
        $currentAfterId = $lastId;
        try {
            $imports = [];
            $importCount = (int)$file['import_count'];
            for ($start = 0; $start < $importCount; $start += 5000) {
                array_push($imports, ...$reader->page($fileId, 'imports', $start, min(5000, $importCount - $start)));
            }

            $exports = [];
            $exportCount = (int)$file['export_count'];
            for ($start = 0; $start < $exportCount; $start += 5000) {
                array_push($exports, ...$reader->page($fileId, 'exports', $start, min(5000, $exportCount - $start)));
            }

            if (count($imports) !== $importCount || count($exports) !== $exportCount) {
                throw new RuntimeException(
                    'v4 metadata row count mismatch: imports=' . count($imports) . '/' . $importCount
                    . ', exports=' . count($exports) . '/' . $exportCount . '.'
                );
            }

            $result = null;
            for ($attempt = 1; ; $attempt++) {
                try {
                    $result = $writer->backfillUe3ExportIdentityProjection(
                        $fileId,
                        (string)$file['package_name'],
                        $imports,
                        $exports
                    );
                    break;
                } catch (Throwable $error) {
                    if (!PdoContention::retryable($error) || $attempt >= 8) {
                        throw $error;
                    }
                    usleep(PdoContention::backoffMicros($attempt, 100000));
                }
            }

            $check = $db->prepare(
                'SELECT COUNT(*) projected_rows,'
                . 'SUM(CASE WHEN object_flags IS NULL OR outer_index IS NULL THEN 1 ELSE 0 END) incomplete_rows'
                . ' FROM ue_export_path_lookup WHERE file_id=?'
            );
            $check->execute([$fileId]);
            $verifiedProjection = $check->fetch(PDO::FETCH_ASSOC) ?: [];
            $projectedRows = (int)($verifiedProjection['projected_rows'] ?? 0);
            $remaining = (int)($verifiedProjection['incomplete_rows'] ?? 0);
            if ($projectedRows !== $exportCount || $remaining !== 0) {
                throw new RuntimeException(
                    'Backfill verification mismatch: projected=' . $projectedRows . '/' . $exportCount
                    . ', incomplete=' . $remaining . '.'
                );
            }

            $totalBackfilled++;
            $totalRows += (int)($result['rows'] ?? 0);
        } catch (Throwable $error) {
            $totalFailed++;
            $failure = [
                'file_id' => $fileId,
                'error' => get_class($error) . ': ' . $error->getMessage(),
            ];
            $failures[] = $failure;
            fwrite(STDERR, '[worker ' . ($workerIndex + 1) . '/' . $workerCount . '] FAILED file='
                . $fileId . ' | ' . $failure['error'] . PHP_EOL);
        }

        if (($position + 1) <= 10 || (($position + 1) % 25) === 0) {
            fwrite(
                STDERR,
                '[worker ' . ($workerIndex + 1) . '/' . $workerCount . '] batch=' . $batchNumber
                . ' processed=' . ($position + 1) . '/' . count($files)
                . ' | files_backfilled=' . $totalBackfilled
                . ' | rows_updated=' . $totalRows
                . ' | failed=' . $totalFailed
                . ' | last_id=' . $lastId
                . PHP_EOL
            );
        }
    }

    if (!$continuous || count($files) < $limit) {
        break;
    }
} while (true);

echo json_encode([
    'ok' => $totalFailed === 0,
    'apply' => true,
    'engine' => 'UE3',
    'continuous' => $continuous,
    'worker_index' => $workerIndex,
    'worker_count' => $workerCount,
    'selected' => $totalSelected,
    'files_backfilled' => $totalBackfilled,
    'rows_updated' => $totalRows,
    'failed' => $totalFailed,
    'after_id' => $afterId,
    'last_id' => $lastId,
    'failure_results' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($totalFailed === 0 ? 0 : 2);
