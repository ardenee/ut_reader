<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/ExternalMirrors.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        catalog_check_csrf('mirror_queue');
        $action = (string)($_POST['action'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($action === 'approve') {
            $db->prepare('UPDATE ue_external_mirror_jobs SET status="queued" WHERE id=? AND status="waiting_admin"')->execute([$id]);
            $_SESSION['mirror_queue_flash'] = 'Mirror job approved/queued.';
        } elseif ($action === 'cancel') {
            $db->prepare('UPDATE ue_external_mirror_jobs SET status="cancelled", finished_at=NOW(), last_error="Cancelled by admin." WHERE id=? AND status IN ("queued","waiting_admin","failed")')->execute([$id]);
            $_SESSION['mirror_queue_flash'] = 'Mirror job cancelled.';
        } elseif ($action === 'retry') {
            $db->prepare('UPDATE ue_external_mirror_jobs SET status="queued", attempts=0, started_at=NULL, finished_at=NULL, last_error=NULL WHERE id=? AND status IN ("failed","cancelled")')->execute([$id]);
            $_SESSION['mirror_queue_flash'] = 'Mirror job retried.';
        } elseif ($action === 'expire_old') {
            $_SESSION['mirror_queue_flash'] = 'Expired ' . external_expire_old_links($db) . ' old active mirror link(s).';
        }
        header('Location: mirror-queue.php');
        exit;
    }

    if (!catalog_require_admin_page('External Mirror Queue')) {
        exit;
    }

    catalog_head('External Mirror Queue');
    catalog_flash($_SESSION['mirror_queue_flash'] ?? null);
    unset($_SESSION['mirror_queue_flash']);

    echo '<div class="card hero"><h1>External Mirror Queue</h1><p class="muted">Queued/pending mirror uploads. Manual provider jobs can be fulfilled by opening the job and pasting the external shared-provider link.</p><p><a class="button" href="dashboard.php">Catalog Admin</a> <a class="button" href="mirror-providers.php">Providers</a> <a class="button" href="mirror-links.php">Mirror Links</a></p><form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('mirror_queue')) . '"><button name="action" value="expire_old">Expire old active links</button></form></div>';

    $jobs = catalog_all($db, 'SELECT j.*, p.provider_name, p.provider_class, f.package_name, f.original_name, f.file_size, f.md5 FROM ue_external_mirror_jobs j LEFT JOIN ue_external_download_providers p ON p.id=j.provider_id JOIN ue_files f ON f.id=j.file_id ORDER BY FIELD(j.status,"waiting_admin","queued","uploading","failed","active","cancelled","expired"), j.created_at DESC LIMIT 500');
    echo '<div class="card"><h2>Jobs</h2>';
    if (!$jobs) {
        echo '<p class="muted">No mirror jobs found.</p>';
    } else {
        echo '<table><tr><th>ID</th><th>File</th><th>Provider</th><th>Status</th><th>Attempts</th><th>Error</th><th>Created</th><th>Action</th></tr>';
        foreach ($jobs as $j) {
            $actions = ['<a class="button" href="mirror-job.php?id=' . (int)$j['id'] . '">Open/Fulfil</a>'];
            if ((string)$j['status'] === 'waiting_admin') {
                $actions[] = '<button name="action" value="approve">Approve</button>';
            }
            if (in_array((string)$j['status'], ['queued','waiting_admin','failed'], true)) {
                $actions[] = '<button name="action" value="cancel">Cancel</button>';
            }
            if (in_array((string)$j['status'], ['failed','cancelled'], true)) {
                $actions[] = '<button name="action" value="retry">Retry</button>';
            }
            $formButtons = array_filter($actions, static fn($a) => str_starts_with($a, '<button'));
            $links = array_filter($actions, static fn($a) => !str_starts_with($a, '<button'));
            $form = implode(' ', $links);
            if ($formButtons) {
                $form .= ' <form method="post" style="display:inline"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('mirror_queue')) . '"><input type="hidden" name="id" value="' . (int)$j['id'] . '">' . implode(' ', $formButtons) . '</form>';
            }
            echo '<tr><td class="mono">' . (int)$j['id'] . '</td><td><a href="file-info.php?id=' . (int)$j['file_id'] . '" target="_blank">' . catalog_h($j['package_name'] . ' / ' . $j['original_name']) . '</a><br><span class="small">' . catalog_h(catalog_bytes((int)$j['file_size'])) . '</span></td><td>' . catalog_h(($j['provider_name'] ?? '') . ' / ' . ($j['provider_class'] ?? '')) . '</td><td>' . catalog_h($j['status']) . '</td><td>' . (int)$j['attempts'] . '</td><td class="path">' . catalog_h($j['last_error']) . '</td><td>' . catalog_h($j['created_at']) . '</td><td>' . $form . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Mirror queue error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
