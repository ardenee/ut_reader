<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

final class CatalogProfiledUploadQueue
{
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
     * @return array{job_id:int,type:string,file:string,size:int,deduplicated:bool}
     */
    public function enqueueStaged(
        int $gameId,
        array $staged,
        string $originalName,
        string $sourceRelativePath,
        bool $strictProfile,
        ?int $userId
    ): array {
        $game = $this->requiredGame($gameId);
        $originalName = $this->requiredName($originalName);
        $size = (int)($staged['size'] ?? 0);
        $isPak = strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION)) === 'pak';
        $limit = $isPak ? $this->containerLimitBytes() : (int)($this->config['max_upload_bytes'] ?? 0);
        if ($size < 1 || ($limit > 0 && $size > $limit)) {
            throw new \RuntimeException(($isPak ? 'PAK' : 'File') . ' exceeds the configured upload limit.');
        }
        if ($isPak) {
            $this->requirePakGame($game);
        }

        $jobType = $isPak ? JobType::IMPORT_STAGED_PAK : JobType::IMPORT_STAGED_PACKAGE;
        $stagedPath = (string)$staged['relative_path'];
        $cleanSourceRelativePath = $this->cleanRelativePath(
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

        // Standard uploads use their content hash. Large browser uploads already
        // have a stable chunk-upload ID derived from file metadata, so use that
        // ID when no whole-file hash is available yet. Either key prevents an
        // exact re-upload from creating another queued/running import.
        $dedupeKey = null;
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
        }

        $queueName = $this->queueName();
        $existingJobId = 0;
        if ($dedupeKey !== null) {
            $existing = $this->db->prepare(
                'SELECT id FROM ue_background_jobs WHERE queue_name=? AND dedupe_key=? LIMIT 1'
            );
            $existing->execute([$queueName, $dedupeKey]);
            $existingJobId = (int)($existing->fetchColumn() ?: 0);
        }

        $jobId = $this->queue()->enqueue(
            $queueName,
            $jobType,
            $payload,
            5,
            null,
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
            // A second conventional upload created a different durable object;
            // remove only that unused copy. A resumed chunk upload shares the
            // active job's path and must remain intact.
            if ($storedPath !== '' && $storedPath !== $stagedPath) {
                (new CatalogIncomingFileStore($this->config))->remove($stagedPath);
            }
        }

        return [
            'job_id' => $jobId,
            'type' => $jobType,
            'file' => $sourceRelativePath,
            'size' => $size,
            'deduplicated' => $deduplicated,
        ];
    }

    /** @return array{job_id:int,type:string,file:string,size:int} */
    public function enqueueChunkedPak(
        int $gameId,
        string $uploadId,
        array $upload,
        bool $strictProfile,
        ?int $userId
    ): array {
        $game = $this->requiredGame($gameId);
        $this->requirePakGame($game);
        if (preg_match('/^[a-f0-9]{64}$/', $uploadId) !== 1) {
            throw new \InvalidArgumentException('Chunked upload identifier is invalid.');
        }
        $originalName = $this->requiredName((string)($upload['original_name'] ?? 'archive.pak'));
        if (strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pak') {
            throw new \InvalidArgumentException('Completed chunked upload is not a PAK file.');
        }
        $size = (int)($upload['file_size'] ?? 0);
        if ($size < 1 || $size > $this->containerLimitBytes()) {
            throw new \RuntimeException('PAK exceeds the configured container upload limit.');
        }
        $relativePath = $this->cleanRelativePath((string)($upload['relative_path'] ?? $originalName));
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
            null,
            'chunk-pak:' . $uploadId,
            $userId,
            3
        );
        return ['job_id' => $jobId, 'type' => JobType::IMPORT_STAGED_PAK, 'file' => $relativePath, 'size' => $size];
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
        $originalName = $this->requiredName(basename($real));
        if (strtolower((string)pathinfo($originalName, PATHINFO_EXTENSION)) !== 'pak') {
            throw new \InvalidArgumentException('Local source is not a .pak archive.');
        }
        $size = filesize($real);
        $mtime = filemtime($real);
        if ($size === false || $size < 1 || (int)$size > $this->containerLimitBytes()) {
            throw new \RuntimeException('Local PAK exceeds the configured container upload limit.');
        }
        $payload = [
            'game_id' => $gameId,
            'staged_path' => 'local-pak:' . $this->encodeLocalPath($real),
            'original_name' => $originalName,
            'source_relative_path' => $this->cleanRelativePath($sourceRelativePath !== '' ? $sourceRelativePath : $originalName),
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

    private function encodeLocalPath(string $path): string
    {
        return rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
    }

    private function requiredName(string $name): string
    {
        $name = basename(str_replace(["\0", '/', '\\'], ['', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], trim($name)));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = rtrim(trim($name), ' .');
        if ($name === '') {
            throw new \InvalidArgumentException('Import filename is missing.');
        }
        return $name;
    }

    private function cleanRelativePath(string $path): string
    {
        $parts = [];
        foreach (explode('/', trim(str_replace(["\0", '\\'], ['', '/'], $path), '/')) as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                if ($parts !== []) {
                    array_pop($parts);
                }
                continue;
            }
            $parts[] = $part;
        }
        return implode('/', $parts);
    }
}
