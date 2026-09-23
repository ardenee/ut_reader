<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Implements the current paginated names/imports/exports examination view used by `file-examine.php`.
 * Why: It avoids loading very large package tables into one request and supersedes the older examination core.
 * Role: Active package-inspection UI core backed by `PdoPackageTablePageQuery`.
 * Audit: Current implementation; keep shared pagination logic here rather than restoring the older core.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoPackageTablePageQuery;

catalog_start_session();

function examine_int(string $key, int $default = 0): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? $default : max(0, (int)$value);
}

function examine_target(string $value): string
{
    return preg_replace('/[^A-Za-z0-9_-]+/', '', $value) ?? '';
}

function examine_target_table(string $target): string
{
    if (str_starts_with($target, 'import-')) {
        return 'imports';
    }
    if (str_starts_with($target, 'export-')) {
        return 'exports';
    }
    return 'names';
}

function examine_href(int $fileId, string $target, int $pageSize): string
{
    $target = examine_target($target);
    $table = examine_target_table($target);
    $index = PdoPackageTablePageQuery::targetIndex($target, $table) ?? 0;
    $page = PdoPackageTablePageQuery::pageForIndex($index, $pageSize);
    return 'file-examine.php?' . http_build_query([
        'id' => $fileId,
        'tab' => $table,
        'page' => $page,
        'page_size' => $pageSize,
        'target' => $target,
    ]) . '#' . rawurlencode($target);
}

function examine_tab_href(int $fileId, string $table, int $pageSize): string
{
    $table = strtolower(trim($table));
    if (!in_array($table, ['names','imports','exports','uses','used-by'], true)) {
        $table = 'names';
    }
    return 'file-examine.php?' . http_build_query([
        'id' => $fileId,
        'tab' => $table,
        'page_size' => $pageSize,
    ]) . '#package-tables';
}

function examine_page_href(int $fileId, string $table, int $page, int $pageSize): string
{
    return 'file-examine.php?' . http_build_query([
        'id' => $fileId,
        'tab' => $table,
        'page' => max(1, $page),
        'page_size' => $pageSize,
    ]) . '#package-tables';
}

function examine_back(int $gameId): string
{
    $candidate = trim((string)($_GET['return_to'] ?? $_SERVER['HTTP_REFERER'] ?? ''));
    $parts = $candidate !== '' ? parse_url($candidate) : false;
    if (is_array($parts) && basename((string)($parts['path'] ?? '')) === 'game-files.php') {
        parse_str((string)($parts['query'] ?? ''), $parameters);
        if ((int)($parameters['id'] ?? 0) === $gameId) {
            return 'game-files.php?' . http_build_query(array_intersect_key(
                $parameters,
                array_flip(['id','file_filter','dep_filter','type_filter','compression_filter','sort','dir','cursor','cursor_move','cursor_page'])
            ));
        }
    }
    return 'game-files.php?id=' . $gameId;
}

function examine_reference(int $reference, int $fileId, int $pageSize): string
{
    if ($reference === 0) {
        return '<span class="muted">none</span>';
    }
    $target = $reference < 0 ? 'import-' . ((-$reference) - 1) : 'export-' . ($reference - 1);
    return '<a class="xref mono" href="' . catalog_h(examine_href($fileId, $target, $pageSize)) . '">' . $reference . '</a>';
}

function examine_link_name(string $value, array $nameLookup, int $fileId, int $pageSize): string
{
    $value = trim($value);
    if ($value === '') {
        return '<span class="muted">none</span>';
    }
    $key = mb_strtolower($value, 'UTF-8');
    if (isset($nameLookup[$key])) {
        $target = 'name-' . (int)$nameLookup[$key];
        return '<a class="xref mono path" href="' . catalog_h(examine_href($fileId, $target, $pageSize)) . '" title="Open name table entry">' . catalog_h($value) . '</a>';
    }
    return '<span class="mono path">' . catalog_h($value) . '</span>';
}

function examine_name_index_link(string $value, ?int $nameIndex, int $fileId, int $pageSize): string
{
    $value = trim($value);
    if ($value === '') {
        return '<span class="muted">none</span>';
    }
    if ($nameIndex !== null && $nameIndex >= 0) {
        return '<a class="xref mono path" href="' . catalog_h(examine_href($fileId, 'name-' . $nameIndex, $pageSize))
            . '" title="Open exact Name table entry">' . catalog_h($value) . '</a>';
    }
    return '<span class="mono path">' . catalog_h($value) . '</span>';
}

function examine_object_label(string $value, int $reference, int $fileId, int $pageSize): string
{
    $value = trim($value);
    if ($value === '') {
        return examine_reference($reference, $fileId, $pageSize);
    }
    if ($reference !== 0) {
        $target = $reference < 0 ? 'import-' . ((-$reference) - 1) : 'export-' . ($reference - 1);
        return '<a class="xref mono path" href="' . catalog_h(examine_href($fileId, $target, $pageSize))
            . '" title="Open referenced Import/Export row">' . catalog_h($value) . '</a>';
    }
    return '<span class="mono path">' . catalog_h($value) . '</span>';
}

function examine_stored_usage(array $row, int $fileId, int $pageSize): string
{
    return examine_usage([
        'imports_count' => (int)($row['imports_count'] ?? 0),
        'imports_target' => isset($row['first_import_index']) ? 'import-' . (int)$row['first_import_index'] : '',
        'exports_count' => (int)($row['exports_count'] ?? 0),
        'exports_target' => isset($row['first_export_index']) ? 'export-' . (int)$row['first_export_index'] : '',
    ], $fileId, $pageSize);
}

function examine_name_flags(mixed $value): string
{
    if ($value === null || $value === '') {
        return '<span class="muted">n/a</span>';
    }
    $integer = (int)$value;
    if ($integer > 65535) {
        return sprintf('0x%04X / 0x%04X', ($integer >> 16) & 0xFFFF, $integer & 0xFFFF);
    }
    return (string)$integer;
}

function examine_usage(array $usage, int $fileId, int $pageSize): string
{
    $parts = [];
    if (($usage['imports_count'] ?? 0) > 0 && ($usage['imports_target'] ?? '') !== '') {
        $parts[] = '<a class="xref" href="' . catalog_h(examine_href($fileId, (string)$usage['imports_target'], $pageSize)) . '">Imports: ' . (int)$usage['imports_count'] . '</a>';
    }
    if (($usage['exports_count'] ?? 0) > 0 && ($usage['exports_target'] ?? '') !== '') {
        $parts[] = '<a class="xref" href="' . catalog_h(examine_href($fileId, (string)$usage['exports_target'], $pageSize)) . '">Exports: ' . (int)$usage['exports_count'] . '</a>';
    }
    return $parts !== [] ? implode(' <span class="muted">·</span> ', $parts) : '<span class="muted">none</span>';
}

function examine_dependency(?array $dependency): string
{
    if ($dependency === null) {
        return '<span class="muted">not built</span>';
    }
    $status = (string)($dependency['status'] ?? 'unknown');
    $title = trim((string)($dependency['required_object_path'] ?? ''));
    return '<span class="dep ' . catalog_h($status) . '"' . ($title !== '' ? ' title="' . catalog_h($title) . '"' : '') . '>' . catalog_h($status) . '</span>';
}

function examine_pagination(int $fileId, string $table, array $page): string
{
    if ((int)$page['pages'] <= 1) {
        return '';
    }
    $current = (int)$page['page'];
    $pages = (int)$page['pages'];
    $size = (int)$page['page_size'];
    $html = '<p class="page-links">';
    if ($current > 1) {
        $html .= '<a class="button" href="' . catalog_h(examine_page_href($fileId, $table, 1, $size)) . '">First</a> ';
        $html .= '<a class="button" href="' . catalog_h(examine_page_href($fileId, $table, $current - 1, $size)) . '">Previous</a> ';
    }
    $html .= '<span>Page ' . $current . ' of ' . $pages . '</span>';
    if ($current < $pages) {
        $html .= ' <a class="button" href="' . catalog_h(examine_page_href($fileId, $table, $current + 1, $size)) . '">Next</a>';
        $html .= ' <a class="button" href="' . catalog_h(examine_page_href($fileId, $table, $pages, $size)) . '">Last</a>';
    }
    return $html . '</p>';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $fileId = examine_int('id');
    $target = examine_target((string)($_GET['target'] ?? ''));
    $requestedTab = strtolower(trim((string)($_GET['tab'] ?? ($target !== '' ? examine_target_table($target) : 'names'))));
    $table = in_array($requestedTab, ['uses','used-by'], true)
        ? $requestedTab
        : PdoPackageTablePageQuery::normalizeTable($requestedTab);
    $pageSize = PdoPackageTablePageQuery::normalizePageSize(examine_int('page_size', PdoPackageTablePageQuery::DEFAULT_PAGE_SIZE));
    $requestedPage = max(1, examine_int('page', 1));

    $file = catalog_one(
        $db,
        'SELECT f.*,g.name game_name,g.id game_id FROM ue_files f JOIN ue_games g ON g.id=f.game_id WHERE f.id=?',
        [$fileId]
    );
    if (!$file) {
        throw new RuntimeException('File not found.');
    }
    if ((string)$file['scan_status'] === 'unverified') {
        header('Location: unverified-file-details.php?id=' . $fileId, true, 302);
        exit;
    }

    if ($target !== '') {
        $targetTable = examine_target_table($target);
        $targetIndex = PdoPackageTablePageQuery::targetIndex($target, $targetTable);
        if ($targetIndex !== null) {
            $table = $targetTable;
            $requestedPage = PdoPackageTablePageQuery::pageForIndex($targetIndex, $pageSize);
        }
    }

    $fetchStarted = microtime(true);
    if ($table === 'uses' || $table === 'used-by') {
        $page = ['rows'=>[],'page'=>1,'pages'=>1,'total'=>0,'page_size'=>$pageSize,'start'=>0,'end'=>0];
    } else {
        $page = PdoPackageTablePageQuery::fetchPage($db, $file, $table, $requestedPage, $pageSize);
    }
    $fetchElapsedMs = (int)round((microtime(true) - $fetchStarted) * 1000);
    error_log(
        '[UnrealDB file examiner page] file_id=' . $fileId
        . ' table=' . $table
        . ' page=' . $requestedPage
        . ' page_size=' . $pageSize
        . ' rows=' . count($page['rows'])
        . ' elapsed_ms=' . $fetchElapsedMs
    );
    $rows = $page['rows'];
    $relationshipRows = [];
    if ($table === 'uses') {
        $relationshipRows = catalog_all($db,
            'SELECT l.resolved_file_id file_id,f.original_name,f.package_name,f.file_size,f.package_guid,f.md5,f.sha1,COUNT(*) reference_count '
            . 'FROM ue_dependency_links l JOIN ue_files f ON f.id=l.resolved_file_id '
            . 'WHERE l.file_id=? AND l.resolved_file_id IS NOT NULL AND f.scan_status="verified" '
            . 'GROUP BY l.resolved_file_id,f.original_name,f.package_name,f.file_size,f.package_guid,f.md5,f.sha1 ORDER BY f.package_name,f.original_name', [$fileId]);
    } elseif ($table === 'used-by') {
        $relationshipRows = catalog_all($db,
            'SELECT l.file_id,f.original_name,f.package_name,f.file_size,f.package_guid,f.md5,f.sha1,COUNT(*) reference_count '
            . 'FROM ue_dependency_links l JOIN ue_files f ON f.id=l.file_id '
            . 'WHERE l.resolved_file_id=? AND f.scan_status="verified" '
            . 'GROUP BY l.file_id,f.original_name,f.package_name,f.file_size,f.package_guid,f.md5,f.sha1 ORDER BY f.package_name,f.original_name', [$fileId]);
    }
    $metadataVersion = (int)(catalog_one($db, 'SELECT format_version FROM ue_file_metadata WHERE file_id=?', [$fileId])['format_version'] ?? 0);
    // Keep the initial examiner request bounded to the requested metadata page.
    // Cross-reference/usage/dependency enrichment is loaded after first paint by
    // file-examine-enrichment.php so a large package cannot block the page shell.
    $nameLookup = [];
    $usage = [];
    $dependencies = $table === 'imports' && $metadataVersion >= 3
        ? PdoPackageTablePageQuery::dependencyPage($db, $fileId, max(0, (int)$page['start'] - 1), $pageSize)
        : [];

    $back = examine_back((int)$file['game_id']);
    catalog_head('Examine ' . (string)$file['package_name']);
    echo '<style>.examine-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 14px;border-bottom:1px solid var(--line);padding-bottom:10px}.examine-tab{display:inline-flex;gap:6px;min-height:34px;padding:6px 10px;border:1px solid var(--line2);border-radius:9px;color:var(--text);background:rgba(255,255,255,.035);font-weight:650;text-decoration:none}.examine-tab.is-active{color:#07111f;background:linear-gradient(180deg,#9dc2ff,#76a9ff);border-color:#a9c9ff}.examine-table-region{overflow-x:auto;border:1px solid var(--line);border-radius:12px}.examine-imports-table{min-width:1420px}.examine-exports-table{min-width:1320px}.is-reference-target td{background:rgba(246,196,83,.18)!important;box-shadow:inset 4px 0 0 #f6c453}.path{white-space:normal}.table-tools{display:flex;align-items:end;gap:10px;flex-wrap:wrap;margin:10px 0}.to-top{position:fixed;right:20px;bottom:20px;width:42px;height:42px;border-radius:50%;display:grid;place-items:center;background:rgba(16,24,39,.94);border:1px solid var(--line2);font-size:22px}</style>';

    echo '<div class="card hero" id="top"><h1>Examine ' . catalog_h($file['package_name']) . '</h1><p class="muted">Bounded database-backed Names, Imports and Exports pages. Cross-references open the page containing the selected row.</p><p><a class="button" href="' . catalog_h($back) . '">Back to files</a> <a class="button" href="file-info.php?id=' . $fileId . '">Package details</a> <a class="button" href="download-info.php?id=' . $fileId . '">Download options</a></p></div>';

    echo '<div class="card"><h2>Package header</h2><div class="two-col"><table>';
    foreach ([
        'Game' => $file['game_name'],
        'File' => $file['original_name'],
        'Package' => '<a href="file-info.php?id=' . $fileId . '">' . catalog_h((string)$file['package_name']) . '</a>',
        'GUID' => $file['package_guid'] ?: '—',
        'Version' => $file['package_version'] ?? '—',
        'Licensee Version' => $file['licensee_version'] ?? '—',
    ] as $label => $value) {
        echo '<tr><th>' . catalog_h($label) . '</th><td class="mono path">'
            . ($label === 'Package' ? (string)$value : catalog_h((string)$value))
            . '</td></tr>';
    }
    echo '</table><table>';
    foreach ([
        'Names' => (int)$file['name_count'],
        'Imports' => (int)$file['import_count'],
        'Exports' => (int)$file['export_count'],
        'Size' => catalog_bytes((int)$file['file_size']),
        'Status' => $file['scan_status'],
    ] as $label => $value) {
        echo '<tr><th>' . catalog_h($label) . '</th><td class="mono path">' . catalog_h((string)$value) . '</td></tr>';
    }
    echo '</table></div></div>';

    if (!empty($file['detection_notes']) || !empty($file['scan_notes'])) {
        echo '<div class="card"><h2>Scanner notes</h2>';
        if (!empty($file['detection_notes'])) {
            echo '<h3>Detection</h3><pre class="mono path">' . catalog_h((string)$file['detection_notes']) . '</pre>';
        }
        if (!empty($file['scan_notes'])) {
            echo '<h3>Scan</h3><pre class="mono path">' . catalog_h((string)$file['scan_notes']) . '</pre>';
        }
        echo '</div>';
    }

    $counts = [
        'names' => (int)$file['name_count'],
        'imports' => (int)$file['import_count'],
        'exports' => (int)$file['export_count'],
    ];
    echo '<div class="card" id="package-tables"><nav class="examine-tabs">';
    foreach (['names' => 'Names','imports' => 'Imports','exports' => 'Exports','uses' => 'Uses','used-by' => 'Used By'] as $key => $label) {
        $tabCount = array_key_exists($key, $counts) ? $counts[$key] : ($table === $key ? count($relationshipRows) : '');
        echo '<a class="examine-tab' . ($table === $key ? ' is-active' : '') . '" href="' . catalog_h(examine_tab_href($fileId, $key, $pageSize)) . '">' . $label . ($tabCount !== '' ? ' <span>' . $tabCount . '</span>' : '') . '</a>';
    }
    echo '</nav><section data-file-examine-native-panel>';

    if ($table === 'uses' || $table === 'used-by') {
        $heading = $table === 'uses' ? 'Uses' : 'Used By';
        echo '<h2>' . $heading . '</h2><p class="muted">Package relationships from the current dependency projection.</p>';
        if ($relationshipRows === []) {
            echo '<p class="muted">No resolved package relationships found.</p></section></div><a class="to-top" href="#top">↑</a>';
            catalog_foot();
            return;
        }
        echo '<div class="examine-table-region"><table><thead><tr><th>File</th><th>Package</th><th>Size</th><th>GUID</th><th>MD5</th><th>SHA1</th><th>References</th></tr></thead><tbody>';
        foreach ($relationshipRows as $relation) {
            $relatedId=(int)$relation['file_id'];
            echo '<tr><td class="mono path"><a href="file-examine.php?id=' . $relatedId . '">' . catalog_h((string)$relation['original_name']) . '</a></td>'
                . '<td class="mono path"><a href="file-info.php?id=' . $relatedId . '">' . catalog_h((string)$relation['package_name']) . '</a></td>'
                . '<td class="mono">' . catalog_h(catalog_bytes((int)$relation['file_size'])) . '</td>'
                . '<td class="mono path">' . catalog_h(trim((string)$relation['package_guid']) ?: '—') . '</td>'
                . '<td class="mono path">' . catalog_h(trim((string)$relation['md5']) ?: '—') . '</td>'
                . '<td class="mono path">' . catalog_h(trim((string)$relation['sha1']) ?: '—') . '</td>'
                . '<td>' . (int)$relation['reference_count'] . '</td></tr>';
        }
        echo '</tbody></table></div></section></div><a class="to-top" href="#top">↑</a>';
        catalog_foot();
        return;
    }

    echo '<div class="table-tools"><form method="get"><input type="hidden" name="id" value="' . $fileId . '"><input type="hidden" name="tab" value="' . catalog_h($table) . '"><label>Rows per page<br><select name="page_size" onchange="this.form.submit()">';
    foreach ([100,250,500,1000] as $option) {
        echo '<option value="' . $option . '"' . ($pageSize === $option ? ' selected' : '') . '>' . $option . '</option>';
    }
    echo '</select></label></form><span class="muted">Showing ' . (int)$page['start'] . '–' . (int)$page['end'] . ' of ' . (int)$page['total'] . '.</span>';
    echo '<a class="button" href="file-examine-export.php?' . catalog_h(http_build_query(['id' => $fileId,'table' => $table,'format' => 'csv'])) . '">CSV</a>';
    echo '<a class="button" href="file-examine-export.php?' . catalog_h(http_build_query(['id' => $fileId,'table' => $table,'format' => 'json'])) . '">JSON</a></div>';
    echo examine_pagination($fileId, $table, $page);

    if ($table === 'names') {
        echo '<h2>Names</h2><div class="examine-table-region"><table><thead><tr><th>Index</th><th>Name</th>' . ($metadataVersion >= 3 ? '<th>Used by</th>' : '') . '<th>Flags / hashes</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $index = (int)$row['name_index'];
            $rowId = 'name-' . $index;
            $text = (string)$row['name_text'];
            echo '<tr id="' . $rowId . '"' . ($target === $rowId ? ' class="is-reference-target"' : '') . '><td class="mono">' . $index . '</td><td class="mono path">' . catalog_h($text) . '</td>'
                . ($metadataVersion >= 3 ? '<td>' . examine_stored_usage($row, $fileId, $pageSize) . '</td>' : '')
                . '<td class="mono">' . examine_name_flags($row['flags'] ?? null) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    } elseif ($table === 'imports') {
        echo '<h2>Imports</h2><p class="muted">Object references: 0 = null; &lt; 0 = import; &gt; 0 = export.</p><div class="examine-table-region"><table class="examine-imports-table"><thead><tr><th>Index</th><th>Package ref</th><th>Class package</th><th>Class</th><th>Object</th><th>Outer ref</th><th>Full path</th><th>Root</th><th>Dependency</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $index = (int)$row['import_index'];
            $rowId = 'import-' . $index;
            echo '<tr id="' . $rowId . '"' . ($target === $rowId ? ' class="is-reference-target"' : '') . '><td class="mono">' . $index . '</td>'
                . '<td class="mono"><a class="xref mono" href="' . catalog_h(examine_href($fileId, $rowId, $pageSize)) . '">' . (-(int)($index + 1)) . '</a></td>'
                . '<td>' . examine_name_index_link((string)$row['class_package'], isset($row['class_package_name_index']) ? (int)$row['class_package_name_index'] : null, $fileId, $pageSize) . '</td>'
                . '<td>' . examine_name_index_link((string)$row['class_name'], isset($row['class_name_index']) ? (int)$row['class_name_index'] : null, $fileId, $pageSize) . '</td>'
                . '<td>' . examine_name_index_link((string)$row['object_name'], isset($row['object_name_index']) ? (int)$row['object_name_index'] : null, $fileId, $pageSize) . '</td>'
                . '<td>' . examine_reference((int)$row['outer_index'], $fileId, $pageSize) . '</td><td class="mono path">' . catalog_h((string)$row['full_path']) . '</td><td class="mono path">' . catalog_h((string)$row['root_package']) . '</td><td>' . examine_dependency($dependencies[$index] ?? null) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    } else {
        echo '<h2>Exports</h2><p class="muted">Object references: 0 = null; &lt; 0 = import; &gt; 0 = export.</p><div class="examine-table-region"><table class="examine-exports-table"><thead><tr><th>Index</th><th>Package ref</th><th>Class</th><th>Object</th><th>Outer ref</th><th>Local path</th><th>Full path</th><th>Flags</th><th>Serial size</th><th>Serial offset</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $index = (int)$row['export_index'];
            $rowId = 'export-' . $index;
            echo '<tr id="' . $rowId . '"' . ($target === $rowId ? ' class="is-reference-target"' : '') . '><td class="mono">' . $index . '</td>'
                . '<td class="mono"><a class="xref mono" href="' . catalog_h(examine_href($fileId, $rowId, $pageSize)) . '">' . ($index + 1) . '</a></td>'
                . '<td>' . examine_object_label((string)$row['class_name'], (int)($row['class_index'] ?? 0), $fileId, $pageSize) . '</td>'
                . '<td>' . examine_name_index_link((string)$row['object_name'], isset($row['object_name_index']) ? (int)$row['object_name_index'] : null, $fileId, $pageSize) . '</td>'
                . '<td>' . examine_reference((int)$row['outer_index'], $fileId, $pageSize) . '</td><td class="mono path">' . catalog_h((string)$row['local_path']) . '</td><td class="mono path">' . catalog_h((string)$row['full_path']) . '</td><td class="mono">' . catalog_h((string)($row['object_flags'] ?? '')) . '</td><td class="mono">' . catalog_h((string)($row['serial_size'] ?? '')) . '</td><td class="mono">' . catalog_h((string)($row['serial_offset'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    echo examine_pagination($fileId, $table, $page) . '</section></div><a class="to-top" href="#top">↑</a>';
    if ($target !== '') {
        echo '<script>window.addEventListener("DOMContentLoaded",function(){var row=document.getElementById(' . json_encode($target, JSON_THROW_ON_ERROR) . ');if(row){row.scrollIntoView({block:"center"});}});</script>';
    }
    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('Examine error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($error->getMessage()) . '</p></div>';
    catalog_foot();
}
