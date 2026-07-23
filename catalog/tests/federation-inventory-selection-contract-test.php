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
];

$content = [];
foreach ($paths as $name => $relativePath) {
    $value = file_get_contents($root . '/' . $relativePath);
    federation_inventory_selection_expect(is_string($value), 'Required federation file is missing: ' . $relativePath);
    $content[$name] = $value;
}

$peerInventory = $content['peer_inventory'];
federation_inventory_selection_expect(str_contains($peerInventory, 'const PI_PAGE_SIZE = 100;'), 'Peer inventory page size is not 100.');
federation_inventory_selection_expect(str_contains($peerInventory, 'Files this parent needs from'), 'Peer inventory does not directly list files needed from the selected child.');
federation_inventory_selection_expect(str_contains($peerInventory, 'Parent needs from child ('), 'Peer inventory lacks the direct needed-files filter.');
federation_inventory_selection_expect(str_contains($peerInventory, "\$_GET['inventory_tab']"), 'Peer inventory does not accept legacy inventory_tab links.');
federation_inventory_selection_expect(str_contains($peerInventory, "'inventory', 'parent', '' => 'inventory'"), 'Peer inventory does not map the legacy parent tab to inventory.');
federation_inventory_selection_expect(str_contains($peerInventory, 'data-check-all="parent-files"'), 'Peer inventory lacks a check-all control.');
federation_inventory_selection_expect(str_contains($peerInventory, 'Needed by parent files'), 'Peer inventory lacks the requiring-file count.');
federation_inventory_selection_expect(str_contains($peerInventory, 'COUNT(DISTINCT needer.id)'), 'Peer inventory does not count distinct requiring files.');
federation_inventory_selection_expect(str_contains($peerInventory, 'LOWER(d.required_package)=LOWER('), 'Peer inventory package matching is not case-insensitive.');
federation_inventory_selection_expect(str_contains($peerInventory, 'needer_game.name='), 'Peer inventory does not prefer exact remote game names.');
federation_inventory_selection_expect(str_contains($peerInventory, 'pi_base_game_sql'), 'Peer inventory does not enforce base-game protection.');
federation_inventory_selection_expect(str_contains($peerInventory, 'GUID / MD5 / SHA1'), 'Peer inventory identity fields are not combined.');
federation_inventory_selection_expect(!str_contains($peerInventory, '<th>Last seen</th>'), 'Peer inventory still displays Last seen.');

$requestGenerate = $content['request_generate'];
federation_inventory_selection_expect(str_contains($requestGenerate, 'reqgen_parent_availability'), 'Request generator does not preflight against the parent.');
federation_inventory_selection_expect(str_contains($requestGenerate, 'Protected by parent; cannot transfer'), 'Parent-protected rows are not blocked.');
federation_inventory_selection_expect(str_contains($requestGenerate, 'Show official base-game packages for reference'), 'Request generator lacks the base-game reference filter.');

$requestSubmit = $content['request_submit'];
federation_inventory_selection_expect(str_contains($requestSubmit, 'The parent may approve the request now; it will remain active'), 'Unavailable request submission still claims the item cannot be approved.');
federation_inventory_selection_expect(str_contains($requestSubmit, 'federation_package_availability'), 'Request submission bypasses shared availability checks.');

$lifecycle = $content['request_lifecycle'];
federation_inventory_selection_expect(str_contains($lifecycle, 'function federation_refresh_request_matches'), 'Request lifecycle refresh is missing.');
federation_inventory_selection_expect(str_contains($lifecycle, 'federation_request_legacy_unavailable_denial'), 'Legacy automatic denials are not repaired.');
federation_inventory_selection_expect(str_contains($lifecycle, 'The request remains active'), 'Approved unavailable requests are not kept active.');

$statusApi = $content['request_status_api'];
federation_inventory_selection_expect(str_contains($statusApi, 'federation_refresh_request_matches'), 'Status polling does not relink newly available parent files.');
federation_inventory_selection_expect(str_contains($statusApi, "'is_base_game' => \$isBaseGame"), 'Status API does not expose reliable base-game classification.');

$statusPage = $content['request_status_page'];
federation_inventory_selection_expect(str_contains($statusPage, 'Show official base-game packages'), 'Outgoing request status lacks the base-game filter.');
federation_inventory_selection_expect(str_contains($statusPage, "empty(\$group['is_base_game'])"), 'Outgoing status does not hide base-game rows by default.');
federation_inventory_selection_expect(str_contains($statusPage, 'approved — waiting for file'), 'Outgoing status does not explain open approved requests.');

$requests = $content['requests'];
federation_inventory_selection_expect(str_contains($requests, 'Approve all non-base-game requests'), 'Parent request page cannot approve unavailable requests.');
federation_inventory_selection_expect(str_contains($requests, 'federation_request_waiting_message'), 'Parent request approval does not keep unavailable rows active.');
federation_inventory_selection_expect(str_contains($requests, 'base_game_file_is_protected'), 'Parent request approval no longer enforces base-game protection.');

federation_inventory_selection_expect(str_contains($content['availability_api'], 'federation_package_availability'), 'Availability endpoint bypasses the shared matcher.');
federation_inventory_selection_expect(str_contains($content['availability_helper'], 'function federation_package_match'), 'Shared federation package matcher is missing.');

echo "Federation inventory and request lifecycle contract tests passed.\n";
