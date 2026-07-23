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
    'join' => 'federation/join.php',
    'join_main' => 'federation/join-main-parent.php',
    'join_review' => 'federation/join-requests.php',
    'join_status' => 'api/federation/join-request-status.php',
    'join_claim' => 'api/federation/join-claim.php',
    'legacy_claim' => 'federation/claim-parent.php',
    'pairing' => 'lib/FederationPairing.php',
    'inventory_api' => 'api/federation/inventory-list.php',
    'inventory' => 'lib/FederationInventory.php',
    'peer_inventory' => 'federation/peer-inventory.php',
    'download_file' => 'api/federation/download-file.php',
    'approved_download_endpoint' => 'api/federation/download-approved-file.php',
    'dependency_downloads' => 'lib/FederationDependencyDownloads.php',
    'approved_downloads' => 'federation/approved-downloads.php',
    'availability_api' => 'api/federation/package-availability.php',
    'availability_helper' => 'lib/FederationPackageAvailability.php',
    'base_policy' => 'lib/FederationBaseGamePolicy.php',
    'request_lifecycle' => 'lib/FederationRequestLifecycle.php',
    'request_status_api' => 'api/federation/request-status.php',
    'request_status_page' => 'federation/request-status.php',
    'request_generate' => 'federation/request-generate.php',
    'request_submit' => 'api/federation/request-submit.php',
    'requests' => 'federation/requests.php',
    'worker_core' => 'lib/FederationWorker.php',
    'worker' => 'federation/worker-run.php',
    'cron' => 'federation/cron-worker-streaming.php',
    'settings' => 'federation/settings.php',
    'navigation' => 'lib/CatalogNavigation.php',
    'secret_helper' => 'lib/FederationPeerSecret.php',
    'http_client' => 'lib/TrustedHttpSourceClient.php',
];

$content = [];
foreach ($paths as $name => $relativePath) {
    $value = file_get_contents($root . '/' . $relativePath);
    federation_role_expect(is_string($value), 'Required federation workflow file is missing: ' . $relativePath);
    $content[$name] = $value;
}

federation_role_expect(str_contains($content['join'], "header('Location: join-main-parent.php')"), 'Administrator join page does not use the automatic join workflow.');
federation_role_expect(str_contains($content['join_main'], "const JMP_OFFICIAL_PARENT_URL = 'https://unrealdb.com';"), 'Official parent option is missing.');
federation_role_expect(str_contains($content['join_main'], 'federation_auto_claim_parent'), 'Child status polling does not complete approved pairing automatically.');
federation_role_expect(!str_contains($content['join_main'], 'Open Claim Parent'), 'Join page still exposes the obsolete manual claim action.');
federation_role_expect(str_contains($content['legacy_claim'], "header('Location: join-main-parent.php')"), 'Legacy claim page does not redirect to automatic pairing.');
federation_role_expect(str_contains($content['join_review'], 'claim_token_hash=request_token_hash'), 'Parent approval does not authorize automatic pairing.');
federation_role_expect(str_contains($content['join_status'], "'claim_ready'"), 'Join status endpoint does not signal automatic pairing readiness.');
federation_role_expect(str_contains($content['join_claim'], "in_array(\$status, ['approved', 'claimed'], true)"), 'Automatic pairing endpoint is not retryable.');

federation_role_expect(str_contains($content['pairing'], "'parent_is_master' => true"), 'Stored peer permissions do not identify the parent as master.');
federation_role_expect(str_contains($content['pairing'], "'child_download_scope' => 'missing_dependencies_only'"), 'Automatic pairing does not enforce dependency-only child downloads.');
federation_role_expect(str_contains($content['inventory_api'], '$localRole === \'parent\' && $peerRole === \'child\''), 'Parent-to-child inventory authorization is missing.');
federation_role_expect(str_contains($content['inventory_api'], '$localRole === \'child\' && $peerRole === \'parent\''), 'Child-to-parent inventory authorization is missing.');
federation_role_expect(str_contains($content['inventory_api'], "'is_base_game'"), 'Inventory API does not classify base-game rows.');
federation_role_expect(str_contains($content['inventory_api'], "'policy'"), 'Parent inventory responses do not advertise the parent policy.');
federation_role_expect(str_contains($content['inventory'], 'federation_pull_inventory_from_peer'), 'Bidirectional inventory synchronization is missing.');
federation_role_expect(str_contains($content['inventory'], 'is_base_game=VALUES(is_base_game)'), 'Peer inventory does not persist base-game classification.');
federation_role_expect(str_contains($content['inventory'], 'federation_cache_parent_base_game_policy'), 'Child inventory synchronization does not cache the signed parent policy.');

federation_role_expect(str_contains($content['peer_inventory'], 'Parent Dependency Needs'), 'Parent dependency view is missing.');
federation_role_expect(str_contains($content['peer_inventory'], 'Parent Needs'), 'Parent ordinary-needs view is missing.');
federation_role_expect(str_contains($content['peer_inventory'], 'Child Dependency Needs'), 'Child dependency view is missing.');
federation_role_expect(str_contains($content['peer_inventory'], 'Refresh both inventories now'), 'Manual inventory refresh is not bidirectional.');
federation_role_expect(str_contains($content['peer_inventory'], 'COALESCE(pf.is_base_game,0)=0'), 'Parent ordinary inventory views do not apply the base-game policy.');
federation_role_expect(str_contains($content['peer_inventory'], 'Base-game dependency matches are included'), 'Parent dependency view does not document the base-game exception.');

federation_role_expect(str_contains($content['download_file'], "(string)\$peer['peer_role'] !== 'parent'"), 'Direct child file endpoint is not restricted to the paired parent.');
federation_role_expect(str_contains($content['download_file'], 'ignore_base_game_files'), 'Child download endpoint does not enforce the signed parent base-game policy.');
federation_role_expect(str_contains($content['download_file'], 'dependency_exception'), 'Child download endpoint does not support the missing-dependency exception.');
federation_role_expect(str_contains($content['worker_core'], 'federation_worker_parent_pull_dependency_exception'), 'Parent worker does not validate dependency exceptions before signing a pull.');
federation_role_expect(str_contains($content['worker_core'], "'ignore_base_game_files' => federation_ignore_base_game_files"), 'Parent worker does not send the effective policy.');

federation_role_expect(str_contains($content['availability_api'], "(string)\$peer['peer_role'] !== 'child'"), 'Parent package availability is not restricted to a paired child.');
federation_role_expect(str_contains($content['availability_helper'], "'dependency_exception' => true"), 'Shared availability does not expose base-game dependency exceptions.');
federation_role_expect(str_contains($content['availability_helper'], "'transferable' => true"), 'Available missing dependencies are not transferable.');
federation_role_expect(str_contains($content['request_generate'], 'base-game dependency'), 'Child request generator does not include missing base-game dependencies.');
federation_role_expect(!str_contains($content['request_generate'], 'Show official base-game packages'), 'Request generator still contains a page-specific base-game visibility switch.');
federation_role_expect(str_contains($content['request_submit'], 'base-game dependency item(s)'), 'Parent request submission does not retain base-game dependency context.');
federation_role_expect(!str_contains($content['request_submit'], '$status = \'denied\';'), 'Parent still automatically denies base-game dependency requests.');

federation_role_expect(str_contains($content['request_lifecycle'], 'federation_request_legacy_base_game_denial'), 'Legacy automatic base-game denials are not repaired.');
federation_role_expect(str_contains($content['request_lifecycle'], 'missing-dependency exception'), 'Request lifecycle does not preserve base-game dependency exceptions.');
federation_role_expect(str_contains($content['request_status_api'], 'federation_parent_base_game_policy'), 'Child status polling does not receive the parent policy.');
federation_role_expect(str_contains($content['request_status_page'], 'base-game dependency'), 'Outgoing request page does not display base-game dependency exceptions.');
federation_role_expect(!str_contains($content['request_status_page'], 'Show official base-game packages'), 'Outgoing request page still has a conflicting local base-game filter.');
federation_role_expect(str_contains($content['requests'], 'Approve all dependency requests'), 'Parent cannot approve every missing dependency request.');
federation_role_expect(str_contains($content['requests'], 'base-game dependency'), 'Parent request review does not show base-game dependency exceptions.');

federation_role_expect(str_contains($content['dependency_downloads'], 'federation_dependency_request_still_needed'), 'Child download queue does not re-check the local missing dependency.');
federation_role_expect(str_contains($content['dependency_downloads'], "(string)(\$item['status'] ?? '') !== 'approved'"), 'Child download queue does not require parent item approval.');
federation_role_expect(str_contains($content['dependency_downloads'], 'base_game_dependency_seen'), 'Child download queue does not report base-game dependency exceptions.');
federation_role_expect(str_contains($content['approved_download_endpoint'], 'approved base-game dependency'), 'Approved dependency endpoint does not allow the base-game exception.');
federation_role_expect(!str_contains($content['approved_downloads'], 'Show official base-game packages'), 'Approved downloads still has a conflicting local base-game filter.');
federation_role_expect(!str_contains($content['approved_downloads'], 'request_item_ids[]'), 'Child approved-download page still allows arbitrary manual item selection.');
federation_role_expect(str_contains($content['worker'], 'federation_queue_approved_dependency_downloads'), 'Interactive federation worker does not auto-queue approved dependency downloads.');
federation_role_expect(str_contains($content['cron'], 'federation_queue_approved_dependency_downloads'), 'Scheduled federation worker does not auto-queue approved dependency downloads.');

federation_role_expect(str_contains($content['base_policy'], "ignore_base_game_files', '1"), 'Base-game federation policy does not default to enabled.');
federation_role_expect(str_contains($content['base_policy'], 'federation_cache_parent_base_game_policy'), 'Parent policy cannot be cached by a child.');
federation_role_expect(str_contains($content['settings'], 'Ignore base-game files'), 'Parent setting is missing.');
federation_role_expect(str_contains($content['settings'], 'The child cannot override this setting.'), 'Child setting page does not identify parent control.');
federation_role_expect(str_contains($content['settings'], 'missing dependency'), 'Setting does not explain the dependency exception.');
federation_role_expect(!str_contains($content['settings'], 'name="allow_parent_pull_from_child"'), 'Settings still expose parent pull as an optional child permission.');

foreach (['claim-parent.php', 'inventory-push.php', 'upload-to-parent.php'] as $obsolete) {
    federation_role_expect(!str_contains($content['navigation'], $obsolete), 'Obsolete child-driven federation action remains in navigation: ' . $obsolete);
}

federation_role_expect(str_contains($content['secret_helper'], 'fed_peer_secret($db, $peer)'), 'Peer signing secret helper does not validate or migrate encrypted secrets.');
federation_role_expect(str_contains($content['inventory'], 'federation_peer_stored_signing_secret'), 'Inventory synchronization bypasses stored encrypted secret handling.');
federation_role_expect(str_contains($content['dependency_downloads'], 'federation_peer_stored_signing_secret'), 'Dependency approval polling bypasses stored encrypted secret handling.');

$settingName = 'allow_self_signed_federation_certificates';
federation_role_expect(str_contains($content['settings'], $settingName), 'Self-signed federation certificate setting is missing.');
federation_role_expect(str_contains($content['join_main'], $settingName), 'Join requests do not honor the self-signed certificate setting.');
federation_role_expect(str_contains($content['http_client'], 'configureFromFederationSetting'), 'Federation HTTP requests do not load the TLS test setting.');
federation_role_expect(str_contains($content['http_client'], 'CURLOPT_SSL_VERIFYPEER => !self::$allowUntrustedTls'), 'Federation cURL verification cannot be relaxed in testing mode.');
federation_role_expect(str_contains($content['http_client'], 'if (!self::$allowPrivateNetwork)'), 'Federation test mode does not support private-network endpoints.');

echo "Federation parent-master workflow contract tests passed.\n";
