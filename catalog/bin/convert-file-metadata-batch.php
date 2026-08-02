#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedFileMetadataConverter;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupportCore.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

function compact_batch_usage(): void
{
    fwrite(STDOUT, "Usage:\n");
    fwrite(STDOUT, "  php catalog/bin/convert-file-metadata-batch.php [options]\n\n");
    fwrite(STDOUT, "Options:\n");
    fwrite(STDOUT, "  --limit=25             Maximum files in this run (1-10000).\n");
    fwrite(STDOUT, "  --start-after=0        Only consider file IDs above this value.\n");
    fwrite(STDOUT, "  --order=id             id or smallest.\n");
    fwrite(STDOUT, "  --max-file-rows=0      Skip files above this Names+Imports+Exports count; 0 disables.\n");
    fwrite(STDOUT, "  --upgrades-only        Only upgrade existing version-1 metadata rows.\n");
    fwrite(STDOUT, "  --continue-on-error    Continue after a file conversion fails.\n");
    fwrite(STDOUT, "  --dry-run              List selected files without converting them.\n");
}

/** @return array{limit:int,start_after:int,order:string,max_file_rows:int,upgrades_only:bool,continue_on_error:bool,dry_run:bool} */
function compact_batch_arguments(array $arguments): array
{
    $result = [
        'limit' => 25,
        'start_after' => 0,
        'order' => 'id',
        'max_file_rows' => 0,
        'upgrades_only' => false,
        'continue_on_error' => false,
        'dry_run' => false,
    ];

    foreach ($arguments as $argument) {
        $argument = trim((string)$argument);
        if (in_array($argument, ['--help', '-h', 'help'], true)) {
            compact_batch_usage();
            exit(0);
        }
        if ($argument === '--continue-on-error') {
            $result['continue_on_error'] = true;
            continue;
        }
        if ($argument === '--dry-run') {
            $result['dry_run'] = true;
            continue;
        }
        if ($argument === '--upgrades-only') {
            $result['upgrades_only'] = true;
            continue;
        }
        foreach (['limit', 'start-after', 'order', 'max-file-rows'] as $name) {
            $prefix = '--' . $name . '=';
            if (str_starts_with($argument, $prefix)) {
                $key = str_replace('-', '_', $name);
                $result[$key] = substr($argument, strlen($prefix));
                continue 2;
            }
        }
        throw new InvalidArgumentException('Unknown argument: ' . $argument);
    }

    $result['limit'] = max(1, min(10000, (int)$result['limit']));
    $result['start_after'] = max(0, (int)$result['start_after']);
    $result['max_file_rows'] = max(0, (int)$result['max_file_rows']);
    $result['order'] = strtolower(trim((string)$result['order']));
    if (!in_array($result['order'], ['id', 'smallest'], true)) {
        throw new InvalidArgumentException('--order must be id or smallest.');
    }

    return $result;
}

try {
    $arguments = compact_batch_arguments(array_slice($argv, 1));
    $config = catalog_config();
    $storagePath = trim((string)($config['storage_path'] ?? ''));
    if ($storagePath === '') {
        throw new RuntimeException('catalog storage_path is not configured.');
    }
    $db = catalog_db($config);

    $where = [
        'f.scan_status="verified"',
        'f.id>?',
    ];
    $parameters = [$arguments['start_after']];
    if ($arguments['upgrades_only']) {
        $where[] = 'm.format_version=1';
    } else {
        $where[] = '(m.file_id IS NULL OR m.format_version<2)';
    }
    if ($arguments['max_file_rows'] > 0) {
        $where[] = '(f.name_count+f.import_count+f.export_count)<=?';
        $parameters[] = $arguments['max_file_rows'];
    }
    $order = $arguments['order'] === 'smallest'
        ? '(f.name_count+f.import_count+f.export_count) ASC,f.id ASC'
        : 'f.id ASC';
    $sql = 'SELECT f.id,f.game_id,f.package_name,f.original_name,f.name_count,f.import_count,f.export_count,'
        . 'COALESCE(m.format_version,0) metadata_version,'
        . '(f.name_count+f.import_count+f.export_count) total_rows '
        . 'FROM ue_files f LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
        . 'WHERE ' . implode(' AND ', $where)
        . ' ORDER BY ' . $order . ' LIMIT ' . (int)$arguments['limit'];
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    $files = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($files === []) {
        fwrite(STDOUT, "No matching verified files require blocked metadata.\n");
        exit(0);
    }

    fwrite(STDOUT, 'Selected ' . count($files) . " file(s).\n");
    foreach ($files as $position => $file) {
        fwrite(
            STDOUT,
            sprintf(
                "  %4d. #%d game=%d rows=%d v%d %s\n",
                $position + 1,
                (int)$file['id'],
                (int)$file['game_id'],
                (int)$file['total_rows'],
                (int)$file['metadata_version'],
                (string)$file['original_name']
            )
        );
    }
    if ($arguments['dry_run']) {
        exit(0);
    }

    $converter = new BlockedCompressedFileMetadataConverter($db, $storagePath);
    $completed = 0;
    $failed = 0;
    $storedBytes = 0;
    $uncompressedBytes = 0;
    $startedAt = microtime(true);

    foreach ($files as $position => $file) {
        $fileId = (int)$file['id'];
        $fileStartedAt = microtime(true);
        fwrite(
            STDOUT,
            sprintf(
                "[%d/%d] Converting #%d %s (%d rows, v%d)...\n",
                $position + 1,
                count($files),
                $fileId,
                (string)$file['original_name'],
                (int)$file['total_rows'],
                (int)$file['metadata_version']
            )
        );
        try {
            $result = $converter->convert($fileId);
            $elapsed = microtime(true) - $fileStartedAt;
            $completed++;
            $storedBytes += (int)($result['compressed_size'] ?? 0);
            $uncompressedBytes += (int)($result['uncompressed_size'] ?? 0);
            fwrite(
                STDOUT,
                sprintf(
                    "  OK %.2fs, %.2f MB -> %.2f MB, blocks=%d, SQL batches=%d\n",
                    $elapsed,
                    ((int)($result['uncompressed_size'] ?? 0)) / 1048576,
                    ((int)($result['compressed_size'] ?? 0)) / 1048576,
                    (int)($result['block_count'] ?? 0),
                    (int)($result['sql_batches'] ?? 0)
                )
            );
        } catch (Throwable $error) {
            $failed++;
            fwrite(STDERR, '  FAILED #' . $fileId . ': ' . $error->getMessage() . PHP_EOL);
            if (!$arguments['continue_on_error']) {
                throw $error;
            }
        }
        gc_collect_cycles();
    }

    $remaining = (int)$db->query(
        'SELECT COUNT(*) FROM ue_files f LEFT JOIN ue_file_metadata m ON m.file_id=f.id '
        . 'WHERE f.scan_status="verified" AND (m.file_id IS NULL OR m.format_version<2)'
    )->fetchColumn();
    $elapsed = microtime(true) - $startedAt;
    fwrite(
        STDOUT,
        sprintf(
            "Batch complete: completed=%d failed=%d remaining=%d elapsed=%.2fs payload=%.2f MB -> %.2f MB\n",
            $completed,
            $failed,
            $remaining,
            $elapsed,
            $uncompressedBytes / 1048576,
            $storedBytes / 1048576
        )
    );
    exit($failed > 0 ? 2 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Blocked metadata batch failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
