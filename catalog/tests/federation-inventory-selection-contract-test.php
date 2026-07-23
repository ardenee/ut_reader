<?php
declare(strict_types=1);

function federation_inventory_selection_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$paths = [
    'peer_inventory' => 'federation/peer-inventory.php',
    'request_generate' => 'federation/request-generate.php',
    'request_submit' => 'api/federation/request-submit.php',
    'request_status_api' => 'api/federation/request-status.php',
    'request_status_page' => 'federation/request-status.php',
    'availability_api' => 'api/federation/package-availability.php',
    'availability_helper' => 'lib/FederationPackageAvailability.php',
    'request_lifecycle' => 'lib/FederationRequestLifecycle.php',
    'requests' => 'federation/requests.php',
    'approved_downloads' => 'federation/approved-downloads.php',
    'base_policy' => 'lib/FederationBaseGamePolicy.php',
];

$content = [];
foreach ($paths as $name => $relativePath) {
    $value = file_get_contents($root . '/' . $relativePath);
    federation_inventory_selection_expect(is_string($value), 'Required federation file is missing: ' . $relativePath);
    $content[$name] = $value;
}

$peerInventory = $content['peer_inventory'];
federation_inventory_selection_expect(str_contains($peerInventory, 'const PI_PAGE_SIZE = 100;'), 'Peer inventory page size is not 100.');
federation_inventory_selection_expect(str_contains($peerInventory, 'Parent Dependency Needs'), 'Peer inventory lacks the dependency-only view.');
federation_inventory_selection_expect(str_contains($peerInventory, 'Parent Needs'), 'Peer inventory lacks the ordinary missing-files view.');
federation_inventory_selection_expect(str_contains($peerInventory, 'Child Dependency Needs'), 'Peer inventory lacks the child dependency view.');
federation_inventory_selection_expect(str_contains($peerInventory, "\$_GET['inventory_tab']"), 'Peer inventory does not accept legacy inventory_tab links.');
federation_inventory_selection_expect(str_contains($peerInventory, 'data-check-all="parent-files"'), 'Peer inventory lacks a check-all control.');
federation_inventory_selection_expect(str_contains($peerInventory, 'Needed by parent files'), 'Peer inventory lacks the requiring-file count.');
federation_inventory_selection_expect(str_contains($peerInventory, 'COUNT(DISTINCT needer.id)'), 'Peer inventory does not count distinct requiring files.');
federation_inventory_selection_expect(str_contains($peerInventory, 'LOWER(d.required_package)=LOWER('), 'Peer inventory package matching is not case-insensitive.');
federation_inventory_selection_expect(str_contains($peerInventory, 'needer_game.name='), 'Peer inventory does not prefer exact remote game names.');
federation_inventory_selection_expect(str_contains($peerInventory, 'COALESCE(pf.is_base_game,0)=0'), 'Ordinary inventory views do not apply the base-game ignore policy.');
federation_inventory_selection_expect(str_contains($peerInventory, 'Base-game dependency matches are included'), 'Dependency inventory does not retain base-game matches.');
federation_inventory_selection_expect(str_contains($peerInventory, 'GUID / MD5 / SHA1'), 'Peer inventory identity fields are not combined.');
federation_inventory_selection_expect(!str_contains($peerInventory, '<th>Last seen</th>'), 'Peer inventory still displays Last seen.');
federation_inventory_selection_expect(str_contains($peerInventory, 'onchange="this.form.submit()"'), 'Child selection is not automatic.');
federation_inventory_selection_expect(!str_contains($peerInventory, '>Open child<'), 'The obsolete Open child button remains.');

$requestGenerate = $content['request_generate'];
federation_inventory_selection_expect(str_contains($requestGenerate, 'reqgen_parent_availability'), 'Request generator does not preflight against the parent.');
federation_inventory_selection_expect(str_contains($requestGenerate, 'base-game dependency'), 'Request generator does not include missing base-game dependencies.');
federation_inventory_selection_expect(str_contains($requestGenerate, 'through dependency exception'), 'Parent availability does not explain the base-game dependency exception.');
federation_inventory_selection_expect(!str_contains($requestGenerate, 'Show official base-game packages'), 'Request generator still has a page-specific base-game filter.');

$requestSubmit = $content['request_submit'];
federation_inventory_selection_expect(str_contains($requestSubmit, 'The parent may approve the request now; it will remain active'), 'Unavailable request submission still claims the item cannot be approved.');
federation_inventory_selection_expect(str_contains($requestSubmit, 'federation_package_availability'), 'Request submission bypasses shared availability checks.');
federation_inventory_selection_expect(str_contains($requestSubmit, 'is_base_game_dependency'), 'Request submission discards base-game dependency context.');
federation_inventory_selection_expect(!str_contains($requestSubmit, "$status = 'denied';"), 'Request submission still automatically denies a base-game dependency.');

$lifecycle = $content['request_lifecycle'];
federation_inventory_selection_expect(str_contains($lifecycle, 'function federation_refresh_request_matches'), 'Request lifecycle refresh is missing.');
federation_inventory_selection_expect(str_contains($lifecycle, 'federation_request_legacy_unavailable_denial'), 'Legacy unavailable denials are not repaired.');
federation_inventory_selection_expect(str_contains($lifecycle, 'federation_request_legacy_base_game_denial'), 'Legacy automatic base-game denials are not repaired.');
federation_inventory_selection_expect(str_contains($lifecycle, 'The request remains active'), 'Approved unavailable requests are not kept active.');

$statusApi = $content['request_status_api'];
federation_inventory_selection_expect(str_contains($statusApi, 'federation_refresh_request_matches'), 'Status polling does not relink newly available parent files.');
federation_inventory_selection_expect(str_contains($statusApi, "'is_base_game' => \$isBaseGame"), 'Status API does not expose reliable base-game classification.');
federation_inventory_selection_expect(str_contains($statusApi, 'federation_parent_base_game_policy'), 'Status API does not return the parent policy.');

$statusPage = $content['request_status_page'];
federation_inventory_selection_expect(!str_contains($statusPage, 'Show official base-game packages'), 'Outgoing request status still has a local base-game filter.');
federation_inventory_selection_expect(str_contains($statusPage, 'base-game dependency'), 'Outgoing status does not identify base-game dependency exceptions.');
federation_inventory_selection_expect(str_contains($statusPage, 'approved — waiting for file'), 'Outgoing status does not explain open approved requests.');

$requests = $content['requests'];
federation_inventory_selection_expect(str_contains($requests, 'Approve all dependency requests'), 'Parent request page cannot approve all missing dependency requests.');
federation_inventory_selection_expect(str_contains($requests, 'federation_request_waiting_message'), 'Parent request approval does not keep unavailable rows active.');
federation_inventory_selection_expect(str_contains($requests, 'base-game dependency'), 'Parent request page does not show base-game dependency exceptions.');
federation_inventory_selection_expect(!str_contains($requests, 'Approve all non-base-game'), 'Parent request page still excludes base-game dependencies from bulk approval.');

federation_inventory_selection_expect(str_contains($content['approved_downloads'], 'Base-game dependency exceptions'), 'Approved downloads do not show base-game dependency exceptions.');
federation_inventory_selection_expect(!str_contains($content['approved_downloads'], 'Show official base-game packages'), 'Approved downloads still has a local base-game filter.');

federation_inventory_selection_expect(str_contains($content['availability_api'], 'federation_package_availability'), 'Availability endpoint bypasses the shared matcher.');
federation_inventory_selection_expect(str_contains($content['availability_helper'], 'function federation_package_match'), 'Shared federation package matcher is missing.');
federation_inventory_selection_expect(str_contains($content['availability_helper'], "'dependency_exception' => true"), 'Shared availability does not permit base-game missing dependencies.');
federation_inventory_selection_expect(str_contains($content['base_policy'], "ignore_base_game_files', '1"), 'The parent base-game policy does not default to enabled.');

echo "Federation inventory and request lifecycle contract tests passed.\n";
