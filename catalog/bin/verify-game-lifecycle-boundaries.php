#!/usr/bin/env php
<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies Game Manager reset/delete lifecycle boundaries and preserved destructive-operation contracts.
 * Why: Controller extraction previously removed gm_emit()/gm_reset_game_files() while GameManagerLifecycle still called them.
 * Role: Read-only CLI architecture verifier; it performs no database or filesystem mutation.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

$catalogRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$repoRoot = dirname($catalogRoot);
require_once $catalogRoot . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Games\CatalogGameLifecycleProgress;
use UnrealDb\Catalog\Infrastructure\Games\PdoCatalogGameTableMaintenance;

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};
$read = static function (string $relative) use ($repoRoot): string {
    $path = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $content = @file_get_contents($path);
    return is_string($content) ? $content : '';
};

$facade = $read('catalog/lib/GameManagerLifecycle.php');
$admin = $read('catalog/src/Infrastructure/Games/CatalogGameAdminService.php');
$lifecycle = $read('catalog/src/Infrastructure/Games/CatalogGameLifecycleService.php');
$managed = $read('catalog/src/Infrastructure/Games/CatalogGameManagedFileCleanup.php');
$storage = $read('catalog/src/Infrastructure/Games/CatalogGameStorageCleanup.php');
$tables = $read('catalog/src/Infrastructure/Games/PdoCatalogGameTableMaintenance.php');
$audit = $read('catalog/src/Application/Maintenance/LegacyMetadataRuntimeAudit.php');

$record(
    'legacy_facade_is_thin',
    str_contains($facade, 'CatalogGameLifecycleService')
        && str_contains($facade, 'PdoCatalogGameTableMaintenance')
        && str_contains($facade, 'CatalogGameStorageCleanup')
        && !str_contains($facade, 'beginTransaction(')
        && !str_contains($facade, 'OPTIMIZE TABLE')
        && !str_contains($facade, '@unlink(')
        && !str_contains($facade, 'DELETE FROM ')
        && !str_contains($facade, 'gm_emit(')
        && !str_contains($facade, 'gm_reset_game_files('),
    'GameManagerLifecycle.php must remain compatibility delegates only'
);

$record(
    'game_admin_uses_namespaced_lifecycle',
    str_contains($admin, 'CatalogGameLifecycleService')
        && str_contains($admin, '$this->lifecycle->reset(')
        && str_contains($admin, '$this->lifecycle->delete(')
        && !str_contains($admin, 'GameManagerLifecycle.php')
        && !str_contains($admin, 'gm_lifecycle_reset_game(')
        && !str_contains($admin, 'gm_lifecycle_delete_game('),
    'production Game Admin must not route destructive actions through the procedural facade'
);

$record(
    'managed_file_cleanup_preserves_contract',
    str_contains($managed, "'Preparing game reset…'")
        && str_contains($managed, "'DELETE FROM ue_file_package_aliases WHERE game_id=?'")
        && str_contains($managed, 'array_chunk($fileIds, 100)')
        && str_contains($managed, "'DELETE FROM ue_files WHERE id IN ('")
        && str_contains($managed, "'Deleting catalog records batch '")
        && str_contains($managed, "'Game reset complete.'"),
    'verified file reset must retain alias cleanup, 100-row batches and historical progress messages'
);

$record(
    'managed_storage_cleanup_preserves_contract',
    str_contains($storage, ". 'games'")
        && str_contains($storage, "'Inspecting managed game storage…'")
        && str_contains($storage, "'Refusing to reset storage outside the catalog storage folder.'")
        && str_contains($storage, 'RecursiveIteratorIterator::CHILD_FIRST')
        && str_contains($storage, "'Could not remove staged game-file note: '")
        && !str_contains($storage, 'ue_pak_files'),
    'storage cleanup must target managed games/<slug> and explicit staged rows only'
);

$record(
    'lifecycle_cleanup_preserves_unverified_and_pak_contract',
    str_contains($lifecycle, 'scan_status="unverified"')
        && str_contains($lifecycle, 'unverified_queue_game_id=?')
        && str_contains($lifecycle, 'FROM ue_pak_archives WHERE game_id=?')
        && str_contains($lifecycle, 'DELETE FROM ue_pak_archives WHERE game_id=?')
        && str_contains($lifecycle, "'unverified_records'")
        && str_contains($lifecycle, "'pak_archives'"),
    'outer cleanup must retain targeted unverified rows and ue_pak_archives accounting'
);

$resetOptimiseCall = "PdoCatalogGameTableMaintenance::tableList(false),\n"
    . "            \$progress,\n"
    . "            78,\n"
    . "            96";
$record(
    'reset_reconciliation_contract',
    str_contains($lifecycle, $resetOptimiseCall)
        && str_contains($lifecycle, "'reconcile'")
        && str_contains($lifecycle, 'CatalogProjectionReconciliationQueue::enqueue(')
        && str_contains($lifecycle, "'reconciliation_job_id'")
        && str_contains($lifecycle, "'Game reset, database optimisation and projection reconciliation complete.'"),
    'reset must optimize, queue zero-state projection reconciliation and report completion'
);

$deleteOptimiseCall = "PdoCatalogGameTableMaintenance::tableList(true),\n"
    . "            \$progress,\n"
    . "            78,\n"
    . "            99";
$record(
    'delete_contract',
    str_contains($lifecycle, $deleteOptimiseCall)
        && str_contains($lifecycle, 'DELETE FROM ue_base_game_files WHERE game_id=?')
        && str_contains($lifecycle, 'DELETE FROM ue_games WHERE id=?')
        && str_contains($lifecycle, "'deleted_game_id'")
        && str_contains($lifecycle, "'sources'")
        && str_contains($lifecycle, "'base_game_rows'")
        && str_contains($lifecycle, "'Game deletion and database optimisation complete.'"),
    'delete must preserve base-game cleanup, game deletion and result accounting'
);

$record(
    'table_maintenance_preserves_mysql_result_errors',
    str_contains($tables, 'information_schema.TABLES')
        && str_contains($tables, 'OPTIMIZE TABLE')
        && str_contains($tables, "'Msg_type'")
        && str_contains($tables, "'error'")
        && str_contains($tables, 'nextRowset()')
        && str_contains($tables, "'Database optimisation completed with '")
        && !str_contains($tables, 'compactHistory('),
    'OPTIMIZE TABLE warnings/errors must retain the old rowset-aware handling'
);

$expectedBaseTables = [
    'ue_asset_registry_tags',
    'ue_asset_registry_dependencies',
    'ue_asset_registry_assets',
    'ue_dependency_package_summaries',
    'ue_dependencies',
    'ue_package_providers',
    'ue_exports',
    'ue_imports',
    'ue_names',
    'ue_external_mirror_jobs',
    'ue_external_download_links',
    'ue_source_file_fingerprints',
    'ue_file_locations',
    'ue_file_package_aliases',
    'ue_pak_entries',
    'ue_pak_archives',
    'ue_game_catalog_stats',
    'ue_files',
];
$expectedDeleteOnly = [
    'ue_base_game_files',
    'ue_sources',
    'ue_federation_peer_files',
    'ue_games',
];
$record(
    'table_list_parity',
    PdoCatalogGameTableMaintenance::tableList(false) === $expectedBaseTables
        && PdoCatalogGameTableMaintenance::tableList(true)
            === array_merge($expectedBaseTables, $expectedDeleteOnly),
    'optimization table selection must remain unchanged from GameManagerLifecycle'
);

$progressStates = [];
CatalogGameLifecycleProgress::emit(
    static function (array $state) use (&$progressStates): void {
        $progressStates[] = $state;
    },
    'test',
    -2,
    0,
    140,
    'message'
);
$record(
    'progress_normalization_contract',
    $progressStates === [[
        'stage' => 'test',
        'done' => 0,
        'total' => 1,
        'percent' => 100,
        'message' => 'message',
    ]],
    'progress callback must preserve gm_emit normalization semantics'
);

$record(
    'legacy_audit_ownership_moved',
    str_contains($audit, "'src/Infrastructure/Games/PdoCatalogGameTableMaintenance.php'")
        && !str_contains($audit, "'lib/GameManagerLifecycle.php'"),
    'optional legacy table names belong to the maintenance collaborator, not the facade'
);

require_once $catalogRoot . '/lib/GameManagerLifecycle.php';
$expectedFunctions = [
    'gm_lifecycle_table_exists',
    'gm_lifecycle_optimise_table_list',
    'gm_lifecycle_optimise_tables',
    'gm_lifecycle_unverified_rows',
    'gm_lifecycle_remove_staged_storage',
    'gm_lifecycle_cleanup_game',
    'gm_lifecycle_reset_game',
    'gm_lifecycle_delete_game',
];
$missingFunctions = array_values(array_filter(
    $expectedFunctions,
    static fn(string $function): bool => !function_exists($function)
));
$record(
    'compatibility_functions_remain_available',
    $missingFunctions === [],
    $missingFunctions === [] ? '' : 'missing: ' . implode(', ', $missingFunctions)
);

$syntaxFiles = [
    'catalog/lib/GameManagerLifecycle.php',
    'catalog/src/Infrastructure/Games/CatalogGameAdminService.php',
    'catalog/src/Infrastructure/Games/CatalogGameLifecycleProgress.php',
    'catalog/src/Infrastructure/Games/CatalogGameLifecycleService.php',
    'catalog/src/Infrastructure/Games/CatalogGameManagedFileCleanup.php',
    'catalog/src/Infrastructure/Games/CatalogGameStorageCleanup.php',
    'catalog/src/Infrastructure/Games/PdoCatalogGameTableMaintenance.php',
    'catalog/src/Application/Maintenance/LegacyMetadataRuntimeAudit.php',
];
foreach ($syntaxFiles as $relative) {
    $path = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $process = proc_open(
        [PHP_BINARY, '-l', $path],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $output = '';
    $exit = 1;
    if (is_resource($process)) {
        $output = trim(
            (string)stream_get_contents($pipes[1])
            . ' '
            . (string)stream_get_contents($pipes[2])
        );
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
    }
    $record(
        'php_syntax_' . str_replace(['/', '.php'], ['_', ''], $relative),
        $exit === 0,
        $exit === 0 ? '' : $output
    );
}

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
