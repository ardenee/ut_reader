<?php
/**
 * Finalizes verified files by publishing authoritative UEDB5 metadata.
 *
 * The legacy method names are retained for scanner/import callers, but there is
 * no UEDB4 verification, publication, repair, or fallback path.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;

final class VerifiedFileCompactMetadataFinalizer
{
    /** @var array<int,array<string,mixed>> */
    private static array $maintenanceBaselines = [];

    /** @param array<string,mixed> $snapshot */
    public static function setMaintenanceBaseline(int $fileId, array $snapshot): void
    {
        if ($fileId < 1) {
            throw new RuntimeException('Maintenance metadata baseline requires a positive file ID.');
        }
        // Retained only as caller-compatible maintenance scope. UEDB5 publication
        // reparses authoritative source bytes and does not trust a V4 baseline.
        self::$maintenanceBaselines[$fileId] = $snapshot;
    }

    public static function clearMaintenanceBaseline(int $fileId): void
    {
        unset(self::$maintenanceBaselines[$fileId]);
    }

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
        return self::publish($db, $config, $result, $progress);
    }

    /**
     * Parser rows remain accepted for compatibility with the import pipeline.
     * UEDB5 is rebuilt from authoritative source bytes so game-specific summary,
     * FName, compression and dependency-source fields are never lost.
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
        ?callable $progress = null,
        bool $resolveDependencies = true
    ): array {
        unset($names, $imports, $exports, $resolveDependencies);
        return self::publish($db, $config, $result, $progress);
    }

    /**
     * @param array<int|string,mixed> $result
     * @return array<int|string,mixed>
     */
    private static function publish(PDO $db, array $config, array $result, ?callable $progress): array
    {
        if ((string)($result[0] ?? '') !== 'verified') {
            return $result;
        }

        $fileId = self::fileId($result);
        VerifiedMetadataPublicationState::pending($db, $fileId);
        self::emit($progress, 99, 'Publishing UEDB5 metadata for file #' . $fileId);

        try {
            $conversion = (new Uedb5VerifiedFilePublisher($db, $config))->publish($fileId);
            if (empty($conversion['verified'])
                || (int)($conversion['format_version'] ?? 0) !== Uedb5MetadataContainer::FORMAT_VERSION) {
                throw new RuntimeException('Verified file publication did not return UEDB5 format version 5.');
            }
            VerifiedMetadataPublicationState::ready($db, $fileId);
            return self::complete($result, $conversion, $progress);
        } catch (Throwable $error) {
            VerifiedMetadataPublicationState::failed($db, $fileId, $error->getMessage());
            self::recordFailure($db, $fileId, $error->getMessage());
            throw new RuntimeException(
                'UEDB5 metadata publication failed for verified file #' . $fileId . ': ' . $error->getMessage(),
                0,
                $error
            );
        } finally {
            self::clearMaintenanceBaseline($fileId);
        }
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

    /**
     * @param array<int|string,mixed> $result
     * @param array<string,mixed> $conversion
     * @return array<int|string,mixed>
     */
    private static function complete(array $result, array $conversion, ?callable $progress): array
    {
        $fileId = self::fileId($result);
        $message = trim((string)($result[2] ?? ''));
        $suffix = 'metadata=v' . Uedb5MetadataContainer::FORMAT_VERSION;
        if (array_key_exists('block_count', $conversion)) {
            $suffix .= ', blocks=' . (int)$conversion['block_count'];
        }
        if ($message === '' || !str_contains($message, $suffix)) {
            $result[2] = $message !== '' ? $message . '; ' . $suffix : $suffix;
        }

        $details = is_array($result[4] ?? null) ? $result[4] : [];
        $details['metadata_format_version'] = Uedb5MetadataContainer::FORMAT_VERSION;
        $details['metadata_block_count'] = (int)($conversion['block_count'] ?? 0);
        $details['metadata_compressed_size'] = (int)($conversion['compressed_size'] ?? 0);
        $details['metadata_source_policy'] = (string)($conversion['source_policy'] ?? '');
        $details['metadata_dependencies_deferred'] = false;
        $details['metadata_republished_from_parser'] = false;
        $result[4] = $details;

        self::emit($progress, 100, 'Published UEDB5 metadata for file #' . $fileId);
        return $result;
    }

    private static function recordFailure(PDO $db, int $fileId, string $message): void
    {
        try {
            $statement = $db->prepare(
                'UPDATE ue_files SET scan_notes=CONCAT_WS("\n",NULLIF(scan_notes,""),?) WHERE id=?'
            );
            $statement->execute(['UEDB5 metadata finalisation failed: ' . trim($message), $fileId]);
        } catch (Throwable $recordError) {
            error_log(
                '[UnrealDB UEDB5 metadata] file_id=' . $fileId
                . ' could not record failure: ' . $recordError->getMessage()
            );
        }
        error_log('[UnrealDB UEDB5 metadata] file_id=' . $fileId . ' finalisation failed: ' . $message);
    }

    private static function emit(?callable $progress, int $percent, string $message): void
    {
        if ($progress === null) {
            return;
        }
        $progress([
            'stage' => 'uedb5_metadata',
            'done' => max(0, min(100, $percent)),
            'total' => 100,
            'percent' => max(0, min(100, $percent)),
            'message' => $message,
        ]);
    }
}
