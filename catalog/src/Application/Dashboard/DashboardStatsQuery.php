<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application port used to read dashboard statistics.
 * Why: Dashboard use-case code must not know whether statistics come from PDO, cached projections, or another adapter.
 * Role: Application-layer read contract implemented by infrastructure adapters at the composition boundary.
 * Audit: Keep this interface narrow; dashboard persistence/query details belong outside Application.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Dashboard;

interface DashboardStatsQuery
{
    /** @return array<string,int> */
    public function load(): array;
}
