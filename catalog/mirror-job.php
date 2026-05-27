<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/ExternalMirrors.php';

function mj_is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

function mj_csrf(): string
{
    $_SESSION['mirror_job_csrf'] ??= bin2hex(random_bytes(16));
    return $_SESSION['mirror_job_csrf'];
}

function mj_check_csrf(): void
{
    if (($_POST['csrf'] ?? '') !== ($_SESSION['mirror_job_csrf'] ?? '')) {
        throw new RuntimeException('Bad CSRF token');
    }
}

function mj_storage_path(array $config, array $file): string
{
    $path = realpath(__DIR__ . '/' . (string)$file['relative_path']);
    $root = realpath(rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR));
    if (!$path || !$root || !str_starts_with($path, $root) || !is_file($path)) {
        throw new RuntimeException('Stored file missing or outside storage.');
    }
    return $path;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!mj_is_admin()) {
        catalog_head('Admin required');
        echo '<div class="card"><h1>Admin required</h1><p>Log in through <a href="index.php?page=login">Admin Login</a>.</p></div>';
        catalog_foot();
        exit;
    }

    $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    $job = catalog_one($db, 'SELECT j.*, p.provider_name, p.provider_key, p.expiry_days provider_expiry_days, f.package_name, f.original_name, f.file_size, f.md5, f.sha1, f.package_guid, f.relative_path FROM ue_external_mirror_jobs j LEFT JOIN ue_external_download_providers p ON p.id=j.provider_id JOIN ue_files f ON f.id=j.file_id WHERE j.id=?', [$id]);
    if (!$job) {
        throw new RuntimeException('Mirror job not found.');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        mj_check_csrf();
        $url = trim((string)($_POST['external_url'] ?? ''));
        $expiryDays = (int)($_POST['expiry_days'] ?? 0);
        if ($url === '' || !preg_match('/^https?:\/\//i', $url)) {
            throw new RuntimeException('A valid http/https external URL is required.');
        }
        if (empty($job['provider_id'])) {
            throw new RuntimeException('Mirror job has no provider.');
        }
        if (!in_array((string)$job['status'], ['queued','waiting_admin','failed','uploading'], true)) {
            throw new RuntimeException('This job cannot be fulfilled from status: ' . (string)$job['status']);
        }

        $days = $expiryDays > 0 ? $expiryDays : (int)($job['provider_expiry_days'] ?: fed_setting($db, 'external_mirror_expiry_days', '7'));
        $db->beginTransaction();
        try {
            $linkId = external_create_manual_link($db, (int)$job['file_id'], (int)$job['provider_id'], $url, $_SESSION['user']['id'] ?? null, $days);
            $db->prepare('UPDATE ue_external_mirror_jobs SET status="active", link_id=?, finished_at=NOW(), last_error=? WHERE id=?')->execute([$linkId, 'Fulfilled manually with external mirror link.', (int)$job['id']]);
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        $_SESSION['mirror_job_flash'] = 'Mirror job fulfilled. Active external link created.';
        header('Location: mirror-job.php?id=' . (int)$job['id']);
        exit;
    }

    catalog_head('Fulfil Mirror Job');

    if (isset($_SESSION['mirror_job_flash'])) {
        echo '<div class="card"><strong>' . catalog_h($_SESSION['mirror_job_flash']) . '</strong></div>';
        unset($_SESSION['mirror_job_flash']);
    }

    $path = '';
    try {
        $path = mj_storage_path($config, $job);
    } catch (Throwable $e) {
        $path = 'missing: ' . $e->getMessage();
    }

    echo '<div class="card"><h1>Fulfil Mirror Job #' . (int)$job['id'] . '</h1><p class="muted">Upload this file to your chosen shared hosting provider, then paste the public URL below. This creates an active cached mirror link for public downloads.</p><p><a class="button" href="mirror-queue.php">Mirror Queue</a> <a class="button" href="mirror-links.php?file_id=' . (int)$job['file_id'] . '">Mirror Links</a> <a class="button" href="download.php?id=' . (int)$job['file_id'] . '">Test public download</a></p></div>';

    echo '<div class="card"><h2>File details</h2><table>';
    echo '<tr><th>Status</th><td>' . catalog_h($job['status']) . '</td></tr>';
    echo '<tr><th>Provider</th><td>' . catalog_h(($job['provider_name'] ?? '') . ' / ' . ($job['provider_key'] ?? '')) . '</td></tr>';
    echo '<tr><th>Package</th><td class="mono">' . catalog_h($job['package_name']) . '</td></tr>';
    echo '<tr><th>Original filename</th><td>' . catalog_h($job['original_name']) . '</td></tr>';
    echo '<tr><th>Size</th><td>' . catalog_h(catalog_bytes((int)$job['file_size'])) . '</td></tr>';
    echo '<tr><th>MD5</th><td class="mono">' . catalog_h($job['md5']) . '</td></tr>';
    echo '<tr><th>SHA1</th><td class="mono">' . catalog_h($job['sha1']) . '</td></tr>';
    echo '<tr><th>GUID</th><td class="mono">' . catalog_h($job['package_guid']) . '</td></tr>';
    echo '<tr><th>Admin storage path</th><td class="mono path">' . catalog_h($path) . '</td></tr>';
    echo '</table></div>';

    if (in_array((string)$job['status'], ['queued','waiting_admin','failed','uploading'], true)) {
        $defaultDays = (int)($job['provider_expiry_days'] ?: fed_setting($db, 'external_mirror_expiry_days', '7'));
        echo '<div class="card"><h2>Complete mirror job</h2><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(mj_csrf()) . '"><input type="hidden" name="id" value="' . (int)$job['id'] . '">';
        echo '<p><label>External shared-provider URL<br><input name="external_url" required style="min-width:760px" placeholder="https://..."></label></p>';
        echo '<p><label>Expiry/stale days<br><input name="expiry_days" value="' . $defaultDays . '" style="width:90px"></label> <span class="muted">Default comes from provider/settings. After this, the link is treated as stale/expired.</span></p>';
        echo '<p><button>Save external link and fulfil job</button></p></form></div>';
    } else {
        echo '<div class="card"><h2>Job not editable</h2><p class="muted">This job is already closed or cannot be fulfilled in its current status.</p></div>';
    }

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Mirror job error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
