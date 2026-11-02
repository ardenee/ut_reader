#!/usr/bin/env php
<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies the final federation logging and JSON/HTTP compatibility boundary.
 * Why: Legacy federation facades must remain delegates while namespaced authentication must not load those facades again.
 * Role: Read-only architecture/protocol regression verification.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

$catalogRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$checks = [];
$failures = [];
$read = static function (string $relative) use ($catalogRoot): string {
    $value = @file_get_contents($catalogRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($value) ? $value : '';
};
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
};

$critical = [
    'lib/FederationAuth.php',
    'lib/FederationTransferAuth.php',
    'src/Infrastructure/Federation/CatalogFederationJsonApi.php',
    'src/Infrastructure/Federation/CatalogFederationLogService.php',
    'src/Infrastructure/Federation/CatalogFederationSignedRequestAuthenticator.php',
    'src/Infrastructure/Federation/CatalogFederationStreamingUploadAuthenticator.php',
];
$syntaxFailures = [];
foreach ($critical as $relative) {
    $path = $catalogRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $output = [];
    $status = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $status);
    if ($status !== 0) $syntaxFailures[] = $relative . ': ' . implode(' ', $output);
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$auth = $read('lib/FederationAuth.php');
$jsonApi = $read('src/Infrastructure/Federation/CatalogFederationJsonApi.php');
$logService = $read('src/Infrastructure/Federation/CatalogFederationLogService.php');
$signed = $read('src/Infrastructure/Federation/CatalogFederationSignedRequestAuthenticator.php');
$streaming = $read('src/Infrastructure/Federation/CatalogFederationStreamingUploadAuthenticator.php');
$transferFacade = $read('lib/FederationTransferAuth.php');

$record(
    'legacy_facade_boundary',
    str_contains($auth, 'CatalogFederationJsonApi::bodyLimitBytes')
        && str_contains($auth, 'CatalogFederationJsonApi::readRequestBody')
        && str_contains($auth, 'CatalogFederationJsonApi::decodeObject')
        && str_contains($auth, 'CatalogFederationJsonApi::requestPath')
        && str_contains($auth, 'CatalogFederationJsonApi::respond')
        && str_contains($auth, 'CatalogFederationLogService($db))->write')
        && !str_contains($auth, 'php://input')
        && !str_contains($auth, 'UNREALDB_FEDERATION_MAX_JSON_BYTES')
        && !str_contains($auth, 'INSERT INTO ue_federation_transfer_logs'),
    'FederationAuth keeps only compatibility delegates for HTTP/logging'
);

$contracts = [
    'UNREALDB_FEDERATION_MAX_JSON_BYTES',
    'Request body exceeds the allowed size.',
    'Request body could not be read.',
    'Invalid JSON payload.',
    'JSON payload must be an object.',
    'JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE',
    "header('Content-Type: application/json; charset=utf-8')",
    "header('Cache-Control: no-store')",
    "header('X-Content-Type-Options: nosniff')",
];
$missing = array_values(array_filter($contracts, static fn(string $value): bool => !str_contains($jsonApi, $value)));
$record('json_api_contract', $missing === [], $missing === [] ? 'HTTP/body/JSON contracts retained' : 'missing: ' . implode(', ', $missing));
$record(
    'log_sql_contract',
    str_contains($logService, 'INSERT INTO ue_federation_transfer_logs(peer_id, transfer_job_id, level, event, details) VALUES(?,?,?,?,?)'),
    'federation audit SQL remains unchanged'
);
$record(
    'authenticator_dependency_boundary',
    !str_contains($signed, "'/lib/FederationAuth.php'")
        && !str_contains($streaming, "'/lib/FederationAuth.php'")
        && !str_contains($signed, '\\fed_setting(')
        && !str_contains($streaming, '\\fed_setting(')
        && !str_contains($signed, '\\fed_log(')
        && !str_contains($streaming, '\\fed_log('),
    'inbound authenticators depend on namespaced settings/logging directly'
);
$record(
    'transfer_facade_dependency_boundary',
    !str_contains($transferFacade, "'/FederationAuth.php'")
        && str_contains($transferFacade, 'CatalogFederationJsonApi::respond'),
    'streaming compatibility facade no longer loads FederationAuth'
);

require_once $catalogRoot . '/bootstrap/autoload.php';
$class = \UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationJsonApi::class;
$previous = getenv('UNREALDB_FEDERATION_MAX_JSON_BYTES');
putenv('UNREALDB_FEDERATION_MAX_JSON_BYTES=999999999');
$upper = $class::bodyLimitBytes();
putenv('UNREALDB_FEDERATION_MAX_JSON_BYTES=1');
$lower = $class::bodyLimitBytes();
if ($previous === false) putenv('UNREALDB_FEDERATION_MAX_JSON_BYTES'); else putenv('UNREALDB_FEDERATION_MAX_JSON_BYTES=' . $previous);
$record('body_limit_contract', $upper === 64 * 1024 * 1024 && $lower === 1024, 'body limit remains clamped to 1 KiB..64 MiB');
$record(
    'request_path_contract',
    $class::requestPath(['REQUEST_URI' => '/api/federation/ping.php?a=1']) === '/api/federation/ping.php'
        && $class::requestPath([]) === '/',
    'request path strips query text exactly'
);
$decoded = $class::decodeObject('{"ok":true,"value":42}');
$record('json_decode_contract', $decoded === ['ok' => true, 'value' => 42], 'valid JSON objects decode unchanged');

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
