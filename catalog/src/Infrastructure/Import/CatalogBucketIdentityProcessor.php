<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `CatalogBucketIdentityProcessor` for catalog bucket identity processor.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;
use ReflectionMethod;
use Throwable;

/**
 * Identity-aware entry point for Upload Bucket processing. It reuses the
 * established storage/index implementation but bypasses its legacy full-file
 * hashing pass because the caller already calculated the package identity while
 * copying ordinary files or while producing decompressed redirect output.
 */
final class CatalogBucketIdentityProcessor
{
    private CatalogBucketUploadProcessor $processor;
    private ReflectionMethod $storeMethod;
    private ReflectionMethod $indexMethod;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $this->processor = new CatalogBucketUploadProcessor($db, $config);
        $this->storeMethod = new ReflectionMethod(CatalogBucketUploadProcessor::class, 'storeBucketUpload');
        $this->indexMethod = new ReflectionMethod(CatalogBucketUploadProcessor::class, 'indexStored');
        $this->storeMethod->setAccessible(true);
        $this->indexMethod->setAccessible(true);
    }

    /**
     * @param callable(array<string,mixed>):void|null $progress
     * @return array{status:string,file_id:int,queue_name:string,original_name:string,path:string,size:int,message:string,parse_error:?string,md5:string,sha1:string}
     */
    public function stage(
        string $temporaryPath,
        string $originalName,
        string $reason,
        int $uploadedBy,
        string $sourceRelativePath,
        string $md5,
        string $sha1,
        ?callable $progress = null
    ): array {
        if (!is_file($temporaryPath)) {
            throw new \RuntimeException('Prepared Upload Bucket file is missing.');
        }
        if ($uploadedBy < 1) {
            throw new \RuntimeException('Administrator identity is missing from the Upload Bucket job.');
        }
        $size = (int)(filesize($temporaryPath) ?: 0);
        if ($size < 1) {
            throw new \RuntimeException('Prepared Upload Bucket file is empty.');
        }

        $md5 = strtolower(trim($md5));
        $sha1 = strtolower(trim($sha1));
        if (preg_match('/^[a-f0-9]{32}$/', $md5) !== 1 || preg_match('/^[a-f0-9]{40}$/', $sha1) !== 1) {
            throw new \RuntimeException('Prepared package MD5 or SHA-1 is missing.');
        }

        $this->emit($progress, 'hash_identity', 55, 'Using MD5 and SHA-1 calculated while the package bytes were produced.', [
            'bytes_done' => $size,
            'bytes_total' => $size,
            'md5' => $md5,
            'sha1' => $sha1,
        ]);

        $this->emit($progress, 'duplicate_check', 58, 'Checking size, MD5 and SHA-1 against physical Upload Bucket and catalog files.');
        $inspection = (new CatalogUploadDuplicateDetector($this->db, $this->config))->inspect($size, $md5, $sha1);
        $duplicate = is_array($inspection['duplicate'] ?? null) ? $inspection['duplicate'] : null;
        if ($duplicate !== null) {
            @unlink($temporaryPath);
            $existingName = trim((string)($duplicate['original_name'] ?? ''));
            if ($existingName === '') {
                $existingName = trim((string)($duplicate['package_name'] ?? '')) ?: 'existing physical package';
            }
            $location = (string)($duplicate['location_kind'] ?? '') === 'upload_bucket'
                ? 'the Upload Bucket'
                : 'catalog storage';
            $message = 'Duplicate size, MD5 and SHA-1 already exist in ' . $location
                . ' as ' . $existingName . ' (file #' . (int)$duplicate['file_id'] . '). Prepared copy discarded.';
            $this->emit($progress, 'duplicate', 100, $message, ['file_id' => (int)$duplicate['file_id']]);
            return [
                'status' => 'duplicate',
                'file_id' => (int)$duplicate['file_id'],
                'queue_name' => '',
                'original_name' => $existingName,
                'path' => (string)$duplicate['physical_path'],
                'size' => $size,
                'message' => $message,
                'parse_error' => null,
                'md5' => $md5,
                'sha1' => $sha1,
            ];
        }

        $missingBase = (int)($inspection['missing_base_game_matches'] ?? 0);
        if ($missingBase > 0) {
            $this->emit(
                $progress,
                'duplicate_check',
                59,
                'Official base-game identity metadata matched, but no physical source file exists. Keeping this package.'
            );
        }

        $this->emit($progress, 'bucket_store', 60, 'Moving the prepared package into Upload Bucket storage.');
        /** @var array{queue_name:string,original_name:string,size:int,path:string} $stored */
        $stored = $this->storeMethod->invoke($this->processor, $temporaryPath, $originalName, $reason);
        $storedPath = (string)$stored['path'];

        try {
            /** @var array<string,mixed> $indexed */
            $indexed = $this->indexMethod->invoke(
                $this->processor,
                (string)$stored['queue_name'],
                $storedPath,
                (string)$stored['original_name'],
                $reason,
                $uploadedBy,
                $sourceRelativePath,
                (int)$stored['size'],
                $md5,
                $sha1,
                $progress
            );
        } catch (Throwable $error) {
            @unlink($storedPath . '.txt');
            @unlink($storedPath);
            throw $error;
        }

        return $indexed + ['md5' => $md5, 'sha1' => $sha1];
    }

    /** @param callable(array<string,mixed>):void|null $progress @param array<string,mixed> $meta */
    private function emit(?callable $progress, string $stage, int $percent, string $message, array $meta = []): void
    {
        if ($progress === null) {
            return;
        }
        $progress($meta + [
            'stage' => $stage,
            'done' => max(0, min(100, $percent)),
            'total' => 100,
            'percent' => max(0, min(100, $percent)),
            'message' => $message,
        ]);
    }
}
