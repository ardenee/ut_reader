<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Promotes one resolved unverified package into verified game storage and queues post-import dependency work.
 * Why: Filesystem placement, identity reuse, compact metadata publication and promotion are one infrastructure use case.
 * Role: Infrastructure service for the established Unverified Files import behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use UnrealDb\Catalog\Infrastructure\Import\CatalogProfileMismatchException;

use PDO;
use Throwable;

final class CatalogUnverifiedPromotion
{
    private readonly CatalogUnverifiedDependencyRecovery $dependencies;
    private readonly CatalogUnverifiedQueueMutationService $queueMutations;
    private readonly CatalogUnverifiedStagingIndex $staging;
    private readonly CatalogUnverifiedCompactMetadataFinalizer $compactMetadata;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config,
        ?CatalogUnverifiedDependencyRecovery $dependencies = null,
        ?CatalogUnverifiedQueueMutationService $queueMutations = null
    ) {
        require_once dirname(__DIR__, 3) . '/lib/GameProfiles.php';
        require_once dirname(__DIR__, 3) . '/lib/CatalogScanner.php';
        $this->dependencies = $dependencies ?? new CatalogUnverifiedDependencyRecovery($db, $config);
        $this->queueMutations = $queueMutations ?? new CatalogUnverifiedQueueMutationService($db, $config);
        $this->staging = new CatalogUnverifiedStagingIndex($db, $config);
        $this->compactMetadata = new CatalogUnverifiedCompactMetadataFinalizer($db, $config);
    }

    /**
     * @param array<string,mixed> $source
     * @param callable(string,int,string):void|null $emit
     * @return array<string,mixed>
     */
    public function promote(
        array $source,
        int $targetGameId,
        ?int $userId,
        bool $allowProfileOverride,
        ?callable $emit = null
    ): array {
        $row = is_array($source['row'] ?? null) ? $source['row'] : null;
        if (!$row || (int)($row['id'] ?? 0) < 1) {
            $this->emit($emit, 'staging', 3, 'Indexing a legacy filesystem-only queued package');
            $indexed = $this->staging->indexItem($source, $userId, false);
            $row = \catalog_one(
                $this->db,
                'SELECT * FROM ue_files WHERE id=? AND scan_status="unverified" LIMIT 1',
                [(int)$indexed['file_id']]
            );
        } else {
            $this->emit($emit, 'staging', 3, 'Reusing compressed staged package metadata');
        }
        if (!$row) {
            throw new \RuntimeException('The unverified database row is unavailable.');
        }

        $target = \catalog_one($this->db, 'SELECT * FROM ue_games WHERE id=?', [$targetGameId]);
        if (!$target) {
            throw new \RuntimeException('Target game not found.');
        }

        $physicalOriginal = (string)$source['original_name'];
        $this->emit($emit, 'preparing', 8, 'Preparing queued package');
        $prepared = CatalogUnverifiedStagingIndex::preparePath((string)$source['path'], $physicalOriginal);
        try {
            $this->emit($emit, 'classifying', 12, 'Checking the selected game profile');
            $classification = \gp_classify_file($this->db, $targetGameId, $prepared['path'], $prepared['name']);
            if (empty($classification['header_ok'])) {
                throw new \RuntimeException(
                    trim((string)(($classification['notes'][0] ?? 'Invalid Unreal package header')))
                        ?: 'Invalid Unreal package header'
                );
            }
            if (!$allowProfileOverride && empty($classification['ok_for_selected_game'])) {
                throw new CatalogProfileMismatchException(
                    'Game/profile mismatch. Detected=' . ($classification['detected_engine'] ?? 'unknown')
                    . ', profile=' . ($classification['selected_engine'] ?? 'unknown') . '.',
                    [
                        'detected_engine' => (string)($classification['detected_engine'] ?? 'UNKNOWN'),
                        'selected_engine' => (string)($classification['selected_engine'] ?? 'UNKNOWN'),
                        'package_version' => $classification['package_version'] ?? null,
                        'licensee_version' => $classification['licensee_version'] ?? null,
                    ]
                );
            }

            $sourceRelativePath = \scanner_normalize_source_relative_path((string)($row['source_relative_path'] ?? ''));
            $detectedEngine = strtoupper((string)($classification['detected_engine'] ?? $row['detected_engine_key'] ?? ''));
            if (in_array($detectedEngine, ['UE4', 'UE5'], true) && $sourceRelativePath === '') {
                throw new \RuntimeException(
                    'UE4 package identity requires a mounted source-relative path. Requeue this file through folder upload, Local Source Scan or PAK import before verifying it.'
                );
            }

            $packageName = in_array($detectedEngine, ['UE4', 'UE5'], true)
                ? \scanner_ue_package_name_from_source_relative($sourceRelativePath)
                : (string)$row['package_name'];

            $this->emit($emit, 'identity', 20, 'Loading staged file identity');
            $identity = $this->packageIdentity($row, $prepared);
            if (empty($identity['reused'])) {
                $this->emit($emit, 'hashing', 25, 'Staged identity was incomplete; recalculating MD5 and SHA-1');
            }
            $md5 = (string)$identity['md5'];
            $sha1 = (string)$identity['sha1'];
            $fileSize = (int)$identity['size'];
            $guid = trim((string)($row['package_guid'] ?? ''));

            $this->emit($emit, 'duplicate_check', 30, 'Checking for an existing file or package alias');
            $duplicate = \catalog_one(
                $this->db,
                'SELECT id,game_id,package_name,original_name,file_size,package_guid,md5 '
                    . 'FROM ue_files WHERE game_id=? AND md5=? AND scan_status="verified" LIMIT 1',
                [$targetGameId, $md5]
            );
            if ($duplicate) {
                $status = 'duplicate';
                $message = 'Duplicate in selected game';
                if (strcasecmp((string)$duplicate['package_name'], $packageName) !== 0) {
                    \catalog_package_alias_add(
                        $this->db,
                        (int)$duplicate['id'],
                        $targetGameId,
                        $packageName,
                        (string)$row['original_name'],
                        $guid,
                        $md5,
                        $fileSize
                    );
                    $status = 'alias';
                    $message = 'Package alias added for existing file identity';
                }
                $this->queueMutations->discard($source);
                $this->emit($emit, 'done', 100, $message);
                return [
                    'status' => $status,
                    'file_id' => (int)$duplicate['id'],
                    'original_name' => (string)$row['original_name'],
                    'target_game' => (string)$target['name'],
                    'message' => $message,
                    'identity_reused' => !empty($identity['reused']),
                ];
            }

            $this->emit($emit, 'storage', 38, 'Moving package into verified storage');
            $extension = \catalog_clean_unreal_extension((string)pathinfo((string)$row['original_name'], PATHINFO_EXTENSION));
            $directory = rtrim((string)$this->config['storage_path'], DIRECTORY_SEPARATOR)
                . '/games/' . \scanner_slug_text((string)$target['slug']) . '/verified';
            if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new \RuntimeException('Could not create verified storage folder.');
            }
            $storedName = $md5 . '.' . $extension;
            $destination = $directory . '/' . $storedName;
            $moved = false;
            if (is_file($destination)) {
                if (!@unlink((string)$source['path'])) {
                    throw new \RuntimeException('Could not discard queued physical duplicate.');
                }
            } elseif (!empty($prepared['temporary'])) {
                if (!@copy((string)$prepared['path'], $destination)) {
                    throw new \RuntimeException('Could not store decompressed package.');
                }
                if (!@unlink((string)$source['path'])) {
                    @unlink($destination);
                    throw new \RuntimeException('Could not remove compressed queue wrapper.');
                }
                $moved = true;
            } else {
                if (!@rename((string)$source['path'], $destination)) {
                    throw new \RuntimeException('Could not move queued package into verified storage.');
                }
                $moved = true;
            }

            $this->emit($emit, 'database', 46, 'Promoting the staged database record');
            try {
                $this->db->beginTransaction();
                $notes = trim((string)$row['scan_notes']
                    . "\nVerified from unverified queue for " . (string)$target['name'] . '.');
                $update = $this->db->prepare(
                    'UPDATE ue_files SET game_id=?,package_name=?,stored_name=?,relative_path=?,'
                    . 'detected_engine_key=?,detected_package_version=?,detected_licensee_version=?,'
                    . 'detection_confidence=?,compatibility_status=?,compatibility_label=?,detection_notes=?,'
                    . 'file_size=?,md5=?,sha1=?,scan_status="verified",scan_notes=?,'
                    . 'uploaded_by=COALESCE(?,uploaded_by),unverified_queue_key=NULL,'
                    . 'unverified_queue_game_id=NULL,unverified_queue_name=NULL,unverified_reason=NULL WHERE id=? '
                    . 'AND scan_status="unverified"'
                );
                $update->execute([
                    $targetGameId,
                    $packageName,
                    $storedName,
                    'storage/games/' . \scanner_slug_text((string)$target['slug']) . '/verified/' . $storedName,
                    $classification['detected_engine'],
                    $classification['package_version'],
                    $classification['licensee_version'],
                    $classification['confidence'],
                    $classification['compatibility_status'] ?? 'native',
                    $classification['compatibility_label'] ?? null,
                    implode("\n", (array)$classification['notes']),
                    $fileSize,
                    $md5,
                    $sha1,
                    $notes,
                    $userId,
                    (int)$row['id'],
                ]);
                if ($update->rowCount() !== 1) {
                    throw new \RuntimeException('The staged database row changed before it could be promoted.');
                }
                $this->db->commit();
            } catch (Throwable $error) {
                if ($this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                if ($moved && is_file($destination) && !is_file((string)$source['path'])) {
                    @rename($destination, (string)$source['path']);
                }
                throw $error;
            }

            if (is_file((string)$source['reason_path'])) {
                @unlink((string)$source['reason_path']);
            }

            // The ue_files promotion is committed before current metadata publication
            // so the format-2 writer can resolve this file against its selected game.
            // The compressed staging row remains until publication verifies, which
            // gives CatalogUnverifiedDependencyRecovery a durable retry source.
            $this->emit($emit, 'compact_metadata', 58, 'Publishing current compact metadata');
            $compact = $this->compactMetadata->finalize((int)$row['id']);

            $this->emit($emit, 'dependency_queue', 72, 'Queueing search and dependency scans');
            $dependencyJobs = $this->dependencies->queueRefresh(
                (int)$row['id'],
                $targetGameId,
                $packageName,
                $userId
            );
            $this->emit($emit, 'done', 100, 'Import complete; background scans queued');
            return [
                'status' => 'verified',
                'file_id' => (int)$row['id'],
                'original_name' => (string)$row['original_name'],
                'target_game' => (string)$target['name'],
                'message' => 'Promoted existing unverified database row to verified; compressed staging metadata was published as format-2.',
                'dependency_jobs' => $dependencyJobs,
                'metadata_format_version' => (int)($compact['format_version'] ?? 0),
                'identity_reused' => !empty($identity['reused']),
            ];
        } finally {
            if (!empty($prepared['temporary']) && is_file((string)$prepared['path'])) {
                @unlink((string)$prepared['path']);
            }
        }
    }

    /** @return array{md5:string,sha1:string,size:int,reused:bool} */
    private function packageIdentity(array $row, array $prepared): array
    {
        $path = (string)($prepared['path'] ?? '');
        $size = is_file($path) ? (int)(filesize($path) ?: 0) : 0;
        if ($size <= 0) {
            throw new \RuntimeException('The queued package is empty or unavailable.');
        }

        $md5 = strtolower(trim((string)($row['md5'] ?? '')));
        $sha1 = strtolower(trim((string)($row['sha1'] ?? '')));
        $storedSize = (int)($row['file_size'] ?? 0);
        $validMd5 = preg_match('/^[a-f0-9]{32}$/', $md5) === 1;
        $validSha1 = preg_match('/^[a-f0-9]{40}$/', $sha1) === 1;

        if ($storedSize === $size && $validMd5 && $validSha1) {
            return ['md5' => $md5, 'sha1' => $sha1, 'size' => $size, 'reused' => true];
        }

        $calculatedMd5 = md5_file($path);
        $calculatedSha1 = sha1_file($path);
        if (!is_string($calculatedMd5) || !is_string($calculatedSha1)) {
            throw new \RuntimeException('Could not calculate queued file hashes.');
        }
        return [
            'md5' => strtolower($calculatedMd5),
            'sha1' => strtolower($calculatedSha1),
            'size' => $size,
            'reused' => false,
        ];
    }

    /** @param callable(string,int,string):void|null $emit */
    private function emit(?callable $emit, string $stage, int $percent, string $message): void
    {
        if ($emit !== null) {
            $emit($stage, max(0, min(100, $percent)), $message);
        }
    }
}
