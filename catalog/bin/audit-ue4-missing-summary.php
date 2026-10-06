#!/usr/bin/env php
<?php
/**
 * Read-only summary audit for UE4/UT4 missing dependencies.
 *
 * This intentionally does not claim VerifyImport correctness. It classifies the
 * currently persisted missing rows by package shape/provider presence and reports
 * UE4 object-version exposure to VER_UE4_NON_OUTER_PACKAGE_IMPORT (519), so the
 * source-conformance repair can be planned without rewriting catalog state.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { fwrite(STDERR, "CLI only.\n"); exit(1); }

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
require_once $root . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;

$options = getopt('', ['game-id::', 'top::', 'examples::']);
$gameId = max(1, (int)($options['game-id'] ?? 7));
$top = max(5, min(100, (int)($options['top'] ?? 30)));
$examples = max(0, min(50, (int)($options['examples'] ?? 10)));

$db = catalog_db(catalog_config());
$formatVersion = BlockedCompressedMetadataContainer::FORMAT_VERSION;

$game = catalog_one(
    $db,
    'SELECT g.id,g.name,g.slug,p.engine_key,p.profile_name FROM ue_games g '
    . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE g.id=? LIMIT 1',
    [$gameId]
);
if (!$game) throw new RuntimeException('Game not found: ' . $gameId);

$versions = catalog_one(
    $db,
    'SELECT COUNT(*) verified_files,MIN(package_version) min_package_version,MAX(package_version) max_package_version,'
    . 'SUM(CASE WHEN package_version>=519 THEN 1 ELSE 0 END) files_version_519_plus,'
    . 'SUM(CASE WHEN package_version<519 THEN 1 ELSE 0 END) files_before_519 '
    . 'FROM ue_files WHERE game_id=? AND scan_status="verified"',
    [$gameId]
) ?: [];

$totals = catalog_one(
    $db,
    'SELECT COUNT(*) missing_rows,COUNT(DISTINCT l.file_id) affected_files,'
    . 'COUNT(DISTINCT l.required_package_term_id) required_packages '
    . 'FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id '
    . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
    . 'WHERE f.game_id=? AND f.scan_status="verified" AND l.status=0',
    [$formatVersion, $gameId]
) ?: [];

$shapeRows = catalog_all(
    $db,
    'SELECT CASE '
    . 'WHEN CONVERT(pkg.value_prefix USING utf8mb4) LIKE "/Script/%" THEN "/Script/*" '
    . 'WHEN CONVERT(pkg.value_prefix USING utf8mb4) LIKE "/Game/%" THEN "/Game/*" '
    . 'WHEN CONVERT(pkg.value_prefix USING utf8mb4) LIKE "/Engine/%" THEN "/Engine/*" '
    . 'WHEN CONVERT(pkg.value_prefix USING utf8mb4) LIKE "/%/%" THEN "other long package" '
    . 'ELSE "short package" END package_shape,'
    . 'COUNT(*) missing_rows,COUNT(DISTINCT l.required_package_term_id) packages,COUNT(DISTINCT l.file_id) affected_files '
    . 'FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id '
    . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
    . 'JOIN ue_terms pkg ON pkg.id=l.required_package_term_id '
    . 'WHERE f.game_id=? AND f.scan_status="verified" AND l.status=0 '
    . 'GROUP BY package_shape ORDER BY missing_rows DESC',
    [$formatVersion, $gameId]
);

$providerSummary = catalog_one(
    $db,
    'SELECT '
    . 'SUM(CASE WHEN EXISTS (SELECT 1 FROM ue_package_providers p JOIN ue_files pf ON pf.id=p.file_id '
    . 'WHERE p.game_id=f.game_id AND pf.scan_status="verified" '
    . 'AND p.package_name=CONVERT(pkg.value_prefix USING utf8mb4) COLLATE utf8mb4_unicode_ci) THEN 1 ELSE 0 END) rows_with_exact_provider,'
    . 'SUM(CASE WHEN NOT EXISTS (SELECT 1 FROM ue_package_providers p JOIN ue_files pf ON pf.id=p.file_id '
    . 'WHERE p.game_id=f.game_id AND pf.scan_status="verified" '
    . 'AND p.package_name=CONVERT(pkg.value_prefix USING utf8mb4) COLLATE utf8mb4_unicode_ci) THEN 1 ELSE 0 END) rows_without_exact_provider '
    . 'FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id '
    . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
    . 'JOIN ue_terms pkg ON pkg.id=l.required_package_term_id '
    . 'WHERE f.game_id=? AND f.scan_status="verified" AND l.status=0',
    [$formatVersion, $gameId]
) ?: [];

$topRows = catalog_all(
    $db,
    'SELECT CONVERT(pkg.value_prefix USING utf8mb4) required_package,COUNT(*) missing_rows,'
    . 'COUNT(DISTINCT l.file_id) affected_files,'
    . 'MAX(CASE WHEN EXISTS (SELECT 1 FROM ue_package_providers p JOIN ue_files pf ON pf.id=p.file_id '
    . 'WHERE p.game_id=f.game_id AND pf.scan_status="verified" '
    . 'AND p.package_name=CONVERT(pkg.value_prefix USING utf8mb4) COLLATE utf8mb4_unicode_ci) THEN 1 ELSE 0 END) exact_provider_exists '
    . 'FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id '
    . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
    . 'JOIN ue_terms pkg ON pkg.id=l.required_package_term_id '
    . 'WHERE f.game_id=? AND f.scan_status="verified" AND l.status=0 '
    . 'GROUP BY l.required_package_term_id,CONVERT(pkg.value_prefix USING utf8mb4) '
    . 'ORDER BY missing_rows DESC LIMIT ' . $top,
    [$formatVersion, $gameId]
);

$sampleRows = [];
if ($examples > 0) {
    $sampleRows = catalog_all(
        $db,
        'SELECT l.file_id,l.import_index,f.original_name,f.package_version,'
        . 'CONVERT(pkg.value_prefix USING utf8mb4) required_package,'
        . 'CONVERT(obj.value_prefix USING utf8mb4) required_object_path,'
        . 'CONVERT(cp.value_prefix USING utf8mb4) class_package,'
        . 'CONVERT(cn.value_prefix USING utf8mb4) class_name '
        . 'FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id '
        . 'JOIN ue_file_metadata m ON m.file_id=f.id AND m.format_version=? '
        . 'JOIN ue_terms pkg ON pkg.id=l.required_package_term_id '
        . 'JOIN ue_terms obj ON obj.id=l.required_object_term_id '
        . 'LEFT JOIN ue_terms cp ON cp.id=l.import_class_package_term_id '
        . 'LEFT JOIN ue_terms cn ON cn.id=l.import_class_name_term_id '
        . 'WHERE f.game_id=? AND f.scan_status="verified" AND l.status=0 '
        . 'ORDER BY l.file_id,l.import_index LIMIT ' . $examples,
        [$formatVersion, $gameId]
    );
}

echo json_encode([
    'ok' => true,
    'read_only' => true,
    'game' => $game,
    'metadata_format_version' => $formatVersion,
    'ue4_non_outer_package_import_version' => 519,
    'verified_file_versions' => $versions,
    'missing_totals' => $totals,
    'missing_package_shapes' => $shapeRows,
    'exact_package_provider_presence' => $providerSummary,
    'top_missing_packages' => $topRows,
    'examples' => $sampleRows,
    'notes' => [
        'This audit reports persisted state only; it does not assert that current UE4 dependency matching is source-conformant.',
        'package_version >= 519 matters because UE4 can serialize FObjectImport::PackageName independently of OuterIndex.',
        'A high /Script/* count may indicate script-package common handling needs review; it is not automatically proof of a resolver bug.',
        'Exact-provider presence is package-name evidence only. Object/class/outer/public verification requires the UE4 VerifyImport audit.',
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
