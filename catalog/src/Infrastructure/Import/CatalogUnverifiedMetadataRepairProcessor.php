<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use ReflectionMethod;

/**
 * Rebuilds metadata for a package that is already physically stored in the
 * neutral Upload Bucket. It deliberately does not move, duplicate or re-upload
 * the file. The established Upload Bucket parser/batched database writer is
 * reused so repair jobs receive the same granular progress as new uploads.
 */
final class CatalogUnverifiedMetadataRepairProcessor
{
    private CatalogBucketUploadProcessor $processor;
    private ReflectionMethod $hashMethod;
    private ReflectionMethod $indexMethod;

    /** @param array<string,mixed> $config */
    public function __construct(PDO $db, array $config)
    {
        $this->processor = new CatalogBucketUploadProcessor($db, $config);
        $this->hashMethod = new ReflectionMethod(CatalogBucketUploadProcessor::class, 'hashIdentity');
        $this->indexMethod = new ReflectionMethod(CatalogBucketUploadProcessor::class, 'indexStored');
        $this->hashMethod->setAccessible(true);
        $this->indexMethod->setAccessible(true);
    }

    /**
     * @param callable(array<string,mixed>):void|null $progress
     * @return array<string,mixed>
     */
    public function repair(
        string $queueName,
        string $path,
        string $originalName,
        string $reason,
        int $uploadedBy,
        string $sourceRelativePath,
        ?callable $progress = null
    ): array {
        $queueName = basename($queueName);
        if ($queueName === '' || !is_file($path)) {
            throw new \RuntimeException('The Upload Bucket package to repair is unavailable.');
        }
        $size = (int)(filesize($path) ?: 0);
        if ($size < 1) {
            throw new \RuntimeException('The Upload Bucket package to repair is empty.');
        }

        /** @var array{md5:string,sha1:string} $identity */
        $identity = $this->hashMethod->invoke($this->processor, $path, $size, $progress);
        $md5 = strtolower(trim((string)($identity['md5'] ?? '')));
        $sha1 = strtolower(trim((string)($identity['sha1'] ?? '')));
        if (preg_match('/^[a-f0-9]{32}$/', $md5) !== 1 || preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1) {
            throw new \RuntimeException('Metadata repair could not calculate the package MD5 and SHA-1.');
        }

        /** @var array<string,mixed> $result */
        $result = $this->indexMethod->invoke(
            $this->processor,
            $queueName,
            $path,
            $originalName,
            $reason,
            $uploadedBy,
            $sourceRelativePath,
            $size,
            $md5,
            $sha1,
            $progress
        );
        return $result + ['md5' => $md5, 'sha1' => $sha1];
    }
}
