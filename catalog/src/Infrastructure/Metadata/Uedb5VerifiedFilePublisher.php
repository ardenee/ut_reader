<?php
/**
 * Publishes one verified catalogue file directly as authoritative UEDB5.
 *
 * Source bytes are reparsed through the registered game/source reader so every
 * engine-specific section is preserved. Publication is V5-only and fails closed.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;
use Throwable;

final class Uedb5VerifiedFilePublisher
{
    private Uedb5SourceSnapshotFactory $snapshots;
    private Uedb5MetadataSnapshotWriter $writer;
    private PdoUedb5StagingRegistrationRepository $registration;
    private PdoUedb5BaseProjectionPublisher $basePublisher;
    private PdoUedb5MigrationStatusRepository $statuses;
    private Uedb5GameDependencyPassService $dependencies;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $storage = rtrim((string)($config['storage_path'] ?? ''), "\\/");
        if ($storage === '') {
            throw new RuntimeException('Catalog storage_path is required for UEDB5 publication.');
        }
        $this->snapshots = new Uedb5SourceSnapshotFactory($db, $config);
        $this->writer = new Uedb5MetadataSnapshotWriter($storage);
        $this->registration = new PdoUedb5StagingRegistrationRepository($db, $storage);
        $this->basePublisher = new PdoUedb5BaseProjectionPublisher($db);
        $this->statuses = new PdoUedb5MigrationStatusRepository($db);
        $this->dependencies = new Uedb5GameDependencyPassService($db, $config);
    }

    /** @return array<string,mixed> */
    public function publish(int $fileId): array
    {
        $file = $this->file($fileId);
        $gameId = (int)$file['game_id'];
        $path = $this->sourcePath($gameId, (string)$file['stored_name']);
        $this->assertSourceIdentity($path, $file);

        try {
            $snapshot = $this->snapshots->buildForGameId($gameId, $path, $file);
            $written = $this->writer->write($snapshot);
            $registration = $this->registration->register($gameId, $fileId);
            $baseProjection = $this->basePublisher->publish($snapshot, $registration);
            $this->statuses->markStageSucceeded($fileId, $gameId);
            $dependency = $this->dependencies->runFile($gameId, $fileId, true, true);

            return [
                'verified' => true,
                'file_id' => $fileId,
                'game_id' => $gameId,
                'format_version' => Uedb5MetadataContainer::FORMAT_VERSION,
                'metadata_path' => (string)$written['path'],
                'compressed_size' => (int)$written['compressed_size'],
                'uncompressed_size' => (int)$written['uncompressed_size'],
                'block_count' => (int)$written['block_count'],
                'payload_sha256_hex' => (string)$written['payload_sha256_hex'],
                'source_policy' => (string)($snapshot['source_policy'] ?? ''),
                'base_projection' => $baseProjection,
                'dependency_result' => (array)($dependency['result'] ?? []),
            ];
        } catch (Throwable $error) {
            try {
                $this->statuses->markStageFailed(
                    $fileId,
                    $gameId,
                    'live_v5_publication_failed',
                    $error->getMessage()
                );
            } catch (Throwable) {
            }
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    private function file(int $fileId): array
    {
        if ($fileId < 1) {
            throw new RuntimeException('A positive verified file ID is required for UEDB5 publication.');
        }
        $statement = $this->db->prepare(
            'SELECT id,game_id,package_name,original_name,stored_name,relative_path,'
            . 'file_size,md5,sha1,package_version,licensee_version,scan_status '
            . 'FROM ue_files WHERE id=? LIMIT 1'
        );
        $statement->execute([$fileId]);
        $file = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($file) || (string)($file['scan_status'] ?? '') !== 'verified') {
            throw new RuntimeException('UEDB5 publication requires an active verified catalogue file.');
        }
        if ((int)($file['game_id'] ?? 0) < 1) {
            throw new RuntimeException('Verified file has no game identity for UEDB5 publication.');
        }
        return $file;
    }

    private function sourcePath(int $gameId, string $storedName): string
    {
        if ($storedName === '' || basename($storedName) !== $storedName) {
            throw new RuntimeException('Invalid verified stored_name for UEDB5 publication.');
        }
        return rtrim((string)$this->config['storage_path'], "\\/")
            . DIRECTORY_SEPARATOR . 'games'
            . DIRECTORY_SEPARATOR . Uedb5GameSourceRegistry::storageKey($gameId)
            . DIRECTORY_SEPARATOR . 'verified'
            . DIRECTORY_SEPARATOR . $storedName;
    }

    /** @param array<string,mixed> $file */
    private function assertSourceIdentity(string $path, array $file): void
    {
        if (!is_file($path)) {
            throw new RuntimeException('Verified source bytes are missing: ' . $path);
        }
        $size = filesize($path);
        if ($size === false || (int)$size !== (int)($file['file_size'] ?? -1)) {
            throw new RuntimeException('Verified source byte size does not match catalogue identity.');
        }
        $md5 = md5_file($path);
        $sha1 = sha1_file($path);
        if (!is_string($md5) || !hash_equals(strtolower((string)$file['md5']), strtolower($md5))) {
            throw new RuntimeException('Verified source MD5 does not match catalogue identity.');
        }
        if (!is_string($sha1) || !hash_equals(strtolower((string)$file['sha1']), strtolower($sha1))) {
            throw new RuntimeException('Verified source SHA1 does not match catalogue identity.');
        }
    }
}
