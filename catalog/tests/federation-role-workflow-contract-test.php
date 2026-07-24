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
federation_role_expect(str_contains($content['inventory_api'], 'bg.id IS NULL'), 'Inventory API does not enforce the effective base-game policy.');
federation_role_expect(str_contains($content['inventory_api'], "'policy'"), 'Parent inventory responses do not advertise the parent policy.');
federation_role_expect(str_contains($content['inventory'], 'federation_pull_inventory_from_peer'), 'Bidirectional inventory synchronization is missing.');
federation_role_expect(str_contains($content['inventory'], 'is_base_game=VALUES(is_base_game)'), 'Peer inventory does not persist base-game classification.');
federation_role_expect(str_contains($content['inventory'], 'federation_cache_parent_base_game_policy'), 'Child inventory synchronization does not cache the signed parent policy.');

federation_role_expect(str_contains($content['peer_inventory'], 'Parent Dependency Needs'), 'Parent dependency view is missing.');
federation_role_expect(str_contains($content['peer_inventory'], 'Parent Needs'), 'Parent ordinary-needs view is missing.');
federation_role_expect(str_contains($content['peer_inventory'], 'Child Dependency Needs'), 'Child dependency view is missing.');
federation_role_expect(str_contains($content['peer_inventory'], 'Refresh both inventories now'), 'Manual inventory refresh is not bidirectional.');
federation_role_expect(substr_count($content['peer_inventory'], 'COALESCE(pf.is_base_game,0)=0') >= 2, 'Parent inventory views and queueing do not consistently apply the base-game policy.');
federation_role_expect(str_contains($content['peer_inventory'], 'Policy-excluded base-game files are removed.'), 'Dependency inventory does not document global policy filtering.');

federation_role_expect(str_contains($content['download_file'], "(string)\$peer['peer_role'] !== 'parent'"), 'Direct child file endpoint is not restricted to the paired parent.');
federation_role_expect(str_contains($content['download_file'], 'ignore_base_game_files'), 'Child download endpoint does not enforce the signed parent base-game policy.');
federation_role_expect(!str_contains($content['download_file'], '$dependencyException'), 'Child download endpoint still supports a dependency exception override.');
federation_role_expect(str_contains($content['worker_core'], "'ignore_base_game_files' => federation_ignore_base_game_files"), 'Parent worker does not send the effective policy.');

federation_role_expect(str_contains($content['availability_api'], "(string)\$peer['peer_role'] !== 'child'"), 'Parent package availability is not restricted to a paired child.');
federation_role_expect(
    str_contains($content['availability_helper'], "'policy_excluded' => \$policyExcluded")
        && substr_count($content['availability_helper'], 'federation_package_unavailable_result(true, true,') >= 2,
    'Shared availability does not exclude ignored base-game packages.'
);
federation_role_expect(!str_contains($content['availability_helper'], "'dependency_exception' => true"), 'Shared availability still exposes a base-game dependency exception.');
federation_role_expect(str_contains($content['availability_helper'], "'transferable' => true"), 'Policy-eligible available dependencies are not transferable.');
federation_role_expect(str_contains($content['request_generate'], 'reqgen_apply_base_game_policy'), 'Child request generator does not apply the inherited policy.');
federation_role_expect(str_contains($content['request_generate'], 'reqgen_policy_having'), 'Child request generator totals are not policy-filtered.');
federation_role_expect(str_contains($content['request_submit'], 'Every selected package is excluded by the parent Ignore base-game files policy.'), 'Parent request submission does not reject an all-base-game request.');
federation_role_expect(str_contains($content['request_submit'], "'policy_excluded'"), 'Parent request submission does not defensively skip excluded rows.');

federation_role_expect(str_contains($content['request_lifecycle'], 'function federation_refresh_request_matches'), 'Request lifecycle refresh is missing.');
federation_role_expect(str_contains($content['request_status_api'], 'federation_parent_base_game_policy'), 'Child status polling does not receive the parent policy.');
federation_role_expect(str_contains($content['request_status_api'], 'if ($ignoreBaseGame && $isBaseGame)'), 'Child status polling does not remove historical base-game request rows.');
federation_role_expect(str_contains($content['request_status_page'], 'crs_group_items(array $items, bool $ignoreBaseGame)'), 'Outgoing request page does not apply inherited base-game filtering.');
federation_role_expect(str_contains($content['requests'], 'Approve all visible dependency requests'), 'Parent cannot approve all policy-visible dependency requests.');
federation_role_expect(str_contains($content['requests'], '$ignoreBaseGame && !empty($group[\'is_base_game\'])'), 'Parent request review does not remove historical base-game groups.');

federation_role_expect(str_contains($content['dependency_downloads'], 'federation_dependency_request_still_needed'), 'Child download queue does not re-check the local missing dependency.');
federation_role_expect(str_contains($content['dependency_downloads'], "(string)(\$item['status'] ?? '') !== 'approved'"), 'Child download queue does not require parent item approval.');
federation_role_expect(str_contains($content['dependency_downloads'], 'base_game_excluded'), 'Child download queue does not report excluded base-game items.');
federation_role_expect(str_contains($content['approved_download_endpoint'], '$isBaseGame && federation_ignore_base_game_files($db)'), 'Approved dependency endpoint does not enforce the global base-game policy.');
federation_role_expect(str_contains($content['approved_downloads'], 'federation_visible_transfer_job_sql'), 'Approved download report does not filter historical jobs.');
federation_role_expect(!str_contains($content['approved_downloads'], 'request_item_ids[]'), 'Child approved-download page still allows arbitrary manual item selection.');
federation_role_expect(str_contains($content['worker'], 'federation_queue_approved_dependency_downloads'), 'Interactive federation worker does not auto-queue approved dependency downloads.');
federation_role_expect(str_contains($content['cron'], 'federation_queue_approved_dependency_downloads'), 'Scheduled federation worker does not auto-queue approved dependency downloads.');

federation_role_expect(str_contains($content['base_policy'], "ignore_base_game_files', '1"), 'Base-game federation policy does not default to enabled.');
federation_role_expect(str_contains($content['base_policy'], "'missing_dependency_exception' => false"), 'Base-game federation policy still enables dependency exceptions.');
federation_role_expect(str_contains($content['base_policy'], 'federation_cache_parent_base_game_policy'), 'Parent policy cannot be cached by a child.');
federation_role_expect(str_contains($content['settings'], 'Ignore base-game files'), 'Parent setting is missing.');
federation_role_expect(str_contains($content['settings'], 'The child cannot override this setting.'), 'Child setting page does not identify parent control.');
federation_role_expect(str_contains($content['settings'], 'There is no missing-dependency exception.'), 'Setting does not explain global exclusion.');
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
