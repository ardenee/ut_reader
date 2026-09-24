#!/usr/bin/env php
<?php
/**
 * Offline .uedb3 -> .uedb4 migration.
 *
 * Production runtime is format-4 only. This command is the only supported
 * bridge from the immediately previous format. Future v5+ cutovers should
 * copy this pattern into a new version-specific migration directory.
 *
 * --continuous keeps selecting the next batch until no matching v3 rows remain.
 * --workers=N launches N child processes over disjoint file-id modulo shards.
 * The worker split makes concurrent conversion deterministic: one file can be
 * selected by exactly one worker.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogSupport.php';
require_once __DIR__ . '/v4-migration/MetadataReaderV3.php';
require_once __DIR__ . '/v4-migration/SnapshotLoaderV3.php';

use UnrealDb\Catalog\MigrationV4\MetadataReaderV3;
use UnrealDb\Catalog\MigrationV4\SnapshotLoaderV3;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogCompactIdentityEnricher;

$options = getopt('', [
    'apply',
    'game-id::',
    'engine::',
    'after-id::',
    'limit::',
    'keep-v3',
    'storage-root::',
    'continuous',
    'workers::',
    'worker-index::',
    'worker-count::',
]);

$apply = array_key_exists('apply', $options);
$keepV3 = array_key_exists('keep-v3', $options);
$continuous = array_key_exists('continuous', $options);
$gameId = isset($options['game-id']) ? max(0, (int)$options['game-id']) : 0;
$engine = strtoupper(trim((string)($options['engine'] ?? '')));
$afterId = isset($options['after-id']) ? max(0, (int)$options['after-id']) : 0;
$limit = isset($options['limit']) ? max(1, min(1000000, (int)$options['limit'])) : 1000;
$workers = isset($options['workers']) ? max(1, min(8, (int)$options['workers'])) : 1;
$workerIndex = isset($options['worker-index']) ? (int)$options['worker-index'] : -1;
$workerCount = isset($options['worker-count']) ? max(1, (int)$options['worker-count']) : 1;

if ($workerIndex >= $workerCount) {
    throw new InvalidArgumentException('--worker-index must be smaller than --worker-count.');
}
if ($workerIndex < -1) {
    throw new InvalidArgumentException('--worker-index must be zero or greater.');
}

/**
 * Parent/coordinator mode.
 *
 * proc_open() accepts an argv array on supported PHP 8.x builds, avoiding
 * Windows shell quoting problems. Each child receives a disjoint MOD(file_id,N)
 * shard and performs the ordinary migration loop below.
 */
if ($workers > 1 && $workerIndex < 0) {
    $children = [];
    $exitCode = 0;

    fwrite(
        STDERR,
        'Starting ' . $workers . ' migration workers'
        . ($continuous ? ' in continuous mode' : '')
        . ' | batch_limit=' . $limit . PHP_EOL
    );

    for ($index = 0; $index < $workers; $index++) {
        $command = [
            PHP_BINARY,
            __FILE__,
            '--worker-index=' . $index,
            '--worker-count=' . $workers,
            '--limit=' . $limit,
            '--after-id=' . $afterId,
        ];
        if ($apply) {
            $command[] = '--apply';
        }
        if ($continuous) {
            $command[] = '--continuous';
        }
        if ($keepV3) {
            $command[] = '--keep-v3';
        }
        if ($gameId > 0) {
            $command[] = '--game-id=' . $gameId;
        }
        if ($engine !== '') {
            $command[] = '--engine=' . $engine;
        }
        if (isset($options['storage-root'])) {
            $command[] = '--storage-root=' . (string)$options['storage-root'];
        }

        $descriptorSpec = [
            0 => ['file', 'php://stdin', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Could not launch migration worker ' . $index . '.');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $children[$index] = [
            'process' => $process,
            'stdout' => $pipes[1],
            'stderr' => $pipes[2],
            'closed' => false,
        ];
    }

    while (true) {
        $running = 0;
        foreach ($children as $index => &$child) {
            if ($child['closed']) {
                continue;
            }

            foreach (['stdout' => STDOUT, 'stderr' => STDERR] as $pipeName => $destination) {
                $data = stream_get_contents($child[$pipeName]);
                if (is_string($data) && $data !== '') {
                    fwrite($destination, $data);
                }
            }

            $status = proc_get_status($child['process']);
            if ((bool)($status['running'] ?? false)) {
                $running++;
                continue;
            }

            foreach (['stdout' => STDOUT, 'stderr' => STDERR] as $pipeName => $destination) {
                $data = stream_get_contents($child[$pipeName]);
                if (is_string($data) && $data !== '') {
                    fwrite($destination, $data);
                }
                fclose($child[$pipeName]);
            }

            $observedExitCode = (int)($status['exitcode'] ?? -1);
            $closedExitCode = proc_close($child['process']);
            $code = $observedExitCode >= 0 ? $observedExitCode : $closedExitCode;
            $child['closed'] = true;
            if ($code !== 0) {
                $exitCode = 2;
                fwrite(STDERR, 'Worker ' . $index . ' exited with code ' . $code . PHP_EOL);
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
$storageRootInput = trim((string)($options['storage-root'] ?? $configuredStorageRoot));
$storageRootInput = rtrim($storageRootInput, "\\/");
if ($storageRootInput === '') {
    throw new RuntimeException('Metadata storage root is required via catalog.storage_path or --storage-root.');
}
$storageRoot = strcasecmp(basename(str_replace('\\', '/', $storageRootInput)), 'metadata') === 0
    ? dirname($storageRootInput)
    : $storageRootInput;
$metadataRoot = $storageRoot . DIRECTORY_SEPARATOR . 'metadata';

$loader = new SnapshotLoaderV3($db, $storageRoot);
$writer = new BlockedCompressedMetadataSnapshotWriter($db, $storageRoot);

$totalSelected = 0;
$totalConverted = 0;
$totalFailed = 0;
$currentAfterId = $afterId;
$lastId = $afterId;
$results = [];
$batchNumber = 0;

do {
    $batchNumber++;

    $sql = 'SELECT f.id,f.game_id,f.package_name,UPPER(TRIM(COALESCE(p.engine_key,""))) engine_key'
        . ' FROM ue_files f'
        . ' JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=3'
        . ' JOIN ue_games g ON g.id=f.game_id'
        . ' LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1'
        . ' WHERE f.scan_status="verified" AND f.id>?';
    $args = [$currentAfterId];

    if ($workerCount > 1) {
        $sql .= ' AND MOD(f.id,?)=?';
        $args[] = $workerCount;
        $args[] = $workerIndex;
    }
    if ($gameId > 0) {
        $sql .= ' AND f.game_id=?';
        $args[] = $gameId;
    }
    if ($engine !== '') {
        $sql .= ' AND UPPER(TRIM(COALESCE(p.engine_key,"")))=?';
        $args[] = $engine;
    }
    $sql .= ' ORDER BY f.id LIMIT ' . $limit;

    $statement = $db->prepare($sql);
    $statement->execute($args);
    $files = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($files === []) {
        if ($continuous) {
            fwrite(
                STDERR,
                '[worker ' . ($workerIndex + 1) . '/' . $workerCount . '] complete'
                . ' | converted=' . $totalConverted
                . ' | failed=' . $totalFailed
                . ' | last_id=' . $lastId . PHP_EOL
            );
        }
        break;
    }

    $batchConverted = 0;
    $batchFailed = 0;
    $totalSelected += count($files);

    foreach ($files as $position => $file) {
        $fileId = (int)$file['id'];
        $rowGameId = (int)$file['game_id'];
        $engineKey = strtoupper(trim((string)$file['engine_key']));
        $lastId = max($lastId, $fileId);
        $currentAfterId = $lastId;

        $v3Path = MetadataReaderV3::path($storageRoot, $rowGameId, $fileId);
        $v4Path = BlockedCompressedMetadataContainer::path($storageRoot, $rowGameId, $fileId);

        if (!$apply) {
            if (count($results) < 100) {
                $results[] = [
                    'file_id' => $fileId,
                    'status' => 'would_convert',
                    'engine' => $engineKey,
                    'v3_path' => $v3Path,
                    'v4_path' => $v4Path,
                ];
            }
            continue;
        }

        try {
            if (!is_file($v3Path)) {
                throw new RuntimeException('Registered format-3 metadata file is missing: ' . $v3Path);
            }

            $snapshot = $loader->load($fileId);
            $snapshot = CatalogCompactIdentityEnricher::enrich($snapshot, $engineKey);

            $write = $writer->write($snapshot);
            if ((int)($write['format_version'] ?? 0) !== 4 || !is_file($v4Path)) {
                throw new RuntimeException('Format-4 publication did not complete for file #' . $fileId . '.');
            }

            $v3Deleted = false;
            $cleanupPending = false;
            if (!$keepV3) {
                clearstatcache(true, $v3Path);
                if (!is_file($v3Path)) {
                    $v3Deleted = true;
                } elseif (@unlink($v3Path)) {
                    $v3Deleted = true;
                } else {
                    $cleanupPending = true;
                }
            }

            $batchConverted++;
            $totalConverted++;
            if (count($results) < 100) {
                $results[] = [
                    'file_id' => $fileId,
                    'status' => $cleanupPending ? 'converted_cleanup_pending' : 'converted',
                    'engine' => $engineKey,
                    'v3_deleted' => $v3Deleted,
                    'cleanup_pending' => $cleanupPending,
                    'compressed_size' => (int)($write['compressed_size'] ?? 0),
                    'sql_batches' => (int)($write['sql_batches'] ?? 0),
                ];
            }
        } catch (Throwable $error) {
            $batchFailed++;
            $totalFailed++;
            if (count($results) < 100) {
                $results[] = [
                    'file_id' => $fileId,
                    'status' => 'failed',
                    'error' => get_class($error) . ': ' . $error->getMessage(),
                ];
            }
        }

        if ((($position + 1) % 25) === 0) {
            fwrite(
                STDERR,
                '[worker ' . ($workerIndex + 1) . '/' . $workerCount . '] '
                . 'batch=' . $batchNumber
                . ' processed=' . ($position + 1) . '/' . count($files)
                . ' | total_converted=' . $totalConverted
                . ' | total_failed=' . $totalFailed
                . ' | last_id=' . $lastId . PHP_EOL
            );
        }
    }

    if (!$apply) {
        break;
    }

    if ($batchFailed > 0) {
        fwrite(
            STDERR,
            '[worker ' . ($workerIndex + 1) . '/' . $workerCount . '] stopping after batch '
            . $batchNumber . ' because ' . $batchFailed . ' conversion(s) failed.' . PHP_EOL
        );
        break;
    }

    if (!$continuous || count($files) < $limit) {
        break;
    }
} while (true);

echo json_encode([
    'ok' => $totalFailed === 0,
    'apply' => $apply,
    'continuous' => $continuous,
    'worker_index' => $workerIndex,
    'worker_count' => $workerCount,
    'storage_root' => $storageRoot,
    'metadata_root' => $metadataRoot,
    'keep_v3' => $keepV3,
    'selected' => $totalSelected,
    'converted' => $totalConverted,
    'failed' => $totalFailed,
    'after_id' => $afterId,
    'last_id' => $lastId,
    'limit' => $limit,
    'game_id' => $gameId > 0 ? $gameId : null,
    'engine' => $engine !== '' ? $engine : null,
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($totalFailed === 0 ? 0 : 2);
