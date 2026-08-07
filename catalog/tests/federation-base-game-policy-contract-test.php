<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies federation base game policy behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

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
    'inventories_page' => 'federation/inventories.php',
    'availability' => 'lib/FederationPackageAvailability.php',
    'request_submit' => 'api/federation/request-submit.php',
    'requests_page' => 'federation/requests.php',
    'status_api' => 'api/federation/request-status.php',
    'approved_endpoint' => 'api/federation/download-approved-file.php',
    'ordinary_endpoint' => 'api/federation/download-file.php',
    'dependency_downloads' => 'lib/FederationDependencyDownloads.php',
    'dashboard' => 'federation/admin.php',
    'transfers' => 'federation/queue.php',
    'diagnostics' => 'federation/diagnostics.php',
];
$content = [];
foreach ($files as $key => $path) {
    $value = file_get_contents($root . '/' . $path);
    federation_base_policy_expect(is_string($value), 'Missing federation base-game policy file: ' . $path);
    $content[$key] = $value;
}

federation_base_policy_expect(str_contains($content['policy'], "fed_setting(\$db, 'ignore_base_game_files', '1')"), 'Policy does not default to ignore base-game files.');
federation_base_policy_expect(str_contains($content['policy'], "'missing_dependency_exception' => false"), 'Policy still advertises a missing-dependency exception.');
federation_base_policy_expect(str_contains($content['policy'], 'federation_visible_request_item_sql'), 'Shared request-item policy filter is missing.');
federation_base_policy_expect(str_contains($content['policy'], 'federation_visible_transfer_job_sql'), 'Shared transfer-job policy filter is missing.');
federation_base_policy_expect(str_contains($content['migration'], 'ADD COLUMN is_base_game'), 'Policy migration does not classify peer inventory rows.');

federation_base_policy_expect(str_contains($content['settings'], 'Ignore official base-game files'), 'Parent policy control is missing.');
federation_base_policy_expect(str_contains($content['settings'], 'There is no missing-dependency exception.'), 'Settings does not explain global exclusion.');
federation_base_policy_expect(str_contains($content['inventory_api'], 'bg.id IS NULL'), 'Inventory API does not exclude base-game rows.');
federation_base_policy_expect(str_contains($content['inventory'], 'is_base_game=VALUES(is_base_game)'), 'Inventory cache does not persist classification.');
federation_base_policy_expect(substr_count($content['inventories_page'], 'COALESCE(pf.is_base_game,0)=0') >= 2, 'Consolidated inventories do not consistently exclude base-game rows.');
federation_base_policy_expect(str_contains($content['availability'], "'policy_excluded' => \$policyExcluded"), 'Availability does not mark policy-excluded files.');
federation_base_policy_expect(str_contains($content['request_submit'], 'Every selected package is excluded by the parent Ignore base-game files policy.'), 'Request endpoint does not reject all-excluded requests.');
federation_base_policy_expect(str_contains($content['requests_page'], 'federation_visible_transfer_job_sql'), 'Consolidated request histories are not policy-filtered.');
federation_base_policy_expect(str_contains($content['status_api'], 'if ($ignoreBaseGame && $isBaseGame)'), 'Status API does not omit base-game request items.');
federation_base_policy_expect(str_contains($content['dependency_downloads'], 'base_game_excluded'), 'Automatic Child download queue does not report policy exclusion.');
federation_base_policy_expect(str_contains($content['ordinary_endpoint'], '$isBaseGame && $ignoreBaseGame'), 'Ordinary Child download endpoint does not enforce policy.');
federation_base_policy_expect(str_contains($content['approved_endpoint'], '$isBaseGame && federation_ignore_base_game_files($db)'), 'Approved Child download endpoint does not enforce policy.');
federation_base_policy_expect(str_contains($content['dashboard'], 'federation_visible_transfer_job_sql'), 'Federation Overview counts are not policy-filtered.');
federation_base_policy_expect(str_contains($content['transfers'], 'federation_visible_transfer_job_sql'), 'Federation Transfers are not policy-filtered.');
federation_base_policy_expect(str_contains($content['diagnostics'], 'COALESCE(pf.is_base_game,0)=0'), 'Conflict diagnostics are not policy-filtered.');

foreach ($content as $key => $value) {
    if ($key === 'migration') {
        continue;
    }
    federation_base_policy_expect(!str_contains(strtolower($value), 'base-game dependency exception'), ucfirst(str_replace('_', ' ', $key)) . ' still describes the removed exception.');
}

echo "Federation base-game policy contract tests passed.\n";
