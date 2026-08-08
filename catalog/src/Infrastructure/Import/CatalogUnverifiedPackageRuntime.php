<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides one explicit adapter for the established Unreal package/scanner and unverified-storage contracts.
 * Why: Namespaced import services should not scatter dependencies on procedural compatibility functions throughout their implementation.
 * Role: Infrastructure compatibility boundary; callers depend on methods, allowing the remaining procedural implementations to be replaced independently.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;

final class CatalogUnverifiedPackageRuntime
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogUnverifiedIndex.php';
        require_once dirname(__DIR__, 3) . '/lib/UnverifiedFileManager.php';
        require_once dirname(__DIR__, 3) . '/lib/GameProfiles.php';
    }

    public function ensureSchema(): void
    {
        \catalog_unverified_schema_ensure($this->db);
    }

    public function bucketDirectory(bool $create): string
    {
        return \uvf_upload_bucket_dir($this->config, $create);
    }

    public function pathInside(string $path, string $root): bool
    {
        return \uvf_path_inside($path, $root);
    }

    public function cleanFilename(string $name): string
    {
        return \catalog_clean_unreal_filename($name);
    }

    public function safeQueueName(string $name): string
    {
        return \uvf_safe_queue_name($name);
    }

    public function uniqueDestination(string $directory, string $queueName): string
    {
        return \uvf_unique_destination($directory, $queueName);
    }

    public function queueKey(int $queueGameId, string $queueName): string
    {
        return \catalog_unverified_queue_key($queueGameId, $queueName);
    }

    public function normalizeSourceRelativePath(string $path): string
    {
        return \scanner_normalize_source_relative_path($path);
    }

    /** @return array{0:string,1:array<string,mixed>} */
    public function detectEngine(string $path, string $name): array
    {
        /** @var array{0:string,1:array<string,mixed>} $result */
        $result = \catalog_unverified_detect_engine($path, $name);
        return $result;
    }

    public function uePackageNameFromSourceRelative(string $path): string
    {
        return \scanner_ue_package_name_from_source_relative($path);
    }

    public function logicalPackageName(string $name): string
    {
        return \scanner_logical_package_name($name);
    }

    public function readerClass(string $engine): string
    {
        return \scanner_load_reader_class($this->config, $engine);
    }

    /** @param array<int,mixed> $issues @return array{0:list<string>,1:list<string>} */
    public function splitReaderIssues(array $issues): array
    {
        /** @var array{0:list<string>,1:list<string>} $result */
        $result = \scanner_split_reader_issues($issues);
        return $result;
    }

    public function storageRelative(string $path): string
    {
        return \catalog_unverified_storage_relative($this->config, $path);
    }

    public function cleanExtension(string $extension): string
    {
        return \catalog_clean_unreal_extension($extension);
    }

    /**
     * @param list<array<string,mixed>> $imports
     * @param list<array<string,mixed>> $exports
     * @param array<int,string> $cache
     */
    public function referencePath(int $reference, array $imports, array $exports, array &$cache): string
    {
        return \scanner_ref_path($reference, $imports, $exports, $cache);
    }

    /** @param list<string> $parts */
    public function joinPathParts(array $parts): string
    {
        return \scanner_join_path_parts($parts);
    }
}
