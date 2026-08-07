#!/usr/bin/env php
<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides the command-line utility for rebuild file metadata projections.
 * Why: It handles administrator, migration, verification, repair, generation, or worker work that should not execute
 *      as an interactive browser request.
 * Role: CLI/maintenance entry point used from the server shell or operational scripts.
 * Audit: Operational entry point; verify scheduled/manual usage before considering removal.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\CompactSearchProjectionWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\CompressedMetadataLegacySnapshot;
use UnrealDb\Catalog\Infrastructure\Metadata\CompressedMetadataLookupWriter;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

require_once __DIR__ . '/../lib/CatalogSupportCore.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

/** @return array{limit:int,start_after:int,continue_on_error:bool,dry_run:bool} */
function metadata_projection_rebuild_arguments(array $arguments): array
{
    $result = [
        'limit' => 500,
        'start_after' => 0,
        'continue_on_error' => false,
        'dry_run' => false,
    ];

    foreach ($arguments as $argument) {
        $argument = trim((string)$argument);
        if (in_array($argument, ['--help', '-h', 'help'], true)) {
            fwrite(
                STDOUT,
                "Usage: php catalog/bin/rebuild-file-metadata-projections.php "
                . "[--limit=500] [--start-after=0] [--continue-on-error] [--dry-run]\n"
            );
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
        foreach (['limit', 'start-after'] as $name) {
            $prefix = '--' . $name . '=';
            if (str_starts_with($argument, $prefix)) {
                $result[str_replace('-', '_', $name)] = substr($argument, strlen($prefix));
                continue 2;
            }
        }
        throw new InvalidArgumentException('Unknown argument: ' . $argument);
    }

    $result['limit'] = max(1, min(10000, (int)$result['limit']));
    $result['start_after'] = max(0, (int)$result['start_after']);
    return $result;
}

try {
    $arguments = metadata_projection_rebuild_arguments(array_slice($argv, 1));
    $config = catalog_config();
    $storagePath = trim((string)($config['storage_path'] ?? ''));
    if ($storagePath === '') {
        throw new RuntimeException('catalog storage_path is not configured.');
    }

    $db = catalog_db($config);
    $statement = $db->prepare(
        'SELECT f.id,f.game_id,f.original_name,f.name_count,f.import_count,f.export_count,'
        . 'm.format_version metadata_version,m.codec metadata_codec,'
        . 'm.compressed_size metadata_compressed_size,'
        . 'm.uncompressed_size metadata_uncompressed_size,'
        . 'm.payload_sha256 metadata_sha256 '
        . 'FROM ue_file_metadata m JOIN ue_files f ON f.id=m.file_id '
        . 'WHERE m.format_version=2 AND f.scan_status="verified" AND f.id>? '
        . 'ORDER BY f.id LIMIT ' . (int)$arguments['limit']
    );
    $statement->execute([$arguments['start_after']]);
    $files = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];

    if ($files === []) {
        fwrite(STDOUT, "No matching version-2 metadata files require projection rebuilding.\n");
        exit(0);
    }

    fwrite(STDOUT, 'Selected ' . count($files) . " file(s).\n");
    foreach ($files as $position => $file) {
        fwrite(STDOUT, sprintf(
            "  %4d. #%d game=%d rows=%d %s\n",
            $position + 1,
            (int)$file['id'],
            (int)$file['game_id'],
            (int)$file['name_count'] + (int)$file['import_count'] + (int)$file['export_count'],
            (string)$file['original_name']
        ));
    }
    if ($arguments['dry_run']) {
        exit(0);
    }

    $snapshotLoader = new CompressedMetadataLegacySnapshot($db);
    $lookupWriter = new CompressedMetadataLookupWriter($db);
    $searchWriter = new CompactSearchProjectionWriter($db);
    $completed = 0;
    $failed = 0;
    $lastFileId = 0;
    $startedAt = microtime(true);

    foreach ($files as $position => $file) {
        $fileId = (int)$file['id'];
        $lastFileId = $fileId;
        $fileStartedAt = microtime(true);
        fwrite(STDOUT, sprintf(
            "[%d/%d] Rebuilding #%d %s...\n",
            $position + 1,
            count($files),
            $fileId,
            (string)$file['original_name']
        ));

        try {
            $metadataPath = BlockedCompressedMetadataContainer::path(
                $storagePath,
                (int)$file['game_id'],
                $fileId
            );
            $storedBytes = file_get_contents($metadataPath);
            if (!is_string($storedBytes)) {
                throw new RuntimeException('Could not read blocked metadata container: ' . $metadataPath);
            }
            if (strlen($storedBytes) !== (int)$file['metadata_compressed_size']) {
                throw new RuntimeException('Blocked metadata container size mismatch for file #' . $fileId . '.');
            }
            if (!hash_equals((string)$file['metadata_sha256'], hash('sha256', $storedBytes, true))) {
                throw new RuntimeException('Blocked metadata container SHA-256 mismatch for file #' . $fileId . '.');
            }
            $verified = BlockedCompressedMetadataContainer::verifyBytes($storedBytes, $fileId);
            $snapshot = $snapshotLoader->capture($fileId);

            $sqlBatches = 0;
            $db->beginTransaction();
            try {
                $lookupWriter->writeVersioned(
                    $snapshot,
                    $storedBytes,
                    (int)$file['metadata_uncompressed_size'],
                    (int)$file['metadata_version'],
                    (int)$file['metadata_codec'],
                    $sqlBatches
                );
                $searchWriter->write($snapshot, $sqlBatches);
                $db->commit();
            } catch (Throwable $error) {
                if ($db->inTransaction()) {
                    $db->rollBack();
                }
                throw $error;
            }

            if ($sqlBatches < 1) {
                throw new RuntimeException(
                    'Projection rebuild performed no SQL writes for file #' . $fileId . '.'
                );
            }

            $completed++;
            fwrite(STDOUT, sprintf(
                "  OK %.2fs, blocks=%d, SQL batches=%d\n",
                microtime(true) - $fileStartedAt,
                (int)($verified['block_count'] ?? 0),
                $sqlBatches
            ));
        } catch (Throwable $error) {
            $failed++;
            fwrite(STDERR, '  FAILED #' . $fileId . ': ' . $error->getMessage() . PHP_EOL);
            if (!$arguments['continue_on_error']) {
                throw $error;
            }
        }
        gc_collect_cycles();
    }

    fwrite(STDOUT, sprintf(
        "Projection rebuild complete: completed=%d failed=%d last_file_id=%d elapsed=%.2fs\n",
        $completed,
        $failed,
        $lastFileId,
        microtime(true) - $startedAt
    ));
    exit($failed > 0 ? 2 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, 'Metadata projection rebuild failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
