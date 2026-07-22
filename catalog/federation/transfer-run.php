<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';

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

function tr_allow_self_signed_tls(PDO $db): bool
{
    return (string)fed_setting($db, 'allow_self_signed_federation_certificates', '0') === '1';
}

function tr_signed_download_context(PDO $db, array $job, string $url, array $payload): array
{
    $shared = (string)$job['shared_secret_plain'];
    if ($shared === '') {
        throw new RuntimeException('Peer has no stored API key.');
    }

    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        throw new RuntimeException('Could not encode download request payload.');
    }

    $timestamp = date('c');
    $nonce = fed_random_secret();
    $path = parse_url($url, PHP_URL_PATH) ?: '/';
    $signature = fed_sign_request($shared, 'POST', $path, $timestamp, $nonce, $body);
    $headers = [
        'Content-Type: application/json',
        'User-Agent: UnrealFileCatalogFederation/1.0',
        'X-Site-Id: ' . fed_setting($db, 'site_id', ''),
        'X-Timestamp: ' . $timestamp,
        'X-Nonce: ' . $nonce,
        'X-Signature: ' . $signature,
    ];
    $allowSelfSigned = tr_allow_self_signed_tls($db);

    return [
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers) . "\r\n",
            'content' => $body,
            'timeout' => 300,
            'ignore_errors' => true,
        ],
        'ssl' => [
            'verify_peer' => !$allowSelfSigned,
            'verify_peer_name' => !$allowSelfSigned,
            'allow_self_signed' => $allowSelfSigned,
        ],
    ];
}

function tr_job_download_url_payload(array $job): array
{
    if ((string)$job['direction'] === 'parent_pull_from_child') {
        return [
            rtrim((string)$job['site_url'], '/') . '/api/federation/download-file.php',
            ['remote_file_id' => (int)$job['remote_file_id']],
            'PARENT_PULL_DOWNLOADED',
        ];
    }

    if ((string)$job['direction'] === 'download_from_parent') {
        return [
            rtrim((string)$job['site_url'], '/') . '/api/federation/download-approved-file.php',
            ['request_item_id' => (int)$job['remote_request_item_id']],
            'CHILD_APPROVED_DOWNLOADED',
        ];
    }

    throw new RuntimeException('Unsupported queued transfer direction: ' . (string)$job['direction']);
}

function run_one_transfer(PDO $db, array $config): array
{
    $job = catalog_one($db, 'SELECT j.*, p.site_name peer_name, p.site_url, p.peer_site_id, p.shared_secret_plain FROM ue_federation_transfer_jobs j JOIN ue_federation_peers p ON p.id=j.peer_id WHERE j.status="queued" AND j.direction IN ("parent_pull_from_child","download_from_parent") AND p.is_active=1 ORDER BY j.created_at ASC LIMIT 1');
    if (!$job) {
        return ['ok' => true, 'message' => 'No queued transfer jobs.'];
    }

    $jobId = (int)$job['id'];
    $db->prepare('UPDATE ue_federation_transfer_jobs SET status="running", started_at=NOW(), attempts=attempts+1, last_error=NULL WHERE id=?')->execute([$jobId]);

    try {
        [$url, $payload, $logEvent] = tr_job_download_url_payload($job);
        $context = stream_context_create(tr_signed_download_context($db, $job, $url, $payload));
        $remote = @fopen($url, 'rb', false, $context);
        if (!$remote) {
            $lastError = error_get_last();
            $detail = is_array($lastError) ? trim((string)($lastError['message'] ?? '')) : '';
            throw new RuntimeException('Could not open remote download stream' . ($detail !== '' ? ': ' . $detail : '.'));
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
        $name = 'peer_' . (int)$job['peer_id'] . '_' . (string)$job['direction'] . '_remote_' . (int)$job['remote_file_id'] . '_item_' . (int)($job['remote_request_item_id'] ?? 0) . '_' . date('Ymd_His') . '.bin';
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
        fed_log($db, (int)$job['peer_id'], $jobId, 'INFO', $logEvent, 'Downloaded remote file ' . (int)$job['remote_file_id'] . ' to ' . basename($dest));

        $delay = (int)$job['wait_after_seconds'];
        if ($delay > 0) {
            sleep($delay);
        }

        return ['ok' => true, 'message' => 'Downloaded one queued transfer job.', 'job_id' => $jobId, 'direction' => (string)$job['direction'], 'file' => basename($dest), 'bytes' => $bytes, 'md5' => $md5];
    } catch (Throwable $e) {
        $db->prepare('UPDATE ue_federation_transfer_jobs SET status="failed", finished_at=NOW(), last_error=? WHERE id=?')->execute([$e->getMessage(), $jobId]);
        fed_log($db, (int)$job['peer_id'], $jobId, 'ERROR', 'TRANSFER_FAIL', $e->getMessage());
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
        catalog_check_csrf('fed_transfer_run');
        $_SESSION['fed_transfer_run_result'] = run_one_transfer($db, $config);
        header('Location: transfer-run.php');
        exit;
    }

    if (!catalog_require_admin_page('Transfer Runner')) {
        exit;
    }

    catalog_head('Transfer Runner');
    catalog_page_header('Transfer Runner', 'Runs one queued federation download at a time: parent pulls from children, or children download approved files from parent.', catalog_federation_links() + ['Parent Pull Queue' => 'parent-pull.php', 'Approved Downloads' => 'approved-downloads.php', 'Import Downloaded Files' => 'import-run.php']);
    if (tr_allow_self_signed_tls($db)) {
        echo CatalogUi::alert('warning', 'Self-signed federation certificates are allowed for outbound transfers. Certificate trust and hostname verification are disabled.', 'Testing TLS mode enabled');
    }

    if (isset($_SESSION['fed_transfer_run_result'])) {
        echo '<div class="card"><h2>Last run</h2><pre class="mono">' . catalog_h(json_encode($_SESSION['fed_transfer_run_result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre></div>';
        unset($_SESSION['fed_transfer_run_result']);
    }

    $queued = (int)(catalog_one($db, 'SELECT COUNT(*) c FROM ue_federation_transfer_jobs WHERE status="queued"')['c'] ?? 0);
    echo '<div class="card"><h2>Run queue</h2><p>Queued jobs: <strong>' . $queued . '</strong></p><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_transfer_run')) . '"><button>Run one queued job</button></form></div>';

    $jobs = catalog_all($db, 'SELECT j.*, p.site_name peer_name FROM ue_federation_transfer_jobs j JOIN ue_federation_peers p ON p.id=j.peer_id ORDER BY j.created_at DESC LIMIT 100');
    echo '<div class="card"><h2>Recent jobs</h2>';
    if (!$jobs) {
        echo '<p class="muted">No transfer jobs yet.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>Peer</th><th>Direction</th><th>Remote item</th><th>Remote file</th><th>Status</th><th>Bytes</th><th>Incoming</th><th>Hashes</th><th>Message</th><th>Created</th></tr>';
        foreach ($jobs as $job) {
            echo '<tr><td class="mono">' . (int)$job['id'] . '</td><td>' . catalog_h($job['peer_name']) . '</td><td>' . catalog_h($job['direction']) . '</td><td class="mono">' . catalog_h($job['remote_request_item_id']) . '</td><td class="mono">' . catalog_h($job['remote_file_id']) . '</td><td>' . catalog_h($job['status']) . '</td><td>' . catalog_h((int)$job['bytes_done'] . ' / ' . (int)$job['bytes_total']) . '</td><td class="mono small">' . catalog_h($job['incoming_path'] ?? '') . '</td><td class="mono small">MD5 ' . catalog_h($job['downloaded_md5'] ?? '') . '<br>SHA1 ' . catalog_h($job['downloaded_sha1'] ?? '') . '</td><td class="path">' . catalog_h($job['last_error']) . '</td><td>' . catalog_h($job['created_at']) . '</td></tr>';
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
