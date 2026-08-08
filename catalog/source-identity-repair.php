<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders Source Identity Repair and queues durable repair jobs.
 * Why: Mounted-source fallback reads and canonical identity audit logic now belong to an Infrastructure query model.
 * Role: Presentation adapter; durable mutations remain in the existing background-job repair workflow.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoSourceIdentityAuditQuery;

function source_identity_repair_get_int(string $name): int
{
    $value = filter_input(INPUT_GET, $name, FILTER_VALIDATE_INT);
    return ($value === false || $value === null || $value < 1) ? 0 : (int)$value;
}

try {
    catalog_start_session();
    if (!catalog_support_is_admin()) {
        throw new RuntimeException('Administrator login is required.');
    }

    $config = catalog_config();
    $db = catalog_db($config);
    $audit = new PdoSourceIdentityAuditQuery($db);
    $games = $audit->games();
    $selectedGameId = source_identity_repair_get_int('game_id');
    if ($selectedGameId === 0 && $games !== []) {
        $selectedGameId = (int)$games[0]['id'];
    }
    $selectedGame = null;
    foreach ($games as $game) {
        if ((int)$game['id'] === $selectedGameId) {
            $selectedGame = $game;
            break;
        }
    }
    $repairSupported = $audit->repairSupported($selectedGame);
    $mismatches = $selectedGameId > 0 ? $audit->mismatches($selectedGameId) : [];

    catalog_head('Source Identity Repair');
    echo <<<'CSS'
<style>
.source-identity-actions { display:flex; gap:10px; flex-wrap:wrap; align-items:end; }
.source-identity-actions label { display:grid; gap:6px; }
.source-identity-actions input[type="number"] { width:190px; }
.source-identity-overlay { position:fixed; inset:0; z-index:1000; display:grid; place-items:center; padding:20px; background:rgba(3,8,18,.72); backdrop-filter:blur(3px); }
.source-identity-dialog { width:min(780px,100%); padding:24px; border:1px solid var(--line2); border-radius:14px; background:#111b2d; box-shadow:0 24px 70px rgba(0,0,0,.5); }
.source-identity-dialog h2 { margin:0 0 8px; }
.source-identity-message { margin:0 0 16px; }
.source-identity-progress { height:14px; overflow:hidden; border:1px solid var(--line2); border-radius:999px; background:rgba(255,255,255,.05); }
.source-identity-progress > span { display:block; width:0; height:100%; border-radius:inherit; background:linear-gradient(90deg,#76a9ff,#9dc2ff); transition:width .18s linear; }
.source-identity-count,.source-identity-summary { margin-top:10px; color:var(--muted); font-size:13px; white-space:pre-wrap; }
.source-identity-failures { max-height:240px; overflow:auto; margin-top:14px; padding:10px 14px; border:1px solid rgba(255,107,122,.55); border-radius:8px; color:#ffd9de; background:rgba(255,107,122,.1); white-space:pre-wrap; }
.source-identity-dialog-actions { display:flex; gap:8px; margin-top:16px; }
</style>
CSS;

    echo CatalogUi::pageHeader(
        'Source Identity Repair',
        'Audit canonical package identities immediately and queue UE4/UE5 repairs through the durable worker. Repair jobs continue if this page closes and can be cancelled at safe checkpoints.',
        ['Back to games' => 'games.php']
    );

    echo '<div id="source-identity-job-root"'
        . ' data-action-url="api/v1/job-action.php"'
        . ' data-status-url="api/v1/job-status.php"'
        . ' data-csrf="' . catalog_h(catalog_csrf('job_action')) . '">';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Repair one UE4/UE5 file</h2>'
        . '<p>Use the numeric file ID from file-info.php or file-examine.php. The worker repairs canonical identity, aliases and affected dependency rows.</p></div></div><div class="ui-section__body">';
    echo '<form id="source-identity-file-form" class="source-identity-actions">';
    echo '<label>File ID <input type="number" min="1" name="file_id" required></label>';
    echo CatalogUi::button('Queue canonical identity repair', ['type' => 'submit']);
    echo '</form></div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Audit or repair a game</h2>'
        . '<p>Audit is read-only. A supported game repair rewrites derived identity fields for each verified package, then performs one dependency-only pass.</p></div></div><div class="ui-section__body">';
    echo '<form method="get" class="source-identity-actions">';
    echo '<label>Game <select name="game_id">';
    foreach ($games as $game) {
        $id = (int)$game['id'];
        $label = (string)$game['name'] . ((string)$game['engine_key'] !== '' ? ' (' . (string)$game['engine_key'] . ')' : '');
        echo '<option value="' . $id . '"' . ($id === $selectedGameId ? ' selected' : '') . '>' . catalog_h($label) . '</option>';
    }
    echo '</select></label>';
    echo CatalogUi::button('Audit canonical identities', ['type' => 'submit', 'variant' => 'secondary']);
    echo '</form>';

    if ($selectedGameId > 0 && $repairSupported) {
        echo '<form id="source-identity-game-form" class="source-identity-actions" style="margin-top:12px">';
        echo '<input type="hidden" name="game_id" value="' . $selectedGameId . '">';
        echo CatalogUi::button('Queue repair for this game', ['type' => 'submit']);
        echo '</form>';
    } elseif ($selectedGameId > 0) {
        echo CatalogUi::alert(
            'info',
            'Mounted source identity repair is intentionally limited to UE4/UE5. Use the read-only audit for legacy UE1/UE2/UE3 packages.',
            'Repair unavailable for this engine'
        );
    }
    echo '</div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Detected primary-path mismatches</h2>'
        . '<p>' . count($mismatches) . ' verified files have a stored package name that differs from the package name derived from their mounted source path.</p></div></div><div class="ui-section__body">';
    if ($mismatches === []) {
        echo CatalogUi::emptyState('No primary identity mismatches', 'All audited primary package names match their mounted source paths.', null, '✓');
    } else {
        echo '<div class="ui-table-region"><table><thead><tr><th>File ID</th><th>File</th><th>Stored package</th><th>Source-derived package</th><th>Mounted source path</th></tr></thead><tbody>';
        foreach ($mismatches as $file) {
            $id = (int)$file['id'];
            echo '<tr>';
            echo '<td class="mono">' . $id . '</td>';
            echo '<td><a href="file-info.php?id=' . $id . '">' . catalog_h((string)$file['original_name']) . '</a></td>';
            echo '<td class="mono">' . catalog_h((string)$file['package_name']) . '</td>';
            echo '<td class="mono">' . catalog_h((string)$file['canonical_package_name']) . '</td>';
            echo '<td class="mono small">' . catalog_h((string)$file['canonical_source_path']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></section>';
    echo '</div>';

    echo '<script src="assets/source-identity-repair-jobs.js"></script>';
    catalog_foot();
} catch (Throwable $error) {
    catalog_head('Source Identity Repair Error');
    echo CatalogUi::alert('danger', $error->getMessage(), 'Repair failed');
    catalog_foot();
}
