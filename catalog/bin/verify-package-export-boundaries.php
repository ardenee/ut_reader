#!/usr/bin/env php
<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies generated-package planning/settings/path boundaries without mutating application data.
 * Why: Package export logic spans preview, workers and format writers; regressions can silently restore the old procedural planner.
 * Role: Read-only CLI architecture/regression verifier.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

$catalogRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$repoRoot = dirname($catalogRoot);
require_once $catalogRoot . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Downloads\CatalogPackageExportFormatPolicy;
use UnrealDb\Catalog\Infrastructure\Downloads\CatalogPackageInstallPathResolver;

$checks = [];
$failures = [];
$read = static function (string $relative) use ($repoRoot): string {
    $path = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $content = @file_get_contents($path);
    return is_string($content) ? $content : '';
};
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$record(
    'format_inference_contract',
    CatalogPackageExportFormatPolicy::inferred(['engine_key' => 'UE1', 'name' => 'Unreal Tournament']) === 'umod'
        && CatalogPackageExportFormatPolicy::inferred(['engine_key' => 'UE2', 'name' => 'Unreal Tournament 2003']) === 'ut2mod'
        && CatalogPackageExportFormatPolicy::inferred(['engine_key' => 'UE2', 'name' => 'Unreal Tournament 2004']) === 'ut4mod'
        && CatalogPackageExportFormatPolicy::inferred(['engine_key' => 'UE3', 'name' => 'Unreal Tournament 3']) === 'ut3_zip'
        && CatalogPackageExportFormatPolicy::inferred(['engine_key' => 'UE4', 'name' => 'Unreal Tournament']) === 'ut4_pak',
    'native export format inference must retain the legacy game/engine rules'
);

$settings = [
    'enabled' => true,
    'dependency_zip_enabled' => true,
    'umod_enabled' => true,
    'ut3_zip_enabled' => true,
    'ut4_pak_enabled' => true,
    'game_formats' => [],
];
$available = CatalogPackageExportFormatPolicy::available(
    ['id' => 4, 'engine_key' => 'UE2', 'name' => 'Unreal Tournament 2004'],
    $settings
);
$record(
    'available_formats_contract',
    $available === ['ut4mod', 'dependency_zip'],
    'native format must lead the dependency ZIP fallback'
);

$record(
    'path_normalization_contract',
    CatalogPackageInstallPathResolver::normalizeRelativePath('C:\\Games\\System\\..\\Maps\\DM-Test.ut2')
        === 'Games/Maps/DM-Test.ut2'
        && CatalogPackageInstallPathResolver::normalizeMountPoint('..\\..\\..\\UnrealTournament\\Content')
        === '../../../UnrealTournament/Content/',
    'relative-path and mount-point normalization must retain legacy behavior'
);

$record(
    'engine_source_path_contract',
    CatalogPackageInstallPathResolver::knownPathFromSource('C:/UT3/UTGame/Published/CookedPC/Maps/DM-Test.ut3', 'UE3')
        === 'UTGame/Published/CookedPC/Maps/DM-Test.ut3'
        && CatalogPackageInstallPathResolver::knownPathFromSource('D:/UT4/UnrealTournament/Content/Maps/DM-Test.umap', 'UE4')
        === 'Maps/DM-Test.umap',
    'UE3 and UE4 source paths must map to the same archive-relative paths as before'
);

$planner = $read('catalog/src/Infrastructure/Downloads/PdoCatalogPackageExportPlanner.php');
$record(
    'planner_owns_dependency_closure',
    str_contains($planner, 'PdoDependencyReadSource::sql')
        && str_contains($planner, 'CatalogPackageExportFormatPolicy::enabled')
        && str_contains($planner, 'base_game_file_is_protected')
        && str_contains($planner, "\$settings['max_files']")
        && str_contains($planner, "\$settings['max_bytes']")
        && str_contains($planner, "\$status === 'common'")
        && str_contains($planner, "\$status === 'missing'")
        && str_contains($planner, "\$status === 'package_only'")
        && str_contains($planner, 'Two catalog files map to the same package path'),
    'planner must preserve compact dependency reads, redistribution policy, limits and path-collision checks'
);

$jobHandler = $read('catalog/src/Infrastructure/Jobs/GeneratedPackageJobHandler.php');
$downloadPage = $read('catalog/download-info.php');
$record(
    'live_callers_use_namespaced_planner',
    str_contains($jobHandler, 'PdoCatalogPackageExportPlanner')
        && !str_contains($jobHandler, '\\modpkg_plan(')
        && str_contains($downloadPage, 'PdoCatalogPackageExportPlanner')
        && !str_contains($downloadPage, 'modpkg_plan('),
    'background generation and preview must not call the procedural planner'
);

$record(
    'worker_uses_namespaced_settings_policy',
    str_contains($jobHandler, 'CatalogPackageExportSettingsService')
        && str_contains($jobHandler, 'CatalogPackageExportFormatPolicy::')
        && !str_contains($jobHandler, '\\modpkg_settings('),
    'worker must not use the procedural settings/format-selection implementation'
);

$record(
    'download_preview_does_not_load_builder_monolith',
    str_contains($downloadPage, 'CatalogPackageExportSettingsService')
        && !str_contains($downloadPage, "require_once __DIR__ . '/lib/ModPackageBuilder.php'"),
    'preview/settings decisions should not load archive writer code'
);

$settingsService = $read('catalog/src/Infrastructure/Downloads/CatalogPackageExportSettingsService.php');
$record(
    'settings_service_owns_policy',
    str_contains($settingsService, 'CatalogPackageExportFormatPolicy::')
        && str_contains($settingsService, 'CatalogPackageInstallPathResolver::normalizeMountPoint')
        && !str_contains($settingsService, 'ModPackageBuilder.php')
        && !str_contains($settingsService, '\\modpkg_'),
    'settings service must not delegate back into the procedural builder'
);

$syntaxFiles = [
    'catalog/download-info.php',
    'catalog/src/Infrastructure/Downloads/CatalogPackageExportFormatPolicy.php',
    'catalog/src/Infrastructure/Downloads/CatalogPackageExportSettingsService.php',
    'catalog/src/Infrastructure/Downloads/CatalogPackageInstallPathResolver.php',
    'catalog/src/Infrastructure/Downloads/PdoCatalogPackageExportPlanner.php',
    'catalog/src/Infrastructure/Jobs/GeneratedPackageJobHandler.php',
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
        $output = trim((string)stream_get_contents($pipes[1]) . ' ' . (string)stream_get_contents($pipes[2]));
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
