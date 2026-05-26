<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/../lib/CatalogSupport.php';
require_once __DIR__ . '/../lib/FederationAuth.php';

function tr_is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

function tr_csrf(): string
{
    $_SESSION['fed_transfer_run_csrf'] ??= bin2hex(random_bytes(16));
    return $_SESSION['fed_transfer_run_csrf'];
}

function tr_check_csrf(): void
{
    if (($_POST['csrf'] ?? '') !== ($_SESSION['fed_transfer_run_csrf'] ?? '')) {
        throw new RuntimeException('Bad CSRF token');
    }
}

function tr_incoming_dir(array $config): string
{
    $dir = rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR) . '/federation/incoming';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create federation incoming folder: ' . $dir);
    }
    return $dir;
}

function tr_safe_name(string $name): string
{
    $name = preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($name)) ?? 'download.bin';
    return $name !== '' ? $name : 'download.bin';
}

function run_one_parent_pull(PDO $db, array $config): array
{
    $job = catalog_one($db, 'SELECT j.*, p.site_name peer_name, p.site_url, p.peer_site_id, p.shared_secret_plain FROM ue_federation_transfer_jobs j JOIN ue_federation_peers p ON p.id=j.peer_id WHERE j.direction="parent_pull_from_child" AND j.status="queued" AND p.is_active=1 ORDER BY j.created_at ASC LIMIT 1');
    if (!$job) {
        return ['ok' => true, 'message' => 'No queued parent pull jobs.'];
    }

    $jobId = (int)$job['id'];
    $db->prepare('UPDATE ue_federation_transfer_jobs SET status="running", started_at=NOW(), attempts=attempts+1, last_error=NULL WHERE id=?')->execute([$jobId]);

    try {
        $secret = (string)$job['shared_secret_plain'];
        if ($secret === '') {
            throw new RuntimeException('Peer has no stored API secret.');
        }

        $url = rtrim((string)$job['site_url'], '/') . '/api/federation/download-file.php';
        $payload = ['remote_file_id' => (int)$job['remote_file_id']];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new RuntimeException('Could not encode download request payload.');
        }

        $timestamp = date('c');
        $nonce = fed_random_secret();
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $signature = fed_sign_request($secret, 'POST', $path, $timestamp, $nonce, $body);
        $headers = [
            'Content-Type: application/json',
            'User-Agent: UnrealFileCatalogFederation/1.0',
            'X-Site-Id: ' . fed_setting($db, 'site_id', ''),
            'X-Timestamp: ' . $timestamp,
            'X-Nonce: ' . $nonce,
            'X-Signature: ' . $signature,
        ];

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers) . "\r\n",
                'content' => $body,
                'timeout' => 300,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $remote = @fopen($url, 'rb', false, $context);
        if (!$remote) {
            throw new RuntimeException('Could not open remote download stream.');
        }

        $meta = stream_get_meta_data($remote);
        $wrapper = $meta['wrapper_data'] ?? [];
        $statusLine = is_array($wrapper) ? (string)($wrapper[0] ?? '') : '';
        if ($statusLine !== '' && !str_contains($statusLine, ' 200 ')) {
            $err = stream_get_contents($remote);
            fclose($remote);
            throw new RuntimeException('Remote returned ' . $statusLine . ': ' . substr((string)$err, 0, 500));
        }

        $incoming = tr_incoming_dir($config);
        $name = 'peer_' . (int)$job['peer_id'] . '_remote_' . (int)$job['remote_file_id'] . '_' . date('Ymd_His') . '.bin';
        $dest = $incoming . '/' . tr_safe_name($name);
        $out = fopen($dest, 'wb');
        if (!$out) {
            fclose($remote);
            throw new RuntimeException('Could not open local incoming file for write.');
        }

        $bytes = 0;
        $limit = (int)$job['speed_limit_kbps'];
        $chunkSize = 65536;
        while (!feof($remote)) {
            $chunk = fread($remote, $chunkSize);
            if ($chunk === false) {
                throw new RuntimeException('Remote read failed.');
            }
            if ($chunk === '') {
                break;
            }
            fwrite($out, $chunk);
            $bytes += strlen($chunk);
            $db->prepare('UPDATE ue_federation_transfer_jobs SET bytes_done=? WHERE id=?')->execute([$bytes, $jobId]);
            if ($limit > 0) {
                $seconds = strlen($chunk) / max(1, ($limit * 1024));
                usleep((int)max(0, $seconds * 1000000));
            }
        }
        fclose($out);
        fclose($remote);

        $md5 = md5_file($dest) ?: '';
        $sha1 = sha1_file($dest) ?: '';
        $relativeIncoming = 'storage/federation/incoming/' . basename($dest);

        $db->prepare('UPDATE ue_federation_transfer_jobs SET status="downloaded", bytes_done=?, incoming_path=?, downloaded_md5=?, downloaded_sha1=?, finished_at=NOW(), last_error=? WHERE id=?')->execute([$bytes, $relativeIncoming, $md5, $sha1, 'Downloaded to incoming: ' . basename($dest), $jobId]);
        fed_log($db, (int)$job['peer_id'], $jobId, 'INFO', 'PARENT_PULL_DOWNLOADED', 'Downloaded remote file ' . (int)$job['remote_file_id'] . ' to ' . basename($dest));

        $delay = (int)$job['wait_after_seconds'];
        if ($delay > 0) {
            sleep($delay);
        }

        return ['ok' => true, 'message' => 'Downloaded one job to federation incoming folder.', 'job_id' => $jobId, 'file' => basename($dest), 'bytes' => $bytes, 'md5' => $md5];
    } catch (Throwable $e) {
        $db->prepare('UPDATE ue_federation_transfer_jobs SET status="failed", finished_at=NOW(), last_error=? WHERE id=?')->execute([$e->getMessage(), $jobId]);
        fed_log($db, (int)$job['peer_id'], $jobId, 'ERROR', 'PARENT_PULL_FAIL', $e->getMessage());
        throw $e;
    }
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!tr_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        tr_check_csrf();
        $_SESSION['fed_transfer_run_result'] = run_one_parent_pull($db, $config);
        header('Location: transfer-run.php');
        exit;
    }

    catalog_head('Transfer Runner');

    if (!tr_is_admin()) {
        echo '<div class="card"><h1>Admin required</h1><p>Log in through <a href="../index.php?page=login">Admin Login</a>.</p></div>';
        catalog_foot();
        exit;
    }

    echo '<div class="card"><h1>Transfer Runner</h1><p class="muted">Runs one queued parent-pull download at a time. Downloaded jobs can now be imported using the federation import runner.</p><p><a class="button" href="admin.php">Federation admin</a> <a class="button" href="parent-pull.php">Parent pull queue</a> <a class="button" href="import-run.php">Import downloaded files</a> <a class="button" href="logs.php">Logs</a></p></div>';

    if (isset($_SESSION['fed_transfer_run_result'])) {
        echo '<div class="card"><h2>Last run</h2><pre class="mono">' . catalog_h(json_encode($_SESSION['fed_transfer_run_result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre></div>';
        unset($_SESSION['fed_transfer_run_result']);
    }

    $queued = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="queued"')['c'] ?? 0);
    echo '<div class="card"><h2>Run queue</h2><p>Queued jobs: <strong>' . $queued . '</strong></p><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(tr_csrf()) . '"><button>Run one queued job</button></form></div>';

    $jobs = catalog_all($db, 'SELECT j.*, p.site_name peer_name FROM ue_federation_transfer_jobs j JOIN ue_federation_peers p ON p.id=j.peer_id ORDER BY j.created_at DESC LIMIT 100');
    echo '<div class="card"><h2>Recent jobs</h2>';
    if (!$jobs) {
        echo '<p class="muted">No transfer jobs yet.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>Peer</th><th>Direction</th><th>Remote file</th><th>Status</th><th>Bytes</th><th>Incoming</th><th>Hashes</th><th>Message</th><th>Created</th></tr>';
        foreach ($jobs as $job) {
            echo '<tr><td class="mono">' . (int)$job['id'] . '</td><td>' . catalog_h($job['peer_name']) . '</td><td>' . catalog_h($job['direction']) . '</td><td class="mono">' . catalog_h($job['remote_file_id']) . '</td><td>' . catalog_h($job['status']) . '</td><td>' . catalog_h((int)$job['bytes_done'] . ' / ' . (int)$job['bytes_total']) . '</td><td class="mono small">' . catalog_h($job['incoming_path'] ?? '') . '</td><td class="mono small">MD5 ' . catalog_h($job['downloaded_md5'] ?? '') . '<br>SHA1 ' . catalog_h($job['downloaded_sha1'] ?? '') . '</td><td class="path">' . catalog_h($job['last_error']) . '</td><td>' . catalog_h($job['created_at']) . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Transfer runner error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
