<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogScanner.php';
require_once __DIR__ . '/lib/CatalogSourceIdentity.php';

function source_identity_repair_get_int(string $name): int
{
    $value = filter_input(INPUT_GET, $name, FILTER_VALIDATE_INT);
    return ($value === false || $value === null || $value < 1) ? 0 : (int)$value;
}

function source_identity_repair_source_path(PDO $db, array $file): string
{
    $path = catalog_source_identity_path((string)($file['source_relative_path'] ?? ''));
    if ($path !== '') {
        return $path;
    }

    $location = catalog_one(
        $db,
        'SELECT source_relative_path FROM ue_file_locations WHERE file_id=? AND source_relative_path<>"" ORDER BY id LIMIT 1',
        [(int)$file['id']]
    );
    return catalog_source_identity_path((string)($location['source_relative_path'] ?? ''));
}

/** @return list<array<string,mixed>> */
function source_identity_repair_audit(PDO $db, int $gameId): array
{
    $files = catalog_all(
        $db,
        'SELECT f.id,f.package_name,f.original_name,f.source_relative_path,f.detected_engine_key,p.engine_key profile_engine'
        . ' FROM ue_files f'
        . ' JOIN ue_games g ON g.id=f.game_id'
        . ' LEFT JOIN ue_game_profiles p ON p.id=g.profile_id'
        . ' WHERE f.game_id=? AND f.scan_status="verified"'
        . ' ORDER BY f.package_name,f.id',
        [$gameId]
    );

    $mismatches = [];
    foreach ($files as $file) {
        $engineKey = strtoupper(trim((string)($file['detected_engine_key'] ?? '')));
        if ($engineKey === '') {
            $engineKey = strtoupper(trim((string)($file['profile_engine'] ?? '')));
        }
        $sourcePath = source_identity_repair_source_path($db, $file);
        $canonical = catalog_source_identity_package_name(
            $engineKey,
            $sourcePath,
            (string)$file['original_name']
        );
        if ($canonical === '' || strcasecmp((string)$file['package_name'], $canonical) === 0) {
            continue;
        }
        $file['canonical_package_name'] = $canonical;
        $file['canonical_source_path'] = $sourcePath;
        $mismatches[] = $file;
    }

    return $mismatches;
}

try {
    catalog_start_session();
    if (!catalog_support_is_admin()) {
        throw new RuntimeException('Administrator login is required.');
    }

    $config = catalog_config();
    $db = catalog_db($config);
    $games = catalog_all(
        $db,
        'SELECT g.id,g.name,UPPER(COALESCE(p.engine_key,"")) engine_key '
        . 'FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id ORDER BY g.name'
    );
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
    $repairSupported = $selectedGame !== null
        && in_array((string)$selectedGame['engine_key'], ['UE4', 'UE5'], true);
    $mismatches = $selectedGameId > 0 ? source_identity_repair_audit($db, $selectedGameId) : [];

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
