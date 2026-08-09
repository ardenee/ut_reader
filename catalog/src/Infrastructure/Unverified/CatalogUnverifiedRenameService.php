<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renames an indexed unverified file while preserving its queue wrapper, sidecar and database identity.
 * Why: Rename validation, filesystem rollback and staging-row updates are one infrastructure use case.
 * Role: Infrastructure service for unverified file rename.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use RuntimeException;
use Throwable;

final class CatalogUnverifiedRenameService
{
    private readonly CatalogUnverifiedStagingIndex $staging;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/CatalogScanner.php';
        $this->staging = new CatalogUnverifiedStagingIndex($db, $config);
    }

    /** @return array{file_id:int,old_name:string,new_name:string,old_queue_name:string,new_queue_name:string,package_name:string} */
    public function rename(int $fileId, string $requestedName): array
    {
        $this->staging->ensureSchema();
        if ($fileId < 1) {
            throw new RuntimeException('Invalid unverified file ID.');
        }

        $row = \catalog_one(
            $this->db,
            'SELECT * FROM ue_files WHERE id=? AND scan_status="unverified" LIMIT 1',
            [$fileId]
        );
        if (!$row) {
            throw new RuntimeException('The unverified staging row no longer exists.');
        }

        $newOriginalName = $this->cleanName($requestedName);
        $oldOriginalName = trim((string)($row['original_name'] ?? ''));
        $oldQueueName = basename(trim((string)($row['unverified_queue_name'] ?? '')));
        $queueGameId = (int)($row['unverified_queue_game_id'] ?? 0);
        if ($oldQueueName === '') {
            throw new RuntimeException('The staging row has no physical queue filename.');
        }

        $queueGame = $queueGameId === 0
            ? CatalogUnverifiedQueueStorage::bucketGame()
            : \catalog_one($this->db, 'SELECT id,name,slug,profile_id FROM ue_games WHERE id=?', [$queueGameId]);
        if (!$queueGame) {
            throw new RuntimeException('The physical queue game no longer exists.');
        }

        $directory = CatalogUnverifiedQueueStorage::unverifiedDirectory($this->config, $queueGame, false);
        $oldPath = $directory . DIRECTORY_SEPARATOR . $oldQueueName;
        if (!is_file($oldPath) || is_link($oldPath) || !CatalogUnverifiedQueueStorage::pathInside($oldPath, $directory)) {
            throw new RuntimeException('The physical staged file is missing or unsafe.');
        }

        $newQueueName = $this->queueName($oldQueueName, $newOriginalName);
        if ($newQueueName === '' || basename($newQueueName) !== $newQueueName) {
            throw new RuntimeException('The corrected queue filename is invalid.');
        }
        if ($oldOriginalName === $newOriginalName && $oldQueueName === $newQueueName) {
            throw new RuntimeException('The file already uses that name.');
        }

        $newPath = $directory . DIRECTORY_SEPARATOR . $newQueueName;
        $caseOnlyPath = strcasecmp($oldPath, $newPath) === 0;
        if (!$caseOnlyPath && file_exists($newPath)) {
            throw new RuntimeException('A staged file with the corrected queue filename already exists.');
        }

        $oldReasonPath = $oldPath . '.txt';
        $newReasonPath = $newPath . '.txt';
        if (!$caseOnlyPath && is_file($oldReasonPath) && file_exists($newReasonPath)) {
            throw new RuntimeException('A queue note already exists for the corrected filename.');
        }

        $newQueueKey = CatalogUnverifiedStagingIndex::queueKey($queueGameId, $newQueueName);
        $collision = \catalog_one(
            $this->db,
            'SELECT id FROM ue_files WHERE unverified_queue_key=? AND id<>? LIMIT 1',
            [$newQueueKey, $fileId]
        );
        if ($collision) {
            throw new RuntimeException('Another staging row already uses the corrected queue filename.');
        }

        $temporaryPath = '';
        $fileMoved = false;
        $reasonMoved = false;
        try {
            if ($oldPath !== $newPath) {
                if ($caseOnlyPath) {
                    $temporaryPath = $oldPath . '.rename-' . bin2hex(random_bytes(4));
                    if (!@rename($oldPath, $temporaryPath) || !@rename($temporaryPath, $newPath)) {
                        if (is_file($temporaryPath) && !is_file($oldPath)) {
                            @rename($temporaryPath, $oldPath);
                        }
                        throw new RuntimeException('Could not apply the case-only physical filename change.');
                    }
                } elseif (!@rename($oldPath, $newPath)) {
                    throw new RuntimeException('Could not rename the physical staged file.');
                }
                $fileMoved = true;
            }

            if (is_file($oldReasonPath) && $oldReasonPath !== $newReasonPath) {
                if (!@rename($oldReasonPath, $newReasonPath)) {
                    if ($fileMoved && is_file($newPath) && !is_file($oldPath)) {
                        @rename($newPath, $oldPath);
                    }
                    throw new RuntimeException('The staged file was restored because its queue note could not be renamed.');
                }
                $reasonMoved = true;
            }

            $newSourceRelativePath = $this->sourceRelative(
                (string)($row['source_relative_path'] ?? ''),
                $newOriginalName
            );
            $engine = strtoupper(trim((string)($row['detected_engine_key'] ?? '')));
            $newPackageName = in_array($engine, ['UE4', 'UE5'], true) && $newSourceRelativePath !== ''
                ? \scanner_ue_package_name_from_source_relative($newSourceRelativePath)
                : \scanner_logical_package_name($newOriginalName);
            if ($newPackageName === '') {
                throw new RuntimeException('The corrected filename does not produce a valid package name.');
            }

            $newRelativePath = CatalogUnverifiedStagingIndex::storageRelative($this->config, $newPath);
            $newExtension = \catalog_clean_unreal_extension((string)pathinfo($newOriginalName, PATHINFO_EXTENSION));
            $renameNote = 'Renamed staged file from '
                . ($oldOriginalName !== '' ? $oldOriginalName : $oldQueueName)
                . ' to ' . $newOriginalName . ' on ' . gmdate('Y-m-d H:i:s') . ' UTC.';
            $scanNotes = trim(trim((string)($row['scan_notes'] ?? '')) . "\n" . $renameNote);

            $this->db->beginTransaction();
            try {
                $update = $this->db->prepare(
                    'UPDATE ue_files SET package_name=?,original_name=?,source_relative_path=?,'
                    . 'stored_name=?,relative_path=?,extension=?,unverified_queue_key=?,'
                    . 'unverified_queue_name=?,scan_notes=? WHERE id=? AND scan_status="unverified"'
                );
                $update->execute([
                    $newPackageName,
                    $newOriginalName,
                    $newSourceRelativePath !== '' ? $newSourceRelativePath : null,
                    $newQueueName,
                    $newRelativePath,
                    $newExtension,
                    $newQueueKey,
                    $newQueueName,
                    $scanNotes,
                    $fileId,
                ]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('The staging row changed before the rename could be saved.');
                }
                // Metadata structure does not change on rename. The compressed
                // staging store rebases Export full paths from the current
                // ue_files.package_name whenever the snapshot is loaded.
                $this->db->commit();
            } catch (Throwable $error) {
                if ($this->db->inTransaction()) $this->db->rollBack();
                throw $error;
            }
        } catch (Throwable $error) {
            if ($reasonMoved && is_file($newReasonPath) && !is_file($oldReasonPath)) {
                @rename($newReasonPath, $oldReasonPath);
            }
            if ($fileMoved && is_file($newPath) && !is_file($oldPath)) {
                @rename($newPath, $oldPath);
            }
            throw $error;
        }

        return [
            'file_id' => $fileId,
            'old_name' => $oldOriginalName,
            'new_name' => $newOriginalName,
            'old_queue_name' => $oldQueueName,
            'new_queue_name' => $newQueueName,
            'package_name' => $newPackageName,
        ];
    }

    private function cleanName(string $requestedName): string
    {
        $requestedName = trim($requestedName);
        $cleanName = basename(\catalog_clean_unreal_filename($requestedName));
        if ($cleanName === '' || $cleanName === '.' || $cleanName === '..') {
            throw new RuntimeException('Enter a valid filename.');
        }
        if (strcasecmp($cleanName, $requestedName) !== 0) {
            throw new RuntimeException('Enter only a filename, without a folder path or invalid Windows filename characters.');
        }
        if (preg_match('/\.uz(?:2|3)?$/i', $cleanName) === 1) {
            throw new RuntimeException('Enter the decompressed Unreal filename without the .uz, .uz2 or .uz3 queue wrapper.');
        }
        $extension = \catalog_clean_unreal_extension((string)pathinfo($cleanName, PATHINFO_EXTENSION));
        if ($extension === '' || preg_match('/^[a-z0-9_]{1,16}$/i', $extension) !== 1) {
            throw new RuntimeException('The new filename must include a valid Unreal file extension.');
        }
        if ($extension === 'txt') {
            throw new RuntimeException('.txt is reserved for the queue note sidecar.');
        }
        return $cleanName;
    }

    private function queueName(string $currentQueueName, string $newOriginalName): string
    {
        $currentQueueName = basename($currentQueueName);
        $prefix = '';
        if (preg_match('/^(\d{8}_\d{6}_[A-Fa-f0-9]{8}_)/', $currentQueueName, $match) === 1) {
            $prefix = $match[1];
        }
        $wrapper = '';
        if (preg_match('/(\.uz(?:2|3)?)$/i', $currentQueueName, $match) === 1) {
            $wrapper = $match[1];
        }
        return $prefix . $newOriginalName . $wrapper;
    }

    private function sourceRelative(string $sourceRelativePath, string $newOriginalName): string
    {
        $sourceRelativePath = \scanner_normalize_source_relative_path($sourceRelativePath);
        if ($sourceRelativePath === '') return '';
        $normalized = str_replace('\\', '/', $sourceRelativePath);
        $slash = strrpos($normalized, '/');
        return $slash === false ? $newOriginalName : substr($normalized, 0, $slash + 1) . $newOriginalName;
    }
}
