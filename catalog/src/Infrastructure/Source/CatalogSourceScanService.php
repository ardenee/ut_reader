<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides the namespaced boundary for direct local package source scans.
 * Why: Presentation and durable jobs should invoke source scanning without entering procedural catalog/lib orchestration.
 * Role: Infrastructure adapter over CatalogSourceScanRunner.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Source;

use PDO;

final class CatalogSourceScanService
{
    private readonly CatalogSourceScanRunner $runner;

    /** @param array<string,mixed> $config */
    public function __construct(PDO $db, array $config)
    {
        $this->runner = new CatalogSourceScanRunner($db, $config);
    }

    /** @return array<string,mixed> */
    public function run(int $sourceId, bool $importUnknown, bool $strictProfile, ?int $userId): array
    {
        return $this->runner->run($sourceId, $importUnknown, $strictProfile, $userId);
    }
}
