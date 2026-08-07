<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders or processes the federation interface for diagnostics conflicts.
 * Why: It keeps parent/child federation administration, inventory, requests, and transfer workflows separate from
 *      general catalog pages.
 * Role: Federation UI/administration entry point backed by shared federation services.
 * Audit: Federation-specific route; consolidate shared behavior into services rather than merging distinct
 *        parent/child screens blindly.
 */
declare(strict_types=1);

/** @param array<string,mixed> $filters @param array<string,mixed> $page */
function federation_diagnostics_conflict_links(
    array $filters,
    array $page,
    int $pageNo,
    int $pageCount,
    int $total
): string {
    $link = static function (string $label, string $move, int $targetPage, string $cursor = '') use ($filters): string {
        $query = $filters + [
            'tab' => 'conflicts',
            'conflict_move' => $move,
            'conflict_page' => $targetPage,
        ];
        if ($cursor !== '') {
            $query['conflict_cursor'] = $cursor;
        }
        return '<a class="button" href="diagnostics.php?' . catalog_h(http_build_query($query)) . '">'
            . catalog_h($label) . '</a>';
    };

    $html = '<p class="page-links"><span class="muted">Page ' . $pageNo . ' of ' . $pageCount
        . ' (' . $total . ' conflicts)</span> ';
    if ($pageNo > 1 && !empty($page['has_previous'])) {
        $html .= $link('First', 'first', 1) . ' '
            . $link('Previous', 'previous', max(1, $pageNo - 1), (string)($page['previous_cursor'] ?? '')) . ' ';
    }
    if ($pageNo < $pageCount && !empty($page['has_next'])) {
        $html .= $link('Next', 'next', min($pageCount, $pageNo + 1), (string)($page['next_cursor'] ?? '')) . ' '
            . $link('Last', 'last', $pageCount);
    }
    return rtrim($html) . '</p>';
}

$ignore = federation_ignore_base_game_files($db);
$peerId = max(0, (int)($_GET['peer_id'] ?? 0));
$pageSize = \UnrealDb\Catalog\Application\Federation\CatalogFederationHistoryPageService::normalizePageSize(
    (int)($_GET['page_size'] ?? 100)
);
$peers = catalog_all(
    $db,
    'SELECT id,site_name,peer_role FROM ue_federation_peers ORDER BY peer_role,site_name,id'
);
$total = \UnrealDb\Catalog\Application\Federation\CatalogFederationConflictListService::count(
    $db,
    $peerId,
    $ignore
);
$pageCount = max(1, (int)ceil($total / max(1, $pageSize)));
$move = strtolower(trim((string)($_GET['conflict_move'] ?? 'first')));
if ($move === 'prev') {
    $move = 'previous';
}
if (!in_array($move, ['first', 'next', 'previous', 'last'], true)) {
    $move = 'first';
}
$pageNo = max(1, min($pageCount, (int)($_GET['conflict_page'] ?? ($move === 'last' ? $pageCount : 1))));
if ($move === 'first') {
    $pageNo = 1;
} elseif ($move === 'last') {
    $pageNo = $pageCount;
}

$context = json_encode([
    'page' => 'federation-conflicts',
    'peer_id' => $peerId,
    'ignore_base_game' => $ignore,
    'limit' => $pageSize,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$cursorToken = trim((string)($_GET['conflict_cursor'] ?? ''));
$cursor = $cursorToken !== ''
    ? \UnrealDb\Catalog\Application\Pagination\CatalogKeysetPaginator::decode($config, $context, $cursorToken)
    : null;
if ($cursorToken !== '' && $cursor === null) {
    $move = 'first';
    $pageNo = 1;
}
$page = \UnrealDb\Catalog\Application\Federation\CatalogFederationConflictListService::fetch(
    $db,
    $peerId,
    $ignore,
    $pageSize,
    $cursor,
    $move
);
if ($page['rows'] === [] && $total > 0 && $move !== 'first') {
    $move = 'first';
    $pageNo = 1;
    $page = \UnrealDb\Catalog\Application\Federation\CatalogFederationConflictListService::fetch(
        $db,
        $peerId,
        $ignore,
        $pageSize,
        null,
        'first'
    );
}
$page['previous_cursor'] = is_array($page['first_cursor'])
    ? \UnrealDb\Catalog\Application\Pagination\CatalogKeysetPaginator::encode(
        $config,
        $context,
        $page['first_cursor']
    )
    : '';
$page['next_cursor'] = is_array($page['last_cursor'])
    ? \UnrealDb\Catalog\Application\Pagination\CatalogKeysetPaginator::encode(
        $config,
        $context,
        $page['last_cursor']
    )
    : '';
$filters = ['peer_id' => $peerId, 'page_size' => $pageSize];

// Double quotes are intentionally literal HTML delimiters inside these PHP
// single-quoted strings; no backslash characters are emitted to the browser.
echo '<div class="card"><h2>Identity Conflict Filters</h2><form method="get">'
    . '<input type="hidden" name="tab" value="conflicts">'
    . '<label>Peer <select name="peer_id"><option value="0">All peers</option>';
foreach ($peers as $peer) {
    echo '<option value="' . (int)$peer['id'] . '"'
        . ((int)$peer['id'] === $peerId ? ' selected' : '') . '>'
        . catalog_h($peer['peer_role'] . ' - ' . $peer['site_name']) . '</option>';
}
echo '</select></label> <label>Rows <select name="page_size">';
foreach ([50, 100, 250, 500] as $option) {
    echo '<option value="' . $option . '"' . ($pageSize === $option ? ' selected' : '') . '>'
        . $option . '</option>';
}
echo '</select></label> <button>Filter</button></form></div>';

echo '<div class="card"><h2>Identity Conflicts</h2><p>'
    . catalog_h(federation_base_game_policy_label($db)) . '</p>';
echo federation_diagnostics_conflict_links($filters, $page, $pageNo, $pageCount, $total);
if (!$page['rows']) {
    echo '<p class="muted">No matching conflicts.</p>';
} else {
    echo '<div class="table-wrap"><table><tr><th>Peer</th><th>Package</th><th>Peer file</th>'
        . '<th>Local file</th><th>Peer identity</th><th>Local identity</th><th>Sizes</th></tr>';
    foreach ($page['rows'] as $row) {
        echo '<tr><td>' . catalog_h($row['peer_name']) . '</td>'
            . '<td class="mono">' . catalog_h($row['package_name']) . '</td>'
            . '<td>' . catalog_h($row['original_name']) . '</td>'
            . '<td><a href="../file-info.php?id=' . (int)$row['local_id'] . '">'
            . catalog_h($row['local_file']) . '</a></td>';
        echo '<td class="mono small">GUID ' . catalog_h($row['package_guid'])
            . '<br>MD5 ' . catalog_h($row['md5'])
            . (!empty($row['sha1']) ? '<br>SHA1 ' . catalog_h($row['sha1']) : '') . '</td>';
        echo '<td class="mono small">GUID ' . catalog_h($row['local_guid'])
            . '<br>MD5 ' . catalog_h($row['local_md5'])
            . (!empty($row['local_sha1']) ? '<br>SHA1 ' . catalog_h($row['local_sha1']) : '') . '</td>';
        echo '<td class="nowrap">'
            . catalog_h(catalog_bytes((int)$row['file_size']) . ' / ' . catalog_bytes((int)$row['local_size']))
            . '</td></tr>';
    }
    echo '</table></div>';
}
echo federation_diagnostics_conflict_links($filters, $page, $pageNo, $pageCount, $total);
echo '</div>';
