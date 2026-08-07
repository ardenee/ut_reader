<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the infrastructure class `LegacyCatalogPackageImporter` for legacy catalog package importer.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Infrastructure implementation for persistence, files, parsing, workers, security, storage, or external
 *       services.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Legacy;

use PDO;
use UnrealDb\Catalog\Application\Upload\Contract\CatalogPackageImporter;
use UnrealDb\Catalog\Infrastructure\Metadata\VerifiedFileCompactMetadataFinalizer;

/**
 * Compatibility adapter for the established scanner and reader stack.
 *
 * It deliberately contains all remaining procedural scanner coupling in one
 * infrastructure class. The application upload service depends only on the
 * contract, allowing the package pipeline to move to worker-backed or namespaced
 * readers later without rewriting the upload controller.
 */
final class LegacyCatalogPackageImporter implements CatalogPackageImporter
{
    public function __construct()
    {
        require_once __DIR__ . '/../../../lib/CatalogScanner.php';
    }

    public function import(
        PDO $db,
        array $config,
        int $gameId,
        string $temporaryPath,
        string $originalName,
        ?int $userId,
        bool $strictProfile,
        ?callable $progress
    ): array {
        $result = \scanner_scan_uploaded_file(
            $db,
            $config,
            $gameId,
            $temporaryPath,
            $originalName,
            $userId,
            $strictProfile,
            $progress
        );

        return VerifiedFileCompactMetadataFinalizer::finalize(
            $db,
            $config,
            $result,
            $progress
        );
    }

    public function preserveFailedUpload(
        array $config,
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

        \scanner_store_failed_upload($config, $temporaryPath, $originalName, $gameSlug, $reason);
    }
}
