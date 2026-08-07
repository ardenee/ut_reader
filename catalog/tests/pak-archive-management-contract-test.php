<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies PAK archive management behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

function pak_archive_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$migration = file_get_contents(__DIR__ . '/../migrations/202607210001_pak_archive_management.php');
pak_archive_expect(is_string($migration), 'Could not read PAK archive migration.');
foreach (['ue_pak_archives', 'ue_pak_entries', 'file_id BIGINT UNSIGNED NULL', 'ON DELETE SET NULL'] as $fragment) {
    pak_archive_expect(str_contains($migration, $fragment), 'PAK archive migration is missing: ' . $fragment);
}

$store = file_get_contents(__DIR__ . '/../src/Infrastructure/Storage/CatalogPakArchiveStore.php');
pak_archive_expect(is_string($store), 'Could not read PAK archive store.');
pak_archive_expect(str_contains($store, "'games' . DIRECTORY_SEPARATOR"), 'Original PAK storage is not game-scoped.');
pak_archive_expect(str_contains($store, "DIRECTORY_SEPARATOR . 'paks'"), 'Original PAK storage does not use a dedicated paks folder.');
pak_archive_expect(str_contains($store, '@copy($source, $part)'), 'Original PAK is not copied into durable storage.');
pak_archive_expect(str_contains($store, "hash_file('sha256', \$part)"), 'Original PAK copy is not verified by SHA-256.');

$handler = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogPakImportJobHandler.php');
pak_archive_expect(is_string($handler), 'Could not read PAK import handler.');
pak_archive_expect(str_contains($handler, 'createOrReset('), 'PAK import does not retain the original archive.');
pak_archive_expect(str_contains($handler, 'addEntry('), 'PAK import does not record every index entry.');
pak_archive_expect(str_contains($handler, 'updateEntry('), 'PAK entries are not linked to import results.');
pak_archive_expect(str_contains($handler, "'source_pak_id' => \$pakId"), 'Package scanner metadata does not identify the source PAK.');
pak_archive_expect(str_contains($handler, "'defer_dependency_rebuild' => true"), 'PAK imports rebuild dependencies once per package instead of deferring them.');
pak_archive_expect(str_contains($handler, 'scanner_rebuild_game('), 'PAK imports do not perform one final game dependency refresh.');
pak_archive_expect(str_contains($handler, 'in_array($engineMajor, [4, 5], true)'), 'PAK handler does not accept both UE4 and UE5 game profiles.');
pak_archive_expect(str_contains($handler, 'UE4 or UE5 game profiles'), 'PAK handler error text does not describe UE4/UE5 support.');
$extractPosition = strpos($handler, '$extracted = \\catalog_pak_archive_extract_to_temp(');
$selectPosition = strpos($handler, 'selectIndexForExtractedFiles(');
$retainPosition = strpos($handler, '$pakId = $archiveStore->createOrReset(');
pak_archive_expect(
    $extractPosition !== false && $selectPosition !== false && $retainPosition !== false
        && $extractPosition < $selectPosition && $selectPosition < $retainPosition,
    'PAK retention does not use the same footer/index candidate that successfully extracted the files.'
);
pak_archive_expect(str_contains($handler, 'magic_offset=(-?[0-9]+)'), 'PAK import does not identify the successful extractor footer metadata.');
pak_archive_expect(str_contains($handler, 'Could not match the successfully extracted PAK files to a parsed index.'), 'PAK import does not fail safely when extracted files cannot be matched to an index.');
pak_archive_expect(str_contains($handler, 'PAK retained; archive entries and extracted packages were cataloged'), 'PAK import completion does not report original retention.');

$importPage = file_get_contents(__DIR__ . '/../pak-import.php');
pak_archive_expect(is_string($importPage), 'Could not read PAK import page.');
pak_archive_expect(str_contains($importPage, "preg_match('/^UE[45]/i"), 'PAK import request validation does not accept UE4 and UE5.');
pak_archive_expect(str_contains($importPage, 'UE5 IoStore .utoc/.ucas'), 'PAK import page does not distinguish UE5 IoStore from PAK support.');

$factory = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
pak_archive_expect(is_string($factory), 'Could not read worker factory.');
$pakPosition = strpos($factory, 'new CatalogPakImportJobHandler(');
$genericPosition = strpos($factory, 'new CatalogNonBlockingImportJobHandler(');
pak_archive_expect(
    $pakPosition !== false && $genericPosition !== false && $pakPosition < $genericPosition,
    'Archive-aware PAK handler is not registered before the generic staged-import handler.'
);

foreach (['paks.php', 'game-paks.php', 'pak-info.php', 'pak-download.php', 'pak-maintenance.php', 'file-pak-sources.php'] as $page) {
    pak_archive_expect(is_file(__DIR__ . '/../' . $page), 'PAK management page is missing: ' . $page);
}

$globalPaks = file_get_contents(__DIR__ . '/../paks.php');
pak_archive_expect(is_string($globalPaks), 'Could not read global PAK page.');
pak_archive_expect(str_contains($globalPaks, 'UE4 and UE5 game PAK collections'), 'Global PAK page does not list both UE4 and UE5 games.');

$gamePaks = file_get_contents(__DIR__ . '/../game-paks.php');
pak_archive_expect(is_string($gamePaks), 'Could not read game PAK list.');
pak_archive_expect(str_contains($gamePaks, 'in_array($engineMajor, [4, 5], true)'), 'Game PAK list does not accept UE4 and UE5 games.');
pak_archive_expect(str_contains($gamePaks, 'PAKs are not mixed into the extracted file list'), 'Game PAK list does not keep PAKs separate from files.');

$pakInfo = file_get_contents(__DIR__ . '/../pak-info.php');
pak_archive_expect(is_string($pakInfo), 'Could not read PAK detail page.');
pak_archive_expect(str_contains($pakInfo, 'Every PAK index entry is listed'), 'PAK detail page does not expose complete contents.');
pak_archive_expect(str_contains($pakInfo, 'file-info.php?id='), 'PAK entries do not link to extracted package information.');

$fileSources = file_get_contents(__DIR__ . '/../file-pak-sources.php');
pak_archive_expect(is_string($fileSources), 'Could not read file PAK source endpoint.');
pak_archive_expect(str_contains($fileSources, 'WHERE e.file_id=?'), 'Extracted files cannot look up their source PAK entries.');

$dependencyUi = file_get_contents(__DIR__ . '/../assets/file-dependency-display.js');
pak_archive_expect(is_string($dependencyUi), 'Could not read file relationship UI script.');
pak_archive_expect(str_contains($dependencyUi, 'file-pak-sources.php?id='), 'File pages do not load source PAK references.');
pak_archive_expect(str_contains($dependencyUi, 'Download original PAK'), 'File pages do not link back to the retained original PAK.');

$ui = file_get_contents(__DIR__ . '/../src/Presentation/Ui/CatalogUi.php');
pak_archive_expect(is_string($ui), 'Could not read UI facade.');
pak_archive_expect(str_contains($ui, "['game-files.php', 'game-paks.php', 'game-upks.php']"), 'Game content pages do not expose container switching.');
pak_archive_expect(str_contains($ui, 'in_array($engineMajor, [4, 5], true)'), 'PAK switching is not enabled for UE4 and UE5 games.');

$download = file_get_contents(__DIR__ . '/../pak-download.php');
pak_archive_expect(is_string($download), 'Could not read PAK download controller.');
pak_archive_expect(str_contains($download, 'ue_base_game_files'), 'PAK download does not enforce base-game protection.');
pak_archive_expect(str_contains($download, 'Content-Disposition: attachment'), 'PAK download does not stream the original archive.');

echo "PAK archive management contract tests passed.\n";
