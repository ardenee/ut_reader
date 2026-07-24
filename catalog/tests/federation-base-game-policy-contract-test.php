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
    'requests' => 'federation/requests.php',
    'status_api' => 'api/federation/request-status.php',
    'status_page' => 'federation/request-status.php',
    'approved_page' => 'federation/approved-downloads.php',
    'approved_endpoint' => 'api/federation/download-approved-file.php',
    'ordinary_endpoint' => 'api/federation/download-file.php',
    'dependency_downloads' => 'lib/FederationDependencyDownloads.php',
    'dashboard' => 'federation/admin.php',
    'launcher' => 'federation-launch.php',
    'request_center' => 'federation/request-center.php',
    'missing_files' => 'federation/missing-files.php',
    'queue' => 'federation/queue.php',
    'parent_pull' => 'federation/parent-pull.php',
    'conflicts' => 'federation/conflicts.php',
];

$content = [];
foreach ($files as $key => $path) {
    $value = file_get_contents($root . '/' . $path);
    federation_base_policy_expect(is_string($value), 'Missing federation base-game policy file: ' . $path);
    $content[$key] = $value;
}

federation_base_policy_expect(str_contains($content['policy'], "fed_setting(\$db, 'ignore_base_game_files', '1')"), 'Policy does not default to ignore base-game files.');
federation_base_policy_expect(str_contains($content['policy'], "'missing_dependency_exception' => false"), 'Policy still advertises a missing-dependency exception.');
federation_base_policy_expect(!str_contains($content['policy'], "'missing_dependency_exception' => true"), 'Policy still enables a missing-dependency exception.');
federation_base_policy_expect(str_contains($content['policy'], 'federation_visible_request_item_sql'), 'Shared request-item policy filter is missing.');
federation_base_policy_expect(str_contains($content['policy'], 'federation_visible_transfer_job_sql'), 'Shared transfer-job policy filter is missing.');
federation_base_policy_expect(str_contains($content['policy'], 'federation_dependency_is_base_game_sql'), 'Shared missing-dependency policy filter is missing.');

federation_base_policy_expect(str_contains($content['migration'], "'version' => '202607230001'"), 'Policy migration version is missing.');
federation_base_policy_expect(str_contains($content['migration'], 'ignore_base_game_files'), 'Policy migration does not seed the default setting.');
federation_base_policy_expect(str_contains($content['migration'], 'ADD COLUMN is_base_game'), 'Policy migration does not classify peer inventory rows.');
federation_base_policy_expect(str_contains($content['migration'], 'idx_ue_federation_peer_files_base_game'), 'Policy migration lacks the peer policy index.');

federation_base_policy_expect(str_contains($content['settings'], 'Ignore base-game files'), 'Parent setting control is missing.');
federation_base_policy_expect(str_contains($content['settings'], "if (\$siteRole === 'parent')"), 'Child can submit the parent-only setting.');
federation_base_policy_expect(str_contains($content['settings'], 'The child cannot override this setting.'), 'Child settings do not identify parent authority.');
federation_base_policy_expect(str_contains($content['settings'], 'There is no missing-dependency exception.'), 'Setting does not explain the global exclusion.');

federation_base_policy_expect(str_contains($content['inventory_api'], 'bg.id IS NULL'), 'Inventory API does not exclude base-game rows before transfer.');
federation_base_policy_expect(str_contains($content['inventory_api'], "'policy'"), 'Parent inventory API does not advertise policy.');
federation_base_policy_expect(str_contains($content['inventory'], 'is_base_game=VALUES(is_base_game)'), 'Inventory cache does not persist classification.');
federation_base_policy_expect(str_contains($content['inventory'], 'federation_cache_parent_base_game_policy'), 'Child inventory pull does not cache parent policy.');

federation_base_policy_expect(substr_count($content['peer_inventory'], 'COALESCE(pf.is_base_game,0)=0') >= 2, 'Child inventory lists, totals or queueing do not consistently exclude base-game rows.');
federation_base_policy_expect(str_contains($content['peer_inventory'], 'pi_child_dependency_rows($db, $peerId, $ignoreBaseGame)'), 'Child dependency view does not apply the policy.');
federation_base_policy_expect(str_contains($content['peer_inventory'], 'Policy-excluded base-game files are removed.'), 'Child inventory page does not document the global exclusion.');

federation_base_policy_expect(str_contains($content['availability'], "'policy_excluded' => true"), 'Shared availability does not mark ignored base-game packages as excluded.');
federation_base_policy_expect(!str_contains($content['availability'], "'dependency_exception' => true"), 'Shared availability still permits a dependency exception.');
federation_base_policy_expect(str_contains($content['request_generate'], 'reqgen_policy_having'), 'Request generator totals are not policy-filtered.');
federation_base_policy_expect(str_contains($content['request_generate'], 'reqgen_apply_base_game_policy'), 'Request generator page and submission are not policy-filtered.');
federation_base_policy_expect(str_contains($content['request_submit'], 'Every selected package is excluded by the parent Ignore base-game files policy.'), 'Parent request endpoint does not reject an all-base-game request.');
federation_base_policy_expect(str_contains($content['request_submit'], "'policy_excluded'"), 'Parent request endpoint does not defensively skip policy-excluded packages.');

federation_base_policy_expect(str_contains($content['requests'], '$ignoreBaseGame && !empty($group[\'is_base_game\'])'), 'Incoming request grouping does not remove base-game rows.');
federation_base_policy_expect(str_contains($content['requests'], 'policy-visible item row(s)'), 'Incoming request bulk actions are not limited to visible rows.');
federation_base_policy_expect(str_contains($content['status_api'], 'if ($ignoreBaseGame && $isBaseGame)'), 'Status API does not omit base-game request items.');
federation_base_policy_expect(str_contains($content['status_page'], 'crs_group_items(array $items, bool $ignoreBaseGame)'), 'Outgoing request page does not independently apply the inherited policy.');
federation_base_policy_expect(str_contains($content['approved_page'], 'federation_visible_transfer_job_sql'), 'Approved-download history is not policy-filtered.');
federation_base_policy_expect(str_contains($content['dependency_downloads'], 'base_game_excluded'), 'Automatic approved-download queue does not report policy-excluded base-game rows.');

federation_base_policy_expect(str_contains($content['ordinary_endpoint'], '$isBaseGame && $ignoreBaseGame'), 'Ordinary child download endpoint does not enforce the global policy.');
federation_base_policy_expect(!str_contains($content['ordinary_endpoint'], '$dependencyException'), 'Ordinary child download endpoint still accepts a dependency exception override.');
federation_base_policy_expect(str_contains($content['approved_endpoint'], '$isBaseGame && federation_ignore_base_game_files($db)'), 'Approved dependency endpoint does not enforce the global policy.');

foreach (['dashboard', 'launcher', 'request_center', 'queue', 'parent_pull'] as $report) {
    federation_base_policy_expect(str_contains($content[$report], 'federation_visible_transfer_job_sql') || str_contains($content[$report], 'federation_visible_request_item_sql'), ucfirst(str_replace('_', ' ', $report)) . ' does not apply shared policy filters.');
}
federation_base_policy_expect(str_contains($content['missing_files'], 'federation_dependency_is_base_game_sql'), 'Missing-file total does not apply the policy.');
federation_base_policy_expect(str_contains($content['conflicts'], 'local_bg.game_id=f.game_id'), 'Conflict report does not exclude local base-game files.');

foreach ($content as $key => $value) {
    if (in_array($key, ['migration', 'inventory'], true)) {
        continue;
    }
    federation_base_policy_expect(!str_contains(strtolower($value), 'base-game dependency exception'), ucfirst(str_replace('_', ' ', $key)) . ' still describes the removed dependency exception.');
}

echo "Federation base-game policy contract tests passed.\n";
