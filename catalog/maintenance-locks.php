<?php
declare(strict_types=1);


require_once __DIR__ . '/lib/CatalogSupport.php';

catalog_start_session();

const CATALOG_MAINTENANCE_WRITE_LOCK = 'unrealdb_catalog_maintenance_write_v1';

function maintenance_lock_thread_id(PDO $db): ?int
{
    $row = catalog_one($db, 'SELECT IS_USED_LOCK(?) thread_id', [CATALOG_MAINTENANCE_WRITE_LOCK]);
    $value = $row['thread_id'] ?? null;
    return $value === null ? null : (int)$value;
}

function maintenance_lock_current_thread_id(PDO $db): int
{
    return (int)(catalog_one($db, 'SELECT CONNECTION_ID() id')['id'] ?? 0);
}

function maintenance_lock_process(PDO $db, int $threadId): ?array
{
    if ($threadId <= 0) {
        return null;
    }

    try {
        return catalog_one($db, 'SELECT ID, USER, HOST, DB, COMMAND, TIME, STATE, INFO FROM information_schema.PROCESSLIST WHERE ID=?', [$threadId]);
    } catch (Throwable) {
        return null;
    }
}

function maintenance_lock_kill(PDO $db, int $threadId): string
{
    if ($threadId <= 0) {
        return 'No maintenance lock owner was found.';
    }

    $current = maintenance_lock_current_thread_id($db);
    if ($threadId === $current) {
        return 'Refusing to kill the current admin request connection.';
    }

    $db->exec('KILL ' . $threadId);
    usleep(250000);

    $remaining = maintenance_lock_thread_id($db);
    if ($remaining === null) {
        return 'Maintenance lock owner connection ' . $threadId . ' was killed and the lock is now free.';
    }

    return 'Kill was sent, but the lock is still held by MySQL connection ' . $remaining . '.';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Maintenance locks')) {
        exit;
    }

    $message = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('maintenance-locks');
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'kill') {
            $message = maintenance_lock_kill($db, maintenance_lock_thread_id($db) ?? 0);
        }
    }

    $threadId = maintenance_lock_thread_id($db);
    $process = $threadId !== null ? maintenance_lock_process($db, $threadId) : null;

    catalog_head('Maintenance locks');
    catalog_page_header(
        'Maintenance locks',
        'Inspect and clear the catalog maintenance MySQL advisory lock used by Full Sync, re-import, rebuild, and delete operations.',
        ['Full Sync' => 'full-sync.php', 'Dashboard' => 'dashboard.php']
    );

    if ($message !== '') {
        echo CatalogUi::alert('info', $message);
    }

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Current lock</h2><p class="muted">Lock name: <code>' . catalog_h(CATALOG_MAINTENANCE_WRITE_LOCK) . '</code></p></div></div><div class="ui-section__body">';
    if ($threadId === null) {
        echo CatalogUi::alert('success', 'Maintenance lock is free.', 'You can retry the re-import or Full Sync now.');
    } else {
        echo CatalogUi::alert('warning', 'Maintenance lock is currently held.', 'A previous Full Sync/re-import request may still be running or stuck. Killing the connection rolls back any active transaction on that connection.');
        echo '<table><tr><th>MySQL connection ID</th><td class="mono">' . (int)$threadId . '</td></tr>';
        if ($process) {
            echo '<tr><th>User</th><td>' . catalog_h((string)$process['USER']) . '</td></tr>';
            echo '<tr><th>Host</th><td>' . catalog_h((string)$process['HOST']) . '</td></tr>';
            echo '<tr><th>Database</th><td>' . catalog_h((string)($process['DB'] ?? '')) . '</td></tr>';
            echo '<tr><th>Command</th><td>' . catalog_h((string)$process['COMMAND']) . '</td></tr>';
            echo '<tr><th>Time</th><td class="mono">' . (int)$process['TIME'] . ' seconds</td></tr>';
            echo '<tr><th>State</th><td>' . catalog_h((string)($process['STATE'] ?? '')) . '</td></tr>';
            echo '<tr><th>Info</th><td class="mono path">' . catalog_h((string)($process['INFO'] ?? '')) . '</td></tr>';
        } else {
            echo '<tr><th>Process details</th><td class="muted">Unavailable. The database user may not have PROCESS visibility.</td></tr>';
        }
        echo '</table>';
        echo '<form method="post" onsubmit="return confirm(\'Kill the MySQL connection holding the maintenance lock? Only do this after stopping the stuck browser/Full Sync request.\')">';
        echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('maintenance-locks')) . '">';
        echo '<input type="hidden" name="action" value="kill">';
        echo '<p><button class="danger" type="submit">Force release maintenance lock</button></p>';
        echo '</form>';
    }
    echo '</div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Manual SQL fallback</h2></div></div><div class="ui-section__body">';
    echo '<p class="muted">If the web user cannot kill the connection, run this as a MySQL admin/root user in phpMyAdmin.</p>';
    echo '<pre class="mono">SELECT IS_USED_LOCK(\'' . catalog_h(CATALOG_MAINTENANCE_WRITE_LOCK) . '\') AS thread_id;
-- Then, if thread_id is not NULL:
KILL thread_id;</pre>';
    echo '</div></section>';

    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('Maintenance lock error');
    }
    echo CatalogUi::alert('danger', $error->getMessage(), 'Could not inspect or clear the maintenance lock.');
    catalog_foot();
}
