<?php
declare(strict_types=1);
require_once dirname(__DIR__,2).'/catalog/lib/CatalogSupport.php';
use UnrealDb\Catalog\Infrastructure\Telemetry\CatalogOperationalAccessLogger;
$base=dirname(__DIR__,2).'/catalog/';
$core=(string)file_get_contents($base.'lib/CatalogSupportCore.php');
$cache=(string)file_get_contents($base.'src/Infrastructure/Cache/CatalogPublicResponseCacheService.php');
$logger=(string)file_get_contents($base.'src/Infrastructure/Telemetry/CatalogOperationalAccessLogger.php');
$privacy=(string)file_get_contents($base.'privacy.html');
$collector=(string)file_get_contents($base.'assets/catalog-access-matrix.js');
$endpoint=(string)file_get_contents($base.'access-event.php');
$settings=(string)file_get_contents($base.'src/Infrastructure/Telemetry/CatalogAccessLoggingSettings.php');
$matrix=(string)file_get_contents($base.'access-matrix.php');
$checks=[
 'dynamic_page_hook' => str_contains($core,'CatalogOperationalAccessLogger::record()'),
 'cache_hit_hook' => str_contains($cache,'CatalogOperationalAccessLogger::record()'),
 'one_event_per_request' => str_contains($logger,'private static bool $recorded = false'),
 'get_only' => str_contains($logger,'REQUEST_METHOD'),
 'cli_no_db' => str_contains($logger,"PHP_SAPI === 'cli'"),
 'existing_exclusions' => str_contains($logger,'CatalogAccessEventRecorder(')
    && str_contains($settings,'ignore_admin_sessions'),
 'privacy_explicit' => str_contains($privacy,'UnrealDB server-side access log'),
 'report_labels_match_collection' => str_contains($matrix,'Server-side page requests, including cache hits')
    && str_contains($matrix,'Browser-confirmed page loads (retired)'),
 'optional_js_inert' => !str_contains($collector,'fetch(')
    && !str_contains($collector,'sendBeacon'),
 'retired_optional_endpoint' => str_contains($endpoint,'http_response_code(204)')
    && !str_contains($endpoint,'catalog_db('),
];
foreach($checks as $name=>$ok)if(!$ok)throw new RuntimeException('FAIL '.$name);
CatalogOperationalAccessLogger::record(); // CLI must not connect or mutate DB
echo json_encode(['pass'=>true,'checks'=>$checks,'db_writes_in_test'=>0],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
