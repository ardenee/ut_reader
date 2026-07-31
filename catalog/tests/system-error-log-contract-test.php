<?php
declare(strict_types=1);

function system_error_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$migration = file_get_contents($root . '/migrations/202607310002_system_error_log.php');
$bootstrap = file_get_contents($root . '/lib/CatalogSystemErrorSafe.php');
$autoload = file_get_contents($root . '/bootstrap/autoload.php');
$jsonResponse = file_get_contents($root . '/src/Presentation/Http/JsonResponse.php');
$browser = file_get_contents($root . '/assets/catalog-system-errors.js');
$api = file_get_contents($root . '/api/v1/system-error.php');
$review = file_get_contents($root . '/system-errors.php');
$navigation = file_get_contents($root . '/lib/CatalogNavigation.php');

foreach (compact('migration', 'bootstrap', 'autoload', 'jsonResponse', 'browser', 'api', 'review', 'navigation') as $name => $source) {
    system_error_expect(is_string($source) && $source !== '', $name . ' source is missing.');
}

system_error_expect(
    str_contains($migration, "'version' => '202607310002'")
        && str_contains($migration, "'ue_system_errors'")
        && str_contains($migration, 'UNIQUE KEY uq_ue_system_errors_key')
        && str_contains($migration, 'occurrence_count BIGINT UNSIGNED NOT NULL DEFAULT 1')
        && str_contains($migration, 'resolution_note VARCHAR(500) NULL'),
    'The central system error schema is incomplete.'
);

system_error_expect(
    str_contains($autoload, "CatalogSystemErrorSafe.php")
        && str_contains($autoload, 'catalog_system_error_register();')
        && str_contains($bootstrap, 'set_error_handler(')
        && str_contains($bootstrap, 'set_exception_handler(')
        && str_contains($bootstrap, 'register_shutdown_function(')
        && str_contains($bootstrap, 'ON DUPLICATE KEY UPDATE')
        && str_contains($bootstrap, 'occurrence_count=occurrence_count+1')
        && str_contains($bootstrap, 'catalog_system_error_record_exception')
        && str_contains($bootstrap, 'catalog_system_error_record_http')
        && !str_contains($bootstrap, 'ob_start('),
    'The central PHP error bootstrap is missing handlers, persistence or safe non-buffering behaviour.'
);

system_error_expect(
    str_contains($jsonResponse, "function_exists('catalog_system_error_record_http')")
        && str_contains($jsonResponse, '\\catalog_system_error_record_http($code, $message, $status')
        && str_contains($jsonResponse, 'detail_keys'),
    'API error responses are not recorded centrally.'
);

system_error_expect(
    str_contains($browser, "window.addEventListener('error'")
        && str_contains($browser, "window.addEventListener('unhandledrejection'")
        && str_contains($browser, 'resource_load_error')
        && str_contains($browser, "'X-UnrealDB-Error-Report': '1'")
        && str_contains($browser, 'localStorage')
        && str_contains($api, 'catalog_api_require_admin(false)')
        && str_contains($api, 'HTTP_X_UNREALDB_ERROR_REPORT')
        && str_contains($api, 'catalog_system_error_record(['),
    'Browser JavaScript/resource errors are not safely retained through the authenticated API.'
);

system_error_expect(
    str_contains($review, "catalog_check_csrf('system_errors')")
        && str_contains($review, 'Resolve')
        && str_contains($review, 'Ignore')
        && str_contains($review, 'Reopen')
        && str_contains($review, 'occurrence_count')
        && str_contains($review, 'Trace / context')
        && str_contains($navigation, "'System Errors' => \$root . 'system-errors.php'")
        && str_contains($navigation, 'catalog-system-errors.js'),
    'The central system error review, resolution or admin browser capture workflow is incomplete.'
);

system_error_expect(
    !str_contains($bootstrap, '$_POST')
        && !str_contains($bootstrap, '$_COOKIE')
        && str_contains($bootstrap, "'query_keys'")
        && str_contains($review, 'Request bodies, passwords, cookies and uploaded file contents are not stored'),
    'The central logger is retaining sensitive request data or does not document its bounded context.'
);

fwrite(STDOUT, "Central PHP, API and browser system error logging contract tests passed.\n");
