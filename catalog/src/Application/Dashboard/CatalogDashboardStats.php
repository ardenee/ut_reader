<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the dashboard statistics use case.
 * Why: It keeps dashboard orchestration in Application while persistence remains behind a narrow query port.
 * Role: Application-layer use case consumed by the dashboard controller.
 * Audit: Primary application implementation; do not add PDO, SQL, filesystem, or Infrastructure dependencies here.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Dashboard;

/** Loads administrative dashboard counters through the configured query port. */
final class CatalogDashboardStats
{
    public function __construct(private readonly DashboardStatsQuery $query)
    {
    }

    /** @return array<string,int> */
    public function load(): array
    {
        return $this->query->load();
    }
}
