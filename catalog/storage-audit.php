<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for Storage Audit.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogStorageAudit.php';

function storage_audit_int(string $key): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? 0 : max(0, (int)$value);
}

function storage_audit_url(int $gameId, bool $run = false): string
{
    $query = [];
    if ($gameId > 0) {
        $query['game_id'] = $gameId;
    }
    if ($run) {
        $query['run'] = '1';
    }
    return 'storage-audit.php' . ($query ? '?' . http_build_query($query) : '');
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Storage Audit')) {
        exit;
    }

    $games = catalog_all($db, 'SELECT id, name, slug FROM ue_games ORDER BY name');
    $selectedGameId = storage_audit_int('game_id');
    $validGameIds = array_map(static fn(array $game): int => (int)$game['id'], $games);
    if ($selectedGameId > 0 && !in_array($selectedGameId, $validGameIds, true)) {
        $selectedGameId = 0;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('storage-audit');
        $action = (string)($_POST['action'] ?? '');
        $token = (string)($_POST['token'] ?? '');
        $returnGameId = filter_input(INPUT_POST, 'return_game_id', FILTER_VALIDATE_INT);
        $returnGameId = $returnGameId === false || $returnGameId === null ? 0 : max(0, (int)$returnGameId);

        try {
            if ($action !== 'queue_orphan') {
                throw new RuntimeException('Unknown storage audit action.');
            }
            $result = storage_audit_queue_orphan($db, $config, $token);
            $_SESSION['storage_audit_flash'] = [
                'type' => 'success',
                'message' => 'Moved ' . $result['original_name'] . ' from ' . $result['game_name'] . ' verified storage into its unverified review queue. It has not been catalogued yet.',
            ];
        } catch (Throwable $error) {
            $_SESSION['storage_audit_flash'] = ['type' => 'danger', 'message' => $error->getMessage()];
        }

        header('Location: ' . storage_audit_url($returnGameId, true));
        exit;
    }

    $runAudit = (string)($_GET['run'] ?? '') === '1';
    $audit = $runAudit ? storage_audit_run($db, $config, $selectedGameId > 0 ? $selectedGameId : null) : null;
    $flash = $_SESSION['storage_audit_flash'] ?? null;
    unset($_SESSION['storage_audit_flash']);

    catalog_head('Storage Audit');
    echo <<<'CSS'
<style>
.storage-audit-filter { display:flex; align-items:end; gap:10px; flex-wrap:wrap; }
.storage-audit-filter label { display:grid; gap:5px; }
.storage-audit-stats { display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:12px; margin:0 0 18px; }
.storage-audit-note { border-left:4px solid #f6c453; padding-left:12px; }
.storage-audit-table { min-width:1220px; }
.storage-audit-table th, .storage-audit-table td { vertical-align:top; }
.storage-audit-path { min-width:310px; overflow-wrap:anywhere; }
.storage-audit-md5 { min-width:285px; white-space:nowrap; }
.storage-audit-match { min-width:250px; }
.storage-audit-actions { min-width:175px; }
.storage-audit-actions form { margin:0; }
.storage-audit-meta { display:block; margin-top:4px; }
@media (max-width:700px) { .storage-audit-stats { grid-template-columns:1fr; } }
</style>
CSS;
    echo CatalogUi::pageHeader(
        'Storage Audit',
        'Compare the physical verified-storage folders with database records. Use this after any manual file copy or cleanup.',
        ['Unverified Files' => 'unverified-files.php', 'Full Sync' => 'full-sync.php', 'Upload Files' => 'profiled-upload.php']
    );

    if (is_array($flash) && !empty($flash['message'])) {
        echo CatalogUi::alert((string)($flash['type'] ?? 'info'), (string)$flash['message']);
    }

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Run physical storage audit</h2><p>Scans only each selected game’s <span class="mono">verified/</span> storage folder. Unverified folders remain managed on Unverified Files.</p></div></div><div class="ui-section__body">';
    echo '<form class="storage-audit-filter" method="get"><input type="hidden" name="run" value="1"><label for="storage-audit-game">Game<select id="storage-audit-game" name="game_id"><option value="">All games</option>';
    foreach ($games as $game) {
        echo '<option value="' . (int)$game['id'] . '"' . ($selectedGameId === (int)$game['id'] ? ' selected' : '') . '>' . catalog_h((string)$game['name']) . '</option>';
    }
    echo '</select></label>' . CatalogUi::button('Run storage audit', ['type' => 'submit', 'variant' => 'secondary']) . '</form>';
    echo '<p class="storage-audit-note">An untracked physical file is not imported automatically. Queue it for review first, then use <strong>Unverified Files → Import selected</strong> when you choose its target game. A missing catalog-storage file is handled by that game’s Full Sync.</p>';
    echo '</div></section>';

    if ($audit !== null) {
        $orphanCount = count($audit['orphans']);
        $missingCount = count($audit['missing_catalog']);
        $totalOrphanBytes = array_sum(array_map(static fn(array $row): int => (int)$row['size'], $audit['orphans']));
        echo '<div class="storage-audit-stats">';
        echo '<div class="stat"><h2>' . (int)$audit['scanned_files'] . '</h2><p>Physical verified files scanned</p></div>';
        echo '<div class="stat"><h2>' . $orphanCount . '</h2><p>Untracked physical files</p><p class="muted small">' . catalog_h(catalog_bytes($totalOrphanBytes)) . '</p></div>';
        echo '<div class="stat"><h2>' . $missingCount . '</h2><p>Catalog rows with storage missing</p></div>';
        echo '</div>';

        echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Physical files not in the database</h2><p>These files exist in a verified folder but no <span class="mono">ue_files.relative_path</span> record points to them.</p></div></div><div class="ui-section__body">';
        if ($audit['orphans'] === []) {
            echo CatalogUi::emptyState('No untracked verified-storage files', 'Every scanned verified file has a matching catalog storage path.');
        } else {
            echo '<div class="table-wrap"><table class="storage-audit-table"><thead><tr><th>Game</th><th>Physical Path</th><th>MD5</th><th>Size</th><th>Catalog MD5 Check</th><th class="storage-audit-actions">Action</th></tr></thead><tbody>';
            foreach ($audit['orphans'] as $orphan) {
                $matches = $orphan['same_game_md5_matches'];
                echo '<tr>';
                echo '<td><strong>' . catalog_h((string)$orphan['game']['name']) . '</strong></td>';
                echo '<td class="mono small storage-audit-path">' . catalog_h((string)$orphan['storage_relative_path']) . '<span class="muted storage-audit-meta">Modified: ' . catalog_h(date('Y-m-d H:i', (int)$orphan['modified_at'])) . '</span></td>';
                echo '<td class="mono small storage-audit-md5">' . ($orphan['md5'] !== '' ? catalog_h((string)$orphan['md5']) : '<span class="muted">unavailable</span>') . '</td>';
                echo '<td class="mono small">' . catalog_h(catalog_bytes((int)$orphan['size'])) . '</td>';
                echo '<td class="storage-audit-match">';
                if ($matches === []) {
                    echo '<span class="muted">No same-game MD5 catalog match.</span>';
                } else {
                    echo '<strong>Extra physical copy.</strong><br>';
                    foreach ($matches as $match) {
                        echo '<a href="file-info.php?id=' . (int)$match['id'] . '">' . catalog_h((string)$match['original_name']) . '</a><br>';
                    }
                }
                echo '</td>';
                echo '<td class="storage-audit-actions"><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('storage-audit')) . '"><input type="hidden" name="action" value="queue_orphan"><input type="hidden" name="token" value="' . catalog_h((string)$orphan['token']) . '"><input type="hidden" name="return_game_id" value="' . $selectedGameId . '"><button type="submit" class="button secondary" title="Move this untracked verified file to the unverified review queue">Queue for review</button></form></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div></section>';

        echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Catalog records whose storage is missing</h2><p>These are database rows whose recorded stored file was not found in the verified folder during this audit.</p></div></div><div class="ui-section__body">';
        if ($audit['missing_catalog'] === []) {
            echo CatalogUi::emptyState('No missing catalog storage', 'Every catalog record in the selected scope has a physical verified-storage file.');
        } else {
            echo '<p class="storage-audit-note">Run Full Sync for the affected game to remove these stale database records and refresh dependency links.</p>';
            echo '<div class="table-wrap"><table><thead><tr><th>Game</th><th>Package</th><th>File</th><th>Recorded Storage Path</th><th>Status</th><th>Full Sync</th></tr></thead><tbody>';
            foreach ($audit['missing_catalog'] as $missing) {
                $file = $missing['file'];
                $game = $missing['game'];
                echo '<tr><td>' . catalog_h((string)$game['name']) . '</td><td class="mono">' . catalog_h((string)$file['package_name']) . '</td><td><a href="file-info.php?id=' . (int)$file['id'] . '">' . catalog_h((string)$file['original_name']) . '</a></td><td class="mono small">' . catalog_h((string)$file['relative_path']) . '</td><td>' . catalog_h((string)$file['scan_status']) . '</td><td><a class="button secondary" href="full-sync.php?game_id=' . (int)$game['id'] . '">Full Sync ' . catalog_h((string)$game['name']) . '</a></td></tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div></section>';
    }

    catalog_foot();
} catch (Throwable $e) {
    catalog_head('Storage Audit Error');
    echo CatalogUi::alert('danger', $e->getMessage(), 'The storage audit could not be completed.');
    catalog_foot();
}
