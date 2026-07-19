<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Dependency Refresh')) {
        exit;
    }

    $games = catalog_all($db, 'SELECT id,name FROM ue_games ORDER BY name');
    catalog_head('Dependency Refresh');
    echo <<<'CSS'
<style>
.dependency-refresh-choice { display:flex; align-items:end; gap:12px; flex-wrap:wrap; }
.dependency-refresh-choice label { display:grid; gap:6px; min-width:min(320px,100%); }
.dependency-refresh-choice input[type="number"] { width:180px; }
.dependency-refresh-overlay { position:fixed; inset:0; z-index:1000; display:grid; place-items:center; padding:20px; background:rgba(3,8,18,.72); backdrop-filter:blur(3px); }
.dependency-refresh-dialog { width:min(760px,100%); padding:24px; border:1px solid var(--line2); border-radius:14px; background:#111b2d; box-shadow:0 24px 70px rgba(0,0,0,.5); }
.dependency-refresh-dialog h2 { margin:0 0 8px; }
.dependency-refresh-message { margin:0 0 16px; }
.dependency-refresh-progress { height:14px; overflow:hidden; border:1px solid var(--line2); border-radius:999px; background:rgba(255,255,255,.05); }
.dependency-refresh-progress > span { display:block; width:0; height:100%; border-radius:inherit; background:linear-gradient(90deg,#76a9ff,#9dc2ff); transition:width .18s linear; }
.dependency-refresh-count { margin-top:9px; color:var(--muted); font-size:13px; }
.dependency-refresh-totals { margin-top:12px; font-family:ui-monospace,SFMono-Regular,Consolas,monospace; font-size:12px; color:var(--muted); white-space:pre-wrap; }
.dependency-refresh-failures { max-height:220px; overflow:auto; margin:14px 0 0; padding:10px 14px; border:1px solid rgba(255,107,122,.55); border-radius:8px; color:#ffd9de; background:rgba(255,107,122,.1); white-space:pre-wrap; }
.dependency-refresh-actions { display:flex; gap:8px; margin-top:16px; }
</style>
CSS;

    catalog_page_header(
        'Dependency Refresh',
        'Queue dependency resolution rebuilds without keeping a browser request open. Progress, cancellation, retries and worker recovery use the durable background-job system.',
        ['Dashboard' => 'dashboard.php', 'Asset Metadata Rebuild' => 'asset-metadata-rebuild.php']
    );

    echo '<div class="card"><h2>Refresh dependencies</h2>';
    echo '<form id="dependency-refresh-form" class="dependency-refresh-choice" method="post" action="dependency-refresh.php"'
        . ' data-action-url="api/v1/job-action.php"'
        . ' data-status-url="api/v1/job-status.php"'
        . ' data-csrf="' . catalog_h(catalog_csrf('job_action')) . '">';
    echo '<label>Single file ID<br><input type="number" name="file_id" min="1" placeholder="optional"></label>';
    echo '<label>Or full game<br><select name="game_id"><option value="0">Select game...</option>';
    foreach ($games as $game) {
        echo '<option value="' . (int)$game['id'] . '">' . catalog_h($game['name']) . '</option>';
    }
    echo '</select></label>';
    echo '<label>Start offset<br><input type="number" name="offset" min="0" value="0"></label>';
    echo '<button type="submit">Queue dependency refresh</button>';
    echo '</form>';
    echo '<p class="muted">A file ID rebuilds that file’s own dependency rows. A game refresh processes verified files from the optional offset. The job continues if this page closes; its URL retains the job ID until completion.</p>';
    echo '</div>';

    echo '<script src="assets/dependency-refresh-jobs.js"></script>';
    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Dependency Refresh error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
