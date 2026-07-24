<?php
declare(strict_types=1);

function federation_role_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$paths = [
    'state' => 'lib/FederationState.php',
    'connections' => 'federation/connections.php',
    'inventories' => 'federation/inventories.php',
    'requests' => 'federation/requests.php',
    'transfers' => 'federation/queue.php',
    'settings' => 'federation/settings.php',
    'diagnostics' => 'federation/diagnostics.php',
    'pairing' => 'lib/FederationPairing.php',
    'join_submit' => 'api/federation/join-request-submit.php',
    'join_status' => 'api/federation/join-request-status.php',
    'join_claim' => 'api/federation/join-claim.php',
    'join_cancel' => 'api/federation/join-request-cancel.php',
    'request_status' => 'api/federation/request-status.php',
    'streaming_cron' => 'federation/cron-worker-streaming.php',
];
$content = [];
foreach ($paths as $name => $path) {
    $value = file_get_contents($root . '/' . $path);
    federation_role_expect(is_string($value), 'Required federation workflow file is missing: ' . $path);
    $content[$name] = $value;
}

federation_role_expect(str_contains($content['state'], 'function federation_reconcile_site_role'), 'Connection-derived role reconciliation is missing.');
federation_role_expect(str_contains($content['state'], "return 'Joining Parent';"), 'Pending Parent join state is not represented separately.');
federation_role_expect(str_contains($content['state'], 'federation_parent_peer($db, false)'), 'Disabled established Parent connections incorrectly lose the Child role.');
federation_role_expect(str_contains($content['state'], 'federation_child_peers($db, false)'), 'Disabled established Children incorrectly lose the Parent role.');

federation_role_expect(str_contains($content['connections'], "federation_set_site_role($db, 'standalone')"), 'Submitting a Parent request does not explicitly remain Standalone.');
federation_role_expect(str_contains($content['connections'], 'federation_auto_claim_parent'), 'Approved Parent pairing is not completed automatically.');
federation_role_expect(str_contains($content['connections'], "federation_set_site_role($db, 'parent')"), 'Approving the first Child does not establish Parent role.');
federation_role_expect(str_contains($content['connections'], 'cancel_parent_join'), 'Pending Parent join requests cannot be cancelled.');
federation_role_expect(str_contains($content['connections'], 'remove_peer'), 'Established connections cannot be removed.');
federation_role_expect(str_contains($content['connections'], 'test_peer'), 'Connection diagnostics action is missing.');

federation_role_expect(!str_contains($content['settings'], 'name="site_role"'), 'Settings still exposes a manual federation role selector.');
federation_role_expect(str_contains($content['settings'], 'Managed by established connections.'), 'Settings does not explain connection-derived roles.');
federation_role_expect(str_contains($content['join_submit'], 'Existing pending join request refreshed.'), 'Repeated Parent join submissions cannot refresh the request token safely.');
federation_role_expect(str_contains($content['join_cancel'], 'JOIN_REQUEST_CANCELLED_BY_CHILD'), 'Remote Parent join cancellation is missing.');
federation_role_expect(str_contains($content['join_status'], "'claim_ready'"), 'Parent join status does not advertise automatic claim readiness.');
federation_role_expect(str_contains($content['join_claim'], "in_array(\$status, ['approved', 'claimed'], true)"), 'Automatic pairing is not retryable.');
federation_role_expect(str_contains($content['pairing'], "'child_download_scope' => 'missing_dependencies_only'"), 'Pairing does not retain dependency-only Child authority.');

federation_role_expect(str_contains($content['inventories'], 'Required by Parent'), 'Parent required-file inventory is missing.');
federation_role_expect(str_contains($content['inventories'], 'Missing from Parent'), 'Parent missing-file inventory is missing.');
federation_role_expect(str_contains($content['inventories'], 'Child required files'), 'Child Parent-inventory view is missing.');
federation_role_expect(str_contains($content['inventories'], 'already requested'), 'Child inventory does not suppress duplicate active requests.');
federation_role_expect(str_contains($content['requests'], 'Requests from Children'), 'Parent request review is missing.');
federation_role_expect(str_contains($content['requests'], 'Downloads Requested from Children'), 'Parent pull request status is missing.');
federation_role_expect(str_contains($content['requests'], 'Requests to Parent'), 'Child outgoing request status is missing.');
federation_role_expect(str_contains($content['request_status'], "!empty(\$payload['list'])"), 'Child cannot list all requests from the Parent.');
federation_role_expect(str_contains($content['request_status'], "!empty(\$payload['package_statuses'])"), 'Child inventory cannot obtain active package request states.');

foreach (['Logs', 'Cleanup', 'Conflicts', 'Worker', 'Connection Diagnostics'] as $section) {
    federation_role_expect(str_contains($content['diagnostics'], $section), 'Diagnostics section is missing: ' . $section);
}
federation_role_expect(str_contains($content['diagnostics'], 'federation_streaming_run_one_transfer'), 'Diagnostics worker is not using the streaming transfer implementation.');
federation_role_expect(is_file($root . '/federation/cron-worker-streaming.php'), 'Streaming cron worker is missing.');
federation_role_expect(!is_file($root . '/federation/cron-worker.php'), 'Legacy non-streaming cron worker still exists.');
federation_role_expect(!is_file($root . '/federation/transfer-run.php'), 'Legacy manual transfer runner still exists.');
federation_role_expect(!is_file($root . '/federation/import-run.php'), 'Legacy manual import runner still exists.');

echo "Federation consolidated role workflow contract tests passed.\n";
