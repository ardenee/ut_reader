<?php
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
pak_archive_expect(str_contains($handler, 'Original PAK retained'), 'PAK import completion does not report original retention.');

$factory = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogJobWorkerFactory.php');
pak_archive_expect(is_string($factory), 'Could not read worker factory.');
$pakPosition = strpos($factory, 'new CatalogPakImportJobHandler(');
$genericPosition = strpos($factory, 'new CatalogNonBlockingImportJobHandler(');
pak_archive_expect(
    $pakPosition !== false && $genericPosition !== false && $pakPosition < $genericPosition,
    'Archive-aware PAK handler is not registered before the generic staged-import handler.'
);

foreach (['paks.php', 'game-paks.php', 'pak-info.php', 'pak-download.php', 'pak-maintenance.php'] as $page) {
    pak_archive_expect(is_file(__DIR__ . '/../' . $page), 'PAK management page is missing: ' . $page);
}

$gamePaks = file_get_contents(__DIR__ . '/../game-paks.php');
pak_archive_expect(is_string($gamePaks), 'Could not read game PAK list.');
pak_archive_expect(str_contains($gamePaks, 'PAKs are not mixed into the extracted file list'), 'Game PAK list does not keep PAKs separate from files.');

$pakInfo = file_get_contents(__DIR__ . '/../pak-info.php');
pak_archive_expect(is_string($pakInfo), 'Could not read PAK detail page.');
pak_archive_expect(str_contains($pakInfo, 'Every PAK index entry is listed'), 'PAK detail page does not expose complete contents.');
pak_archive_expect(str_contains($pakInfo, 'file-info.php?id='), 'PAK entries do not link to extracted package information.');

$ui = file_get_contents(__DIR__ . '/../src/Presentation/Ui/CatalogUi.php');
pak_archive_expect(is_string($ui), 'Could not read UI facade.');
pak_archive_expect(str_contains($ui, "'game-files.php', 'game-paks.php'"), 'Game content pages do not expose Files/PAK switching.');

$download = file_get_contents(__DIR__ . '/../pak-download.php');
pak_archive_expect(is_string($download), 'Could not read PAK download controller.');
pak_archive_expect(str_contains($download, 'ue_base_game_files'), 'PAK download does not enforce base-game protection.');
pak_archive_expect(str_contains($download, 'Content-Disposition: attachment'), 'PAK download does not stream the original archive.');

echo "PAK archive management contract tests passed.\n";
