<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Repairs metadata for one queued unverified file in a durable background job.
 * Why: Worker execution should use the namespaced queue-storage and staging services rather than procedural facades.
 * Role: Infrastructure job handler preserving the existing repair progress and result contract.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogUnverifiedMetadataRepairProcessor;
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogSystemErrorRecorder;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedQueueStorage;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedStagingIndex;

final class CatalogUnverifiedMetadataRepairJobHandler implements JobHandler
{
    private readonly CatalogUnverifiedStagingIndex $staging;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
        $this->staging = new CatalogUnverifiedStagingIndex($db, $config);
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::REPAIR_UNVERIFIED_METADATA;
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        $queueGameId = (int)($job->payload['queue_game_id'] ?? -1);
        $queueName = basename((string)($job->payload['queue_name'] ?? ''));
        if ($queueGameId < 0
            || $queueName === ''
            || $queueName !== (string)($job->payload['queue_name'] ?? '')) {
            throw new \RuntimeException('The metadata-repair queue reference is invalid.');
        }

        $fileStartedAt = gmdate(DATE_ATOM);
        $context->checkpoint([
            'stage' => 'repair_resolve',
            'done' => 0,
            'total' => 100,
            'percent' => 0,
            'part' => 0,
            'part_total' => 4,
            'file_started_at' => $fileStartedAt,
            'stage_started_at' => $fileStartedAt,
            'message' => 'Resolving the physical unverified file for this repair job.',
        ]);

        if ($queueGameId === 0) {
            $game = CatalogUnverifiedQueueStorage::bucketGame();
        } else {
            $game = \catalog_one(
                $this->db,
                'SELECT id,name,slug,profile_id FROM ue_games WHERE id=?',
                [$queueGameId]
            );
            if (!$game) {
                throw new \RuntimeException('The unverified source game no longer exists.');
            }
        }

        $directory = CatalogUnverifiedQueueStorage::unverifiedDirectory($this->config, $game, false);
        $path = $directory . DIRECTORY_SEPARATOR . $queueName;
        if (!is_file($path)
            || is_link($path)
            || !CatalogUnverifiedQueueStorage::pathInside($path, $directory)) {
            throw new \RuntimeException('The physical unverified file is no longer available.');
        }

        $size = (int)(filesize($path) ?: 0);
        $expectedSize = max(0, (int)($job->payload['expected_size'] ?? 0));
        if ($size < 1) {
            throw new \RuntimeException('The physical unverified file is empty.');
        }
        if ($expectedSize > 0 && $size !== $expectedSize) {
            throw new \RuntimeException('The physical file size changed after the repair job was queued.');
        }

        $key = CatalogUnverifiedStagingIndex::queueKey($queueGameId, $queueName);
        $existing = \catalog_one(
            $this->db,
            'SELECT * FROM ue_files WHERE scan_status="unverified" AND unverified_queue_key=? LIMIT 1',
            [$key]
        ) ?: [];
        $originalName = trim((string)($existing['original_name'] ?? $job->payload['original_name'] ?? ''));
        if ($originalName === '') {
            $originalName = CatalogUnverifiedQueueStorage::originalNameFromQueueName($queueName);
        }
        $sourceRelativePath = trim((string)($existing['source_relative_path'] ?? ''));
        $uploadedBy = (int)($job->payload['requested_by'] ?? $existing['uploaded_by'] ?? 0);
        $reasonPath = $path . '.txt';
        $reason = is_file($reasonPath)
            && CatalogUnverifiedQueueStorage::pathInside($reasonPath, $directory)
                ? trim((string)@file_get_contents($reasonPath, false, null, 0, 65535))
                : '';

        $lastVisibleStage = '';
        $lastVisiblePercent = -1;
        $lastVisibleAt = 0.0;
        $stageStartedAt = $fileStartedAt;
        $progress = function (array $raw) use (
            $context,
            $originalName,
            $fileStartedAt,
            &$lastVisibleStage,
            &$lastVisiblePercent,
            &$lastVisibleAt,
            &$stageStartedAt
        ): void {
            $mapped = $this->mapRepairProgress($raw, $originalName);
            $stage = (string)$mapped['stage'];
            $percent = (int)$mapped['percent'];
            $now = microtime(true);
            $stageChanged = $stage !== $lastVisibleStage;
            if ($stageChanged) {
                $stageStartedAt = gmdate(DATE_ATOM);
            }
            $mapped['file_started_at'] = $fileStartedAt;
            $mapped['stage_started_at'] = $stageStartedAt;

            $forceVisible = $stageChanged
                || $lastVisiblePercent < 0
                || abs($percent - $lastVisiblePercent) >= 3
                || ($now - $lastVisibleAt) >= 2.0
                || $percent >= 99;
            if ($forceVisible) {
                $context->checkpoint($mapped);
                $lastVisibleStage = $stage;
                $lastVisiblePercent = $percent;
                $lastVisibleAt = $now;
                return;
            }
            $context->heartbeatIfDue($mapped);
        };

        if ($queueGameId === 0) {
            $progress([
                'stage' => 'hash_identity',
                'percent' => 45,
                'message' => 'Starting the Upload Bucket repair.',
            ]);
            $result = (new CatalogUnverifiedMetadataRepairProcessor($this->db, $this->config))->repair(
                $queueName,
                $path,
                $originalName,
                $reason,
                max(1, $uploadedBy),
                $sourceRelativePath,
                $progress
            );
        } else {
            // Older per-game unverified queues use the same namespaced staging
            // index with explicit visible checkpoints around the operation.
            $context->checkpoint([
                'stage' => 'repair_header',
                'done' => 0,
                'total' => 100,
                'percent' => 0,
                'part' => 1,
                'part_total' => 4,
                'file_started_at' => $fileStartedAt,
                'stage_started_at' => gmdate(DATE_ATOM),
                'message' => 'Part 1 of 4 — reading package identity and Header for ' . $originalName . '.',
            ]);
            $result = $this->staging->indexPath(
                $queueGameId,
                $queueName,
                $path,
                $originalName,
                $reason,
                $uploadedBy > 0 ? $uploadedBy : null,
                $sourceRelativePath,
                true
            );
        }

        $fileId = (int)($result['file_id'] ?? 0);
        if ($fileId < 1) {
            throw new \RuntimeException('Metadata repair did not return a database file ID.');
        }
        $this->db->prepare(
            'UPDATE ue_files SET game_id=NULL WHERE id=? AND scan_status="unverified"'
        )->execute([$fileId]);

        $row = \catalog_one(
            $this->db,
            'SELECT id,original_name,md5,sha1,package_guid,detected_engine_key,detected_package_version,'
            . 'detected_licensee_version,name_count,import_count,export_count,scan_notes'
            . ' FROM ue_files WHERE id=? AND scan_status="unverified"',
            [$fileId]
        );
        if (!$row) {
            throw new \RuntimeException('The repaired staging row could not be reloaded.');
        }

        $notes = preg_replace(
            '/(?:^|\R)Metadata repair attempted:.*$/m',
            '',
            (string)($row['scan_notes'] ?? '')
        );
        $notes = trim((string)$notes);
        $marker = 'Metadata repair attempted: ' . gmdate(DATE_ATOM)
            . ' | job #' . $job->id
            . ' | result=' . (string)($result['status'] ?? 'updated');
        if (!empty($result['parse_error'])) {
            $marker .= ' | parser=' . preg_replace('/\s+/', ' ', trim((string)$result['parse_error']));
        }
        $notes = trim($notes . ($notes !== '' ? "\n" : '') . $marker);
        $this->db->prepare('UPDATE ue_files SET scan_notes=?,detection_notes=? WHERE id=?')
            ->execute([$notes, $notes, $fileId]);

        $completionMessage = empty($result['parse_error'])
            ? 'Metadata repair completed for ' . $originalName . ': Header, '
                . (int)$row['name_count'] . ' Names, '
                . (int)$row['import_count'] . ' Imports and '
                . (int)$row['export_count'] . ' Exports recorded.'
            : 'Basic metadata was repaired for ' . $originalName
                . ', but package tables remain unreadable: ' . trim((string)$result['parse_error']);
        $context->checkpoint([
            'stage' => 'complete',
            'done' => 100,
            'total' => 100,
            'percent' => 100,
            'part' => 4,
            'part_total' => 4,
            'file_started_at' => $fileStartedAt,
            'stage_started_at' => gmdate(DATE_ATOM),
            'file_id' => $fileId,
            'message' => $completionMessage,
        ]);

        $sourceJobId = max(0, (int)($job->payload['revalidation_source_job_id'] ?? 0));
        if ($sourceJobId > 0 && empty($result['parse_error'])) {
            $this->resolveSuccessfulRevalidation($sourceJobId, $job->id, $fileId, $originalName);
        }

        return [
            'status' => 'completed',
            'message' => $completionMessage,
            'operation' => 'repair_unverified_metadata',
            'file_id' => $fileId,
            'queue_game_id' => $queueGameId,
            'queue_name' => $queueName,
            'original_name' => (string)$row['original_name'],
            'md5' => (string)$row['md5'],
            'sha1' => (string)$row['sha1'],
            'package_guid' => (string)($row['package_guid'] ?? ''),
            'engine' => (string)($row['detected_engine_key'] ?? ''),
            'version' => $row['detected_package_version'] ?? null,
            'licensee' => $row['detected_licensee_version'] ?? null,
            'name_count' => (int)$row['name_count'],
            'import_count' => (int)$row['import_count'],
            'export_count' => (int)$row['export_count'],
            'parse_error' => $result['parse_error'] ?? null,
            'requested_missing_reasons' => array_values((array)($job->payload['missing_reasons'] ?? [])),
        ];
    }

    private function resolveSuccessfulRevalidation(
        int $sourceJobId,
        int $revalidationJobId,
        int $fileId,
        string $originalName
    ): void {
        $source = \catalog_one(
            $this->db,
            'SELECT result_json FROM ue_background_jobs '
                . 'WHERE id=? AND status="completed" AND display_status="invalid_ue_package" LIMIT 1',
            [$sourceJobId]
        );
        if (!$source) {
            return;
        }

        $result = json_decode((string)($source['result_json'] ?? ''), true);
        $result = is_array($result) ? $result : [];
        $previousStatus = strtolower(trim((string)($result['status'] ?? 'invalid_ue_package')));
        $result['previous_status'] = $previousStatus !== '' ? $previousStatus : 'invalid_ue_package';
        $result['status'] = 'revalidated';
        $result['revalidation_job_id'] = $revalidationJobId;
        $result['revalidated_file_id'] = $fileId;
        $result['revalidated_at'] = gmdate('Y-m-d H:i:s');
        $result['message'] = 'Retained source revalidated successfully with the current package reader: '
            . $originalName . '.';

        $encoded = json_encode(
            $result,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        );
        $statement = $this->db->prepare(
            'UPDATE ue_background_jobs SET result_json=?,updated_at=? '
                . 'WHERE id=? AND status="completed" AND display_status="invalid_ue_package"'
        );
        $statement->execute([$encoded, gmdate('Y-m-d H:i:s'), $sourceJobId]);

        if ($statement->rowCount() > 0) {
            CatalogSystemErrorRecorder::resolveInvalidUeJob(
                $sourceJobId,
                $revalidationJobId,
                (string)($row['md5'] ?? ''),
                (string)($row['sha1'] ?? '')
            );
        }
    }

    /** @param array<string,mixed> $raw @return array<string,mixed> */
    private function mapRepairProgress(array $raw, string $originalName): array
    {
        $rawStage = trim((string)($raw['stage'] ?? 'repair_header'));
        $rawPercent = max(0, min(100, (int)($raw['percent'] ?? 0)));
        $meta = $raw;
        unset($meta['stage'], $meta['done'], $meta['total'], $meta['percent'], $meta['message']);

        if ($rawStage === 'hash_identity') {
            $fraction = max(0.0, min(1.0, ($rawPercent - 45) / 10));
            $percent = (int)floor($fraction * 18);
            $done = (int)($raw['bytes_done'] ?? 0);
            $total = (int)($raw['bytes_total'] ?? 0);
            $detail = $total > 0 ? ' (' . $done . ' of ' . $total . ' bytes)' : '';
            return $meta + [
                'stage' => 'repair_header',
                'done' => $percent,
                'total' => 100,
                'percent' => $percent,
                'part' => 1,
                'part_total' => 4,
                'message' => 'Part 1 of 4 — calculating MD5/SHA-1 before reading the Header for '
                    . $originalName . $detail . '.',
            ];
        }

        return match ($rawStage) {
            'engine_detect' => $meta + $this->partProgress(
                1,
                18,
                'Part 1 of 4 — detecting the Unreal Engine generation and package summary.'
            ),
            'reader_validate' => $meta + $this->partProgress(
                1,
                21,
                'Part 1 of 4 — validating the package reader.'
            ),
            'read_header' => $meta + $this->partProgress(
                1,
                23,
                'Part 1 of 4 — reading the package Header.'
            ),
            'read_names' => $meta + $this->partProgress(
                2,
                25,
                'Part 2 of 4 — reading the Names table.'
            ),
            'read_imports' => $meta + $this->partProgress(
                3,
                50,
                'Part 3 of 4 — reading the Imports table.'
            ),
            'read_exports' => $meta + $this->partProgress(
                4,
                75,
                'Part 4 of 4 — reading the Exports table.'
            ),
            'reader_warning' => $meta + $this->partProgress(
                4,
                80,
                'Package table reading failed; preserving the basic identity and parser error.'
            ),
            'database_file' => $meta + $this->saveProgress(
                82,
                'Saving the repaired file identity and package summary.'
            ),
            'database_names' => $meta + $this->saveProgress(
                max(83, min(89, $rawPercent)),
                (string)($raw['message'] ?? 'Saving the Names table.')
            ),
            'database_imports' => $meta + $this->saveProgress(
                max(90, min(94, $rawPercent)),
                (string)($raw['message'] ?? 'Saving the Imports table.')
            ),
            'database_exports' => $meta + $this->saveProgress(
                max(95, min(98, $rawPercent)),
                (string)($raw['message'] ?? 'Saving the Exports table.')
            ),
            'database_commit' => $meta + $this->saveProgress(
                99,
                'Committing the repaired package inventory.'
            ),
            default => $meta + $this->saveProgress(
                max(1, min(99, $rawPercent)),
                trim((string)($raw['message'] ?? 'Repairing package metadata.'))
                    ?: 'Repairing package metadata.'
            ),
        };
    }

    /** @return array<string,mixed> */
    private function partProgress(int $part, int $percent, string $message): array
    {
        return [
            'stage' => match ($part) {
                1 => 'repair_header',
                2 => 'repair_names',
                3 => 'repair_imports',
                default => 'repair_exports',
            },
            'done' => $percent,
            'total' => 100,
            'percent' => $percent,
            'part' => $part,
            'part_total' => 4,
            'message' => $message,
        ];
    }

    /** @return array<string,mixed> */
    private function saveProgress(int $percent, string $message): array
    {
        return [
            'stage' => 'repair_save',
            'done' => $percent,
            'total' => 100,
            'percent' => $percent,
            'part' => 4,
            'part_total' => 4,
            'message' => trim($message) !== '' ? $message : 'Saving the repaired package inventory.',
        ];
    }
}
