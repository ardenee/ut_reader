<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

/** Queues every Upload Bucket redirect wrapper for the detached CLI worker. */
final class CatalogBucketUploadQueue
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function queueName(): string
    {
        $base = trim((string)($this->config['queue']['name'] ?? 'catalog')) ?: 'catalog';
        return $base . ':bucket-redirects';
    }

    /**
     * @return array{job_id:int,type:string,file:string,size:int,deduplicated:bool}
     */
    public function enqueueRedirect(
        string $uploadId,
        string $originalName,
        string $sourceRelativePath,
        int $size,
        int $userId
    ): array {
        if (preg_match('/^[a-f0-9]{64}$/', $uploadId) !== 1) {
            throw new \InvalidArgumentException('Chunked upload identifier is invalid.');
        }
        return $this->enqueue(
            [
                'upload_id' => $uploadId,
                'staged_path' => 'chunk-upload:' . $uploadId,
                'source_kind' => 'chunk-upload',
            ],
            'bucket-redirect:' . $uploadId,
            $originalName,
            $sourceRelativePath,
            $size,
            $userId
        );
    }

    /**
     * @param array{relative_path:string,size:int,sha256?:string} $staged
     * @return array{job_id:int,type:string,file:string,size:int,deduplicated:bool}
     */
    public function enqueueStagedRedirect(
        array $staged,
        string $originalName,
        string $sourceRelativePath,
        int $userId
    ): array {
        $stagedPath = trim((string)($staged['relative_path'] ?? ''));
        $size = (int)($staged['size'] ?? 0);
        $sha256 = strtolower(trim((string)($staged['sha256'] ?? '')));
        if ($stagedPath === '' || $size < 1 || $sha256 === '') {
            throw new \InvalidArgumentException('Durable redirect staging metadata is incomplete.');
        }
        $result = $this->enqueue(
            [
                'staged_path' => $stagedPath,
                'source_kind' => 'incoming-file',
            ],
            'bucket-redirect-staged:' . hash('sha256', strtolower($originalName) . "\0" . $sha256),
            $originalName,
            $sourceRelativePath,
            $size,
            $userId
        );
        if ($result['deduplicated']) {
            (new CatalogIncomingFileStore($this->config))->delete($stagedPath);
        }
        return $result;
    }

    /**
     * @param array<string,mixed> $source
     * @return array{job_id:int,type:string,file:string,size:int,deduplicated:bool}
     */
    private function enqueue(
        array $source,
        string $dedupeKey,
        string $originalName,
        string $sourceRelativePath,
        int $size,
        int $userId
    ): array {
        $originalName = $this->requiredName($originalName);
        require_once dirname(__DIR__, 3) . '/lib/CatalogRedirectArchive.php';
        if (!\catalog_redirect_archive_is_supported_filename($originalName)) {
            throw new \InvalidArgumentException('Bucket redirect job requires a .uz, .uz2 or .uz3 wrapper.');
        }
        if ($size < 1 || $userId < 1) {
            throw new \InvalidArgumentException('Bucket redirect job metadata is incomplete.');
        }

        $queueName = $this->queueName();
        $existing = $this->db->prepare(
            'SELECT id FROM ue_background_jobs WHERE queue_name=? AND dedupe_key=? LIMIT 1'
        );
        $existing->execute([$queueName, $dedupeKey]);
        $existingJobId = (int)($existing->fetchColumn() ?: 0);

        $jobId = (new PdoJobQueue($this->db))->enqueue(
            $queueName,
            JobType::PREPARE_BUCKET_REDIRECT,
            $source + [
                'original_name' => $originalName,
                'source_relative_path' => $this->cleanRelativePath(
                    $sourceRelativePath !== '' ? $sourceRelativePath : $originalName
                ),
                'size' => $size,
                'user_id' => $userId,
            ],
            5,
            null,
            $dedupeKey,
            $userId,
            3
        );

        return [
            'job_id' => $jobId,
            'type' => JobType::PREPARE_BUCKET_REDIRECT,
            'file' => $sourceRelativePath,
            'size' => $size,
            'deduplicated' => $existingJobId > 0 && $existingJobId === $jobId,
        ];
    }

    private function requiredName(string $name): string
    {
        $name = basename(str_replace(["\0", '/', '\\'], ['', DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR], trim($name)));
        $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
        $name = rtrim(trim($name), ' .');
        if ($name === '') {
            throw new \InvalidArgumentException('Bucket redirect filename is missing.');
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
