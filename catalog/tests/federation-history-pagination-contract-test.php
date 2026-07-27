<?php
declare(strict_types=1);

function federation_history_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$service = file_get_contents(__DIR__ . '/../src/Application/Federation/CatalogFederationHistoryPageService.php');
federation_history_expect(is_string($service), 'Federation history page service could not be read.');
federation_history_expect(
    str_contains($service, 'CatalogKeysetPaginator::decode(')
        && str_contains($service, 'CatalogKeysetPaginator::comparison(')
        && str_contains($service, 'CatalogKeysetPaginator::order(')
        && str_contains($service, "['first', 'next', 'previous', 'last']")
        && str_contains($service, '[50, 100, 250, 500]'),
    'Federation history service does not provide bounded signed cursor navigation.'
);

$requests = file_get_contents(__DIR__ . '/../federation/requests.php');
$parent = file_get_contents(__DIR__ . '/../federation/_requests-parent.php');
$child = file_get_contents(__DIR__ . '/../federation/_requests-child.php');
$api = file_get_contents(__DIR__ . '/../api/federation/request-status.php');
foreach ([$requests, $parent, $child, $api] as $source) {
    federation_history_expect(is_string($source), 'Federation request pagination source could not be read.');
}
federation_history_expect(
    str_contains($requests, 'fr_page_links(')
        && str_contains($parent, 'CatalogFederationHistoryPageService::fetch(')
        && str_contains($parent, 'request_cursor')
        && str_contains($parent, 'transfer_cursor')
        && !str_contains($parent, 'LIMIT 300')
        && !str_contains($parent, 'LIMIT 500'),
    'Parent request or Parent-pull history still uses fixed history limits.'
);
federation_history_expect(
    str_contains($api, "'request_page' => [")
        && str_contains($api, "'cursor' => (string)(\$payload['cursor'] ?? '')")
        && str_contains($api, 'CatalogFederationHistoryPageService::fetch(')
        && !str_contains($api, 'ORDER BY created_at DESC,id DESC LIMIT 200'),
    'Parent request-status API does not expose cursor-paged request history.'
);
federation_history_expect(
    str_contains($child, "'closed' => \$closed")
        && str_contains($child, "'request_cursor'")
        && str_contains($child, 'CatalogFederationHistoryPageService::fetch(')
        && !str_contains($child, 'LIMIT 300'),
    'Child request or transfer history is not cursor paginated.'
);

$queue = file_get_contents(__DIR__ . '/../federation/queue.php');
$diagnostics = file_get_contents(__DIR__ . '/../federation/diagnostics.php');
federation_history_expect(is_string($queue) && is_string($diagnostics), 'Transfer/log pagination source could not be read.');
federation_history_expect(
    str_contains($queue, 'CatalogFederationHistoryPageService::fetch(')
        && str_contains($queue, 'ft_page_links(')
        && !str_contains($queue, 'LIMIT 500'),
    'Federation transfer queue still uses a fixed history limit.'
);
federation_history_expect(
    str_contains($diagnostics, 'CatalogFederationHistoryPageService::fetch(')
        && str_contains($diagnostics, 'diagnostics_log_links(')
        && str_contains($diagnostics, 'log_cursor')
        && !str_contains($diagnostics, 'ORDER BY l.created_at DESC,l.id DESC LIMIT 1000'),
    'Federation log history still uses a fixed 1,000-row limit.'
);

$migration = file_get_contents(__DIR__ . '/../migrations/202607270009_federation_history_pagination.php');
federation_history_expect(is_string($migration), 'Federation history migration could not be read.');
foreach ([
    'idx_ue_federation_requests_history',
    'idx_ue_federation_requests_peer_history',
    'idx_ue_federation_request_items_history',
    'idx_ue_federation_transfer_history',
    'idx_ue_federation_transfer_peer_history',
    'idx_ue_federation_logs_history',
    'idx_ue_federation_logs_level_history',
    'idx_ue_federation_logs_peer_history',
] as $index) {
    federation_history_expect(str_contains($migration, $index), 'Missing federation history index: ' . $index);
}

fwrite(STDOUT, "Federation history pagination contract tests passed.\n");
