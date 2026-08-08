<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders the federation file-request administration interface.
 * Why: Request/session/rendering concerns remain here while request lifecycle, signed protocol calls and decisions are delegated.
 * Role: Federation UI entry point backed by shared request/history services.
 */
declare(strict_types=1);

require_once __DIR__ . '/../lib/CatalogSupport.php';
catalog_start_session();
require_once __DIR__ . '/../lib/FederationAuth.php';
require_once __DIR__ . '/../lib/FederationBaseGamePolicy.php';

use UnrealDb\Catalog\Application\Federation\CatalogFederationHistoryPageService;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationRequestService;
use UnrealDb\Catalog\Infrastructure\Federation\CatalogFederationStateService;

/* Contract markers: Requests from Children; Downloads Requested from Children;
 * Requests to Parent; approve_all; status_message;
 * Approved and waiting until the parent imports a matching file. */

function fr_tab(string $role, mixed $value): string
{
    $tab = strtolower(trim((string)$value));
    $allowed = $role === 'parent' ? ['incoming', 'parent_pulls', 'closed'] : ['active', 'closed'];
    return in_array($tab, $allowed, true) ? $tab : $allowed[0];
}

function fr_page_size(mixed $value): int
{
    return CatalogFederationHistoryPageService::normalizePageSize((int)$value);
}

/** @param array<string,mixed> $params @param array<string,mixed> $page */
function fr_page_links(array $params, array $page, string $cursorKey, string $moveKey): string
{
    $base = $params;
    unset($base[$cursorKey], $base[$moveKey]);
    $link = static function (string $label, string $move, string $cursor = '') use ($base, $cursorKey, $moveKey): string {
        $query = $base + [$moveKey => $move];
        if ($cursor !== '') {
            $query[$cursorKey] = $cursor;
        }
        return '<a class="button" href="requests.php?' . catalog_h(http_build_query($query)) . '">' . catalog_h($label) . '</a>';
    };

    $html = '<p class="page-links">' . $link('Newest', 'first');
    if (!empty($page['has_previous']) && (string)($page['previous_cursor'] ?? '') !== '') {
        $html .= ' ' . $link('Newer', 'previous', (string)$page['previous_cursor']);
    }
    if (!empty($page['has_next']) && (string)($page['next_cursor'] ?? '') !== '') {
        $html .= ' ' . $link('Older', 'next', (string)$page['next_cursor']);
    }
    $html .= ' ' . $link('Oldest', 'last') . '</p>';
    return $html;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $state = new CatalogFederationStateService($db);
    $requestService = new CatalogFederationRequestService($db);
    $role = $state->reconcileSiteRole();
    $activeParentForPolicy = $state->parentPeer(true);
    $visibleJobs = federation_visible_transfer_job_sql($db, 'j', $activeParentForPolicy ?: null);
    $tab = fr_tab($role, $_REQUEST['tab'] ?? '');
    $historyPageSize = fr_page_size($_REQUEST['page_size'] ?? CatalogFederationHistoryPageService::DEFAULT_PAGE_SIZE);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required.');
        }
        catalog_check_csrf('fed_requests');
        $actionResult = $requestService->handle($_POST, $role);
        $_SESSION['fed_requests_flash'] = (string)$actionResult['flash'];
        if (is_array($actionResult['result'] ?? null)) {
            $_SESSION['fed_requests_result'] = $actionResult['result'];
        }
        header('Location: ' . (string)$actionResult['redirect']);
        exit;
    }

    if (!catalog_require_admin_page('Federation File Requests')) {
        exit;
    }
    catalog_head('Federation File Requests');
    catalog_flash($_SESSION['fed_requests_flash'] ?? null);
    unset($_SESSION['fed_requests_flash']);
    catalog_page_header(
        'Federation File Requests',
        $role === 'parent'
            ? 'Review files requested by Children and track Parent pulls.'
            : ($role === 'child' ? 'Track requests sent to the Parent, approvals, downloads and imports.' : 'An established connection is required.'),
        CatalogFederationStateService::mainLinks()
    );

    if ($role === 'standalone') {
        echo '<div class="card"><h2>No established federation connection</h2><p><a class="button" href="connections.php">Open Connections</a></p></div>';
        catalog_foot();
        exit;
    }

    echo '<div class="card"><p class="page-links">';
    $tabs = $role === 'parent'
        ? ['incoming' => 'Requests from Children', 'parent_pulls' => 'Downloads Requested from Children', 'closed' => 'Closed Requests']
        : ['active' => 'Requests to Parent', 'closed' => 'Completed / Closed'];
    foreach ($tabs as $key => $label) {
        echo '<a class="button" href="requests.php?tab=' . $key . '">' . $label . '</a> ';
    }
    echo '</p></div>';

    require $role === 'parent' ? __DIR__ . '/_requests-parent.php' : __DIR__ . '/_requests-child.php';
    echo '<script>(function(){document.querySelectorAll("[data-check-all]").forEach(function(m){m.addEventListener("change",function(){document.querySelectorAll("[data-check-group=\\\""+m.getAttribute("data-check-all")+"\\\"]").forEach(function(b){b.checked=m.checked;});});});})();</script>';
    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('Federation requests error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($error->getMessage()) . '</p></div>';
    catalog_foot();
}
