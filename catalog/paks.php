<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Storage\CatalogPakArchiveStore;

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_head('PAK Archives');
    catalog_page_header(
        'PAK Archives',
        'Original UE4 PAK containers are stored and managed separately from the package files extracted from them.',
        ['Import PAK' => 'pak-import.php', 'Background Jobs' => 'background-jobs.php', 'Games' => 'games.php']
    );

    if (!CatalogPakArchiveStore::schemaInstalled($db)) {
        echo CatalogUi::alert('warning', 'PAK archive management is not installed. Run php catalog/bin/migrate.php migrate.');
        catalog_foot();
        exit;
    }

    $games = catalog_all(
        $db,
        'SELECT g.id,g.name,g.slug,p.engine_key,'
        . '(SELECT COUNT(*) FROM ue_pak_archives a WHERE a.game_id=g.id) pak_count,'
        . '(SELECT COALESCE(SUM(a.file_size),0) FROM ue_pak_archives a WHERE a.game_id=g.id) pak_bytes,'
        . '(SELECT COALESCE(SUM(a.entry_count),0) FROM ue_pak_archives a WHERE a.game_id=g.id) entry_count '
        . 'FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id '
        . 'WHERE UPPER(p.engine_key) LIKE ? ORDER BY g.name',
        ['UE4%']
    );

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>UE4 game PAK collections</h2><p>Select a game to switch between its normal extracted files and original PAK containers.</p></div></div><div class="ui-section__body">';
    if ($games === []) {
        echo CatalogUi::emptyState('No UE4 games configured', 'Assign a UE4 game profile before importing PAK archives.', ['label' => 'Game manager', 'href' => 'game-manager.php'], '▣');
    } else {
        echo '<div class="grid">';
        foreach ($games as $game) {
            echo '<div class="card"><h3>' . catalog_h((string)$game['name']) . '</h3>';
            echo '<p><strong>' . number_format((int)$game['pak_count']) . '</strong> PAK archive(s)<br>'
                . number_format((int)$game['entry_count']) . ' indexed entries<br>'
                . catalog_h(catalog_bytes((int)$game['pak_bytes'])) . ' retained container data</p>';
            echo '<p><a class="button primary" href="game-paks.php?id=' . (int)$game['id'] . '">View PAK archives</a> '
                . '<a class="button" href="game-files.php?id=' . (int)$game['id'] . '">View extracted files</a> '
                . '<a class="button" href="pak-import.php?game_id=' . (int)$game['id'] . '">Import PAK</a></p></div>';
        }
        echo '</div>';
    }
    echo '</div></section>';
    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('PAK archive error');
    }
    echo CatalogUi::alert('danger', $error->getMessage(), 'PAK archive management could not be loaded.');
    catalog_foot();
}
