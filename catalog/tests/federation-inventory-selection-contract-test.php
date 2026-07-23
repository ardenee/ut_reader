<?php
declare(strict_types=1);

function federation_inventory_selection_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$peerInventory = file_get_contents($root . '/federation/peer-inventory.php');
$requestGenerate = file_get_contents($root . '/federation/request-generate.php');
$requestSubmit = file_get_contents($root . '/api/federation/request-submit.php');
$availabilityApi = file_get_contents($root . '/api/federation/package-availability.php');
$availabilityHelper = file_get_contents($root . '/lib/FederationPackageAvailability.php');
$requests = file_get_contents($root . '/federation/requests.php');

foreach ([$peerInventory, $requestGenerate, $requestSubmit, $availabilityApi, $availabilityHelper, $requests] as $content) {
    federation_inventory_selection_expect(is_string($content), 'A required federation inventory/request file is missing.');
}

federation_inventory_selection_expect(str_contains($peerInventory, 'const PI_PAGE_SIZE = 100;'), 'Peer inventory page size is not 100.');
federation_inventory_selection_expect(str_contains($peerInventory, 'Full child inventory'), 'Peer inventory does not display the full child inventory.');
federation_inventory_selection_expect(str_contains($peerInventory, "'present' => 'Files parent already has'"), 'Peer inventory lacks the already-present filter.');
federation_inventory_selection_expect(str_contains($peerInventory, 'Total child inventory files'), 'Peer inventory lacks a total inventory count.');
federation_inventory_selection_expect(str_contains($peerInventory, 'data-check-all="parent-files"'), 'Peer inventory lacks a check-all control.');
federation_inventory_selection_expect(str_contains($peerInventory, 'Needed by files'), 'Peer inventory lacks the game-specific dependency count.');
federation_inventory_selection_expect(str_contains($peerInventory, 'COUNT(DISTINCT needer.id)'), 'Peer inventory does not count distinct requiring files.');
federation_inventory_selection_expect(str_contains($peerInventory, 'needer_game.name='), 'Peer inventory does not prefer exact remote game names when matching needs.');
federation_inventory_selection_expect(str_contains($peerInventory, 'Protected official base-game files'), 'Peer inventory does not report protected base-game inventory rows.');
federation_inventory_selection_expect(str_contains($peerInventory, 'pi_base_game_sql'), 'Peer inventory queue does not enforce base-game protection.');
federation_inventory_selection_expect(str_contains($peerInventory, 'GUID / MD5 / SHA1'), 'Peer inventory identity fields are not combined.');
federation_inventory_selection_expect(!str_contains($peerInventory, '<th>Last seen</th>'), 'Peer inventory still displays the Last seen column.');
federation_inventory_selection_expect(str_contains($peerInventory, 'Files this child requested from the parent'), 'Peer inventory lacks the child request tab data.');

federation_inventory_selection_expect(str_contains($requestGenerate, 'const REQGEN_PAGE_SIZE = 950;'), 'Request generator page size is not 950.');
federation_inventory_selection_expect(str_contains($requestGenerate, 'name="item_keys[]"'), 'Request generator does not submit selected package keys.');
federation_inventory_selection_expect(str_contains($requestGenerate, 'data-check-all="request-packages" checked'), 'Request generator does not select requestable packages by default.');
federation_inventory_selection_expect(str_contains($requestGenerate, 'COUNT(DISTINCT d.file_id) use_count'), 'Request generator does not count distinct requiring files.');
federation_inventory_selection_expect(str_contains($requestGenerate, 'federation_peer_stored_signing_secret'), 'Request generator bypasses encrypted peer-secret handling.');
federation_inventory_selection_expect(str_contains($requestGenerate, "require_once __DIR__ . '/../lib/BaseGameProtection.php';"), 'Request generator does not load base-game protection.');
federation_inventory_selection_expect(str_contains($requestGenerate, 'reqgen_base_game_join'), 'Request generator does not match missing packages to the local base-game list.');
federation_inventory_selection_expect(str_contains($requestGenerate, 'bg.original_name'), 'Request generator does not use base-game original filenames as a package fallback.');
federation_inventory_selection_expect(str_contains($requestGenerate, 'bg_source.package_name'), 'Request generator does not use the protected source file as a package fallback.');
federation_inventory_selection_expect(str_contains($requestGenerate, 'reqgen_parent_availability'), 'Request generator does not preflight package protection with the selected parent.');
federation_inventory_selection_expect(str_contains($requestGenerate, '/api/federation/package-availability.php'), 'Request generator does not call the parent availability endpoint.');
federation_inventory_selection_expect(str_contains($requestGenerate, 'Protected by parent; cannot transfer'), 'Parent-protected base-game rows are not blocked in the request UI.');
federation_inventory_selection_expect(str_contains($requestGenerate, 'Show official base-game packages for reference'), 'Request generator lacks the base-game visibility control.');
federation_inventory_selection_expect(str_contains($requestGenerate, 'name="show_base_game"'), 'Request generator does not preserve the base-game filter through submissions.');

federation_inventory_selection_expect(str_contains($availabilityApi, "(string)\$peer['peer_role'] !== 'child'"), 'Availability endpoint is not restricted to a paired child.');
federation_inventory_selection_expect(str_contains($availabilityApi, 'count($items) > 950'), 'Availability endpoint does not cap package checks.');
federation_inventory_selection_expect(str_contains($availabilityApi, 'federation_package_availability'), 'Availability endpoint bypasses the shared package matcher.');
federation_inventory_selection_expect(str_contains($availabilityHelper, 'function federation_package_match'), 'Shared federation package matcher is missing.');
$gamePosition = strpos($availabilityHelper, "if (\$gameName !== '')");
$enginePosition = strpos($availabilityHelper, "if (\$engineKey !== '')", $gamePosition === false ? 0 : $gamePosition + 1);
federation_inventory_selection_expect($gamePosition !== false && $enginePosition !== false && $gamePosition < $enginePosition, 'Shared package matching does not prefer exact game context over engine context.');
federation_inventory_selection_expect(str_contains($availabilityHelper, 'base_game_file_is_protected'), 'Shared package availability does not enforce base-game protection.');

federation_inventory_selection_expect(str_contains($requestSubmit, 'count($items) > 950'), 'Parent request endpoint does not enforce the 950-package limit.');
federation_inventory_selection_expect(str_contains($requestSubmit, 'request_submit_package_match'), 'Parent request endpoint lacks the compatibility package-match wrapper.');
federation_inventory_selection_expect(str_contains($requestSubmit, 'federation_package_availability'), 'Parent request endpoint does not use the same availability decision as request preflight.');
$baseDecisionPosition = strpos($requestSubmit, "if (!empty(\$availability['is_base_game']))");
$missingDecisionPosition = strpos($requestSubmit, "elseif (empty(\$availability['available']))");
federation_inventory_selection_expect($baseDecisionPosition !== false && $missingDecisionPosition !== false && $baseDecisionPosition < $missingDecisionPosition, 'Parent request endpoint does not deny parent-identified base-game packages before treating them as unavailable.');

federation_inventory_selection_expect(str_contains($requests, 'Show official base-game packages'), 'Parent request page lacks the base-game visibility control.');
federation_inventory_selection_expect(str_contains($requests, 'base_game_file_is_protected'), 'Parent request approval no longer enforces base-game protection.');

echo "Federation inventory selection contract tests passed.\n";
