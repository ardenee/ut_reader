<?php
declare(strict_types=1);


require_once __DIR__ . '/../lib/CatalogSupport.php';

catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';

function reqgen_items(PDO $db): array
{
    $rows = catalog_all($db, 'SELECT d.required_package, d.required_object_path, COUNT(*) use_count FROM ue_dependencies d JOIN ue_files f ON f.id=d.file_id WHERE d.status="missing" AND f.scan_status="verified" GROUP BY d.required_package, d.required_object_path ORDER BY use_count DESC, d.required_package, d.required_object_path');
    $items = [];
    foreach ($rows as $row) {
        $items[] = [
            'required_package' => (string)$row['required_package'],
            'required_object_path' => (string)$row['required_object_path'],
            'wanted_guid' => '',
            'wanted_md5' => '',
            'use_count' => (int)$row['use_count'],
        ];
    }
    return $items;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        catalog_check_csrf('fed_reqgen');
        $parentId = (int)($_POST['peer_id'] ?? 0);
        $parent = catalog_one($db, 'SELECT * FROM ue_federation_peers WHERE id=? AND peer_role="parent" AND is_active=1', [$parentId]);
        if (!$parent) {
            throw new RuntimeException('Active parent peer not found.');
        }
        $apiKey = (string)($parent['shared_secret_plain'] ?? '');
        if ($apiKey === '') {
            throw new RuntimeException('Parent peer has no stored API key.');
        }

        $items = reqgen_items($db);
        if (!$items) {
            throw new RuntimeException('No missing dependency rows found.');
        }

        $payload = [
            'title' => 'Missing dependency request from ' . (fed_setting($db, 'site_name', '') ?: fed_setting($db, 'site_url', '') ?: fed_setting($db, 'site_id', 'child')),
            'notes' => 'Generated from local ue_dependencies where status=missing.',
            'generated_at' => date('c'),
            'items' => $items,
        ];

        $url = rtrim((string)$parent['site_url'], '/') . '/api/federation/request-submit.php';
        $result = fed_http_post_signed($url, (string)fed_setting($db, 'site_id', ''), $apiKey, $payload);
        fed_log($db, (int)$parent['id'], null, !empty($result['ok']) ? 'INFO' : 'ERROR', 'REQUEST_SUBMIT_SEND', json_encode($result, JSON_UNESCAPED_SLASHES));
        $_SESSION['fed_reqgen_result'] = $result;
        header('Location: request-generate.php');
        exit;
    }

    if (!catalog_require_admin_page('Generate Missing Dependency Request')) {
        exit;
    }

    catalog_head('Generate Missing Dependency Request');
    catalog_page_header('Generate Missing Dependency Request', 'Child-side tool. Builds a request from local missing dependency rows and submits it to the configured parent. If the parent has an older submitted/approved request from this child, it is marked updated on the parent.', catalog_federation_links() + ['Peers' => 'peers.php', 'Request Status' => 'request-status.php', 'Approved Downloads' => 'approved-downloads.php']);

    if (isset($_SESSION['fed_reqgen_result'])) {
        echo '<div class="card"><h2>Last submit result</h2><pre class="mono">' . catalog_h(json_encode($_SESSION['fed_reqgen_result'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre></div>';
        unset($_SESSION['fed_reqgen_result']);
    }

    $items = reqgen_items($db);
    echo '<div class="card"><h2>Request preview</h2><p>Missing dependency items: <strong>' . count($items) . '</strong></p>';
    if ($items) {
        echo '<table><tr><th>Required package</th><th>Required object</th><th>Used by files</th></tr>';
        foreach (array_slice($items, 0, 300) as $item) {
            echo '<tr><td class="mono">' . catalog_h($item['required_package']) . '</td><td class="mono path">' . catalog_h($item['required_object_path']) . '</td><td>' . (int)$item['use_count'] . '</td></tr>';
        }
        echo '</table>';
        if (count($items) > 300) {
            echo '<p class="muted">Showing first 300 only. Full request will include all items.</p>';
        }
    }
    echo '</div>';

    $parents = catalog_all($db, 'SELECT * FROM ue_federation_peers WHERE peer_role="parent" AND is_active=1 ORDER BY site_name');
    echo '<div class="card"><h2>Submit to parent</h2>';
    if (!$parents) {
        echo '<p class="muted">No active parent peer configured.</p>';
    } elseif (!$items) {
        echo '<p class="muted">No missing dependencies to request.</p>';
    } else {
        echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('fed_reqgen')) . '"><p><label>Parent<br><select name="peer_id">';
        foreach ($parents as $parent) {
            echo '<option value="' . (int)$parent['id'] . '">' . catalog_h($parent['site_name'] . ' - ' . $parent['site_url']) . '</option>';
        }
        echo '</select></label></p><button>Submit missing dependency request</button></form>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Request generate error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
