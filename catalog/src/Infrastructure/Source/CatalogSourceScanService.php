<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides the namespaced boundary for the direct package source-scan compatibility workflow.
 * Why: Presentation should not call the procedural scanner implementation directly.
 * Role: Infrastructure adapter; preserves scanner behavior while allowing the legacy library to shrink independently.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Source;

use PDO;

final class CatalogSourceScanService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSourceScanNoContainers.php';
    }

    /** @return array<string,mixed> */
    public function run(int $sourceId, bool $importUnknown, bool $strictProfile, ?int $userId): array
    {
        return \catalog_source_scan_run_without_containers(
            $this->db,
            $this->config,
            $sourceId,
            $importUnknown,
            $strictProfile,
            $userId
        );
    }
}
