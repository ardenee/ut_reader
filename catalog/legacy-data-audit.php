<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for UE1 / UE2 / UE3 Data Audit.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Legacy Data Audit')) {
        exit;
    }

    $games = catalog_all(
        $db,
        'SELECT g.id,g.name,p.engine_key,COUNT(f.id) file_count'
        . ' FROM ue_games g'
        . ' JOIN ue_game_profiles p ON p.id=g.profile_id'
        . ' LEFT JOIN ue_files f ON f.game_id=g.id AND f.scan_status="verified"'
        . ' WHERE UPPER(p.engine_key) IN ("UE1","UE2","UE3")'
        . ' GROUP BY g.id,g.name,p.engine_key ORDER BY p.engine_key,g.name'
    );
    $selectedGameId = filter_input(INPUT_GET, 'game_id', FILTER_VALIDATE_INT);
    $selectedGameId = ($selectedGameId === false || $selectedGameId === null || $selectedGameId < 1)
        ? (int)($games[0]['id'] ?? 0)
        : (int)$selectedGameId;

    catalog_head('Legacy Data Audit');
    echo CatalogUi::pageHeader(
        'UE1 / UE2 / UE3 Data Audit',
        'Read-only verification against a fresh parse of each stored package. No database rows or package files are changed.',
        ['Back to dashboard' => 'dashboard.php']
    );

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Select game</h2>'
        . '<p>The audit compares Names, Imports, Exports, paths, counts, outer references and dependency projections.</p></div></div><div class="ui-section__body">';
    if ($games === []) {
        echo CatalogUi::emptyState('No UE1/UE2/UE3 games', 'No legacy-engine games are currently assigned to an active profile.');
    } else {
        echo '<form id="legacy-audit-form" method="get">';
        echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('legacy-data-audit')) . '">';
        echo '<label>Game <select id="legacy-audit-game" name="game_id">';
        foreach ($games as $game) {
            $id = (int)$game['id'];
            echo '<option value="' . $id . '"' . ($id === $selectedGameId ? ' selected' : '') . '>'
                . catalog_h((string)$game['name']) . ' — ' . catalog_h((string)$game['engine_key'])
                . ' — ' . (int)$game['file_count'] . ' files</option>';
        }
        echo '</select></label> ';
        echo CatalogUi::button('Audit selected game', ['type' => 'submit']);
        echo '</form>';
    }
    echo '</div></section>';

    echo '<section class="ui-section" id="legacy-audit-results-section"><div class="ui-section__header"><div><h2>Audit results</h2>'
        . '<p id="legacy-audit-summary">No audit has been run in this browser session.</p></div></div><div class="ui-section__body">';
    echo '<div id="legacy-audit-results"></div>';
    echo '</div></section>';

    echo '<script src="assets/catalog-long-job.js"></script>';
    echo '<script src="assets/legacy-data-audit.js"></script>';
    catalog_foot();
} catch (Throwable $error) {
    catalog_head('Legacy Data Audit Error');
    echo CatalogUi::alert('danger', $error->getMessage(), 'Audit page failed');
    catalog_foot();
}
