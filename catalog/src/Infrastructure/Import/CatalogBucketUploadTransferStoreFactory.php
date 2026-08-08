<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Creates the Upload Bucket v2 chunk store with the effective PHP-safe chunk size and unrestricted durable staging limits.
 * Why: PHP ini/chunk-storage composition is Infrastructure policy and should not be duplicated inside the HTTP endpoint.
 * Role: Infrastructure composition helper for resumable Upload Bucket transfers.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

final class CatalogBucketUploadTransferStoreFactory
{
    /** @param array<string,mixed> $config */
    public static function create(array $config): CatalogChunkedUploadStore
    {
        $storeConfig = $config;
        $storeConfig['max_upload_bytes'] = PHP_INT_MAX;
        $storeConfig['max_container_upload_bytes'] = PHP_INT_MAX;
        $chunkConfig = is_array($storeConfig['chunk_upload'] ?? null) ? $storeConfig['chunk_upload'] : [];
        $chunkConfig['chunk_bytes'] = self::effectiveChunkBytes($config);
        $storeConfig['chunk_upload'] = $chunkConfig;
        return new CatalogChunkedUploadStore($storeConfig);
    }

    /** @param array<string,mixed> $config */
    public static function effectiveChunkBytes(array $config): int
    {
        $chunkConfig = is_array($config['chunk_upload'] ?? null) ? $config['chunk_upload'] : [];
        $bytes = max(
            1024 * 1024,
            min((int)($chunkConfig['chunk_bytes'] ?? (16 * 1024 * 1024)), 64 * 1024 * 1024)
        );
        $phpLimits = array_filter([
            self::iniBytes((string)ini_get('upload_max_filesize')),
            self::iniBytes((string)ini_get('post_max_size')),
        ], static fn(int $limit): bool => $limit > 0);
        if ($phpLimits !== []) {
            $bytes = min($bytes, max(1024 * 1024, min($phpLimits) - (512 * 1024)));
        }
        return max(1024 * 1024, $bytes);
    }

    private static function iniBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return 0;
        }
        if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([KMGTP]?)B?$/i', $value, $match) !== 1) {
            return max(0, (int)$value);
        }
        $power = match (strtoupper((string)$match[2])) {
            'K' => 1,
            'M' => 2,
            'G' => 3,
            'T' => 4,
            'P' => 5,
            default => 0,
        };
        return (int)floor((float)$match[1] * (1024 ** $power));
    }
}
