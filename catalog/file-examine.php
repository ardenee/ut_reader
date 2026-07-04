<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';

function examine_page_int(string $key, int $default, int $min, int $max): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        $value = $default;
    }
    return max($min, min($max, (int)$value));
}

function examine_game_files_return_url(string $candidate, int $gameId): ?string
{
    $candidate = trim($candidate);
    if ($candidate === '') {
        return null;
    }

    $parts = parse_url($candidate);
    if ($parts === false || basename((string)($parts['path'] ?? '')) !== 'game-files.php') {
        return null;
    }

    parse_str((string)($parts['query'] ?? ''), $params);
    if ((int)($params['id'] ?? 0) !== $gameId) {
        return null;
    }

    $allowed = ['id', 'file_filter', 'dep_filter', 'type_filter', 'compression_filter', 'sort', 'dir', 'file_page'];
    $safe = [];
    foreach ($allowed as $key) {
        if (isset($params[$key]) && !is_array($params[$key])) {
            $safe[$key] = (string)$params[$key];
        }
    }
    $safe['id'] = (string)$gameId;

    return 'game-files.php?' . http_build_query($safe);
}

function examine_back_to_files_url(int $gameId): string
{
    $fromQuery = examine_game_files_return_url((string)($_GET['return_to'] ?? ''), $gameId);
    if ($fromQuery !== null) {
        return $fromQuery;
    }

    $fromReferer = examine_game_files_return_url((string)($_SERVER['HTTP_REFERER'] ?? ''), $gameId);
    if ($fromReferer !== null) {
        return $fromReferer;
    }

    return 'game-files.php?id=' . $gameId;
}

function examine_remote_url(int $fileId, string $backToFilesUrl, string $anchor = ''): string
{
    $url = 'file-examine.php?' . http_build_query([
        'id' => $fileId,
        'return_to' => $backToFilesUrl,
    ]);

    return $anchor !== '' ? $url . '#' . rawurlencode($anchor) : $url;
}

/**
 * FPackageIndex rule for legacy Unreal packages:
 *   0  = null
 *   <0 = import at (-index - 1)
 *   >0 = export at ( index - 1)
 */
function examine_ref_link(int $ref): string
{
    if ($ref === 0) {
        return '<span class="muted">none</span>';
    }

    if ($ref < 0) {
        $index = (-$ref) - 1;
        return '<a class="xref mono" href="#import-' . $index . '" title="Import index ' . $index . '">' . $ref . '</a>';
    }

    $index = $ref - 1;
    return '<a class="xref mono" href="#export-' . $index . '" title="Export index ' . $index . '">' . $ref . '</a>';
}

function examine_normalize_reference_text(string $value): string
{
    return strtolower(trim($value));
}

function examine_add_reference_target(array &$targets, string $value, string $targetId): void
{
    $key = examine_normalize_reference_text($value);
    if ($key === '') {
        return;
    }
    $targets[$key] ??= [];
    $targets[$key][] = $targetId;
}

function examine_local_reference_link(string $value, array $referenceTargets): string
{
    $text = trim($value);
    if ($text === '') {
        return '<span class="muted">none</span>';
    }

    $targets = array_values(array_unique($referenceTargets[examine_normalize_reference_text($text)] ?? []));
    if ($targets === []) {
        return '<span class="mono path">' . catalog_h($text) . '</span>';
    }

    return '<a class="xref mono path" href="#' . catalog_h($targets[0]) . '" data-reference-targets="' . catalog_h((string)json_encode($targets, JSON_THROW_ON_ERROR)) . '">' . catalog_h($text) . '</a>';
}

function examine_name_link(string $value, array $nameLookup): string
{
    $text = trim($value);
    if ($text === '') {
        return '';
    }

    $key = examine_normalize_reference_text($text);
    if (isset($nameLookup[$key])) {
        return '<a class="xref mono path" href="#name-' . (int)$nameLookup[$key] . '">' . catalog_h($text) . '</a>';
    }

    return '<span class="mono path">' . catalog_h($text) . '</span>';
}

function examine_resolved_file_link(?int $fileId, string $label, ?int $exportIndex, string $backToFilesUrl): string
{
    if (!$fileId) {
        return '<span class="muted">none</span>';
    }

    if ($exportIndex !== null && $exportIndex >= 0) {
        return '<a class="xref" href="' . catalog_h(examine_remote_url($fileId, $backToFilesUrl, 'export-' . $exportIndex)) . '">' . catalog_h($label) . '</a>';
    }

    return '<a class="xref" href="file-info.php?id=' . (int)$fileId . '">' . catalog_h($label) . '</a>';
}

function examine_dependency_html(?array $dependency, string $backToFilesUrl): string
{
    if (!$dependency) {
        return '<span class="muted">not built</span>';
    }

    $status = (string)$dependency['status'];
    $detail = '<strong>' . catalog_h($status) . '</strong>';
    $resolvedFileId = !empty($dependency['resolved_file_id']) ? (int)$dependency['resolved_file_id'] : null;
    $resolvedExportIndex = array_key_exists('resolved_export_index', $dependency) && $dependency['resolved_export_index'] !== null
        ? (int)$dependency['resolved_export_index']
        : null;

    if ($resolvedFileId) {
        $label = (string)($dependency['resolved_package'] ?: $dependency['resolved_file'] ?: ('file #' . $resolvedFileId));
        $detail .= '<span>→ ' . examine_resolved_file_link($resolvedFileId, $label, $resolvedExportIndex, $backToFilesUrl) . '</span>';
    }
    if ($resolvedFileId && $resolvedExportIndex !== null && !empty($dependency['resolved_export_path'])) {
        $detail .= '<span class="mono path">' . examine_resolved_file_link($resolvedFileId, (string)$dependency['resolved_export_path'], $resolvedExportIndex, $backToFilesUrl) . '</span>';
    }

    return '<div class="examine-dependency-entry"><span class="dep ' . catalog_h($status) . ' examine-dependency-pill">' . $detail . '</span></div>';
}

function examine_add_name_usage(array &$usageMap, string $name, string $tab, string $targetId): void
{
    $key = examine_normalize_reference_text($name);
    if ($key === '') {
        return;
    }

    $usageMap[$key] ??= ['imports' => [], 'exports' => []];
    $usageMap[$key][$tab][] = $targetId;
}

function examine_usage_link(string $label, array $targets): string
{
    $targets = array_values(array_unique($targets));
    if ($targets === []) {
        return '';
    }

    return '<a class="xref name-usage-link" href="#' . catalog_h($targets[0]) . '" data-reference-targets="' . catalog_h((string)json_encode($targets, JSON_THROW_ON_ERROR)) . '">' . catalog_h($label) . '</a>';
}

function examine_hex_bytes(string $bytes): string
{
    return strtoupper(trim(chunk_split(bin2hex($bytes), 2, ' ')));
}

function examine_u32(string $bytes, int $offset): int
{
    return (int)unpack('V', substr($bytes, $offset, 4))[1];
}

function examine_i32(string $bytes, int $offset): int
{
    $value = examine_u32($bytes, $offset);
    return ($value & 0x80000000) ? $value - 0x100000000 : $value;
}

function examine_pkg_flags(int $flags): array
{
    $known = [
        0x00000001 => 'AllowDownload',
        0x00000002 => 'ClientOptional',
        0x00000004 => 'ServerSideOnly',
        0x00000008 => 'NoExportAllowed',
        0x00000010 => 'Cooked',
        0x00000020 => 'Encrypted',
        0x00008000 => 'Map',
        0x00020000 => 'Script',
        0x00040000 => 'ContainsMap',
        0x00080000 => 'DebugInfo',
        0x00100000 => 'Imports',
        0x00200000 => 'Compressed',
        0x00400000 => 'FullyCompressed',
    ];
    $out = [];
    foreach ($known as $bit => $name) {
        if (($flags & $bit) !== 0) {
            $out[] = $name;
        }
    }
    return $out;
}

function examine_build_label(int $version): string
{
    if ($version >= 500) {
        return 'UE3';
    }
    if ($version >= 100) {
        return 'UE2';
    }
    if ($version > 0) {
        return 'Unreal1';
    }
    return 'unknown';
}

function examine_header_field(array &$rows, string $bytes, int $offset, int $size, string $field, string $type, string $value, string $note = ''): void
{
    $rows[] = [
        'offset' => $offset,
        'size' => $size,
        'field' => $field,
        'type' => $type,
        'value' => $value,
        'hex' => examine_hex_bytes(substr($bytes, $offset, $size)),
        'note' => $note,
    ];
}

function examine_parse_package_header(?string $path): array
{
    if (!$path || !is_file($path)) {
        return ['ok' => false, 'error' => 'Stored package file is not available on disk.', 'summary' => [], 'rows' => []];
    }

    $bytes = @file_get_contents($path, false, null, 0, 4096);
    if ($bytes === false || strlen($bytes) < 40) {
        return ['ok' => false, 'error' => 'Stored package file is too small to parse header.', 'summary' => [], 'rows' => []];
    }

    $rows = [];
    $tag = examine_u32($bytes, 0);
    examine_header_field($rows, $bytes, 0, 4, 'signature', 'uint32', (string)$tag, sprintf('0x%08X', $tag));
    if ($tag !== 0x9E2A83C1) {
        return ['ok' => false, 'error' => sprintf('Bad package tag 0x%08X', $tag), 'summary' => [], 'rows' => $rows];
    }

    $packed = examine_u32($bytes, 4);
    $version = $packed & 0xFFFF;
    $licensee = ($packed >> 16) & 0xFFFF;
    examine_header_field($rows, $bytes, 4, 4, 'packedVersionLicensee', 'uint32', 'packed=' . $packed . ', version=' . $version . ', licensee=' . $licensee);

    $flags = examine_u32($bytes, 8);
    examine_header_field($rows, $bytes, 8, 4, 'pkgFlags', 'uint32', (string)$flags, sprintf('0x%08X', $flags));

    $nameCount = examine_i32($bytes, 12);
    $nameOffset = examine_i32($bytes, 16);
    $exportCount = examine_i32($bytes, 20);
    $exportOffset = examine_i32($bytes, 24);
    $importCount = examine_i32($bytes, 28);
    $importOffset = examine_i32($bytes, 32);
    examine_header_field($rows, $bytes, 12, 4, 'nameCount', 'int32', (string)$nameCount);
    examine_header_field($rows, $bytes, 16, 4, 'nameOffset', 'int32', (string)$nameOffset);
    examine_header_field($rows, $bytes, 20, 4, 'exportCount', 'int32', (string)$exportCount);
    examine_header_field($rows, $bytes, 24, 4, 'exportOffset', 'int32', (string)$exportOffset);
    examine_header_field($rows, $bytes, 28, 4, 'importCount', 'int32', (string)$importCount);
    examine_header_field($rows, $bytes, 32, 4, 'importOffset', 'int32', (string)$importOffset);

    $offset = 36;
    $heritageCount = null;
    $heritageOffset = null;
    $guid = '';
    $generationCount = null;

    if ($version < 68) {
        $heritageCount = examine_i32($bytes, $offset);
        examine_header_field($rows, $bytes, $offset, 4, 'heritageCount', 'int32', (string)$heritageCount);
        $offset += 4;
        $heritageOffset = examine_i32($bytes, $offset);
        examine_header_field($rows, $bytes, $offset, 4, 'heritageOffset', 'int32', (string)$heritageOffset);
        $offset += 4;
    }

    if (strlen($bytes) >= $offset + 16) {
        $dwords = [examine_u32($bytes, $offset), examine_u32($bytes, $offset + 4), examine_u32($bytes, $offset + 8), examine_u32($bytes, $offset + 12)];
        $guid = sprintf('%08X-%08X-%08X-%08X', $dwords[0], $dwords[1], $dwords[2], $dwords[3]);
        examine_header_field($rows, $bytes, $offset, 16, 'guid', 'FGuid', $guid);
        $offset += 16;
    }

    if ($version >= 68 && strlen($bytes) >= $offset + 4) {
        $generationCount = examine_i32($bytes, $offset);
        examine_header_field($rows, $bytes, $offset, 4, 'generationCount', 'int32', (string)$generationCount);
        $offset += 4;
        for ($i = 0; $i < $generationCount && strlen($bytes) >= $offset + 8; $i++) {
            $exportGen = examine_i32($bytes, $offset);
            $nameGen = examine_i32($bytes, $offset + 4);
            examine_header_field($rows, $bytes, $offset, 8, 'generation[' . $i . ']', 'int32,int32', 'exportCount=' . $exportGen . ', nameCount=' . $nameGen);
            $offset += 8;
        }
    }

    $flagsList = examine_pkg_flags($flags);
    $summary = [
        'GUID' => $guid,
        'Signature' => sprintf('0x%08X', $tag),
        'Version' => $version,
        'Licensee Version' => $licensee,
        'Flags' => sprintf('0x%08X', $flags) . ($flagsList ? ' / ' . implode(', ', $flagsList) : ''),
        'Build' => examine_build_label($version),
        'Heritage' => ($heritageCount !== null || $heritageOffset !== null) ? (($heritageCount ?? 0) . ' / ' . ($heritageOffset ?? 0)) : 'n/a',
        'Counts' => 'N ' . $nameCount . ' / I ' . $importCount . ' / E ' . $exportCount,
        'Name Offset' => $nameOffset,
        'Import Offset' => $importOffset,
        'Export Offset' => $exportOffset,
    ];
    if ($generationCount !== null) {
        $summary['Generations'] = $generationCount;
    }

    return ['ok' => true, 'error' => '', 'summary' => $summary, 'rows' => $rows];
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $id = examine_page_int('id', 0, 1, PHP_INT_MAX);
    $file = catalog_one($db, 'SELECT f.*, g.name game_name, g.id game_id FROM ue_files f JOIN ue_games g ON g.id=f.game_id WHERE f.id=?', [$id]);
    if (!$file) {
        throw new RuntimeException('File not found');
    }

    $backToFilesUrl = examine_back_to_files_url((int)$file['game_id']);

    $storageRoot = realpath(rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR));
    $storedPath = realpath(__DIR__ . '/' . (string)$file['relative_path']);
    if ($storageRoot && $storedPath && !str_starts_with($storedPath, $storageRoot)) {
        $storedPath = null;
    }
    $parsedHeader = examine_parse_package_header($storedPath);

    $names = catalog_all($db, 'SELECT * FROM ue_names WHERE file_id=? ORDER BY name_index', [$id]);
    $imports = catalog_all($db, 'SELECT * FROM ue_imports WHERE file_id=? ORDER BY import_index', [$id]);
    $exports = catalog_all($db, 'SELECT * FROM ue_exports WHERE file_id=? ORDER BY export_index', [$id]);
    $dependencies = catalog_all($db, 'SELECT d.*, rf.package_name resolved_package, rf.original_name resolved_file, re.full_path resolved_export_path, re.export_index resolved_export_index FROM ue_dependencies d LEFT JOIN ue_files rf ON rf.id=d.resolved_file_id LEFT JOIN ue_exports re ON re.id=d.resolved_export_id WHERE d.file_id=? ORDER BY d.id', [$id]);

    $nameLookup = [];
    $localReferenceTargets = [];
    foreach ($names as $name) {
        $text = (string)$name['name_text'];
        $key = examine_normalize_reference_text($text);
        if ($key !== '' && !isset($nameLookup[$key])) {
            $nameLookup[$key] = (int)$name['name_index'];
        }
        examine_add_reference_target($localReferenceTargets, $text, 'name-' . (int)$name['name_index']);
    }

    $dependencyByImportId = [];
    foreach ($dependencies as $dependency) {
        $dependencyByImportId[(int)$dependency['import_id']] = $dependency;
    }

    $nameUsage = [];
    foreach ($imports as $import) {
        $targetId = 'import-' . (int)$import['import_index'];
        foreach (['class_package', 'class_name', 'object_name'] as $field) {
            $value = (string)$import[$field];
            examine_add_name_usage($nameUsage, $value, 'imports', $targetId);
            examine_add_reference_target($localReferenceTargets, $value, $targetId);
        }
    }
    foreach ($exports as $export) {
        $targetId = 'export-' . (int)$export['export_index'];
        foreach (['class_name', 'object_name'] as $field) {
            $value = (string)$export[$field];
            examine_add_name_usage($nameUsage, $value, 'exports', $targetId);
            examine_add_reference_target($localReferenceTargets, $value, $targetId);
        }
    }
    foreach ($nameUsage as &$usage) {
        $usage['imports'] = array_values(array_unique($usage['imports']));
        $usage['exports'] = array_values(array_unique($usage['exports']));
    }
    unset($usage);
    foreach ($localReferenceTargets as &$targets) {
        $targets = array_values(array_unique($targets));
    }
    unset($targets);

    catalog_head('Examine ' . (string)$file['package_name']);
    echo <<<'CSS'
<style>
html { scroll-behavior: smooth; }
#examine-top { scroll-margin-top: 86px; }

.examine-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 0 0 14px;
    border-bottom: 1px solid var(--line);
    padding-bottom: 10px;
}
.examine-tab {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    min-height: 34px;
    padding: 6px 10px;
    border: 1px solid var(--line2);
    border-radius: 9px;
    color: var(--text);
    background: rgba(255, 255, 255, .035);
    font-weight: 650;
    text-decoration: none;
}
.examine-tab:hover { background: rgba(118, 169, 255, .14); text-decoration: none; }
.examine-tab.is-active { color: #07111f; background: linear-gradient(180deg, #9dc2ff, #76a9ff); border-color: #a9c9ff; }
.examine-tab__count { color: inherit; font-size: 12px; opacity: .85; }
.examine-tab-panel[hidden] { display: none; }

.examine-table-region { overflow-x: auto; border: 1px solid var(--line); border-radius: 12px; }
.examine-table-region > table { min-width: 760px; }
.examine-table-region > .examine-imports-table { min-width: 1420px; }
.examine-table-region > .examine-exports-table { min-width: 1320px; }
.examine-reference-note { margin: 0 0 12px; }

.name-usage-links { display: flex; flex-wrap: wrap; gap: 6px; }
.name-usage-links .muted { margin-right: 2px; }

.examine-dependency-entry { display: block; margin: 0 0 3px; }
.examine-dependency-pill {
    display: inline-flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 2px;
    max-width: 100%;
    margin: 0;
    white-space: normal;
    line-height: 1.28;
}
.examine-dependency-pill > span { display: block; }

.is-reference-target td {
    background: rgba(246, 196, 83, .18) !important;
    box-shadow: inset 4px 0 0 var(--amber);
    border-top: 1px solid rgba(246, 196, 83, .55);
    border-bottom: 1px solid rgba(246, 196, 83, .55);
}

[data-sortable-table] th { cursor: pointer; user-select: none; }
[data-sortable-table] th::after { content: '↕'; display: inline-block; margin-left: 7px; color: var(--muted); font-size: 11px; opacity: .7; }
[data-sortable-table] th.is-sort-ascending::after { content: '▲'; color: var(--blue); opacity: 1; }
[data-sortable-table] th.is-sort-descending::after { content: '▼'; color: var(--blue); opacity: 1; }

.examine-to-top {
    position: fixed;
    right: 20px;
    bottom: 20px;
    z-index: 9;
    display: grid;
    place-items: center;
    width: 42px;
    height: 42px;
    border: 1px solid var(--line2);
    border-radius: 50%;
    color: var(--text);
    background: rgba(16, 24, 39, .94);
    box-shadow: var(--shadow);
    font-size: 22px;
    font-weight: 800;
}
.examine-to-top:hover { background: rgba(44, 66, 112, .98); text-decoration: none; }
@media (max-width: 700px) { .examine-to-top { right: 14px; bottom: 14px; } }
</style>
CSS;

    echo '<span id="examine-top" aria-hidden="true"></span>';
    echo '<div class="card hero"><h1>Examine ' . catalog_h($file['package_name']) . '</h1><p class="muted">Database-backed package names, imports, exports and dependency links, with header data parsed from the stored package file.</p><p><a class="button" href="' . catalog_h($backToFilesUrl) . '">Back to files</a> <a class="button" href="file-info.php?id=' . $id . '">Details</a></p></div>';

    echo '<div class="card"><h2>Package header</h2>';
    if (!$parsedHeader['ok']) {
        echo '<p class="muted">' . catalog_h($parsedHeader['error']) . '</p>';
    }
    echo '<div class="two-col"><table>';
    foreach (['GUID', 'Version', 'Licensee Version', 'Signature', 'Name Offset', 'Import Offset', 'Export Offset'] as $label) {
        if (array_key_exists($label, $parsedHeader['summary'])) {
            echo '<tr><th>' . catalog_h($label) . '</th><td class="mono path">' . catalog_h((string)$parsedHeader['summary'][$label]) . '</td></tr>';
        }
    }
    echo '</table><table>';
    foreach (['Flags', 'Build', 'Heritage', 'Counts', 'Generations'] as $label) {
        if (array_key_exists($label, $parsedHeader['summary'])) {
            echo '<tr><th>' . catalog_h($label) . '</th><td class="mono path">' . catalog_h((string)$parsedHeader['summary'][$label]) . '</td></tr>';
        }
    }
    echo '</table></div></div>';

    if ($parsedHeader['rows']) {
        echo '<div class="card"><h2>Raw header data</h2><div class="examine-table-region"><table data-sortable-table><thead><tr><th>Offset</th><th>Size</th><th>Field</th><th>Type</th><th>Value</th><th>Raw hex</th><th>Note</th></tr></thead><tbody>';
        foreach ($parsedHeader['rows'] as $row) {
            echo '<tr><td class="mono" data-sort-value="' . (int)$row['offset'] . '">' . (int)$row['offset'] . '</td><td class="mono" data-sort-value="' . (int)$row['size'] . '">' . (int)$row['size'] . '</td><td class="mono">' . catalog_h($row['field']) . '</td><td class="mono">' . catalog_h($row['type']) . '</td><td class="mono path">' . catalog_h($row['value']) . '</td><td class="mono path">' . catalog_h($row['hex']) . '</td><td>' . catalog_h($row['note']) . '</td></tr>';
        }
        echo '</tbody></table></div></div>';
    }

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

    $tabs = [
        'names' => ['Names', count($names)],
        'imports' => ['Imports', count($imports)],
        'exports' => ['Exports', count($exports)],
    ];
    echo '<div class="card" id="package-tables"><nav class="examine-tabs" aria-label="Package tables" role="tablist">';
    foreach ($tabs as $tab => [$label, $count]) {
        $active = $tab === 'names';
        echo '<a id="examine-tab-' . $tab . '" class="examine-tab' . ($active ? ' is-active' : '') . '" href="#tab-' . $tab . '" data-examine-tab="' . $tab . '" role="tab" aria-controls="tab-' . $tab . '" aria-selected="' . ($active ? 'true' : 'false') . '">' . catalog_h($label) . ' <span class="examine-tab__count">' . $count . '</span></a>';
    }
    echo '</nav>';

    echo '<section id="tab-names" class="examine-tab-panel" data-examine-panel="names" role="tabpanel" aria-labelledby="examine-tab-names">';
    echo '<h2>Names</h2><div class="examine-table-region"><table data-sortable-table><thead><tr><th>Index</th><th>Name</th><th>Used by</th><th>Flags</th></tr></thead><tbody>';
    foreach ($names as $name) {
        $nameIndex = (int)$name['name_index'];
        $nameText = (string)$name['name_text'];
        $nameKey = examine_normalize_reference_text($nameText);
        $importTargets = $nameUsage[$nameKey]['imports'] ?? [];
        $exportTargets = $nameUsage[$nameKey]['exports'] ?? [];
        $allUsageTargets = array_values(array_unique(array_merge($importTargets, $exportTargets)));
        $nameHtml = $allUsageTargets === []
            ? '<span class="mono path">' . catalog_h($nameText) . '</span>'
            : '<a class="xref mono path name-usage-link" href="#' . catalog_h($allUsageTargets[0]) . '" data-reference-targets="' . catalog_h((string)json_encode($allUsageTargets, JSON_THROW_ON_ERROR)) . '">' . catalog_h($nameText) . '</a>';
        $usedBy = [];
        if ($importTargets !== []) {
            $usedBy[] = examine_usage_link('Imports: ' . count($importTargets), $importTargets);
        }
        if ($exportTargets !== []) {
            $usedBy[] = examine_usage_link('Exports: ' . count($exportTargets), $exportTargets);
        }
        $usedByHtml = $usedBy === [] ? '<span class="muted">none</span>' : '<span class="name-usage-links">' . implode('<span class="muted">·</span>', $usedBy) . '</span>';
        echo '<tr id="name-' . $nameIndex . '"><td class="mono" data-sort-value="' . $nameIndex . '"><a class="xref" href="#name-' . $nameIndex . '">' . $nameIndex . '</a></td><td>' . $nameHtml . '</td><td>' . $usedByHtml . '</td><td class="mono">' . catalog_h((string)($name['flags'] ?? '')) . '</td></tr>';
    }
    echo '</tbody></table></div></section>';

    echo '<section id="tab-imports" class="examine-tab-panel" data-examine-panel="imports" role="tabpanel" aria-labelledby="examine-tab-imports" hidden>';
    echo '<h2>Imports</h2><p class="muted examine-reference-note">Object references: <span class="mono">0</span> = null; <span class="mono">&lt; 0</span> = Import at <span class="mono">(-index - 1)</span>; <span class="mono">&gt; 0</span> = Export at <span class="mono">(index - 1)</span>.</p><div class="examine-table-region"><table class="examine-imports-table" data-sortable-table><thead><tr><th>Index</th><th>Package ref</th><th>Class package</th><th>Class</th><th>Object</th><th>Outer ref</th><th>Full path</th><th>Root</th><th>Dependency</th></tr></thead><tbody>';
    foreach ($imports as $import) {
        $importIndex = (int)$import['import_index'];
        $packageRef = -($importIndex + 1);
        $dependency = $dependencyByImportId[(int)$import['id']] ?? null;
        $fullPathHtml = '<span class="mono path">' . catalog_h($import['full_path']) . '</span>';
        if ($dependency && !empty($dependency['resolved_file_id']) && $dependency['resolved_export_index'] !== null) {
            $fullPathHtml = examine_resolved_file_link((int)$dependency['resolved_file_id'], (string)$import['full_path'], (int)$dependency['resolved_export_index'], $backToFilesUrl);
        }

        echo '<tr id="import-' . $importIndex . '">';
        echo '<td class="mono" data-sort-value="' . $importIndex . '"><a class="xref" href="#import-' . $importIndex . '">' . $importIndex . '</a></td>';
        echo '<td class="mono" data-sort-value="' . $packageRef . '"><a class="xref" href="#import-' . $importIndex . '">' . $packageRef . '</a></td>';
        echo '<td>' . examine_name_link((string)$import['class_package'], $nameLookup) . '</td>';
        echo '<td>' . examine_name_link((string)$import['class_name'], $nameLookup) . '</td>';
        echo '<td>' . examine_name_link((string)$import['object_name'], $nameLookup) . '</td>';
        echo '<td>' . examine_ref_link((int)$import['outer_index']) . '</td>';
        echo '<td>' . $fullPathHtml . '</td>';
        echo '<td>' . examine_local_reference_link((string)$import['root_package'], $localReferenceTargets) . '</td>';
        echo '<td>' . examine_dependency_html($dependency, $backToFilesUrl) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div></section>';

    echo '<section id="tab-exports" class="examine-tab-panel" data-examine-panel="exports" role="tabpanel" aria-labelledby="examine-tab-exports" hidden>';
    echo '<h2>Exports</h2><p class="muted examine-reference-note">Object references: <span class="mono">0</span> = null; <span class="mono">&lt; 0</span> = Import at <span class="mono">(-index - 1)</span>; <span class="mono">&gt; 0</span> = Export at <span class="mono">(index - 1)</span>.</p><div class="examine-table-region"><table class="examine-exports-table" data-sortable-table><thead><tr><th>Index</th><th>Package ref</th><th>Class</th><th>Object</th><th>Outer ref</th><th>Local path</th><th>Full path</th><th>Flags</th><th>Serial size</th><th>Serial offset</th></tr></thead><tbody>';
    foreach ($exports as $export) {
        $exportIndex = (int)$export['export_index'];
        $packageRef = $exportIndex + 1;
        echo '<tr id="export-' . $exportIndex . '">';
        echo '<td class="mono" data-sort-value="' . $exportIndex . '"><a class="xref" href="#export-' . $exportIndex . '">' . $exportIndex . '</a></td>';
        echo '<td class="mono" data-sort-value="' . $packageRef . '"><a class="xref" href="#export-' . $exportIndex . '">' . $packageRef . '</a></td>';
        echo '<td>' . examine_name_link((string)$export['class_name'], $nameLookup) . '</td>';
        echo '<td>' . examine_name_link((string)$export['object_name'], $nameLookup) . '</td>';
        echo '<td>' . examine_ref_link((int)$export['outer_index']) . '</td>';
        echo '<td class="mono path">' . catalog_h($export['local_path']) . '</td>';
        echo '<td class="mono path">' . catalog_h($export['full_path']) . '</td>';
        echo '<td class="mono">' . catalog_h((string)($export['object_flags'] ?? '')) . '</td>';
        echo '<td class="mono" data-sort-value="' . (int)($export['serial_size'] ?? 0) . '">' . catalog_h((string)($export['serial_size'] ?? '')) . '</td>';
        echo '<td class="mono" data-sort-value="' . (int)($export['serial_offset'] ?? 0) . '">' . catalog_h((string)($export['serial_offset'] ?? '')) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div></section>';
    echo '</div>';

    echo '<a class="examine-to-top" href="#examine-top" data-to-top title="To Top" aria-label="To Top">↑</a>';
    echo <<<'HTML'
<script>
(function () {
    'use strict';

    var tabs = Array.from(document.querySelectorAll('[data-examine-tab]'));
    var panels = Array.from(document.querySelectorAll('[data-examine-panel]'));
    var referenceHighlightClass = 'is-reference-target';

    function tabFromTarget(target) {
        var panel = target ? target.closest('[data-examine-panel]') : null;
        return panel ? panel.dataset.examinePanel : 'names';
    }

    function activateTab(tabName) {
        if (!panels.some(function (panel) { return panel.dataset.examinePanel === tabName; })) {
            tabName = 'names';
        }
        panels.forEach(function (panel) {
            panel.hidden = panel.dataset.examinePanel !== tabName;
        });
        tabs.forEach(function (tab) {
            var active = tab.dataset.examineTab === tabName;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
    }

    function clearReferenceHighlights() {
        document.querySelectorAll('.' + referenceHighlightClass).forEach(function (row) {
            row.classList.remove(referenceHighlightClass);
        });
    }

    function revealTargets(targetIds, scroll) {
        var targets = targetIds.map(function (targetId) {
            return document.getElementById(targetId);
        }).filter(Boolean);
        if (!targets.length) {
            return;
        }

        clearReferenceHighlights();
        targets.forEach(function (target) {
            var row = target.closest('tr');
            if (row) {
                row.classList.add(referenceHighlightClass);
            }
        });

        activateTab(tabFromTarget(targets[0]));
        if (scroll) {
            window.setTimeout(function () {
                targets[0].scrollIntoView({ block: 'center' });
            }, 0);
        }
    }

    function revealHashTarget(scroll) {
        var hash = decodeURIComponent(window.location.hash.replace(/^#/, ''));
        if (!hash) {
            activateTab('names');
            return;
        }
        if (hash.indexOf('tab-') === 0) {
            clearReferenceHighlights();
            activateTab(hash.slice(4));
            return;
        }
        revealTargets([hash], scroll);
    }

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function (event) {
            event.preventDefault();
            var tabName = tab.dataset.examineTab;
            window.history.pushState(null, '', '#tab-' + tabName);
            clearReferenceHighlights();
            activateTab(tabName);
            document.getElementById('package-tables').scrollIntoView({ block: 'start' });
        });
    });

    document.addEventListener('click', function (event) {
        var toTop = event.target.closest('[data-to-top]');
        if (toTop) {
            event.preventDefault();
            window.scrollTo({ top: 0, behavior: 'smooth' });
            return;
        }

        var usageLink = event.target.closest('[data-reference-targets]');
        if (usageLink) {
            event.preventDefault();
            var targets = [];
            try {
                targets = JSON.parse(usageLink.dataset.referenceTargets || '[]');
            } catch (error) {
                targets = [];
            }
            if (targets.length) {
                window.history.pushState(null, '', '#' + targets[0]);
                revealTargets(targets, true);
            }
            return;
        }

        var localReference = event.target.closest('a.xref[href^="#"]');
        if (localReference) {
            var targetId = decodeURIComponent(localReference.getAttribute('href').slice(1));
            if (document.getElementById(targetId)) {
                event.preventDefault();
                window.history.pushState(null, '', '#' + targetId);
                revealTargets([targetId], true);
            }
        }
    });

    window.addEventListener('hashchange', function () {
        revealHashTarget(true);
    });
    revealHashTarget(true);

    document.querySelectorAll('table[data-sortable-table]').forEach(function (table) {
        var headerRow = table.tHead && table.tHead.rows.length ? table.tHead.rows[0] : null;
        var body = table.tBodies.length ? table.tBodies[0] : null;
        if (!headerRow || !body) {
            return;
        }

        var activeIndex = -1;
        var ascending = true;

        function cellValue(row, index) {
            var cell = row.cells[index];
            return cell ? (cell.dataset.sortValue || cell.textContent || '').trim() : '';
        }

        function compareValues(left, right) {
            var numeric = /^-?\d+(?:\.\d+)?$/;
            if (numeric.test(left) && numeric.test(right)) {
                return Number(left) - Number(right);
            }
            return left.localeCompare(right, undefined, { numeric: true, sensitivity: 'base' });
        }

        function updateHeaders(index) {
            Array.from(headerRow.cells).forEach(function (header, headerIndex) {
                header.classList.remove('is-sort-ascending', 'is-sort-descending');
                header.removeAttribute('aria-sort');
                if (headerIndex === index) {
                    header.classList.add(ascending ? 'is-sort-ascending' : 'is-sort-descending');
                    header.setAttribute('aria-sort', ascending ? 'ascending' : 'descending');
                }
            });
        }

        function sortBy(index) {
            if (activeIndex === index) {
                ascending = !ascending;
            } else {
                activeIndex = index;
                ascending = true;
            }

            Array.from(body.rows).sort(function (leftRow, rightRow) {
                var comparison = compareValues(cellValue(leftRow, index), cellValue(rightRow, index));
                return ascending ? comparison : -comparison;
            }).forEach(function (row) {
                body.appendChild(row);
            });
            updateHeaders(index);
        }

        Array.from(headerRow.cells).forEach(function (header, index) {
            header.tabIndex = 0;
            header.setAttribute('role', 'button');
            header.setAttribute('title', 'Click to sort ascending. Click again to sort descending.');
            header.setAttribute('aria-label', header.textContent.trim() + '. Click to sort this table.');
            header.addEventListener('click', function () { sortBy(index); });
            header.addEventListener('keydown', function (event) {
                if (event.key === 'Enter' || event.key === ' ') {
                    event.preventDefault();
                    sortBy(index);
                }
            });
        });
    });
})();
</script>
HTML;

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Examine error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
