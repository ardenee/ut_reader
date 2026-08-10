<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Ensures every verified package uses the authoritative format-2 metadata container.
 * Why: Parsed package metadata is published directly from reader output and no retired SQL metadata staging is written.
 * Role: Infrastructure verified-import compact metadata finalizer.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;

final class VerifiedFileCompactMetadataFinalizer
{
    /**
     * Verify an already-published current metadata result.
     *
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

        $fileId = self::fileId($result);
        $storageRoot = self::storageRoot($config);
        self::emit($progress, 99, 'Verifying compact metadata for file #' . $fileId);

        try {
            $statement = $db->prepare('SELECT format_version FROM ue_file_metadata WHERE file_id=?');
            $statement->execute([$fileId]);
            $formatVersion = (int)($statement->fetchColumn() ?: 0);
            if ($formatVersion !== BlockedCompressedMetadataContainer::FORMAT_VERSION) {
                throw new RuntimeException(
                    'Verified file #' . $fileId . ' has no current format-2 metadata.'
                );
            }
            $conversion = (new BlockedCompressedMetadataReader($db, $storageRoot))->verify($fileId);
            $conversion['already_compact'] = true;
        } catch (Throwable $error) {
            self::recordFailure($db, $fileId, $error->getMessage());
            throw new RuntimeException(
                'Compact metadata verification failed for verified file #' . $fileId . ': '
                . $error->getMessage(),
                0,
                $error
            );
        }

        return self::complete($result, $conversion, $progress);
    }

    /**
     * Publish current metadata directly from parser output for a newly verified or reimported package.
     *
     * Parsed reader output is authoritative here. Even when a format-2 registration already exists,
     * maintenance reimport must replace it so stale/corrupt metadata and parser changes are actually
     * repaired instead of merely verifying the previous container.
     *
     * @param array<int|string,mixed> $result
     * @param array<int,mixed> $names
     * @param array<int,mixed> $imports
     * @param array<int,mixed> $exports
     * @return array<int|string,mixed>
     */
    public static function finalizeParsed(
        PDO $db,
        array $config,
        array $result,
        array $names,
        array $imports,
        array $exports,
        ?callable $progress = null
    ): array {
        if ((string)($result[0] ?? '') !== 'verified') {
            return $result;
        }

        $fileId = self::fileId($result);
        $storageRoot = self::storageRoot($config);
        self::emit($progress, 99, 'Publishing compact metadata for verified file #' . $fileId);

        try {
            $statement = $db->prepare(
                'SELECT id,game_id,package_name,original_name,scan_status FROM ue_files WHERE id=?'
            );
            $statement->execute([$fileId]);
            $file = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($file) || (string)($file['scan_status'] ?? '') !== 'verified') {
                throw new RuntimeException('Verified file row is unavailable during compact metadata publication.');
            }

            $snapshot = (new CatalogParsedPackageMetadataSnapshotBuilder($db, $config))->build(
                $fileId,
                (int)$file['game_id'],
                (string)$file['package_name'],
                (string)$file['original_name'],
                $names,
                $imports,
                $exports
            );
            $conversion = (new BlockedCompressedMetadataSnapshotWriter($db, $storageRoot))->write($snapshot);
            $conversion['already_compact'] = false;
            $conversion['republished_from_parser'] = true;

            if (
                empty($conversion['verified'])
                || (int)($conversion['format_version'] ?? 0) !== BlockedCompressedMetadataContainer::FORMAT_VERSION
            ) {
                throw new RuntimeException('Compact metadata verification did not return format version 2.');
            }
        } catch (Throwable $error) {
            self::recordFailure($db, $fileId, $error->getMessage());
            throw new RuntimeException(
                'Imported file #' . $fileId . ' was stored, but direct compact metadata publication failed: '
                . $error->getMessage(),
                0,
                $error
            );
        }

        return self::complete($result, $conversion, $progress);
    }

    /** @param array<int|string,mixed> $result */
    private static function fileId(array $result): int
    {
        $fileId = (int)($result[1] ?? ($result[4]['file_id'] ?? 0));
        if ($fileId < 1) {
            throw new RuntimeException('Verified scanner result has no valid file ID.');
        }
        return $fileId;
    }

    /** @param array<string,mixed> $config */
    private static function storageRoot(array $config): string
    {
        $storageRoot = trim((string)($config['storage_path'] ?? ''));
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for compact metadata finalisation.');
        }
        return $storageRoot;
    }

    /**
     * @param array<int|string,mixed> $result
     * @param array<string,mixed> $conversion
     * @return array<int|string,mixed>
     */
    private static function complete(
        array $result,
        array $conversion,
        ?callable $progress
    ): array {
        $fileId = self::fileId($result);
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
        $details['metadata_republished_from_parser'] = !empty($conversion['republished_from_parser']);
        $result[4] = $details;

        self::emit($progress, 100, 'Verified compact metadata for file #' . $fileId);
        return $result;
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
