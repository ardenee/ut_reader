#!/usr/bin/env php
<?php
/**
 * Purpose: Read-only verification of format-2 blocked metadata registration against on-disk .uedb2 containers.
 * Role: Post-maintenance integrity audit; never repairs, rewrites or deletes catalog metadata.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/lib/CatalogSupport.php';

$gameId = 0;
$withHash = false;
$withContainer = false;
$maxFailures = 100;

foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--hash') {
        $withHash = true;
        continue;
    }
    if ($argument === '--container') {
        $withContainer = true;
        $withHash = true;
        continue;
    }
    if (str_starts_with($argument, '--game=')) {
        $value = substr($argument, strlen('--game='));
        if ($value === '' || !ctype_digit($value) || (int)$value < 1) {
            fwrite(STDERR, "--game requires a positive integer game ID.\n");
            exit(1);
        }
        $gameId = (int)$value;
        continue;
    }
    if (str_starts_with($argument, '--max-failures=')) {
        $value = substr($argument, strlen('--max-failures='));
        if ($value === '' || !ctype_digit($value)) {
            fwrite(STDERR, "--max-failures requires a non-negative integer.\n");
            exit(1);
        }
        $maxFailures = max(0, min(1000, (int)$value));
        continue;
    }

    fwrite(STDERR, "Unknown argument: {$argument}\n");
    fwrite(STDERR, "Usage: php catalog/bin/verify-blocked-metadata-storage.php [--game=ID] [--hash] [--container] [--max-failures=N]\n");
    exit(1);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $storageRoot = trim((string)($config['storage_path'] ?? ''));
    if ($storageRoot === '') {
        throw new RuntimeException('catalog storage_path is not configured.');
    }

    $sql = 'SELECT f.id,f.game_id,f.original_name,f.name_count file_name_count,'
        . 'f.import_count file_import_count,f.export_count file_export_count,'
        . 'm.format_version,m.codec,m.compressed_size,m.payload_sha256,'
        . 'm.name_count metadata_name_count,m.import_count metadata_import_count,'
        . 'm.export_count metadata_export_count '
        . 'FROM ue_files f JOIN ue_file_metadata m ON m.file_id=f.id '
        . 'WHERE f.scan_status="verified" AND m.format_version=?';
    $arguments = [BlockedCompressedMetadataContainer::FORMAT_VERSION];
    if ($gameId > 0) {
        $sql .= ' AND f.game_id=?';
        $arguments[] = $gameId;
    }
    $sql .= ' ORDER BY f.id';

    $statement = $db->prepare($sql);
    $statement->execute($arguments);

    $checked = 0;
    $missing = 0;
    $sizeMismatches = 0;
    $hashMismatches = 0;
    $containerFailures = 0;
    $countMismatches = 0;
    $failures = [];

    $recordFailure = static function (array $failure) use (&$failures, $maxFailures): void {
        if (count($failures) < $maxFailures) {
            $failures[] = $failure;
        }
    };

    while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        $checked++;
        $fileId = (int)$row['id'];
        $rowGameId = (int)$row['game_id'];
        $path = BlockedCompressedMetadataContainer::path($storageRoot, $rowGameId, $fileId);

        $countsDiffer = (int)$row['file_name_count'] !== (int)$row['metadata_name_count']
            || (int)$row['file_import_count'] !== (int)$row['metadata_import_count']
            || (int)$row['file_export_count'] !== (int)$row['metadata_export_count'];
        if ($countsDiffer) {
            $countMismatches++;
            $recordFailure([
                'file_id' => $fileId,
                'game_id' => $rowGameId,
                'original_name' => (string)$row['original_name'],
                'kind' => 'count_mismatch',
                'file_counts' => [
                    'names' => (int)$row['file_name_count'],
                    'imports' => (int)$row['file_import_count'],
                    'exports' => (int)$row['file_export_count'],
                ],
                'metadata_counts' => [
                    'names' => (int)$row['metadata_name_count'],
                    'imports' => (int)$row['metadata_import_count'],
                    'exports' => (int)$row['metadata_export_count'],
                ],
            ]);
        }

        clearstatcache(true, $path);
        if (!is_file($path)) {
            $missing++;
            $recordFailure([
                'file_id' => $fileId,
                'game_id' => $rowGameId,
                'original_name' => (string)$row['original_name'],
                'kind' => 'metadata_file_missing',
                'path' => $path,
            ]);
            continue;
        }

        clearstatcache(true, $path);
        $actualSize = filesize($path);
        $expectedSize = (int)$row['compressed_size'];
        if ($actualSize === false || (int)$actualSize !== $expectedSize) {
            $sizeMismatches++;
            $recordFailure([
                'file_id' => $fileId,
                'game_id' => $rowGameId,
                'original_name' => (string)$row['original_name'],
                'kind' => 'size_mismatch',
                'expected_size' => $expectedSize,
                'actual_size' => $actualSize === false ? null : (int)$actualSize,
                'path' => $path,
            ]);
        }

        if ($withHash) {
            $actualHash = hash_file('sha256', $path, true);
            $expectedHash = (string)$row['payload_sha256'];
            if (!is_string($actualHash) || !hash_equals($expectedHash, $actualHash)) {
                $hashMismatches++;
                $recordFailure([
                    'file_id' => $fileId,
                    'game_id' => $rowGameId,
                    'original_name' => (string)$row['original_name'],
                    'kind' => 'sha256_mismatch',
                    'path' => $path,
                ]);
            }
        }

        if ($withContainer) {
            try {
                $bytes = file_get_contents($path);
                if (!is_string($bytes)) {
                    throw new RuntimeException('Could not read metadata container.');
                }
                BlockedCompressedMetadataContainer::verifyBytes($bytes, $fileId);
            } catch (Throwable $error) {
                $containerFailures++;
                $recordFailure([
                    'file_id' => $fileId,
                    'game_id' => $rowGameId,
                    'original_name' => (string)$row['original_name'],
                    'kind' => 'container_invalid',
                    'error' => $error->getMessage(),
                    'path' => $path,
                ]);
            }
        }
    }

    $problemCount = $missing + $sizeMismatches + $hashMismatches + $containerFailures + $countMismatches;
    $result = [
        'ok' => $problemCount === 0,
        'read_only' => true,
        'game_id' => $gameId > 0 ? $gameId : null,
        'hash_checked' => $withHash,
        'container_checked' => $withContainer,
        'checked_files' => $checked,
        'metadata_files_missing' => $missing,
        'size_mismatches' => $sizeMismatches,
        'sha256_mismatches' => $hashMismatches,
        'container_failures' => $containerFailures,
        'file_metadata_count_mismatches' => $countMismatches,
        'problem_count' => $problemCount,
        'failure_sample_limit' => $maxFailures,
        'failures' => $failures,
    ];

    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($problemCount === 0 ? 0 : 2);
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
