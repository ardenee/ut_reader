<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies UPK package management behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function upk_package_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

foreach (['upks.php', 'game-upks.php', 'upk-info.php', 'lib/CatalogUpkPackage.php'] as $page) {
    upk_package_expect(is_file(__DIR__ . '/../' . $page), 'UPK package management file is missing: ' . $page);
}

$helper = file_get_contents(__DIR__ . '/../lib/CatalogUpkPackage.php');
upk_package_expect(is_string($helper), 'Could not read UPK helper.');
upk_package_expect(str_contains($helper, 'catalog_upk_supported_engine'), 'UPK helper does not enforce UE3 profiles.');
upk_package_expect(str_contains($helper, 'catalog_upk_export_href'), 'UPK exports cannot link to the package examiner.');
upk_package_expect(str_contains($helper, "'export-' . max(0, \$exportIndex)"), 'UPK export links do not use the examiner export target format.');

$gameFiles = file_get_contents(__DIR__ . '/../game-files.php');
upk_package_expect(is_string($gameFiles), 'Could not read game file list.');
upk_package_expect(str_contains($gameFiles, '$separateUpkContainers = $engineMajor === 3'), 'UE3 game files do not enable separate UPK handling.');
upk_package_expect(str_contains($gameFiles, 'LOWER(f.extension)<>"upk"'), 'UE3 UPKs remain mixed into the normal game-file table.');
upk_package_expect(str_contains($gameFiles, 'UPK containers are available under the UPK packages tab'), 'UE3 file view does not explain the separate UPK view.');

$global = file_get_contents(__DIR__ . '/../upks.php');
upk_package_expect(is_string($global), 'Could not read global UPK page.');
upk_package_expect(str_contains($global, 'UE3 game UPK collections'), 'Global UPK page does not group packages by UE3 game.');
upk_package_expect(str_contains($global, 'LOWER(f.extension)="upk"'), 'Global UPK page does not select UPK files.');

$game = file_get_contents(__DIR__ . '/../game-upks.php');
upk_package_expect(is_string($game), 'Could not read game UPK page.');
foreach ([
    'UPKs are not mixed into the normal file list',
    'A UE3 UPK contains serialized UObject exports',
    'upk-info.php?id=',
    'file-examine.php?id=',
    'download.php?id=',
    'file-maintenance.php',
    'serialized_export_bytes',
    'title="Names / Imports / Exports">N/I/E',
    'name_count',
    'import_count',
    'export_count',
    'serialized payload',
    'CatalogUi::identity(',
] as $fragment) {
    upk_package_expect(str_contains($game, $fragment), 'Game UPK page is missing: ' . $fragment);
}
upk_package_expect(!str_contains($game, 'indexed_exports'), 'Game UPK page still runs the obsolete per-row export count query.');
upk_package_expect(!str_contains($game, '>Contents</th>'), 'Game UPK page still uses the old Contents column.');
upk_package_expect(!str_contains($game, '>Imported</th>'), 'Game UPK page still includes the Imported column.');
upk_package_expect(!str_contains($game, '>View contents</a>'), 'Game UPK actions still include the redundant View contents link.');

$info = file_get_contents(__DIR__ . '/../upk-info.php');
upk_package_expect(is_string($info), 'Could not read UPK detail page.');
foreach ([
    'FROM ue_exports e',
    'UPK contents',
    'serialized UE3 UObject exports',
    'catalog_upk_export_href',
    'serial_offset',
    'serial_size',
    'object_flags',
    'Download original UPK',
] as $fragment) {
    upk_package_expect(str_contains($info, $fragment), 'UPK detail page is missing: ' . $fragment);
}
upk_package_expect(!str_contains($info, 'INSERT INTO ue_files'), 'UPK export objects are incorrectly presented as independent package files.');

$ui = file_get_contents(__DIR__ . '/../src/Presentation/Ui/CatalogUi.php');
upk_package_expect(is_string($ui), 'Could not read UI facade.');
upk_package_expect(str_contains($ui, '$engineMajor === 3'), 'UI does not recognize UE3 content switching.');
upk_package_expect(str_contains($ui, "'UPK packages' => 'game-upks.php?id='"), 'UI does not expose the UPK package tab.');

$navigation = file_get_contents(__DIR__ . '/../lib/CatalogNavigation.php');
upk_package_expect(is_string($navigation), 'Could not read administrator navigation.');
upk_package_expect(str_contains($navigation, "'UPK Packages' => \$root . 'upks.php'"), 'Administrator navigation does not link to UPK packages.');

$docs = file_get_contents(__DIR__ . '/../../docs/upk-package-management.md');
upk_package_expect(is_string($docs), 'Could not read UPK package documentation.');
upk_package_expect(str_contains($docs, 'not a valid standalone `.upk`'), 'UPK documentation does not explain why exports are not child package files.');

echo "UPK package management contract tests passed.\n";
