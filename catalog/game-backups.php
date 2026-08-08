<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and processes Game Backups.
 * Why: Backup UI concerns stay in this page while durable-job reads and worker lifecycle are delegated.
 * Role: Web UI entry point for game backup export/import management.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogQueueWorkerStarter;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoBackgroundJobLookupQuery;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Storage\GameBackupStore;

catalog_start_session();

/** @param list<array<string,mixed>> $jobs */
function game_backup_is_active(array $jobs, string $backupKey): bool
{
    foreach ($jobs as $job) {
        $payload = json_decode((string)($job['payload_json'] ?? ''), true);
        if (is_array($payload) && hash_equals((string)($payload['backup_key'] ?? ''), $backupKey)) {
            return true;
        }
    }
    return false;
}

function game_backup_key(string $slug): string
{
    $slug = strtolower(trim($slug));
    $slug = preg_replace('/[^a-z0-9._-]+/', '-', $slug) ?? 'game';
    $slug = trim($slug, '-_.') ?: 'game';
    return $slug . '-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
}

/** @return array<string,mixed> */
function game_backup_start_worker(PDO $db, array $config, string $queueName, ?int $userId): array
{
    $state = (new CatalogQueueWorkerStarter($db, $config))->start($queueName, true, $userId);
    $error = trim((string)($state['worker_error'] ?? ''));
    if ($error !== '') {
        throw new RuntimeException($error);
    }
    return is_array($state['worker'] ?? null) ? $state['worker'] : [];
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Game Backups')) {
        exit;
    }

    $store = new GameBackupStore($config);
    $queueName = trim((string)($config['queue']['name'] ?? 'catalog')) ?: 'catalog';
    $queue = new PdoJobQueue($db);
    $jobLookup = new PdoBackgroundJobLookupQuery($db);
    $backupJobTypes = [JobType::EXPORT_GAME_BACKUP, JobType::IMPORT_GAME_BACKUP];
    $userId = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('game_backups');
        $action = strtolower(trim((string)($_POST['action'] ?? '')));

        if ($action === 'export') {
            $gameId = (int)($_POST['game_id'] ?? 0);
            $game = $gameId > 0 ? catalog_one($db, 'SELECT id,name,slug FROM ue_games WHERE id=?', [$gameId]) : null;
            if (!$game) {
                throw new RuntimeException('Choose a valid game to export.');
            }
            $backupKey = game_backup_key((string)$game['slug']);
            $jobId = $queue->enqueue(
                $queueName,
                JobType::EXPORT_GAME_BACKUP,
                [
                    'game_id' => $gameId,
                    'backup_key' => $backupKey,
                    'user_id' => $userId,
                ],
                20,
                null,
                'game-backup-export-' . $gameId,
                $userId,
                2
            );
            $worker = game_backup_start_worker($db, $config, $queueName, $userId);
            $_SESSION['game_backup_flash'] = 'Game backup queued as job #' . $jobId . ': ' . $backupKey
                . (!empty($worker['started']) ? '. The detached worker was started.' : '. The existing worker will process it.');
        } elseif ($action === 'import') {
            $backupKey = trim((string)($_POST['backup_key'] ?? ''));
            $gameId = (int)($_POST['game_id'] ?? 0);
            $manifest = $store->readManifest($backupKey);
            if ((string)($manifest['status'] ?? '') !== 'complete') {
                throw new RuntimeException('Only a completed backup can be imported.');
            }
            $game = $gameId > 0 ? catalog_one($db, 'SELECT id,name FROM ue_games WHERE id=?', [$gameId]) : null;
            if (!$game) {
                throw new RuntimeException('Choose a valid target game for the backup import.');
            }
            $jobId = $queue->enqueue(
                $queueName,
                JobType::IMPORT_GAME_BACKUP,
                [
                    'game_id' => $gameId,
                    'backup_key' => $backupKey,
                    'user_id' => $userId,
                    'strict_profile' => true,
                    'rebuild_dependencies' => isset($_POST['rebuild_dependencies']),
                ],
                25,
                null,
                'game-backup-import-' . $backupKey . '-' . $gameId,
                $userId,
                2
            );
            $worker = game_backup_start_worker($db, $config, $queueName, $userId);
            $_SESSION['game_backup_flash'] = 'Backup import queued as job #' . $jobId . ' for ' . (string)$game['name']
                . (!empty($worker['started']) ? '. The detached worker was started.' : '. The existing worker will process it.');
        } elseif ($action === 'delete') {
            $backupKey = trim((string)($_POST['backup_key'] ?? ''));
            if (game_backup_is_active($jobLookup->activeByTypes($backupJobTypes), $backupKey)) {
                throw new RuntimeException('This backup cannot be deleted while an export or import job is active.');
            }
            $store->delete($backupKey);
            $_SESSION['game_backup_flash'] = 'Deleted game backup: ' . $backupKey;
        } else {
            throw new RuntimeException('Unsupported game-backup action.');
        }

        header('Location: game-backups.php');
        exit;
    }

    $games = catalog_all(
        $db,
        'SELECT g.id,g.name,g.slug,p.engine_key,p.profile_name,'
        . '(SELECT COUNT(*) FROM ue_files f WHERE f.game_id=g.id AND f.scan_status="verified") file_count,'
        . '(SELECT COALESCE(SUM(f.file_size),0) FROM ue_files f WHERE f.game_id=g.id AND f.scan_status="verified") total_bytes '
        . 'FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id ORDER BY g.name'
    );
    $backups = $store->listBackups();
    $recentJobs = $jobLookup->recentByTypes($backupJobTypes, 20);
    $hasActiveBackupJobs = $jobLookup->hasActiveByTypes($backupJobTypes);

    catalog_head('Game Backups');
    catalog_page_header(
        'Game Backups',
        'Create independent file-copy backups with original names, recorded paths and legacy game-folder placement, then restore them through a queued import.',
        ['Background Jobs' => 'background-jobs.php', 'Game Admin' => 'game-manager.php', 'Local Source Scan' => 'source-scan.php']
    );

    if (isset($_SESSION['game_backup_flash'])) {
        catalog_flash((string)$_SESSION['game_backup_flash']);
        unset($_SESSION['game_backup_flash']);
    }
    if ($hasActiveBackupJobs) {
        echo '<div class="alert info" id="game-backup-auto-refresh">A backup export or import is active. This page refreshes automatically every 5 seconds.</div>';
    }

    echo '<div class="card"><h2>Create game backup</h2>';
    echo '<p class="muted">Exports use normal file copies only. Recorded source folders are preserved; flat UE1/UE2 packages are placed into their standard Maps, System, Textures, Sounds, Music, StaticMeshes, Animations or Prefabs folders. Same-name variations remain beside each other as Name.ext, Name (2).ext, Name (3).ext and so on. No _Conflicts directory is created.</p>';
    echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('game_backups')) . '"><input type="hidden" name="action" value="export">';
    echo '<label>Game<br><select name="game_id" required><option value="">Choose game</option>';
    foreach ($games as $game) {
        echo '<option value="' . (int)$game['id'] . '">' . catalog_h((string)$game['name'])
            . ' — ' . (int)$game['file_count'] . ' files / ' . catalog_h(catalog_bytes((int)$game['total_bytes'])) . '</option>';
    }
    echo '</select></label> <button class="primary" type="submit">Build server backup</button></form>';
    echo '<p class="small muted">Backup root: <span class="mono">' . catalog_h($store->root()) . '</span></p></div>';

    echo '<div class="card"><h2>Exports currently on this server</h2>';
    if ($backups === []) {
        echo '<p>No game backups are present.</p></div>';
    } else {
        echo '<table><tr><th>Backup</th><th>Game</th><th>Status</th><th>Files</th><th>Size</th><th>Created</th><th>Server path</th><th>Restore / manage</th></tr>';
        foreach ($backups as $backup) {
            $state = is_array($backup['state'] ?? null) ? $backup['state'] : [];
            $status = (string)$backup['status'];
            $progress = '';
            if (!$backup['complete'] && (int)($state['files_total'] ?? 0) > 0) {
                $progress = ' (' . (int)($state['files_done'] ?? 0) . '/' . (int)$state['files_total'] . ')';
            }
            echo '<tr><td><strong>' . catalog_h((string)$backup['backup_key']) . '</strong><br><span class="small mono">manifest v1</span></td>';
            echo '<td>' . catalog_h((string)($backup['game_name'] ?: $backup['game_slug'])) . '</td>';
            echo '<td>' . catalog_h($status . $progress) . '</td>';
            echo '<td>' . number_format((int)$backup['entries'])
                . ((int)$backup['physical_files'] > 0 ? '<br><span class="small muted">' . number_format((int)$backup['physical_files']) . ' physical copies</span>' : '')
                . ((int)($backup['renamed_variations'] ?? 0) > 0 ? '<br><span class="small">' . number_format((int)$backup['renamed_variations']) . ' same-name variations renamed</span>' : '')
                . ((int)$backup['conflicts'] > 0 ? '<br><span class="small">' . number_format((int)$backup['conflicts']) . ' legacy conflict entries</span>' : '')
                . ((int)($backup['paths_from_locations'] ?? 0) > 0 ? '<br><span class="small muted">' . number_format((int)$backup['paths_from_locations']) . ' paths recovered from source locations</span>' : '') . '</td>';
            echo '<td class="nowrap">' . catalog_h(catalog_bytes((int)$backup['bytes'])) . '</td>';
            echo '<td class="nowrap">' . catalog_h((string)$backup['created_at']) . '</td>';
            echo '<td><span class="mono small">' . catalog_h((string)$backup['path']) . '</span></td><td>';

            if (!empty($backup['complete'])) {
                echo '<form method="post" style="margin-bottom:8px"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('game_backups')) . '"><input type="hidden" name="action" value="import"><input type="hidden" name="backup_key" value="' . catalog_h((string)$backup['backup_key']) . '">';
                echo '<select name="game_id" required>';
                foreach ($games as $game) {
                    $selected = ((int)$game['id'] === (int)$backup['game_id'] || (string)$game['slug'] === (string)$backup['game_slug']) ? ' selected' : '';
                    echo '<option value="' . (int)$game['id'] . '"' . $selected . '>' . catalog_h((string)$game['name']) . '</option>';
                }
                echo '</select><br><label class="small"><input type="checkbox" name="rebuild_dependencies" value="1" checked> rebuild dependencies once after restore</label><br><button type="submit">Import backup</button></form>';
            }
            echo '<form method="post" onsubmit="return confirm(\'Delete this complete backup directory and every copied file?\')"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('game_backups')) . '"><input type="hidden" name="action" value="delete"><input type="hidden" name="backup_key" value="' . catalog_h((string)$backup['backup_key']) . '"><button class="danger" type="submit">Delete export</button></form>';
            echo '</td></tr>';
        }
        echo '</table></div>';
    }

    echo '<div class="card"><h2>Recent backup jobs</h2>';
    if ($recentJobs === []) {
        echo '<p>No backup jobs have been created.</p>';
    } else {
        echo '<table><tr><th>Job</th><th>Operation</th><th>Status</th><th>Progress / result</th><th>Updated</th></tr>';
        foreach ($recentJobs as $job) {
            $progress = json_decode((string)($job['progress_json'] ?? ''), true);
            $result = json_decode((string)($job['result_json'] ?? ''), true);
            $detail = '';
            if (is_array($progress) && !empty($progress['message'])) {
                $detail = (string)$progress['message'];
            } elseif (is_array($result)) {
                $detail = 'Backup ' . (string)($result['backup_key'] ?? '')
                    . (isset($result['imported']) ? ': ' . (int)$result['imported'] . ' imported, ' . (int)($result['failed'] ?? 0) . ' failed' : '');
            } elseif (!empty($job['last_error'])) {
                $detail = (string)$job['last_error'];
            }
            echo '<tr><td><a href="background-jobs.php">#' . (int)$job['id'] . '</a></td><td>'
                . ((string)$job['job_type'] === JobType::EXPORT_GAME_BACKUP ? 'Export' : 'Import')
                . '</td><td>' . catalog_h((string)$job['status']) . '</td><td>' . catalog_h($detail) . '</td><td class="nowrap">'
                . catalog_h((string)$job['updated_at']) . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    echo '<div class="card"><h2>Restore behaviour</h2><p>The importer verifies every backup file against the manifest, makes a temporary working copy, and imports that copy. It uses the original logical filename from the manifest, so an exported Name (2).ext variation is restored under its recorded original name. It never moves, renames, hard-links, or modifies files inside the backup. Canonical files are restored before aliases, and dependency links are rebuilt once at the end when selected.</p></div>';

    if ($hasActiveBackupJobs) {
        echo <<<'HTML'
<script>
(() => {
    const refreshDelayMs = 5000;
    const scrollKey = 'unrealdb-game-backups-scroll-y';
    const previousScroll = sessionStorage.getItem(scrollKey);
    if (previousScroll !== null) {
        sessionStorage.removeItem(scrollKey);
        requestAnimationFrame(() => window.scrollTo(0, Number(previousScroll) || 0));
    }

    const refreshPage = () => {
        if (document.visibilityState !== 'visible') {
            return;
        }
        const activeElement = document.activeElement;
        if (activeElement && /^(INPUT|SELECT|TEXTAREA)$/.test(activeElement.tagName)) {
            window.setTimeout(refreshPage, refreshDelayMs);
            return;
        }
        sessionStorage.setItem(scrollKey, String(window.scrollY));
        window.location.reload();
    };

    window.setTimeout(refreshPage, refreshDelayMs);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible') {
            window.setTimeout(refreshPage, 250);
        }
    });
})();
</script>
HTML;
    }

    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('Game backup error');
    }
    echo '<div class="card"><h1>Game backup error</h1><p>' . catalog_h($error->getMessage()) . '</p><p><a class="button" href="game-backups.php">Back to game backups</a></p></div>';
    catalog_foot();
}
