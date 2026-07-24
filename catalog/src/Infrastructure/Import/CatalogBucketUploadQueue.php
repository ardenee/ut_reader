<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

/** Queues redirect wrappers for the detached CLI worker after chunk upload. */
final class CatalogBucketUploadQueue
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
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
        $originalName = $this->requiredName($originalName);
        require_once dirname(__DIR__, 3) . '/lib/CatalogRedirectArchive.php';
        if (!\catalog_redirect_archive_is_supported_filename($originalName)) {
            throw new \InvalidArgumentException('Bucket redirect job requires a .uz, .uz2 or .uz3 wrapper.');
        }
        if ($size < 1 || $userId < 1) {
            throw new \InvalidArgumentException('Bucket redirect job metadata is incomplete.');
        }

        $queueName = trim((string)($this->config['queue']['name'] ?? 'catalog')) ?: 'catalog';
        $dedupeKey = 'bucket-redirect:' . $uploadId;
        $existing = $this->db->prepare(
            'SELECT id FROM ue_background_jobs WHERE queue_name=? AND dedupe_key=? LIMIT 1'
        );
        $existing->execute([$queueName, $dedupeKey]);
        $existingJobId = (int)($existing->fetchColumn() ?: 0);

        $jobId = (new PdoJobQueue($this->db))->enqueue(
            $queueName,
            JobType::PREPARE_BUCKET_REDIRECT,
            [
                'upload_id' => $uploadId,
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
