<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `VerifiedFileCompactMetadataFinalizer` for verified file compact metadata
 *          finalizer.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;

/** Ensures a newly verified scanner result immediately receives format-2 metadata. */
final class VerifiedFileCompactMetadataFinalizer
{
    /**
     * @param array<int|string,mixed> $result
     * @return array<int|string,mixed>
     */
    public static function finalize(
        PDO $db,
        array $config,
        array $result,
        ?callable $progress = null
    ): array {
        if ((string)($result[0] ?? '') !== 'verified') {
            return $result;
        }

        $fileId = (int)($result[1] ?? ($result[4]['file_id'] ?? 0));
        if ($fileId < 1) {
            throw new RuntimeException('Verified scanner result has no valid file ID.');
        }

        $storageRoot = trim((string)($config['storage_path'] ?? ''));
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for compact metadata finalisation.');
        }

        self::emit($progress, 99, 'Building compact metadata for verified file #' . $fileId);

        try {
            $statement = $db->prepare('SELECT format_version FROM ue_file_metadata WHERE file_id=?');
            $statement->execute([$fileId]);
            $existingVersion = (int)($statement->fetchColumn() ?: 0);

            if ($existingVersion === BlockedCompressedMetadataContainer::FORMAT_VERSION) {
                $conversion = (new BlockedCompressedMetadataReader($db, $storageRoot))->verify($fileId);
                $conversion['already_compact'] = true;
            } else {
                $conversion = (new BlockedCompressedFileMetadataConverter($db, $storageRoot))->convert($fileId);
                $conversion['already_compact'] = false;
            }

            if (
                empty($conversion['verified'])
                || (int)($conversion['format_version'] ?? 0) !== BlockedCompressedMetadataContainer::FORMAT_VERSION
            ) {
                throw new RuntimeException('Compact metadata verification did not return format version 2.');
            }

            $legacyRowsRemoved = self::removeLegacyStagingRows($db, $fileId);
        } catch (Throwable $error) {
            self::recordFailure($db, $fileId, $error->getMessage());
            throw new RuntimeException(
                'Imported file #' . $fileId . ' was stored, but compact metadata finalisation failed: '
                . $error->getMessage(),
                0,
                $error
            );
        }

        $message = trim((string)($result[2] ?? ''));
        $suffix = 'compact metadata=v2, blocks=' . (int)($conversion['block_count'] ?? 0);
        if ($message === '' || !str_contains($message, 'compact metadata=v2')) {
            $result[2] = $message !== '' ? $message . '; ' . $suffix : $suffix;
        }

        $details = is_array($result[4] ?? null) ? $result[4] : [];
        $details['metadata_format_version'] = BlockedCompressedMetadataContainer::FORMAT_VERSION;
        $details['metadata_block_count'] = (int)($conversion['block_count'] ?? 0);
        $details['metadata_compressed_size'] = (int)($conversion['compressed_size'] ?? 0);
        $details['metadata_already_compact'] = !empty($conversion['already_compact']);
        $details['legacy_staging_rows_removed'] = $legacyRowsRemoved;
        $result[4] = $details;

        self::emit($progress, 100, 'Verified compact metadata for file #' . $fileId);
        return $result;
    }

    /** @return array<string,int> */
    private static function removeLegacyStagingRows(PDO $db, int $fileId): array
    {
        if ($db->inTransaction()) {
            throw new RuntimeException('Legacy staging cleanup requires ownership of the database transaction.');
        }

        $removed = [
            'ue_dependencies' => 0,
            'ue_imports' => 0,
            'ue_exports' => 0,
            'ue_names' => 0,
        ];

        $db->beginTransaction();
        try {
            foreach (array_keys($removed) as $table) {
                $statement = $db->prepare('DELETE FROM ' . $table . ' WHERE file_id=?');
                $statement->execute([$fileId]);
                $removed[$table] = $statement->rowCount();
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }

        return $removed;
    }

    private static function recordFailure(PDO $db, int $fileId, string $message): void
    {
        try {
            $statement = $db->prepare(
                'UPDATE ue_files SET scan_notes=CONCAT_WS("\n",NULLIF(scan_notes,""),?) WHERE id=?'
            );
            $statement->execute([
                'Compact metadata finalisation failed: ' . trim($message),
                $fileId,
            ]);
        } catch (Throwable $recordError) {
            error_log(
                '[UnrealDB compact metadata] file_id=' . $fileId
                . ' could not record failure: ' . $recordError->getMessage()
            );
        }

        error_log('[UnrealDB compact metadata] file_id=' . $fileId . ' finalisation failed: ' . $message);
    }

    private static function emit(?callable $progress, int $percent, string $message): void
    {
        if ($progress === null) {
            return;
        }
        $progress([
            'stage' => 'compact_metadata',
            'done' => max(0, min(100, $percent)),
            'total' => 100,
            'percent' => max(0, min(100, $percent)),
            'message' => $message,
        ]);
    }
}
