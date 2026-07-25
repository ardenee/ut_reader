<?php
declare(strict_types=1);

function federation_inventory_selection_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$inventory = file_get_contents($root . '/federation/inventories.php');
$requests = file_get_contents($root . '/federation/requests.php');
$requestSubmit = file_get_contents($root . '/api/federation/request-submit.php');
$requestStatus = file_get_contents($root . '/api/federation/request-status.php');
$availability = file_get_contents($root . '/lib/FederationPackageAvailability.php');
$lifecycle = file_get_contents($root . '/lib/FederationRequestLifecycle.php');
$policy = file_get_contents($root . '/lib/FederationBaseGamePolicy.php');

foreach (compact('inventory', 'requests', 'requestSubmit', 'requestStatus', 'availability', 'lifecycle', 'policy') as $label => $value) {
    federation_inventory_selection_expect(is_string($value), 'Missing federation selection component: ' . $label);
}

federation_inventory_selection_expect(str_contains($inventory, 'fi_parent_need_count_sql'), 'Parent dependency matcher is missing.');
federation_inventory_selection_expect(str_contains($inventory, 'LOWER(d.required_package)=LOWER('), 'Parent dependency package matching is not case-insensitive.');
federation_inventory_selection_expect(str_contains($inventory, 'COALESCE(pf.is_base_game,0)=0'), 'Inventory selection does not enforce Parent base-game policy.');
federation_inventory_selection_expect(str_contains($inventory, "federation_dependency_is_base_game_sql('f', 'd')"), 'Child inventory does not use the shared base-game dependency matcher.');
federation_inventory_selection_expect(!str_contains($inventory, 'fi_missing_base_join'), 'Child inventory still uses the narrower legacy base-game join.');
federation_inventory_selection_expect(str_contains($inventory, '$ignoreBaseGame = federation_ignore_base_game_files($db, $peer);'), 'Child request submission does not reload the signed Parent policy.');
federation_inventory_selection_expect(str_contains($inventory, "'is_base_game_dependency' => !empty(\$row['is_base_game'])"), 'Child request payload discards base-game classification.');
$submitStatusPosition = strpos($inventory, '$activeStatuses = fi_child_request_statuses($db, $peer);');
$submitRowsPosition = $submitStatusPosition === false ? false : strpos($inventory, '$rows = fi_child_missing_rows($db, $peerId, $page, $ignoreBaseGame);', $submitStatusPosition);
federation_inventory_selection_expect($submitStatusPosition !== false && $submitRowsPosition !== false && $submitStatusPosition < $submitRowsPosition, 'Child request rows are built before the signed Parent policy is refreshed.');
federation_inventory_selection_expect(str_contains($inventory, 'The Parent base-game policy changed or was refreshed.'), 'Child request submission does not recover from a Parent policy race.');
federation_inventory_selection_expect(str_contains($inventory, 'queue_parent_pull'), 'Parent cannot queue selected Child files.');
federation_inventory_selection_expect(str_contains($inventory, 'submit_child_request'), 'Child cannot request selected required packages.');
federation_inventory_selection_expect(str_contains($inventory, 'fi_child_request_statuses'), 'Existing request status is not checked.');
federation_inventory_selection_expect(str_contains($inventory, "['requested', 'approved', 'queued', 'downloading', 'downloaded']"), 'Active duplicate-request statuses are incomplete.');

federation_inventory_selection_expect(str_contains($requestSubmit, 'Every selected package is excluded by the parent Ignore base-game files policy.'), 'Parent request submission does not reject an all-base-game request.');
federation_inventory_selection_expect(str_contains($availability, "'policy_excluded' => \$policyExcluded"), 'Shared availability does not expose policy exclusion.');
federation_inventory_selection_expect(str_contains($lifecycle, 'function federation_refresh_request_matches'), 'Request lifecycle refresh is missing.');
federation_inventory_selection_expect(str_contains($requestStatus, 'federation_refresh_request_matches'), 'Request status polling does not relink newly available Parent files.');
federation_inventory_selection_expect(str_contains($requestStatus, "!empty(\$payload['package_statuses'])"), 'Package-level active request status endpoint is missing.');
federation_inventory_selection_expect(str_contains($requests, 'approve_all'), 'Parent cannot approve all request items.');
federation_inventory_selection_expect(str_contains($requests, 'Approved and waiting until the parent imports a matching file.'), 'Parent request decisions do not preserve approved-waiting state.');
federation_inventory_selection_expect(str_contains($requests, 'status_message'), 'Child request view does not display Parent decision messages.');
federation_inventory_selection_expect(str_contains($policy, "ignore_base_game_files', '1"), 'Parent base-game policy does not default to enabled.');
federation_inventory_selection_expect(str_contains($policy, "'missing_dependency_exception' => false"), 'Parent base-game policy still enables dependency exceptions.');

echo "Federation inventory and request lifecycle contract tests passed.\n";
