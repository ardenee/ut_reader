<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for PAK Archives.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Storage\CatalogPakArchiveStore;
use UnrealDb\Catalog\Infrastructure\Storage\CatalogIoStoreContainerIndexer;

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_head('Package Viewer');
    catalog_page_header(
        'Package Viewer',
        'Browse UE4/UE5 PAK archives and UE5 IoStore (.utoc/.ucas) container families and indexed packages.',
        ['Import PAK' => 'pak-import.php', 'Import IoStore' => 'iostore-import.php', 'Background Jobs' => 'background-jobs.php', 'Games' => 'games.php']
    );

    $pakInstalled = CatalogPakArchiveStore::schemaInstalled($db);
    $ioInstalled = CatalogIoStoreContainerIndexer::schemaInstalled($db);
    if (!$pakInstalled && !$ioInstalled) {
        echo CatalogUi::alert('warning', 'Package-container management is not installed. Run php catalog/bin/migrate.php migrate.');
        catalog_foot();
        exit;
    }

    $ioJoin = $ioInstalled
        ? ',(SELECT COUNT(*) FROM ue_iostore_containers c WHERE c.game_id=g.id) iostore_count'
          . ',(SELECT COALESCE(SUM(c.package_count),0) FROM ue_iostore_containers c WHERE c.game_id=g.id) iostore_packages'
        : ',0 iostore_count,0 iostore_packages';
    $games = catalog_all(
        $db,
        'SELECT g.id,g.name,g.slug,p.engine_key,'
        . '(SELECT COUNT(*) FROM ue_pak_archives a WHERE a.game_id=g.id) pak_count,'
        . '(SELECT COALESCE(SUM(a.file_size),0) FROM ue_pak_archives a WHERE a.game_id=g.id) pak_bytes,'
        . '(SELECT COALESCE(SUM(a.entry_count),0) FROM ue_pak_archives a WHERE a.game_id=g.id) entry_count '
        . $ioJoin . ' FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id '
        . 'WHERE UPPER(p.engine_key) LIKE "UE4%" OR UPPER(p.engine_key) LIKE "UE5%" '
        . 'ORDER BY g.name'
    );

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>UE4 and UE5 package containers</h2><p>Select a game to browse PAK archives or retained UE5 IoStore container families and package entries.</p></div></div><div class="ui-section__body">';
    if ($games === []) {
        echo CatalogUi::emptyState('No UE4 or UE5 games configured', 'Assign a UE4 or UE5 game profile before importing PAK archives.', ['label' => 'Game manager', 'href' => 'game-manager.php'], '▣');
    } else {
        echo '<div class="grid">';
        foreach ($games as $game) {
            echo '<div class="card"><h3>' . catalog_h((string)$game['name']) . '</h3>';
            echo '<p><span class="mono small">' . catalog_h((string)$game['engine_key']) . '</span><br><strong>' . number_format((int)$game['pak_count']) . '</strong> PAK archive(s)<br>'
                . number_format((int)$game['entry_count']) . ' PAK entries<br>'
                . (str_starts_with(strtoupper((string)$game['engine_key']), 'UE5')
                    ? '<strong>' . number_format((int)$game['iostore_count']) . '</strong> IoStore container(s)<br>'
                      . number_format((int)$game['iostore_packages']) . ' IoStore package(s)<br>'
                    : '')
                . catalog_h(catalog_bytes((int)$game['pak_bytes'])) . ' retained container data</p>';
            echo '<p><a class="button primary" href="game-paks.php?id=' . (int)$game['id'] . '">Open Package Viewer</a> '
                . '<a class="button" href="game-files.php?id=' . (int)$game['id'] . '">View extracted files</a> '
                . '<a class="button" href="pak-import.php?game_id=' . (int)$game['id'] . '">Import PAK</a> '
                . (str_starts_with(strtoupper((string)$game['engine_key']), 'UE5')
                    ? '<a class="button" href="iostore-import.php?game_id=' . (int)$game['id'] . '">Import IoStore</a>'
                    : '') . '</p></div>';
        }
        echo '</div>';
    }
    echo '</div></section>';
    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('Package Viewer error');
    }
    echo CatalogUi::alert('danger', catalog_exception_display_message($error), 'Package Viewer could not be loaded.');
    catalog_foot();
}
