<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Queues the administrator Full Sync workflow for one game.
 * Why: A game-wide rescan can take many hours and must run as durable background work rather than browser orchestration.
 * Role: Web UI entry point; the durable worker coordinates reimport, provider, dependency and final projection phases.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

function full_sync_int(string $key): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? 0 : max(0, (int)$value);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Full Sync')) {
        exit;
    }

    $games = catalog_all(
        $db,
        'SELECT g.id,g.name,COUNT(f.id) catalog_file_count,COALESCE(SUM(f.file_size),0) total_size'
        . ' FROM ue_games g'
        . ' LEFT JOIN ue_files f ON f.game_id=g.id AND f.scan_status="verified"'
        . ' GROUP BY g.id,g.name ORDER BY g.name'
    );
    $selectedGameId = full_sync_int('game_id');
    if ($selectedGameId === 0 && $games) {
        $selectedGameId = (int)$games[0]['id'];
    }
    $selectedGame = null;
    foreach ($games as $game) {
        if ((int)$game['id'] === $selectedGameId) {
            $selectedGame = $game;
            break;
        }
    }

    $queueName = trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog';

    catalog_head('Full Sync');
    echo <<<'CSS'
<style>
.full-sync-choice { display: flex; align-items: end; gap: 12px; flex-wrap: wrap; }
.full-sync-choice label { display: grid; gap: 6px; min-width: min(420px, 100%); }
.full-sync-scope { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin: 16px 0; }
.full-sync-scope .stat { min-height: auto; }
.full-sync-warning { border-left: 4px solid #f6c453; padding-left: 12px; }
.full-sync-start { margin-top: 16px; }
.full-sync-result { margin-top: 16px; }
.full-sync-result .button-row { margin-top: 10px; }
@media (max-width: 700px) { .full-sync-scope { grid-template-columns: 1fr; } }
</style>
CSS;
    echo CatalogUi::pageHeader(
        'Full Sync',
        'Queue a durable game-wide storage validation, re-import and dependency rebuild. You may leave this page immediately after the job is queued.',
        [
            'Background Jobs' => 'background-jobs.php?queue=' . rawurlencode($queueName),
            'System Errors' => 'system-errors.php',
            'Back to dashboard' => 'dashboard.php',
        ]
    );

    echo CatalogUi::alert(
        'info',
        'Full Sync now runs as a background job.',
        'The browser no longer performs or owns the sync. Progress, cancellation and terminal status are managed under Maintenance → Background Jobs.'
    );

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Select game</h2><p>Choose the game whose verified catalog packages should be checked against storage.</p></div></div><div class="ui-section__body">';
    if (!$games) {
        echo CatalogUi::emptyState(
            'No games available',
            'Create a game before running a full sync.',
            ['label' => 'Game Admin', 'href' => 'game-manager.php']
        );
    } else {
        echo '<form method="get" class="full-sync-choice">';
        echo '<label for="full-sync-game">Game<select id="full-sync-game" name="game_id">';
        foreach ($games as $game) {
            echo '<option value="' . (int)$game['id'] . '"'
                . ((int)$game['id'] === $selectedGameId ? ' selected' : '') . '>'
                . catalog_h($game['name']) . ' — ' . (int)$game['catalog_file_count']
                . ' verified packages</option>';
        }
        echo '</select></label>';
        echo CatalogUi::button('Choose game', ['type' => 'submit', 'variant' => 'secondary']);
        echo '</form>';
    }
    echo '</div></section>';

    if ($selectedGame !== null) {
        $count = (int)$selectedGame['catalog_file_count'];
        echo '<section class="ui-section"><div class="ui-section__header"><div><h2>'
            . catalog_h($selectedGame['name'])
            . '</h2><p>Durable storage validation, scanner rebuild and dependency reconciliation scope.</p></div></div>'
            . '<div class="ui-section__body">';
        echo '<div class="full-sync-scope">';
        echo '<div class="stat"><h2>' . $count . '</h2><p>Verified packages</p></div>';
        echo '<div class="stat"><h2>' . catalog_h(catalog_bytes((int)$selectedGame['total_size']))
            . '</h2><p>Recorded verified size</p></div>';
        echo '<div class="stat"><h2>1 job</h2><p>4 durable phases</p></div>';
        echo '</div>';
        echo '<p class="full-sync-warning"><strong>Background workflow:</strong> the worker checks each verified package, removes only genuinely missing stored packages, and re-imports existing packages while preserving their stable file IDs. It then rebuilds package providers, refreshes dependencies in bounded batches of up to 100 files, and finalizes dependency summaries and cached game counters. Individual package failures are recorded under <a href="system-errors.php">Maintenance → System Errors</a> and the job continues where safe.</p>';
        if ($count === 0) {
            echo '<p class="muted">This game has no verified catalog packages to sync.</p>';
        } else {
            echo '<form id="full-sync-form" class="full-sync-start" '
                . 'data-action-url="api/v1/full-sync-job.php" '
                . 'data-background-url="background-jobs.php?queue=' . catalog_h(rawurlencode($queueName)) . '" '
                . 'data-csrf="' . catalog_h(catalog_csrf('job_action')) . '">';
            echo '<input type="hidden" name="game_id" value="' . (int)$selectedGame['id'] . '">';
            echo CatalogUi::button(
                'Queue full sync for ' . $selectedGame['name'],
                ['type' => 'submit', 'variant' => 'danger']
            );
            echo '</form>';
            echo '<div id="full-sync-result" class="full-sync-result" aria-live="polite"></div>';
        }
        echo '</div></section>';
    }

    $fullSyncScriptVersion = @filemtime(__DIR__ . '/full-sync.js');
    echo '<script src="full-sync.js?v=' . (int)($fullSyncScriptVersion ?: 1) . '"></script>';
    catalog_foot();
} catch (Throwable $e) {
    catalog_head('Full Sync Error');
    echo CatalogUi::alert('danger', $e->getMessage(), 'The full sync page could not be loaded.');
    catalog_foot();
}
