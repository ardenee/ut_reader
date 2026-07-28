<?php
declare(strict_types=1);

function public_scale_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$security = file_get_contents($root . '/lib/CatalogSecurity.php');
$support = file_get_contents($root . '/lib/CatalogSupport.php');
$cache = file_get_contents($root . '/lib/CatalogPublicResponseCache.php');
$trace = file_get_contents($root . '/lib/CatalogResourceTracing.php');
$index = file_get_contents($root . '/index.php');
$page = file_get_contents($root . '/workload-tracing.php');
$migration = file_get_contents($root . '/migrations/202607280001_public_scale_tracing.php');
$config = file_get_contents($root . '/config.example.php');
$navigation = file_get_contents($root . '/lib/CatalogNavigation.php');

foreach (compact('security', 'support', 'cache', 'trace', 'index', 'page', 'migration', 'config', 'navigation') as $name => $source) {
    public_scale_expect(is_string($source), $name . ' source is missing.');
}

public_scale_expect(
    str_contains($security, 'catalog_session_request_needs_state')
        && str_contains($security, "if (!\$force && !catalog_session_request_needs_state())")
        && str_contains($security, "in_array(\$method, ['GET', 'HEAD'], true)")
        && str_contains($security, "isset(\$_COOKIE['UNREALDB_REMEMBER'])"),
    'Anonymous read-only requests still force a PHP session or remembered users are not restored.'
);

public_scale_expect(
    str_contains($support, "CatalogResourceTracing.php")
        && str_contains($support, "CatalogPublicResponseCache.php")
        && str_contains($support, 'catalog_public_cache_bootstrap(catalog_config())'),
    'Shared resource tracing and public response caching are not attached during catalog bootstrap.'
);

public_scale_expect(
    str_contains($cache, "'game-files.php' => 60")
        && str_contains($cache, "'file-examine.php' => 300")
        && str_contains($cache, 'catalog_public_cache_anonymous_request')
        && str_contains($cache, "header('X-UnrealDB-Page-Cache: '")
        && str_contains($cache, 'stale-while-revalidate')
        && str_contains(strtolower($cache), 'set-cookie:'),
    'The public cache is not bounded to anonymous allow-listed pages with safe response checks.'
);

public_scale_expect(
    str_contains($index, 'LEFT JOIN ue_game_catalog_stats s ON s.game_id=g.id')
        && str_contains($index, '$resultLimit = $adminSearch ? 200 : 100;')
        && str_contains($index, '$resultLimit + 1')
        && str_contains($index, 'do not calculate an exact result total')
        && !str_contains($index, "\ncatalog_start_session();\n"),
    'Public home/search still performs raw aggregation, exact totals, excessive result work or unconditional sessions.'
);

public_scale_expect(
    str_contains($trace, 'getrusage')
        && str_contains($trace, 'memory_get_peak_usage(true)')
        && str_contains($trace, 'ue_request_resource_performance')
        && str_contains($trace, "random_int(1, 20) === 1")
        && str_contains($trace, 'last_slowest_query_hash'),
    'Request tracing does not retain sampled CPU, peak memory, SQL and slow-query fingerprints.'
);

public_scale_expect(
    str_contains($page, 'events_statements_summary_by_digest')
        && str_contains($page, 'innodb_buffer_pool_size')
        && str_contains($page, 'Threads_running')
        && str_contains($page, 'Created_tmp_disk_tables')
        && str_contains($page, 'Application routes by sampled CPU')
        && str_contains($page, 'MySQL statement families by total execution time'),
    'The workload page does not expose current MySQL pressure and normalized expensive query families.'
);

public_scale_expect(
    str_contains($migration, "'version' => '202607280001'")
        && str_contains($migration, 'ue_request_resource_performance')
        && str_contains($migration, 'idx_ue_request_resource_cpu')
        && str_contains($migration, 'idx_ue_request_resource_memory'),
    'The resource tracing schema is incomplete.'
);

public_scale_expect(
    str_contains($config, "'public_response_enabled' => true")
        && str_contains($config, "'mysql_buffer_pool_bytes' => 36 * 1024 * 1024 * 1024")
        && str_contains($config, "'apache_threads_per_child' => 100")
        && str_contains($navigation, "'Workload Tracing' => \$root . 'workload-tracing.php'"),
    'Deployment targets or Workload Tracing navigation are missing.'
);

fwrite(STDOUT, "Public concurrency and workload tracing contract tests passed.\n");
