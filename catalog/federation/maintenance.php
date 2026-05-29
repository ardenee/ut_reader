<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../lib/CatalogSupport.php';
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/ExternalMirrors.php';

function fm_csrf(): string
{
    $_SESSION['fed_maintenance_csrf'] ??= bin2hex(random_bytes(16));
    return $_SESSION['fed_maintenance_csrf'];
}

function fm_check_csrf(): void
{
    if (($_POST['csrf'] ?? '') !== ($_SESSION['fed_maintenance_csrf'] ?? '')) {
        throw new RuntimeException('Bad CSRF token');
    }
}

function fm_dir_size(string $dir): array
{
    $count = 0;
    $bytes = 0;
    if (!is_dir($dir)) {
        return ['count' => 0, 'bytes' => 0, 'files' => []];
    }
    $files = [];
    foreach (new DirectoryIterator($dir) as $file) {
        if ($file->isDot() || !$file->isFile()) {
            continue;
        }
        $count++;
        $size = $file->getSize();
        $bytes += $size;
        $files[] = ['name' => $file->getFilename(), 'size' => $size, 'mtime' => date('Y-m-d H:i:s', $file->getMTime())];
    }
    usort($files, static fn($a, $b) => strcmp($b['mtime'], $a['mtime']));
    return ['count' => $count, 'bytes' => $bytes, 'files' => $files];
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        fm_check_csrf();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'prune') {
            $nonceTtl = max(300, (int)(fed_setting($db, 'api_nonce_ttl_seconds', '300') ?: 300));
            $logDays = max(1, (int)(fed_setting($db, 'log_retention_days', '90') ?: 90));
            $nonceStmt = $db->prepare('DELETE FROM ue_federation_nonces WHERE created_at < DATE_SUB(NOW(), INTERVAL ? SECOND)');
            $nonceStmt->execute([$nonceTtl]);
            $logStmt = $db->prepare('DELETE FROM ue_federation_transfer_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)');
            $logStmt->execute([$logDays]);
            $mirror = external_mirror_maintenance($db);
            $_SESSION['fed_maintenance_flash'] = 'Pruned ' . $nonceStmt->rowCount() . ' nonce(s), ' . $logStmt->rowCount() . ' log row(s). Mirror maintenance: ' . json_encode($mirror, JSON_UNESCAPED_SLASHES);
            fed_log($db, null, null, 'INFO', 'MAINTENANCE_PRUNE', $_SESSION['fed_maintenance_flash']);
        } elseif ($action === 'mirror_only') {
            $mirror = external_mirror_maintenance($db);
            $_SESSION['fed_maintenance_flash'] = 'Mirror maintenance: ' . json_encode($mirror, JSON_UNESCAPED_SLASHES);
            fed_log($db, null, null, 'INFO', 'MIRROR_MAINTENANCE', $_SESSION['fed_maintenance_flash']);
        }
        header('Location: maintenance.php');
        exit;
    }

    if (!catalog_require_admin_page('Federation Maintenance')) {
        exit;
    }

    catalog_head('Federation Maintenance');
    catalog_flash($_SESSION['fed_maintenance_flash'] ?? null);
    unset($_SESSION['fed_maintenance_flash']);

    $nonceCount = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_nonces')['c'] ?? 0);
    $logCount = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_logs')['c'] ?? 0);
    $mirrorActive = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_external_download_links WHERE status="active"')['c'] ?? 0);
    $mirrorExpired = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_external_download_links WHERE status="expired"')['c'] ?? 0);
    $mirrorJobs = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_external_mirror_jobs WHERE status IN ("queued","waiting_admin","uploading")')['c'] ?? 0);
    $incomingDir = rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR) . '/federation/incoming';
    $incoming = fm_dir_size($incomingDir);
    $waiting = catalog_all($db, 'SELECT incoming_path FROM ue_federation_transfer_jobs WHERE incoming_path IS NOT NULL AND incoming_path<>"" AND status IN ("downloaded","running")');
    $known = [];
    foreach ($waiting as $row) {
        $known[basename((string)$row['incoming_path'])] = true;
    }

    catalog_page_header('Federation Maintenance', 'Prune old nonces/logs, run mirror maintenance, and review incoming federation storage.', catalog_federation_links() + ['Mirror Queue' => '../mirror-queue.php', 'Mirror Links' => '../mirror-links.php']);

    echo '<div class="grid">';
    catalog_stat_card('Stored API nonces', $nonceCount);
    catalog_stat_card('Federation log rows', $logCount);
    catalog_stat_card('Active mirror links', $mirrorActive, '', $mirrorActive > 0 ? 'good' : '');
    catalog_stat_card('Expired mirror links', $mirrorExpired);
    catalog_stat_card('Mirror jobs active/waiting', $mirrorJobs, '', $mirrorJobs > 0 ? 'attention' : '');
    catalog_stat_card('Incoming folder usage', catalog_bytes((int)$incoming['bytes']));
    catalog_stat_card('Incoming files', (int)$incoming['count']);
    echo '</div>';

    echo '<div class="card"><h2>Prune old API/log rows + mirror maintenance</h2><p class="muted">Uses api_nonce_ttl_seconds, log_retention_days, and external mirror expiry settings.</p><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(fm_csrf()) . '"><input type="hidden" name="action" value="prune"><button>Prune old nonces/logs and run mirror maintenance</button></form></div>';
    echo '<div class="card"><h2>Mirror maintenance only</h2><p class="muted">Expires stale active mirror links, moves ManualProvider queued jobs to waiting_admin, and fails stale uploading jobs.</p><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(fm_csrf()) . '"><input type="hidden" name="action" value="mirror_only"><button>Run mirror maintenance only</button></form></div>';

    echo '<div class="card"><h2>Incoming folder</h2><p class="mono path">' . catalog_h($incomingDir) . '</p>';
    if (!$incoming['files']) {
        echo '<p class="muted">No incoming files found.</p>';
    } else {
        echo '<table><tr><th>File</th><th>Size</th><th>Modified</th><th>Known waiting job?</th></tr>';
        foreach (array_slice($incoming['files'], 0, 300) as $file) {
            echo '<tr><td class="mono small">' . catalog_h($file['name']) . '</td><td>' . catalog_h(catalog_bytes((int)$file['size'])) . '</td><td>' . catalog_h($file['mtime']) . '</td><td>' . (isset($known[$file['name']]) ? 'yes' : 'possibly orphan/imported') . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Federation maintenance error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
