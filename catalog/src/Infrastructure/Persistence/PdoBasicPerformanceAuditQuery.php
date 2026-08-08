<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Reads the latest administrator request-resource measurements for the basic page audit targets.
 * Why: Presentation should not know the request-resource telemetry table or implement route matching itself.
 * Role: Infrastructure read model; returns measurements keyed by the caller's stable target IDs.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use Throwable;

final class PdoBasicPerformanceAuditQuery
{
    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
    }

    /**
     * @param list<array{id:string,route_suffix:string,method:string}> $targets
     * @return array{metrics:array<string,array<string,mixed>>,error:string}
     */
    public function metrics(array $targets): array
    {
        try {
            $rows = \catalog_all(
                $this->db,
                'SELECT route_key,method,audience,sample_count,total_duration_us,total_sql_us,total_cpu_us,'
                . 'max_duration_us,max_sql_us,max_cpu_us,last_duration_us,last_sql_us,last_cpu_us,'
                . 'last_query_count,last_status,slow_sample_count,last_seen_at '
                . 'FROM ue_request_resource_performance '
                . 'WHERE audience="admin" ORDER BY last_seen_at DESC'
            );
        } catch (Throwable $error) {
            return ['metrics' => [], 'error' => $error->getMessage()];
        }

        $metrics = [];
        foreach ($targets as $target) {
            foreach ($rows as $row) {
                if (
                    strtoupper((string)($row['method'] ?? '')) === strtoupper((string)$target['method'])
                    && $this->routeMatches((string)($row['route_key'] ?? ''), (string)$target['route_suffix'])
                ) {
                    $metrics[(string)$target['id']] = $row;
                    break;
                }
            }
        }
        return ['metrics' => $metrics, 'error' => ''];
    }

    private function routeMatches(string $route, string $suffix): bool
    {
        return str_ends_with(str_replace('\\', '/', $route), $suffix);
    }
}
