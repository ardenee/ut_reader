<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for UE3 UPK Packages.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogUpkPackage.php';

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);

    $games = catalog_all(
        $db,
        'SELECT g.id,g.name,g.slug,p.engine_key,'
        . '(SELECT COUNT(*) FROM ue_files f WHERE f.game_id=g.id AND f.scan_status="verified" AND LOWER(f.extension)="upk") upk_count,'
        . '(SELECT COALESCE(SUM(f.file_size),0) FROM ue_files f WHERE f.game_id=g.id AND f.scan_status="verified" AND LOWER(f.extension)="upk") upk_bytes,'
        . '(SELECT COALESCE(SUM(f.export_count),0) FROM ue_files f WHERE f.game_id=g.id AND f.scan_status="verified" AND LOWER(f.extension)="upk") export_count '
        . 'FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id '
        . 'WHERE UPPER(p.engine_key) LIKE "UE3%" ORDER BY g.name'
    );

    catalog_head('UE3 UPK Packages');
    catalog_page_header(
        'UE3 UPK Packages',
        'UE3 .upk containers are managed separately from other game files. Their original package is retained and every parsed internal export is listed and linked.',
        ['Games' => 'games.php', 'Upload Files' => 'profiled-upload.php', 'Background Jobs' => 'background-jobs.php']
    );

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>UE3 game UPK collections</h2>'
        . '<p>Select a game to switch between normal files and its original UPK package containers.</p></div></div><div class="ui-section__body">';
    if ($games === []) {
        echo CatalogUi::emptyState(
            'No UE3 games configured',
            'Create a game or assign a UE3 profile before cataloging UPK packages.',
            catalog_support_is_admin() ? ['label' => 'Game manager', 'href' => 'game-manager.php'] : null,
            '▤'
        );
    } else {
        echo '<div class="grid">';
        foreach ($games as $game) {
            echo '<div class="card"><h3>' . catalog_h((string)$game['name']) . '</h3>';
            echo '<p><strong>' . number_format((int)$game['upk_count']) . '</strong> UPK package(s)<br>'
                . number_format((int)$game['export_count']) . ' indexed internal exports<br>'
                . catalog_h(catalog_bytes((int)$game['upk_bytes'])) . ' retained UPK data</p>';
            echo '<p><a class="button primary" href="game-upks.php?id=' . (int)$game['id'] . '">View UPK packages</a> '
                . '<a class="button" href="game-files.php?id=' . (int)$game['id'] . '">View other files</a> '
                . '<a class="button" href="profiled-upload.php?game_id=' . (int)$game['id'] . '">Upload files</a></p></div>';
        }
        echo '</div>';
    }
    echo '</div></section>';

    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('UPK package error');
    }
    echo CatalogUi::alert('danger', $error->getMessage(), 'UPK package management could not be loaded.');
    catalog_foot();
}
