#!/usr/bin/env php
<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies Federation Connections/Inventories controller boundaries after orchestration extraction.
 * Why: Pairing/network/transfer SQL can easily drift back into large rendering pages during maintenance.
 * Role: Read-only CLI architecture/regression verification; never mutates schema or application data.
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
    $path = $catalogRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $content = @file_get_contents($path);
    return is_string($content) ? $content : '';
};

$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$criticalPhp = [
    'bin/verify-federation-boundaries.php',
    'federation/connections.php',
    'federation/inventories.php',
    'src/Infrastructure/Federation/CatalogFederationConnectionActions.php',
    'src/Infrastructure/Federation/CatalogFederationConnectionQuery.php',
    'src/Infrastructure/Federation/CatalogFederationInventoryActions.php',
];

if (!function_exists('proc_open')) {
    $record('php_syntax', false, 'proc_open is unavailable; run php -l manually on the guarded PHP files.');
} else {
    $syntaxFailures = [];
    foreach ($criticalPhp as $relative) {
        $path = $catalogRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        if (!is_file($path)) {
            $syntaxFailures[] = $relative . ' is missing';
            continue;
        }
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-l', $path],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        if (!is_resource($process)) {
            $syntaxFailures[] = $relative . ' could not be linted';
            continue;
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            $syntaxFailures[] = $relative . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
        }
    }
    $record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));
}

$connections = $read('federation/connections.php');
$connectionActions = $read('src/Infrastructure/Federation/CatalogFederationConnectionActions.php');
$connectionQuery = $read('src/Infrastructure/Federation/CatalogFederationConnectionQuery.php');
$record(
    'connections_controller_boundary',
    str_contains($connections, 'CatalogFederationConnectionActions')
        && str_contains($connections, 'CatalogFederationConnectionQuery')
        && !str_contains($connections, 'TrustedHttpSourceClient')
        && !str_contains($connections, 'fed_http_post_signed(')
        && !str_contains($connections, 'beginTransaction()')
        && !str_contains($connections, 'INSERT INTO ue_federation_')
        && !str_contains($connections, 'UPDATE ue_federation_'),
    'Connections page must render/validate transport only; pairing and persistence belong to services'
);

$connectionActionsRequired = [
    "'/api/federation/join-request-submit.php'",
    "'/api/federation/join-request-status.php'",
    "'/api/federation/join-request-cancel.php'",
    "'/api/federation/ping.php'",
    "'submit_parent'",
    "'poll_parent'",
    "'cancel_parent_join'",
    "'approve_child'",
    "'deny_child'",
    "'toggle_peer'",
    "'update_child'",
    "'remove_peer'",
    "'test_peer'",
    "'refresh_peer'",
    "'stop_parent'",
];
$missingConnectionContracts = [];
foreach ($connectionActionsRequired as $needle) {
    if (!str_contains($connectionActions, $needle)) {
        $missingConnectionContracts[] = $needle;
    }
}
$record(
    'connections_protocol_contract',
    $missingConnectionContracts === []
        && str_contains($connectionActions, 'beginTransaction()')
        && str_contains($connectionActions, 'claim_token_hash=request_token_hash')
        && str_contains($connectionActions, 'User-Agent: UnrealFileCatalogFederation/2.0'),
    $missingConnectionContracts === []
        ? 'join/peer action and wire contracts retained'
        : 'missing: ' . implode(', ', $missingConnectionContracts)
);
$record(
    'connections_query_boundary',
    str_contains($connectionQuery, 'ue_federation_join_requests')
        && str_contains($connectionQuery, 'FIELD(status,"pending","approved","claimed","denied","expired")')
        && !str_contains($connections, 'ue_federation_join_requests'),
    'incoming join-request persistence read must remain outside rendering page'
);

$inventories = $read('federation/inventories.php');
$inventoryActions = $read('src/Infrastructure/Federation/CatalogFederationInventoryActions.php');
$record(
    'inventories_controller_boundary',
    str_contains($inventories, 'CatalogFederationInventoryActions')
        && str_contains($inventories, 'PdoFederationInventoryListQuery')
        && !str_contains($inventories, 'fed_http_post_signed(')
        && !str_contains($inventories, 'federation_pull_inventory_from_peer(')
        && !str_contains($inventories, 'federation_push_inventory_to_parent(')
        && !str_contains($inventories, 'federation_request_child_refresh_parent_inventory(')
        && !str_contains($inventories, 'INSERT INTO ue_federation_transfer_jobs'),
    'Inventories page must keep keyset/rendering reads while network and transfer mutations stay in service'
);

$inventoryContracts = [
    "'refresh'",
    "'queue_parent_pull'",
    "'submit_child_request'",
    "'/api/federation/request-status.php'",
    "'/api/federation/request-submit.php'",
    "'package_statuses' => true",
    'parent_pull_from_child',
    "'Every selected package is excluded by the parent Ignore base-game files policy.'",
];
$missingInventoryContracts = [];
foreach ($inventoryContracts as $needle) {
    if (!str_contains($inventoryActions, $needle)) {
        $missingInventoryContracts[] = $needle;
    }
}
$record(
    'inventories_protocol_contract',
    $missingInventoryContracts === []
        && str_contains($inventoryActions, 'PARENT_PAGE_SIZE = 100')
        && str_contains($inventoryActions, 'CHILD_PAGE_SIZE = 950')
        && str_contains($inventories, 'FI_PARENT_PAGE_SIZE = 100')
        && str_contains($inventories, 'FI_CHILD_PAGE_SIZE = 950'),
    $missingInventoryContracts === []
        ? 'refresh/pull/request and page-size contracts retained'
        : 'missing: ' . implode(', ', $missingInventoryContracts)
);

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
