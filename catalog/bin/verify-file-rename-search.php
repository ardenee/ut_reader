#!/usr/bin/env php
<?php
/**
 * Read-only/no-database regression verifier for verified-file rename and search
 * responsiveness contracts.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$checks = [];
$failures = [];
$read = static function (string $relative) use ($root): string {
    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $source = @file_get_contents($path);
    return is_string($source) ? $source : '';
};
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$phpFiles = [
    'file-examine.php',
    'lib/CatalogSearchService.php',
    'lib/CatalogPublicRateLimit.php',
    'src/Infrastructure/Maintenance/CatalogVerifiedFileRenameService.php',
    'src/Infrastructure/Search/PdoCatalogSearchRepository.php',
    'src/Infrastructure/Jobs/CatalogDependencyRefreshJobHandler.php',
    'src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshCoordinator.php',
    'index.php',
];
$syntaxFailures = [];
if (!function_exists('proc_open')) {
    $syntaxFailures[] = 'proc_open unavailable';
} else {
    foreach ($phpFiles as $relative) {
        $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $syntaxFailures[] = $relative . ' could not be linted';
            continue;
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            $syntaxFailures[] = $relative . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
        }
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$page = $read('file-examine.php');
$rename = $read('src/Infrastructure/Maintenance/CatalogVerifiedFileRenameService.php');
$dependencyHandler = $read('src/Infrastructure/Jobs/CatalogDependencyRefreshJobHandler.php');
$affected = $read('src/Infrastructure/Jobs/CatalogAffectedDependencyRefreshCoordinator.php');
$searchFacade = $read('lib/CatalogSearchService.php');
$search = $read('src/Infrastructure/Search/PdoCatalogSearchRepository.php');
$index = $read('index.php');
$rateLimit = $read('lib/CatalogPublicRateLimit.php');

$record(
    'file_examine_rename_is_admin_csrf_only',
    str_contains($page, "catalog_support_is_admin()")
        && str_contains($page, "catalog_check_csrf('file_examine_rename')")
        && str_contains($page, "action\" value=\"rename_file")
        && !str_contains($page, 'catalog_require_recent_admin'),
    'Rename must require the existing admin session and CSRF token without recent-login reauthentication.'
);

$record(
    'rename_changes_logical_identity_only',
    str_contains($rename, 'UPDATE ue_files SET original_name=?,package_name=?,source_relative_path=?')
        && !str_contains($rename, 'UPDATE ue_files SET stored_name=')
        && !str_contains($rename, 'UPDATE ue_files SET relative_path=')
        && !str_contains($rename, 'UPDATE ue_file_locations'),
    'A correction updates durable logical identity while leaving the internal stored object and physical source-location records untouched.'
);

$record(
    'rename_queues_fresh_dependency_pass',
    str_contains($rename, 'rename-file-dependencies:')
        && str_contains($rename, "'post_import' => true")
        && str_contains($rename, "'rename_refresh' => true")
        && str_contains($rename, "'old_package_name' => \$oldPackageName")
        && str_contains($dependencyHandler, 'enqueueRenameRefresh('),
    'A rename must get a fresh post-rename file dependency job rather than coalescing with an older active rebuild.'
);

$record(
    'rename_refreshes_old_new_and_resolved_consumers',
    str_contains($affected, "'additional_package_names'")
        && str_contains($affected, "'include_resolved_provider' => true")
        && str_contains($affected, 'WHERE l.resolved_file_id=?')
        && str_contains($affected, 'activeDiscoveryOptions('),
    'Canonical rename refresh must cover new-name imports, old-name imports and rows previously resolved to the renamed provider.'
);

$record(
    'rename_suggestions_are_bounded_and_evidence_based',
    str_contains($rename, 'MAX_SUGGESTION_EXPORT_TERMS = 500')
        && str_contains($rename, 'MAX_SUGGESTION_DEPENDENCY_ROWS = 5000')
        && str_contains($rename, 'd.import_object_term_id IN (')
        && str_contains($rename, 'd.resolved_file_id IS NULL'),
    'Possible-name hints must use bounded unresolved import/export object overlap rather than an unbounded text scan.'
);

$record(
    'global_search_no_longer_fans_out_by_game',
    !str_contains($searchFacade, 'MAX_GLOBAL_GAMES')
        && !str_contains($searchFacade, 'foreach ($games')
        && substr_count($searchFacade, '->findFiles(') === 1,
    'One all-game administrator search must not multiply the same repository work by the number of games.'
);

$record(
    'compact_metadata_search_is_indexed_exact_term',
    str_contains($search, 'WHERE value_hash=? AND value_length=? LIMIT 1')
        && str_contains($search, "'object_term_id'")
        && str_contains($search, "'local_path_term_id'")
        && str_contains($search, "'import_object_term_id'")
        && str_contains($search, "'required_object_term_id'")
        && !str_contains($search, 'private function collectMetadataMatches(')
        && !str_contains($search, 'private function collectAliasExportMatches(')
        && !str_contains($search, 'CONVERT(t.value_prefix USING utf8mb4) COLLATE'),
    'Deep object/import/export search must use ue_terms identity plus indexed term-id references, not leading-wildcard metadata scans.'
);

$sessionClose = strpos($index, 'session_write_close();');
$searchCall = strpos($index, 'CatalogSearchService::findFiles(');
$record(
    'search_releases_session_before_database_work',
    $sessionClose !== false && $searchCall !== false && $sessionClose < $searchCall,
    'A long search must not hold the PHP session lock and block other pages opened by the same logged-in administrator.'
);

$record(
    'search_rate_limit_does_not_reopen_closed_admin_session',
    str_contains($rateLimit, "(\$_SESSION['user']['role'] ?? '') === 'admin'")
        && str_contains($rateLimit, 'session_status() === PHP_SESSION_ACTIVE && catalog_support_is_admin()'),
    'After search closes its session, the public-rate-limit check must not reopen and lock that admin session.'
);

require_once $root . '/lib/CatalogSupport.php';
require_once $root . '/lib/Scanner/CatalogScannerPath.php';
$fixture = '[GO]tex_1.utx';
$record(
    'legacy_unreal_filename_characters_are_preserved',
    catalog_clean_unreal_filename($fixture) === $fixture
        && scanner_clean_original_filename($fixture) === $fixture
        && scanner_logical_package_name($fixture) === '[GO]tex_1',
    'Current import/maintenance filename policy must not recreate the historical bracket-to-underscore corruption.'
);

try {
    $class = new ReflectionClass(UnrealDb\Catalog\Infrastructure\Maintenance\CatalogVerifiedFileRenameService::class);
    $service = $class->newInstanceWithoutConstructor();
    $packageMethod = $class->getMethod('correctedPackageName');
    $sourceMethod = $class->getMethod('correctedSourceRelativePath');
    if (method_exists($packageMethod, 'setAccessible')) {
        $packageMethod->setAccessible(true);
        $sourceMethod->setAccessible(true);
    }
    $classic = $packageMethod->invoke($service, '_GO_tex_1', '[GO]tex_1.utx');
    $mounted = $packageMethod->invoke($service, '/Game/Textures/_GO_tex_1', '[GO]tex_1.uasset');
    $source = $sourceMethod->invoke($service, 'UTGame/CookedPC/Textures/_GO_tex_1.utx', '[GO]tex_1.utx');
    $record(
        'rename_preserves_classic_and_mounted_identity_shapes',
        $classic === '[GO]tex_1'
            && $mounted === '/Game/Textures/[GO]tex_1'
            && $source === 'UTGame/CookedPC/Textures/[GO]tex_1.utx',
        'Classic package identity uses the corrected stem; mounted UE4/UE5 identity keeps its package path; maintenance source identity keeps its directory.'
    );
} catch (Throwable $error) {
    $record('rename_preserves_classic_and_mounted_identity_shapes', false, $error->getMessage());
}

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
