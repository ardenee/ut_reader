<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CatalogProfiledUploadQueue` for catalog profiled upload queue.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external services.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use DateTimeImmutable;
use DateTimeZone;
use PDO;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Archive\CatalogArchiveExtractor;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

final class CatalogProfiledUploadQueue
{
    private const BATCH_HOLD_SECONDS = 86400;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function containerLimitBytes(): int
    {
        return max(
            (int)($this->config['max_upload_bytes'] ?? 0),
            (int)($this->config['max_container_upload_bytes'] ?? (64 * 1024 * 1024 * 1024))
        );
    }

    /**
     * @param array{relative_path:string,original_name?:string,size:int,sha256?:string} $staged
     * @return array{job_id:int,type:string,file:string,size:int,deduplicated:bool,held:bool}
     */
    public function enqueueStaged(
        int $gameId,
        array $staged,
        string $originalName,
        string $sourceRelativePath,
        bool $strictProfile,
        ?int $userId,
        bool $holdForBatch = false
    ): array {
        $game = $this->requiredGame($gameId);
        $originalName = CatalogImportPathPolicy::filename($originalName);
        $size = (int)($staged['size'] ?? 0);
        $extension = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION));
        $isPak = $extension === 'pak';
        $isArchive = CatalogArchiveExtractor::isArchiveName($originalName);
        $limit = ($isPak || $isArchive)
            ? $this->containerLimitBytes()
            : (int)($this->config['max_upload_bytes'] ?? 0);
        if ($size < 1 || ($limit > 0 && $size > $limit)) {
            $label = $isPak ? 'PAK' : ($isArchive ? 'Archive' : 'File');
            throw new \RuntimeException($label . ' exceeds the configured upload limit.');
        }
        if ($isPak) {
            $this->requirePakGame($game);
        }

        $jobType = $isPak
            ? JobType::IMPORT_STAGED_PAK
            : ($isArchive ? JobType::IMPORT_STAGED_ARCHIVE : JobType::IMPORT_STAGED_PACKAGE);
        $stagedPath = (string)$staged['relative_path'];
        $cleanSourceRelativePath = CatalogImportPathPolicy::relative(
            $sourceRelativePath !== '' ? $sourceRelativePath : $originalName
        );
        $payload = [
            'game_id' => $gameId,
            'staged_path' => $stagedPath,
            'original_name' => $originalName,
            'source_relative_path' => $cleanSourceRelativePath,
            'strict_profile' => $strictProfile,
            'user_id' => $userId,
            'size' => $size,
        ];
        $sha256 = strtolower(trim((string)($staged['sha256'] ?? '')));
        if ($sha256 !== '') {
            $payload['sha256'] = $sha256;
        }

        if ($sha256 !== '') {
            $dedupeKey = 'profiled-upload:' . hash(
                'sha256',
                $gameId . "\0"
                . $jobType . "\0"
                . strtolower($originalName) . "\0"
                . strtolower($cleanSourceRelativePath) . "\0"
                . $sha256 . "\0"
                . ($strictProfile ? 'strict' : 'loose')
            );
        } elseif (preg_match('/^chunk-upload:([a-f0-9]{64})$/', $stagedPath, $chunkMatch) === 1) {
            $dedupeKey = 'profiled-chunk:' . hash(
                'sha256',
                $gameId . "\0"
                . $jobType . "\0"
                . strtolower($originalName) . "\0"
                . strtolower($cleanSourceRelativePath) . "\0"
                . $chunkMatch[1] . "\0"
                . ($strictProfile ? 'strict' : 'loose')
            );
        } else {
            // The staging path is server-generated and unique. It is sufficient
            // to prevent a repeated queue request for the same durable object
            // without rereading the entire upload to calculate a synchronous hash.
            $dedupeKey = 'profiled-staged:' . hash(
                'sha256',
                $gameId . "\0" . $jobType . "\0" . $stagedPath . "\0" . ($strictProfile ? 'strict' : 'loose')
            );
        }

        $queueName = $this->queueName();
        $existing = $this->db->prepare(
            'SELECT id FROM ue_background_jobs WHERE queue_name=? AND dedupe_key=? LIMIT 1'
        );
        $existing->execute([$queueName, $dedupeKey]);
        $existingJobId = (int)($existing->fetchColumn() ?: 0);

        $jobId = $this->queue()->enqueue(
            $queueName,
            $jobType,
            $payload,
            5,
            $holdForBatch ? $this->batchHoldUntil() : null,
            $dedupeKey,
            $userId,
            3
        );

        $deduplicated = $existingJobId > 0 && $existingJobId === $jobId;
        if ($deduplicated) {
            $row = $this->db->prepare('SELECT payload_json FROM ue_background_jobs WHERE id=? LIMIT 1');
            $row->execute([$jobId]);
            $storedPayload = json_decode((string)($row->fetchColumn() ?: ''), true);
            $storedPath = is_array($storedPayload) ? (string)($storedPayload['staged_path'] ?? '') : '';
            if ($storedPath !== '' && $storedPath !== $stagedPath) {
                (new CatalogIncomingFileStore($this->config))->delete($stagedPath);
            }
        }

        return [
            'job_id' => $jobId,
            'type' => $jobType,
            'file' => $sourceRelativePath,
            'size' => $size,
            'deduplicated' => $deduplicated,
            'held' => $holdForBatch,
        ];
    }

    /** @return array{job_id:int,type:string,file:string,size:int,held:bool} */
    public function enqueueChunkedPak(
        int $gameId,
        string $uploadId,
        array $upload,
        bool $strictProfile,
        ?int $userId,
        bool $holdForBatch = false
    ): array {
        $game = $this->requiredGame($gameId);
        $this->requirePakGame($game);
        if (preg_match('/^[a-f0-9]{64}$/', $uploadId) !== 1) {
            throw new \InvalidArgumentException('Chunked upload identifier is invalid.');
        }
        $originalName = CatalogImportPathPolicy::filename((string)($upload['original_name'] ?? 'archive.pak'));
        if (strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pak') {
            throw new \InvalidArgumentException('Completed chunked upload is not a PAK file.');
        }
        $size = (int)($upload['file_size'] ?? 0);
        if ($size < 1 || $size > $this->containerLimitBytes()) {
            throw new \RuntimeException('PAK exceeds the configured container upload limit.');
        }
        $relativePath = CatalogImportPathPolicy::relative((string)($upload['relative_path'] ?? $originalName));
        $jobId = $this->queue()->enqueue(
            $this->queueName(),
            JobType::IMPORT_STAGED_PAK,
            [
                'game_id' => $gameId,
                'staged_path' => 'chunk-upload:' . $uploadId,
                'original_name' => $originalName,
                'source_relative_path' => $relativePath,
                'strict_profile' => $strictProfile,
                'user_id' => $userId,
                'size' => $size,
            ],
            5,
            $holdForBatch ? $this->batchHoldUntil() : null,
            'chunk-pak:' . $uploadId,
            $userId,
            3
        );
        return [
            'job_id' => $jobId,
            'type' => JobType::IMPORT_STAGED_PAK,
            'file' => $relativePath,
            'size' => $size,
            'held' => $holdForBatch,
        ];
    }

    /**
     * Make held profiled-upload jobs runnable immediately after the browser has
     * durably staged its selected batch. Only queued jobs owned by this admin
     * and belonging to the profiled import job types can be released.
     *
     * @param list<int> $jobIds
     */
    public function releaseHeldJobs(array $jobIds, int $userId): int
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $jobIds),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return 0;
        }
        if (count($ids) > 10000) {
            throw new \InvalidArgumentException('Too many upload jobs were supplied for one batch release.');
        }
        if ($userId < 1) {
            throw new \RuntimeException('A valid administrator identity is required to release upload jobs.');
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = 'UPDATE ue_background_jobs SET available_at=UTC_TIMESTAMP(),updated_at=UTC_TIMESTAMP() '
            . 'WHERE status="queued" AND created_by=? '
            . 'AND job_type IN (?,?,?) AND id IN (' . $placeholders . ')';
        $statement = $this->db->prepare($sql);
        $statement->execute(array_merge([
            $userId,
            JobType::IMPORT_STAGED_PACKAGE,
            JobType::IMPORT_STAGED_PAK,
            JobType::IMPORT_STAGED_ARCHIVE,
        ], $ids));
        return $statement->rowCount();
    }

    /** @return array{job_id:int,type:string,file:string,size:int} */
    public function enqueueLocalPak(
        int $gameId,
        string $localPath,
        string $sourceRelativePath,
        bool $strictProfile,
        ?int $userId,
        ?int $sourceId = null
    ): array {
        $game = $this->requiredGame($gameId);
        $this->requirePakGame($game);
        $real = realpath($localPath);
        if ($real === false || !is_file($real) || !is_readable($real) || is_link($real)) {
            throw new \RuntimeException('Local PAK path is not a readable regular file.');
        }
        $originalName = CatalogImportPathPolicy::filename(basename($real));
        if (strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pak') {
            throw new \InvalidArgumentException('Local source is not a .pak archive.');
        }
        $size = filesize($real);
        $mtime = filemtime($real);
        if ($size === false || $size < 1 || (int)$size > $this->containerLimitBytes()) {
            throw new \RuntimeException('Local PAK exceeds the configured container limit.');
        }
        $payload = [
            'game_id' => $gameId,
            'staged_path' => 'local-pak:' . $this->encodeLocalPath($real),
            'original_name' => $originalName,
            'source_relative_path' => CatalogImportPathPolicy::relative($sourceRelativePath !== '' ? $sourceRelativePath : $originalName),
            'strict_profile' => $strictProfile,
            'user_id' => $userId,
            'size' => (int)$size,
            'source_mtime' => (int)($mtime ?: 0),
        ];
        if ($sourceId !== null && $sourceId > 0) {
            $payload['source_id'] = $sourceId;
        }
        $dedupeKey = $sourceId !== null && $sourceId > 0
            ? 'local-pak:' . hash('sha256', $gameId . "\0" . $real . "\0" . (int)$size . "\0" . (int)($mtime ?: 0))
            : null;
        $jobId = $this->queue()->enqueue(
            $this->queueName(),
            JobType::IMPORT_STAGED_PAK,
            $payload,
            5,
            null,
            $dedupeKey,
            $userId,
            3
        );
        return ['job_id' => $jobId, 'type' => JobType::IMPORT_STAGED_PAK, 'file' => $sourceRelativePath, 'size' => (int)$size];
    }

    /** @return array<string,mixed> */
    private function requiredGame(int $gameId): array
    {
        if ($gameId < 1) {
            throw new \InvalidArgumentException('Choose a valid target game.');
        }
        $statement = $this->db->prepare(
            'SELECT g.id,g.name,g.slug,p.engine_key profile_engine FROM ue_games g '
            . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE g.id=?'
        );
        $statement->execute([$gameId]);
        $game = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($game)) {
            throw new \RuntimeException('Target game no longer exists: ' . $gameId);
        }
        return $game;
    }

    /** @param array<string,mixed> $game */
    private function requirePakGame(array $game): void
    {
        if (preg_match('/^UE[45]/i', trim((string)($game['profile_engine'] ?? ''))) !== 1) {
            throw new \RuntimeException('PAK container import requires a UE4 or UE5 target game.');
        }
    }

    private function queue(): PdoJobQueue
    {
        return new PdoJobQueue($this->db);
    }

    private function queueName(): string
    {
        return trim((string)($this->config['queue']['name'] ?? 'catalog')) ?: 'catalog';
    }

    private function batchHoldUntil(): DateTimeImmutable
    {
        return (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . self::BATCH_HOLD_SECONDS . ' seconds');
    }

    private function encodeLocalPath(string $path): string
    {
        return rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
    }
}
