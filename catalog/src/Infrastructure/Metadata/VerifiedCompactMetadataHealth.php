<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

/**
 * Verifies authoritative UEDB5 publication health for one verified file.
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
            throw new RuntimeException('UEDB5 metadata verification requires a positive file ID.');
        }
        $storageRoot = trim((string)($config['storage_path'] ?? ''));
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for UEDB5 metadata verification.');
        }

        try {
            $statement = $db->prepare(
                'SELECT f.game_id,f.scan_status,v.format_version,v.payload_sha256,'
                . 's.dependency_policy,s.dependency_payload_sha256 '
                . 'FROM ue_files f '
                . 'LEFT JOIN ue_uedb5_files v ON v.file_id=f.id AND v.game_id=f.game_id '
                . 'LEFT JOIN ue_uedb5_migration_status s ON s.file_id=f.id AND s.game_id=f.game_id '
                . 'WHERE f.id=? LIMIT 1'
            );
            $statement->execute([$fileId]);
            $row = $statement->fetch(PDO::FETCH_ASSOC);
            if (!is_array($row) || (string)($row['scan_status'] ?? '') !== 'verified') {
                throw new RuntimeException('File #' . $fileId . ' is not an active verified catalogue file.');
            }
            $gameId = (int)($row['game_id'] ?? 0);
            if ($gameId < 1 || (int)($row['format_version'] ?? 0) !== Uedb5MetadataContainer::FORMAT_VERSION) {
                throw new RuntimeException('File #' . $fileId . ' has no authoritative UEDB5 registration.');
            }

            $result = (new Uedb5MetadataReader($storageRoot))->verify($gameId, $fileId);
            $payloadSha = (string)($result['payload_sha256'] ?? '');
            if (strlen($payloadSha) !== 32) {
                throw new RuntimeException('File #' . $fileId . ' UEDB5 verification returned an invalid payload identity.');
            }
            if (!hash_equals((string)($row['payload_sha256'] ?? ''), $payloadSha)) {
                throw new RuntimeException('File #' . $fileId . ' UEDB5 registration payload does not match the container.');
            }
            if ((string)($row['dependency_policy'] ?? '') !== Uedb5GameDependencyPassService::DEPENDENCY_POLICY
                || !hash_equals((string)($row['dependency_payload_sha256'] ?? ''), $payloadSha)) {
                throw new RuntimeException(
                    'File #' . $fileId . ' UEDB5 dependency projection is not complete for the current payload/policy.'
                );
            }

            VerifiedMetadataPublicationState::ready($db, $fileId);
            return [
                'verified'=>true,
                'file_id'=>$fileId,
                'game_id'=>$gameId,
                'format_version'=>Uedb5MetadataContainer::FORMAT_VERSION,
                'compressed_size'=>(int)($result['compressed_size'] ?? 0),
                'block_count'=>(int)($result['block_count'] ?? 0),
                'payload_sha256'=>$payloadSha,
                'payload_sha256_hex'=>strtoupper(bin2hex($payloadSha)),
                'dependency_policy'=>Uedb5GameDependencyPassService::DEPENDENCY_POLICY,
            ];
        } catch (Throwable $error) {
            VerifiedMetadataPublicationState::failed($db, $fileId, self::errorText($error));
            throw $error;
        }
    }

    /** @param array<string,mixed> $config */
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
                'file_id'=>$fileId,
                'game_id'=>(int)$file['game_id'],
                'requested_by'=>$requestedBy,
                'source_relative_path'=>'UEDB5 metadata recovery · ' . (string)$file['original_name'],
                'detected_error'=>$cause !== null ? self::errorText($cause) : '',
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
