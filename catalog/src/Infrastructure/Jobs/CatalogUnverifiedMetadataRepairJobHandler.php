<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

use PDO;
use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Application\Jobs\JobHandler;
use UnrealDb\Catalog\Domain\Jobs\ClaimedJob;
use UnrealDb\Catalog\Domain\Jobs\JobType;

final class CatalogUnverifiedMetadataRepairJobHandler implements JobHandler
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/UnverifiedFileManager.php';
        require_once dirname(__DIR__, 3) . '/lib/CatalogUnverifiedIndex.php';
    }

    public function supports(string $jobType): bool
    {
        return $jobType === JobType::REPAIR_UNVERIFIED_METADATA;
    }

    public function handle(ClaimedJob $job, JobExecutionContext $context): array
    {
        $queueGameId = (int)($job->payload['queue_game_id'] ?? -1);
        $queueName = basename((string)($job->payload['queue_name'] ?? ''));
        if ($queueGameId < 0 || $queueName === '' || $queueName !== (string)($job->payload['queue_name'] ?? '')) {
            throw new \RuntimeException('The metadata-repair queue reference is invalid.');
        }

        $context->heartbeatIfDue([
            'stage' => 'repair_resolve',
            'done' => 3,
            'total' => 100,
            'percent' => 3,
            'message' => 'Resolving the physical unverified file.',
        ]);

        if ($queueGameId === 0) {
            $game = \uvf_bucket_game();
        } else {
            $game = \catalog_one($this->db, 'SELECT id,name,slug,profile_id FROM ue_games WHERE id=?', [$queueGameId]);
            if (!$game) {
                throw new \RuntimeException('The unverified source game no longer exists.');
            }
        }

        $directory = \uvf_unverified_dir($this->config, $game, false);
        $path = $directory . DIRECTORY_SEPARATOR . $queueName;
        if (!is_file($path) || is_link($path) || !\uvf_path_inside($path, $directory)) {
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

        $key = \catalog_unverified_queue_key($queueGameId, $queueName);
        $existing = \catalog_one(
            $this->db,
            'SELECT * FROM ue_files WHERE scan_status="unverified" AND unverified_queue_key=? LIMIT 1',
            [$key]
        ) ?: [];
        $originalName = trim((string)($existing['original_name'] ?? $job->payload['original_name'] ?? ''));
        if ($originalName === '') {
            $originalName = \uvf_original_name_from_queue_name($queueName);
        }
        $sourceRelativePath = trim((string)($existing['source_relative_path'] ?? ''));
        $uploadedBy = (int)($job->payload['requested_by'] ?? $existing['uploaded_by'] ?? 0);
        $reasonPath = $path . '.txt';
        $reason = is_file($reasonPath) && \uvf_path_inside($reasonPath, $directory)
            ? trim((string)@file_get_contents($reasonPath, false, null, 0, 65535))
            : '';

        // This repair path uses older indexing helpers that do not yet emit
        // record-level heartbeats. Extend the lease before entering the parser so
        // large packages cannot be reclaimed by another worker mid-repair.
        $lease = $this->db->prepare(
            'UPDATE ue_background_jobs SET lease_expires_at=DATE_ADD(UTC_TIMESTAMP(),INTERVAL 6 HOUR),'
            . ' last_heartbeat_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP()'
            . ' WHERE id=? AND status="running" AND lease_token=?'
        );
        $lease->execute([$job->id, $job->leaseToken]);

        $context->heartbeatIfDue([
            'stage' => 'repair_inventory',
            'done' => 10,
            'total' => 100,
            'percent' => 10,
            'message' => 'Recalculating identity, engine information and package tables for ' . $originalName . '.',
        ]);

        $result = \catalog_unverified_index_path(
            $this->db,
            $this->config,
            $queueGameId,
            $queueName,
            $path,
            $originalName,
            $reason,
            $uploadedBy > 0 ? $uploadedBy : null,
            $sourceRelativePath,
            true
        );

        $fileId = (int)($result['file_id'] ?? 0);
        if ($fileId < 1) {
            throw new \RuntimeException('Metadata repair did not return a database file ID.');
        }
        $this->db->prepare('UPDATE ue_files SET game_id=NULL WHERE id=? AND scan_status="unverified"')->execute([$fileId]);

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

        $notes = preg_replace('/(?:^|\R)Metadata repair attempted:.*$/m', '', (string)($row['scan_notes'] ?? ''));
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

        $context->heartbeatIfDue([
            'stage' => 'repair_complete',
            'done' => 100,
            'total' => 100,
            'percent' => 100,
            'message' => 'Metadata repair completed for ' . $originalName . '.',
        ]);

        return [
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
}
