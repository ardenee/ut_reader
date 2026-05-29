<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../lib/CatalogSupport.php';
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/CatalogImport.php';

function fir_resolve_incoming_path(array $config, string $relativePath): string
{
    $root = realpath(rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR));
    $path = realpath(__DIR__ . '/../' . $relativePath);
    if (!$root || !$path || !str_starts_with($path, $root) || !is_file($path)) {
        throw new RuntimeException('Incoming file is missing or outside storage: ' . $relativePath);
    }
    return $path;
}

function fir_original_name(PDO $db, array $job): string
{
    if ((string)$job['direction'] === 'download_from_parent') {
        return basename((string)$job['incoming_path']);
    }
    $pf = catalog_one($db, 'SELECT original_name FROM ue_federation_peer_files WHERE peer_id=? AND remote_file_id=? ORDER BY id DESC LIMIT 1', [(int)$job['peer_id'], (int)$job['remote_file_id']]);
    if ($pf && trim((string)$pf['original_name']) !== '') {
        return (string)$pf['original_name'];
    }
    return basename((string)$job['incoming_path']);
}

function fir_game_id_for_profile_engine(PDO $db, string $engineKey): ?int
{
    $engineKey = strtoupper(trim($engineKey));
    if ($engineKey === '') {
        return null;
    }
    $game = catalog_one($db, 'SELECT g.id FROM ue_games g JOIN ue_game_profiles p ON p.game_id=g.id AND p.is_active=1 WHERE UPPER(p.engine_key)=? ORDER BY g.id LIMIT 1', [$engineKey]);
    return $game ? (int)$game['id'] : null;
}

function fir_preferred_game_id(PDO $db, array $job): ?int
{
    if ((string)$job['direction'] === 'download_from_parent') {
        return null;
    }
    $pf = catalog_one($db, 'SELECT game_id, remote_game_name, remote_engine_key FROM ue_federation_peer_files WHERE peer_id=? AND remote_file_id=? ORDER BY id DESC LIMIT 1', [(int)$job['peer_id'], (int)$job['remote_file_id']]);
    if ($pf && !empty($pf['game_id'])) {
        $game = catalog_one($db, 'SELECT id FROM ue_games WHERE id=?', [(int)$pf['game_id']]);
        if ($game) {
            return (int)$game['id'];
        }
    }
    if ($pf && !empty($pf['remote_engine_key'])) {
        return fir_game_id_for_profile_engine($db, (string)$pf['remote_engine_key']);
    }
    return null;
}

function fir_notify_parent(PDO $db, array $job, array $result, string $status): void
{
    if ((string)$job['direction'] !== 'download_from_parent' || empty($job['remote_request_item_id'])) {
        return;
    }

    $peer = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role="parent" AND is_active=1', [(int)$job['peer_id']]);
    if (!$peer || empty($peer['shared_secret_plain'])) {
        fed_log($db, (int)$job['peer_id'], (int)$job['id'], 'WARN', 'PARENT_STATUS_NOTIFY_SKIP', 'Parent peer missing or has no API secret.');
        return;
    }

    $payload = [
        'request_item_id' => (int)$job['remote_request_item_id'],
        'status' => $status === 'imported' ? 'imported' : 'failed',
        'child_local_file_id' => $result['file_id'] ?? null,
        'md5' => (string)($job['downloaded_md5'] ?? ''),
        'sha1' => (string)($job['downloaded_sha1'] ?? ''),
        'message' => (string)($result['message'] ?? $result['status'] ?? ''),
    ];

    $url = rtrim((string)$peer['site_url'], '/') . '/api/federation/request-item-status-update.php';
    $response = fed_http_post_signed($url, (string)fed_setting($db, 'site_id', ''), (string)$peer['shared_secret_plain'], $payload);
    fed_log($db, (int)$peer['id'], (int)$job['id'], !empty($response['ok']) ? 'INFO' : 'ERROR', 'PARENT_STATUS_NOTIFY', json_encode($response, JSON_UNESCAPED_SLASHES));
}

function run_one_import(PDO $db, array $config): array
{
    $job = catalog_one($db, 'SELECT * FROM ue_federation_transfer_jobs WHERE status="downloaded" AND incoming_path IS NOT NULL AND incoming_path<>"" ORDER BY finished_at ASC, id ASC LIMIT 1');
    if (!$job) {
        return ['ok' => true, 'message' => 'No downloaded jobs waiting for import.'];
    }

    $jobId = (int)$job['id'];
    $incoming = fir_resolve_incoming_path($config, (string)$job['incoming_path']);
    $md5 = md5_file($incoming) ?: '';
    $sha1 = sha1_file($incoming) ?: '';

    if (!empty($job['downloaded_md5']) && !hash_equals((string)$job['downloaded_md5'], $md5)) {
        throw new RuntimeException('MD5 mismatch before import for job ' . $jobId);
    }
    if (!empty($job['downloaded_sha1']) && !hash_equals((string)$job['downloaded_sha1'], $sha1)) {
        throw new RuntimeException('SHA1 mismatch before import for job ' . $jobId);
    }

    $db->prepare('UPDATE ue_federation_transfer_jobs SET status="running", started_at=COALESCE(started_at,NOW()), last_error=NULL WHERE id=?')->execute([$jobId]);

    try {
        $originalName = fir_original_name($db, $job);
        $preferredGameId = fir_preferred_game_id($db, $job);
        $result = catalog_import_file($db, $config, $incoming, $originalName, $preferredGameId, $_SESSION['user']['id'] ?? null);
        $status = ($result['status'] === 'verified' || str_starts_with((string)$result['status'], 'duplicate_')) ? 'imported' : 'failed';
        $db->prepare('UPDATE ue_federation_transfer_jobs SET status=?, local_file_id=?, finished_at=NOW(), last_error=? WHERE id=?')->execute([$status, $result['file_id'] ?? null, $result['message'] ?? $result['status'], $jobId]);
        fed_log($db, (int)$job['peer_id'], $jobId, $status === 'imported' ? 'INFO' : 'WARN', 'FEDERATION_IMPORT', json_encode($result, JSON_UNESCAPED_SLASHES));
        fir_notify_parent($db, $job, $result, $status);
        return ['ok' => true, 'job_id' => $jobId, 'result' => $result, 'notified_parent' => (string)$job['direction'] === 'download_from_parent'];
    } catch (Throwable $e) {
        $db->prepare('UPDATE ue_federation_transfer_jobs SET status="failed", finished_at=NOW(), last_error=? WHERE id=?')->execute([$e->getMessage(), $jobId]);
        fed_log($db, (int)$job['peer_id'], $jobId, 'ERROR', 'FEDERATION_IMPORT_FAIL', $e->getMessage());
        fir_notify_parent($db, $job, ['status' => 'failed', 'message' => $e->getMessage()], 'failed');
        throw $e;
    }
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        catalog_check_csrf('fed_import_run');
        $_SESSION['fed_import_result'] = run_one_import($db, $config);
        header('Location: import-run.php');
        exit;
    }

    if (!catalog_require_admin_page('Federation Import Runner')) {
        exit;
    }

    catalog_head('Federation Import Runner');
    catalog_page_header('Federation Import Runner', 'Imports one downloaded federation file into normal catalog storage/DB, rebuilds dependencies, marks the transfer job imported, and reports approved child-download imports back to the parent.', catalog_federation_links() + ['Transfer Runner' => 'transfer-run.php', 'Parent Pull Queue' => 'parent-pull.php', 'Approved Downloads' => 'approved-downloads.php']);

    if (isset($_SESSION['fed_import_result'])) {
        echo '<div class="card"><h2>Last import</h2><pre class="mono">' . catalog_h(json_encode($_SESSION['fed_import_result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre></div>';
        unset($_SESSION['fed_import_result']);
    }

    $waiting = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="downloaded" AND incoming_path IS NOT NULL AND incoming_path<>""')['c'] ?? 0);
    echo '<div class="card"><h2>Run import</h2><p>Downloaded jobs waiting for import: <strong>' . $waiting . '</strong></p><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_import_run')) . '"><button>Import one downloaded job</button></form></div>';

    $jobs = catalog_all($db, 'SELECT j.*, p.site_name peer_name FROM ue_federation_transfer_jobs j JOIN ue_federation_peers p ON p.id=j.peer_id WHERE j.status IN ("downloaded","imported","failed") ORDER BY j.finished_at DESC, j.id DESC LIMIT 100');
    echo '<div class="card"><h2>Recent downloaded/import jobs</h2>';
    if (!$jobs) {
        echo '<p class="muted">No downloaded/imported jobs yet.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>Peer</th><th>Direction</th><th>Remote item</th><th>Status</th><th>Incoming</th><th>Local file</th><th>Message</th><th>Finished</th></tr>';
        foreach ($jobs as $job) {
            $local = !empty($job['local_file_id']) ? '<a href="../file-info.php?id=' . (int)$job['local_file_id'] . '" target="_blank">file ' . (int)$job['local_file_id'] . '</a>' : '';
            echo '<tr><td class="mono">' . (int)$job['id'] . '</td><td>' . catalog_h($job['peer_name']) . '</td><td>' . catalog_h($job['direction']) . '</td><td class="mono">' . catalog_h($job['remote_request_item_id']) . '</td><td>' . catalog_h($job['status']) . '</td><td class="mono small">' . catalog_h($job['incoming_path']) . '</td><td>' . $local . '</td><td class="path">' . catalog_h($job['last_error']) . '</td><td>' . catalog_h($job['finished_at']) . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Federation import error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
