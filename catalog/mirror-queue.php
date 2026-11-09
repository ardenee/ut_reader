<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and controls the external mirror queue.
 * Why: Mirror job lifecycle SQL is centralized in the mirror admin service instead of the page.
 * Role: Presentation adapter only.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogExternalMirrorAdminService;

catalog_start_session();

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $service = new CatalogExternalMirrorAdminService($db, $config);

    // Filter and pagination are bounded and preserved on maintenance POST.
    $status = strtolower(trim((string)($_REQUEST['status'] ?? 'waiting')));
    if (!in_array($status, ['waiting','failed','cancelled','active','all'],true)) $status='waiting';
    $pageSize = (int)($_REQUEST['page_size'] ?? 100);
    if (!in_array($pageSize,[50,100],true)) $pageSize=100;
    $requestedPage = max(1,(int)($_REQUEST['page'] ?? 1));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        catalog_check_csrf('mirror_queue');
        $flash = $service->handleQueueAction(
            (string)($_POST['action'] ?? ''),
            (int)($_POST['id'] ?? 0)
        );
        if ($flash !== '') {
            $_SESSION['mirror_queue_flash'] = $flash;
        }
        header('Location: mirror-queue.php?' . http_build_query([
            'status'=>$status,'page'=>$requestedPage,'page_size'=>$pageSize
        ]),true,303);
        exit;
    }

    if (!catalog_require_admin_page('External Mirror Queue')) {
        exit;
    }

    catalog_head('External Mirror Queue');
    catalog_flash($_SESSION['mirror_queue_flash'] ?? null);
    unset($_SESSION['mirror_queue_flash']);

    echo '<div class="card hero"><h1>External Mirror Queue</h1><p class="muted">'
        . 'This is the external mirror-provider queue, separate from the background Jobs page. '
        . 'Manual provider jobs can be fulfilled by opening a job and supplying an external URL.</p>'
        . '<p><a class="button" href="dashboard.php">Catalog Admin</a> '
        . '<a class="button" href="mirror-providers.php">Providers</a> '
        . '<a class="button" href="mirror-links.php">Mirror Links</a> '
        . '<a class="button" href="dashboard-detail.php?metric=mirrorWaiting#queue-controls">'
        . 'Cancel pending / purge cancelled jobs</a></p>'
        . '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('mirror_queue')) . '">'
        . '<input type="hidden" name="status" value="' . catalog_h($status) . '">'
        . '<input type="hidden" name="page" value="' . $requestedPage . '">'
        . '<input type="hidden" name="page_size" value="' . $pageSize . '">'
        . '<button name="action" value="expire_old">Expire old active links</button></form></div>';

    $pageData = $service->pageJobs($requestedPage, $pageSize, $status);
    $jobs = $pageData['rows'];
    $currentPage = (int)$pageData['page'];
    $pages = (int)$pageData['page_count'];
    $total = (int)$pageData['total'];
    $navigation = static function (int $current) use ($status,$pageSize): string {
        return 'mirror-queue.php?' . http_build_query([
            'status'=>$status,'page'=>$current,'page_size'=>$pageSize
        ]);
    };
    echo '<div class="card"><h2>Jobs (' . number_format($total) . ')</h2>'
        . '<p class="page-links">Status: ';
    foreach (['waiting'=>'Waiting','failed'=>'Failed','cancelled'=>'Cancelled','active'=>'Active','all'=>'All'] as $key=>$label) {
        echo '<a class="button secondary" href="mirror-queue.php?'
            . catalog_h(http_build_query(['status'=>$key,'page_size'=>$pageSize]))
            . '">' . catalog_h($label) . '</a> ';
    }
    echo '</p><p class="page-links">Show <a href="'
        . catalog_h('mirror-queue.php?'.http_build_query(['status'=>$status,'page_size'=>50]))
        . '">50</a> / <a href="'
        . catalog_h('mirror-queue.php?'.http_build_query(['status'=>$status,'page_size'=>100]))
        . '">100</a> per page. Page ' . $currentPage . ' of ' . $pages . '. ';
    if ($currentPage>1) echo '<a class="button secondary" href="' . catalog_h($navigation(1)) . '">First</a> '
        . '<a class="button secondary" href="' . catalog_h($navigation($currentPage-1)) . '">Previous</a> ';
    if ($currentPage<$pages) echo '<a class="button secondary" href="' . catalog_h($navigation($currentPage+1)) . '">Next</a> '
        . '<a class="button secondary" href="' . catalog_h($navigation($pages)) . '">Last</a>';
    echo '</p>';
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
            if ((string)$j['status'] === 'cancelled') {
                $actions[] = '<button class="danger" name="action" value="delete_cancelled">'
                    . 'Delete cancelled job</button>';
            }
            $formButtons = array_filter($actions, static fn($a) => str_starts_with($a, '<button'));
            $links = array_filter($actions, static fn($a) => !str_starts_with($a, '<button'));
            $form = implode(' ', $links);
            if ($formButtons) {
                $form .= ' <form method="post" style="display:inline" onsubmit="return confirm(\'Apply this mirror job action?\')">'
                    . '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('mirror_queue')) . '">'
                    . '<input type="hidden" name="status" value="' . catalog_h($status) . '">'
                    . '<input type="hidden" name="page" value="' . $currentPage . '">'
                    . '<input type="hidden" name="page_size" value="' . $pageSize . '">'
                    . '<input type="hidden" name="id" value="' . (int)$j['id'] . '">'
                    . implode(' ', $formButtons) . '</form>';
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
