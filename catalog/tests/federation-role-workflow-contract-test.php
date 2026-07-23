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
    'dependency_downloads' => 'lib/FederationDependencyDownloads.php',
    'approved_downloads' => 'federation/approved-downloads.php',
    'availability_api' => 'api/federation/package-availability.php',
    'availability_helper' => 'lib/FederationPackageAvailability.php',
    'request_lifecycle' => 'lib/FederationRequestLifecycle.php',
    'request_status_api' => 'api/federation/request-status.php',
    'request_status_page' => 'federation/request-status.php',
    'requests' => 'federation/requests.php',
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
federation_role_expect(str_contains($content['inventory_api'], "(string)\$peer['peer_role'] !== 'parent'"), 'Child inventory API is not restricted to the paired parent.');
federation_role_expect(str_contains($content['inventory'], 'federation_pull_inventory_from_child'), 'Parent-side direct child inventory synchronization is missing.');

federation_role_expect(str_contains($content['peer_inventory'], 'Refresh inventory from child'), 'Parent inventory page cannot initiate direct synchronization.');
federation_role_expect(str_contains($content['peer_inventory'], 'Files this parent needs from'), 'Parent inventory page does not show needed child files directly.');
federation_role_expect(str_contains($content['peer_inventory'], 'Parent needs from child ('), 'Parent inventory page lacks the needed-files shortcut.');
federation_role_expect(str_contains($content['peer_inventory'], 'Other files parent lacks ('), 'Parent inventory page lacks the other-missing-files shortcut.');
federation_role_expect(str_contains($content['peer_inventory'], 'Already on parent ('), 'Parent inventory page lacks the already-present shortcut.');
federation_role_expect(str_contains($content['peer_inventory'], 'pi_local_presence_sql'), 'Parent inventory page cannot classify files held locally.');
federation_role_expect(str_contains($content['peer_inventory'], 'pi_local_absence_sql'), 'Parent inventory page cannot classify files absent locally.');
federation_role_expect(str_contains($content['peer_inventory'], 'pi_base_game_sql'), 'Parent inventory page does not enforce base-game protection.');
federation_role_expect(str_contains($content['peer_inventory'], "\$_GET['inventory_tab']"), 'Legacy inventory links are not supported.');

federation_role_expect(str_contains($content['download_file'], "(string)\$peer['peer_role'] !== 'parent'"), 'Direct child file endpoint is not restricted to the paired parent.');
federation_role_expect(!str_contains($content['download_file'], 'allow_parent_pull_from_child'), 'Child can still disable parent/master pulls after pairing.');
federation_role_expect(str_contains($content['download_file'], 'base_game_file_is_protected'), 'Parent pulls do not retain base-game protection.');

federation_role_expect(str_contains($content['availability_api'], "(string)\$peer['peer_role'] !== 'child'"), 'Parent package availability is not restricted to a paired child.');
federation_role_expect(str_contains($content['availability_helper'], 'base_game_file_is_protected'), 'Parent availability checks do not enforce base-game protection.');
federation_role_expect(str_contains($content['availability_helper'], 'function federation_package_match'), 'Shared parent package matcher is missing.');

federation_role_expect(str_contains($content['request_lifecycle'], 'function federation_refresh_request_matches'), 'Approved request lifecycle refresh is missing.');
federation_role_expect(str_contains($content['request_lifecycle'], 'federation_request_legacy_unavailable_denial'), 'Legacy automatic request denials are not repaired.');
federation_role_expect(str_contains($content['request_status_api'], 'federation_refresh_request_matches'), 'Child status polling does not relink newly available files.');
federation_role_expect(str_contains($content['request_status_page'], 'Show official base-game packages'), 'Outgoing request page cannot hide base-game rows.');
federation_role_expect(str_contains($content['requests'], 'Approve all non-base-game requests'), 'Parent cannot approve unavailable open requests.');

federation_role_expect(str_contains($content['dependency_downloads'], 'federation_dependency_request_still_needed'), 'Child download queue does not re-check the local missing dependency.');
federation_role_expect(str_contains($content['dependency_downloads'], "(string)(\$item['status'] ?? '') !== 'approved'"), 'Child download queue does not require parent item approval.');
federation_role_expect(str_contains($content['worker'], 'federation_queue_approved_dependency_downloads'), 'Interactive federation worker does not auto-queue approved dependency downloads.');
federation_role_expect(str_contains($content['cron'], 'federation_queue_approved_dependency_downloads'), 'Scheduled federation worker does not auto-queue approved dependency downloads.');
federation_role_expect(!str_contains($content['approved_downloads'], 'request_item_ids[]'), 'Child approved-download page still allows arbitrary manual item selection.');

foreach (['claim-parent.php', 'inventory-push.php', 'upload-to-parent.php'] as $obsolete) {
    federation_role_expect(!str_contains($content['navigation'], $obsolete), 'Obsolete child-driven federation action remains in navigation: ' . $obsolete);
}
federation_role_expect(str_contains($content['settings'], 'Parent/master'), 'Settings page does not document fixed parent/master authority.');
federation_role_expect(!str_contains($content['settings'], 'name="allow_parent_pull_from_child"'), 'Settings still expose parent pull as an optional child permission.');

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
