<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function federation_base_policy_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$files = [
    'policy' => 'lib/FederationBaseGamePolicy.php',
    'migration' => 'migrations/202607230001_federation_base_game_policy.php',
    'settings' => 'federation/settings.php',
    'inventory' => 'lib/FederationInventory.php',
    'inventory_api' => 'api/federation/inventory-list.php',
    'peer_inventory' => 'federation/peer-inventory.php',
    'availability' => 'lib/FederationPackageAvailability.php',
    'request_generate' => 'federation/request-generate.php',
    'request_submit' => 'api/federation/request-submit.php',
    'request_lifecycle' => 'lib/FederationRequestLifecycle.php',
    'requests' => 'federation/requests.php',
    'status_api' => 'api/federation/request-status.php',
    'status_page' => 'federation/request-status.php',
    'approved_page' => 'federation/approved-downloads.php',
    'approved_endpoint' => 'api/federation/download-approved-file.php',
    'ordinary_endpoint' => 'api/federation/download-file.php',
    'worker' => 'lib/FederationWorker.php',
    'dashboard' => 'federation/admin.php',
    'launcher' => 'federation-launch.php',
    'conflicts' => 'federation/conflicts.php',
];

$content = [];
foreach ($files as $key => $path) {
    $value = file_get_contents($root . '/' . $path);
    federation_base_policy_expect(is_string($value), 'Missing federation base-game policy file: ' . $path);
    $content[$key] = $value;
}

federation_base_policy_expect(str_contains($content['policy'], "fed_setting(\$db, 'ignore_base_game_files', '1')"), 'Policy does not default to ignore base-game files.');
federation_base_policy_expect(str_contains($content['policy'], 'parent_policy'), 'Child cannot cache the signed parent policy.');
federation_base_policy_expect(str_contains($content['policy'], "\$role !== 'child'"), 'Policy does not distinguish parent control from child inheritance.');

federation_base_policy_expect(str_contains($content['migration'], "'version' => '202607230001'"), 'Policy migration version is missing.');
federation_base_policy_expect(str_contains($content['migration'], 'ignore_base_game_files'), 'Policy migration does not seed the default setting.');
federation_base_policy_expect(str_contains($content['migration'], 'ADD COLUMN is_base_game'), 'Policy migration does not classify peer inventory rows.');
federation_base_policy_expect(str_contains($content['migration'], 'idx_ue_federation_peer_files_base_game'), 'Policy migration lacks the peer policy index.');

federation_base_policy_expect(str_contains($content['settings'], 'Ignore base-game files'), 'Parent setting control is missing.');
federation_base_policy_expect(str_contains($content['settings'], "if (\$siteRole === 'parent')"), 'Child can submit the parent-only setting.');
federation_base_policy_expect(str_contains($content['settings'], 'The child cannot override this setting.'), 'Child settings do not identify parent authority.');
federation_base_policy_expect(str_contains($content['settings'], 'Missing dependencies are the exception'), 'Setting does not explain the dependency exception.');

federation_base_policy_expect(str_contains($content['inventory_api'], "'is_base_game'"), 'Inventory API does not return base-game classification.');
federation_base_policy_expect(str_contains($content['inventory_api'], "'policy'"), 'Parent inventory API does not advertise policy.');
federation_base_policy_expect(str_contains($content['inventory'], 'is_base_game=VALUES(is_base_game)'), 'Inventory cache does not persist classification.');
federation_base_policy_expect(str_contains($content['inventory'], 'federation_cache_parent_base_game_policy'), 'Child inventory pull does not cache parent policy.');

federation_base_policy_expect(str_contains($content['peer_inventory'], 'COALESCE(pf.is_base_game,0)=0'), 'Ordinary peer inventory does not exclude base-game rows.');
federation_base_policy_expect(str_contains($content['peer_inventory'], 'Base-game dependency matches are included'), 'Peer dependency view does not include base-game exceptions.');
federation_base_policy_expect(str_contains($content['peer_inventory'], 'pi_parent_dependency_sql'), 'Peer inventory queue does not re-check dependency eligibility.');

federation_base_policy_expect(str_contains($content['availability'], "'dependency_exception' => true"), 'Base-game dependency availability is not transferable.');
federation_base_policy_expect(str_contains($content['request_generate'], 'Base-game packages are included'), 'Request generator does not include missing base-game dependencies.');
federation_base_policy_expect(!str_contains($content['request_generate'], 'Show official base-game packages'), 'Request generator retains an inconsistent local visibility switch.');
federation_base_policy_expect(!str_contains($content['request_submit'], "$status = 'denied';"), 'Request submission still automatically denies base-game dependencies.');
federation_base_policy_expect(str_contains($content['request_lifecycle'], 'federation_request_legacy_base_game_denial'), 'Legacy automatic base-game denials are not repaired.');
federation_base_policy_expect(str_contains($content['requests'], 'Approve all dependency requests'), 'Parent cannot approve base-game dependency exceptions.');
federation_base_policy_expect(str_contains($content['status_api'], "'dependency_exception' => \$isBaseGame"), 'Status API does not expose dependency exception classification.');
federation_base_policy_expect(!str_contains($content['status_page'], 'Show official base-game packages'), 'Outgoing status retains an inconsistent local filter.');
federation_base_policy_expect(!str_contains($content['approved_page'], 'Show official base-game packages'), 'Approved downloads retains an inconsistent local filter.');
federation_base_policy_expect(str_contains($content['approved_endpoint'], 'base-game dependency'), 'Approved dependency endpoint does not allow base-game exceptions.');

federation_base_policy_expect(str_contains($content['worker'], "'ignore_base_game_files' => federation_ignore_base_game_files"), 'Parent worker does not sign its effective policy.');
federation_base_policy_expect(str_contains($content['worker'], "'dependency_exception' => federation_worker_parent_pull_dependency_exception"), 'Parent worker does not sign dependency exceptions.');
federation_base_policy_expect(str_contains($content['ordinary_endpoint'], '$ignoreBaseGame && !$dependencyException'), 'Child ordinary download endpoint does not enforce parent policy.');

foreach (['dashboard', 'launcher', 'conflicts'] as $report) {
    federation_base_policy_expect(str_contains($content[$report], 'federation_ignore_base_game_files'), ucfirst($report) . ' does not apply the effective base-game policy.');
}

echo "Federation base-game policy contract tests passed.\n";
