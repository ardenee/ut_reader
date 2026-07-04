<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Legacy;

use PDO;
use UnrealDb\Catalog\Application\Upload\Contract\CatalogPackageImporter;

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
        return \scanner_scan_uploaded_file(
            $db,
            $config,
            $gameId,
            $temporaryPath,
            $originalName,
            $userId,
            $strictProfile,
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
        \scanner_store_failed_upload($config, $temporaryPath, $originalName, $gameSlug, $reason);
    }
}
