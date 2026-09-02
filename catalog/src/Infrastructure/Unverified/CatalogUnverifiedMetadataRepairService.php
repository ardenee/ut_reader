<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Discovers incomplete unverified metadata and queues targeted repair jobs.
 * Why: Filesystem inventory, compressed staging completeness and durable-job orchestration are one infrastructure use case.
 * Role: Infrastructure service for Repair Missing Unverified Metadata.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Import\CatalogBucketBatchQueue;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

final class CatalogUnverifiedMetadataRepairService
{
    private readonly CatalogUnverifiedStagingIndex $staging;
    private readonly CatalogUnverifiedMetadataStore $metadata;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/GameProfiles.php';
        $this->staging = new CatalogUnverifiedStagingIndex($db, $config);
        $this->metadata = new CatalogUnverifiedMetadataStore($db);
    }

    /**
     * sourceGameId follows unverified-files.php semantics:
     *  0 = all queues; -1 = Upload Bucket only; >0 = one game's queue.
     *
     * @return list<array<string,mixed>>
     */
    public function inventory(int $sourceGameId = -1): array
    {
        $this->staging->ensureSchema();

        $games = [];
        if ($sourceGameId === 0 || $sourceGameId === -1) {
            $games[] = CatalogUnverifiedQueueStorage::bucketGame();
        }
        if ($sourceGameId !== -1) {
            $sql = 'SELECT id,name,slug,profile_id FROM ue_games';
            $args = [];
            if ($sourceGameId > 0) {
                $sql .= ' WHERE id=?';
                $args[] = $sourceGameId;
            }
            $sql .= ' ORDER BY name';
            foreach (\catalog_all($this->db, $sql, $args) as $game) {
                $games[] = $game;
            }
        }

        $rowSql = 'SELECT f.* FROM ue_files f WHERE f.scan_status="unverified"';
        $rowArgs = [];
        if ($sourceGameId === -1) {
            $rowSql .= ' AND f.unverified_queue_game_id=0';
        } elseif ($sourceGameId > 0) {
            $rowSql .= ' AND f.unverified_queue_game_id=?';
            $rowArgs[] = $sourceGameId;
        }

        $rows = \catalog_all($this->db, $rowSql, $rowArgs);
        $counts = $this->metadata->countsForFiles(array_map(
            static fn(array $row): int => (int)$row['id'],
            $rows
        ));
        $rowsByKey = [];
        foreach ($rows as $row) {
            $fileId = (int)$row['id'];
            $storedCounts = $counts[$fileId] ?? null;
            $row['metadata_staging_present'] = is_array($storedCounts);
            $row['actual_name_count'] = (int)($storedCounts['name_count'] ?? 0);
            $row['actual_import_count'] = (int)($storedCounts['import_count'] ?? 0);
            $row['actual_export_count'] = (int)($storedCounts['export_count'] ?? 0);
            $key = trim((string)($row['unverified_queue_key'] ?? ''));
            if ($key !== '') {
                $rowsByKey[$key] = $row;
            }
        }

        $items = [];
        foreach ($games as $game) {
            $gameId = (int)($game['id'] ?? 0);
            $directory = CatalogUnverifiedQueueStorage::unverifiedDirectory($this->config, $game, false);
            if (!is_dir($directory) || !is_readable($directory)) {
                continue;
            }
            $entries = scandir($directory);
            if ($entries === false) {
                continue;
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.') || str_ends_with(strtolower($entry), '.txt')) {
                    continue;
                }
                $path = $directory . DIRECTORY_SEPARATOR . $entry;
                if (!is_file($path) || is_link($path) || !CatalogUnverifiedQueueStorage::pathInside($path, $directory)) {
                    continue;
                }

                $size = (int)(filesize($path) ?: 0);
                $key = CatalogUnverifiedStagingIndex::queueKey($gameId, $entry);
                $row = $rowsByKey[$key] ?? null;
                $reasons = $this->missingReasons(is_array($row) ? $row : null, $size, $path);
                $fallbackName = CatalogUnverifiedQueueStorage::originalNameFromQueueName($entry);
                $items[] = [
                    'token' => CatalogUnverifiedQueueStorage::token($gameId, $entry),
                    'queue_game_id' => $gameId,
                    'queue_name' => $entry,
                    'queue_key' => $key,
                    'queue_label' => (string)($game['name'] ?? ($gameId === 0 ? 'Upload Bucket' : 'Unknown queue')),
                    'original_name' => $row ? (string)($row['original_name'] ?? $fallbackName) : $fallbackName,
                    'path' => $path,
                    'size' => $size,
                    'file_id' => $row ? (int)$row['id'] : 0,
                    'row' => $row,
                    'missing_reasons' => $reasons,
                    'needs_repair' => $reasons !== [],
                ];
            }
        }

        usort($items, static fn(array $left, array $right): int =>
            strcasecmp((string)$left['original_name'], (string)$right['original_name'])
        );
        return $items;
    }

    /** @param array<string,mixed>|null $row @return list<string> */
    public function missingReasons(?array $row, int $physicalSize, string $path = ''): array
    {
        if ($row === null) {
            return ['Missing database inventory row'];
        }

        $reasons = [];
        $md5 = strtolower(trim((string)($row['md5'] ?? '')));
        $sha1 = strtolower(trim((string)($row['sha1'] ?? '')));
        $engine = strtoupper(trim((string)($row['detected_engine_key'] ?? '')));
        $version = $row['detected_package_version'] ?? null;
        $notes = (string)($row['scan_notes'] ?? '');
        $alreadyAttempted = str_contains($notes, 'Metadata repair attempted:');

        if (preg_match('/^[a-f0-9]{32}$/', $md5) !== 1) $reasons[] = 'MD5 is missing';
        if (preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1) $reasons[] = 'SHA-1 is missing';
        if ($physicalSize < 1 || (int)($row['file_size'] ?? 0) !== $physicalSize) {
            $reasons[] = 'Stored size does not match the physical file';
        }
        if (trim((string)($row['package_name'] ?? '')) === '') $reasons[] = 'Package name is missing';
        if (trim((string)($row['extension'] ?? '')) === '') $reasons[] = 'File extension is missing';

        if ($path !== '' && is_file($path)) {
            $summary = \gp_read_legacy_summary($path);
            if (!empty($summary['ok'])) {
                $headerEngine = strtoupper(trim((string)($summary['engine_hint'] ?? '')));
                $headerVersion = $summary['version'] ?? null;
                if ($headerEngine !== '' && $headerEngine !== 'UNKNOWN'
                    && $engine !== '' && $engine !== 'UNKNOWN' && $headerEngine !== $engine) {
                    $reasons[] = 'Stored engine ' . $engine . ' does not match package header ' . $headerEngine;
                }
                if (is_numeric($headerVersion) && is_numeric($version) && (int)$headerVersion !== (int)$version) {
                    $reasons[] = 'Stored package version does not match the package header';
                }
            }
        }

        if (!$alreadyAttempted) {
            if ($engine === '' || $engine === 'UNKNOWN') $reasons[] = 'Detected engine is missing';
            if ($version === null || $version === '') $reasons[] = 'Detected package version is missing';
            if (in_array($engine, ['UE1', 'UE2', 'UE3'], true)
                && is_numeric($version) && (int)$version >= 68
                && trim((string)($row['package_guid'] ?? '')) === '') {
                $reasons[] = 'Package GUID is missing';
            }
        }

        if (empty($row['metadata_staging_present'])) {
            $reasons[] = 'Compressed package metadata snapshot is missing';
        }
        $actualNameCount = (int)($row['actual_name_count'] ?? 0);
        $actualImportCount = (int)($row['actual_import_count'] ?? 0);
        $actualExportCount = (int)($row['actual_export_count'] ?? 0);
        foreach (['name', 'import', 'export'] as $table) {
            $declared = (int)($row[$table . '_count'] ?? 0);
            $actual = (int)($row['actual_' . $table . '_count'] ?? 0);
            if ($declared !== $actual) {
                $reasons[] = ucfirst($table) . ' count does not match staged metadata';
            }
        }
        if (!$alreadyAttempted
            && !empty($row['metadata_staging_present'])
            && in_array($engine, ['UE1', 'UE2', 'UE3', 'UE4', 'UE5'], true)
            && $actualNameCount === 0 && $actualImportCount === 0 && $actualExportCount === 0) {
            $reasons[] = 'Package table inventory is empty';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * Queue a forced current-code reparse for one retained Unverified file.
     * This is deliberately separate from retrying its old upload transport job:
     * the browser/archive source may already have been consumed, while the
     * durable Unverified file is the authoritative retained copy.
     *
     * @return array{queue:string,job_id:int,file_id:int,queue_game_id:int,queue_name:string}
     */
    public function queueFileRevalidation(int $fileId, ?int $createdBy = null, int $sourceJobId = 0): array
    {
        if ($fileId < 1) {
            throw new \InvalidArgumentException('A positive retained Unverified file ID is required.');
        }
        $this->staging->ensureSchema();

        $row = \catalog_one(
            $this->db,
            'SELECT id,original_name,file_size,unverified_queue_game_id,unverified_queue_name '
            . 'FROM ue_files WHERE id=? AND scan_status="unverified" LIMIT 1',
            [$fileId]
        );
        if (!$row) {
            throw new \RuntimeException(
                'The invalid-package job no longer has a retained Unverified file to revalidate.'
            );
        }

        $queueGameId = max(0, (int)($row['unverified_queue_game_id'] ?? 0));
        $queueName = basename(trim((string)($row['unverified_queue_name'] ?? '')));
        if ($queueName === '' || $queueName !== trim((string)($row['unverified_queue_name'] ?? ''))) {
            throw new \RuntimeException('The retained Unverified queue filename is invalid.');
        }

        if ($queueGameId === 0) {
            $game = CatalogUnverifiedQueueStorage::bucketGame();
        } else {
            $game = \catalog_one(
                $this->db,
                'SELECT id,name,slug,profile_id FROM ue_games WHERE id=? LIMIT 1',
                [$queueGameId]
            );
            if (!$game) {
                throw new \RuntimeException('The retained Unverified source game no longer exists.');
            }
        }

        $directory = CatalogUnverifiedQueueStorage::unverifiedDirectory($this->config, $game, false);
        $path = $directory . DIRECTORY_SEPARATOR . $queueName;
        if (!is_file($path)
            || is_link($path)
            || !CatalogUnverifiedQueueStorage::pathInside($path, $directory)) {
            throw new \RuntimeException(
                'The retained Unverified source file is no longer present on disk; it cannot be revalidated without another source copy.'
            );
        }
        $size = (int)(filesize($path) ?: 0);
        if ($size < 1) {
            throw new \RuntimeException('The retained Unverified source file is empty.');
        }

        $jobQueueName = (new CatalogBucketBatchQueue($this->db, $this->config))->queueName();
        $version = substr(hash_file(
            'sha256',
            dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Readers' . DIRECTORY_SEPARATOR . 'CatalogLegacyPackageReader.php'
        ) ?: hash('sha256', 'unverified-revalidate'), 0, 16);
        $dedupeKey = 'unverified-revalidate:' . $fileId . ':' . $version;

        $jobId = (new PdoJobQueue($this->db))->enqueue(
            $jobQueueName,
            JobType::REPAIR_UNVERIFIED_METADATA,
            [
                'queue_game_id' => $queueGameId,
                'queue_name' => $queueName,
                'original_name' => trim((string)($row['original_name'] ?? '')) ?: $queueName,
                'expected_size' => $size,
                'missing_reasons' => ['Explicit current-code revalidation requested from Background Jobs'],
                'requested_by' => $createdBy,
                'revalidation_file_id' => $fileId,
                'revalidation_source_job_id' => max(0, $sourceJobId),
            ],
            6,
            null,
            $dedupeKey,
            $createdBy,
            3
        );

        return [
            'queue' => $jobQueueName,
            'job_id' => $jobId,
            'file_id' => $fileId,
            'source_job_id' => max(0, $sourceJobId),
            'queue_game_id' => $queueGameId,
            'queue_name' => $queueName,
        ];
    }

    /** @return array{scope_count:int,candidate_count:int,job_ids:list<int>,queue:string} */
    public function queueRepairs(int $sourceGameId, ?int $createdBy = null): array
    {
        $items = $this->inventory($sourceGameId);
        $queueName = (new CatalogBucketBatchQueue($this->db, $this->config))->queueName();
        $queue = new PdoJobQueue($this->db);
        $jobIds = [];

        foreach ($items as $item) {
            if (empty($item['needs_repair'])) continue;
            $dedupeKey = 'unverified-metadata-v2:' . substr(hash(
                'sha256',
                (int)$item['queue_game_id'] . "\0" . (string)$item['queue_name']
            ), 0, 45);
            $jobId = $queue->enqueue(
                $queueName,
                JobType::REPAIR_UNVERIFIED_METADATA,
                [
                    'queue_game_id' => (int)$item['queue_game_id'],
                    'queue_name' => (string)$item['queue_name'],
                    'original_name' => (string)$item['original_name'],
                    'expected_size' => (int)$item['size'],
                    'missing_reasons' => array_values((array)$item['missing_reasons']),
                    'requested_by' => $createdBy,
                ],
                7,
                null,
                $dedupeKey,
                $createdBy,
                3
            );
            $jobIds[$jobId] = $jobId;
        }

        return [
            'scope_count' => count($items),
            'candidate_count' => count($jobIds),
            'job_ids' => array_values($jobIds),
            'queue' => $queueName,
        ];
    }
}
