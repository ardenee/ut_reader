<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Adapts the established procedural scanner and reader stack to the application package-import port.
 * Why: Parser, PDO, runtime configuration and failed-file storage details belong in Infrastructure rather than upload orchestration.
 * Role: Legacy infrastructure adapter used by the profiled-upload application service.
 * Audit: Keep all remaining procedural scanner coupling here until the scanner itself is decomposed.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Legacy;

use PDO;
use UnrealDb\Catalog\Application\Upload\Contract\CatalogPackageImporter;
use UnrealDb\Catalog\Infrastructure\Metadata\VerifiedFileCompactMetadataFinalizer;

final class LegacyCatalogPackageImporter implements CatalogPackageImporter
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once __DIR__ . '/../../../lib/CatalogScanner.php';
    }

    public function import(
        int $gameId,
        string $temporaryPath,
        string $originalName,
        ?int $userId,
        bool $strictProfile,
        ?callable $progress
    ): array {
        $result = \scanner_scan_uploaded_file(
            $this->db,
            $this->config,
            $gameId,
            $temporaryPath,
            $originalName,
            $userId,
            $strictProfile,
            $progress
        );

        $result = VerifiedFileCompactMetadataFinalizer::finalize(
            $this->db,
            $this->config,
            $result,
            $progress
        );

        if (($result[0] ?? '') === 'alias') {
            $metadata = is_array($result[4] ?? null) ? $result[4] : [];
            $metadata['alias_already_exists'] = function_exists('catalog_package_alias_last_add_was_existing')
                && \catalog_package_alias_last_add_was_existing();
            $result[4] = $metadata;
        }

        return $result;
    }

    public function preserveFailedUpload(
        string $temporaryPath,
        string $originalName,
        string $gameSlug,
        string $reason
    ): void {
        if (!is_file($temporaryPath)) {
            return;
        }

        $bytes = @file_get_contents($temporaryPath, false, null, 0, 4);
        $tag = is_string($bytes) && strlen($bytes) === 4 ? (int)(unpack('V', $bytes)[1] ?? 0) : 0;
        if ($tag !== 0x9E2A83C1) {
            @unlink($temporaryPath);
            return;
        }

        \scanner_store_failed_upload($this->config, $temporaryPath, $originalName, $gameSlug, $reason);
    }
}
