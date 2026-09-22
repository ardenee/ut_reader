<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

/**
 * Verifies the authoritative format-3 container and keeps ue_files publication
 * state aligned with physical reality.
 *
 * A ue_file_metadata registration alone is not proof that the container still
 * exists or is readable. Callers that decide whether recovery is needed should
 * use this boundary rather than checking format_version directly.
 */
final class VerifiedCompactMetadataHealth
{
    /**
     * @param array<string,mixed> $config
     * @return array<string,mixed>
     */
    public static function verify(PDO $db, array $config, int $fileId): array
    {
        if ($fileId < 1) {
            throw new RuntimeException('Compact metadata verification requires a positive file ID.');
        }
        $storageRoot = trim((string)($config['storage_path'] ?? ''));
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for compact metadata verification.');
        }

        try {
            $result = (new BlockedCompressedMetadataReader($db, $storageRoot))->verify($fileId);
            $formatVersion = (int)($result['format_version'] ?? 0);
            if (empty($result['verified']) || $formatVersion !== BlockedCompressedMetadataContainer::FORMAT_VERSION) {
                throw new RuntimeException(
                    'File #' . $fileId . ' did not verify as a supported compact metadata format.'
                );
            }
            VerifiedMetadataPublicationState::ready($db, $fileId);
            return $result;
        } catch (Throwable $error) {
            VerifiedMetadataPublicationState::failed($db, $fileId, self::errorText($error));
            throw $error;
        }
    }

    /**
     * Verify v3 metadata and, on failure, queue one globally deduplicated repair.
     *
     * @param array<string,mixed> $config
     */
    public static function verifyOrQueueRepair(PDO $db, array $config, int $fileId, ?int $requestedBy = null): array
    {
        try {
            return self::verify($db, $config, $fileId);
        } catch (Throwable $error) {
            self::queueRepair($db, $config, $fileId, $requestedBy, $error);
            throw $error;
        }
    }

    /** @param array<string,mixed> $config */
    public static function queueRepair(
        PDO $db,
        array $config,
        int $fileId,
        ?int $requestedBy = null,
        ?Throwable $cause = null
    ): int {
        if ($fileId < 1) {
            return 0;
        }
        $statement = $db->prepare(
            'SELECT id,game_id,original_name FROM ue_files WHERE id=? AND scan_status="verified" LIMIT 1'
        );
        $statement->execute([$fileId]);
        $file = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($file)) {
            return 0;
        }
        $queueName = trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog';
        return (new PdoJobQueue($db))->enqueue(
            $queueName,
            JobType::REPAIR_COMPACT_METADATA_FILE,
            [
                'file_id' => $fileId,
                'game_id' => (int)$file['game_id'],
                'requested_by' => $requestedBy,
                'source_relative_path' => 'Format-3 metadata recovery · ' . (string)$file['original_name'],
                'detected_error' => $cause !== null ? self::errorText($cause) : '',
            ],
            15,
            null,
            'compact-metadata-repair:' . $fileId,
            $requestedBy,
            5
        );
    }

    /** @param array<string,mixed> $config */
    public static function healthy(PDO $db, array $config, int $fileId): bool
    {
        try {
            self::verify($db, $config, $fileId);
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private static function errorText(Throwable $error): string
    {
        $message = trim($error->getMessage());
        if ($message === '') {
            $message = get_class($error);
        }
        return mb_substr($message, 0, 60000, 'UTF-8');
    }
}
