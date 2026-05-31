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

function examine_ref_link(int $ref): string
{
    if ($ref === 0) {
        return '<span class="muted">none</span>';
    }

    if ($ref < 0) {
        $idx = abs($ref) - 1;
        return '<a class="xref mono" href="#import-' . $idx . '">' . $ref . '</a>';
    }

    $idx = $ref - 1;
    return '<a class="xref mono" href="#export-' . $idx . '">' . $ref . '</a>';
}

function examine_name_link(string $value, array $nameLookup): string
{
    $text = trim($value);
    if ($text === '') {
        return '';
    }

    $key = strtolower($text);
    if (isset($nameLookup[$key])) {
        return '<a class="xref mono path" href="#name-' . (int)$nameLookup[$key] . '">' . catalog_h($text) . '</a>';
    }

    return '<span class="mono path">' . catalog_h($text) . '</span>';
}

function examine_file_link(?int $fileId, string $label): string
{
    if (!$fileId) {
        return '<span class="muted">none</span>';
    }
    return '<a class="xref" href="file-examine.php?id=' . (int)$fileId . '">' . catalog_h($label) . '</a>';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $id = examine_page_int('id', 0, 1, PHP_INT_MAX);
    $file = catalog_one($db, 'SELECT f.*, g.name game_name, g.id game_id FROM ue_files f JOIN ue_games g ON g.id=f.game_id WHERE f.id=?', [$id]);
    if (!$file) {
        throw new RuntimeException('File not found');
    }

    $names = catalog_all($db, 'SELECT * FROM ue_names WHERE file_id=? ORDER BY name_index', [$id]);
    $imports = catalog_all($db, 'SELECT * FROM ue_imports WHERE file_id=? ORDER BY import_index', [$id]);
    $exports = catalog_all($db, 'SELECT * FROM ue_exports WHERE file_id=? ORDER BY export_index', [$id]);
    $dependencies = catalog_all($db, 'SELECT d.*, rf.package_name resolved_package, rf.original_name resolved_file, re.full_path resolved_export_path FROM ue_dependencies d LEFT JOIN ue_files rf ON rf.id=d.resolved_file_id LEFT JOIN ue_exports re ON re.id=d.resolved_export_id WHERE d.file_id=? ORDER BY d.id', [$id]);

    $nameLookup = [];
    foreach ($names as $name) {
        $text = strtolower(trim((string)$name['name_text']));
        if ($text !== '' && !isset($nameLookup[$text])) {
            $nameLookup[$text] = (int)$name['name_index'];
        }
    }

    $dependencyByImportId = [];
    foreach ($dependencies as $dep) {
        $dependencyByImportId[(int)$dep['import_id']] = $dep;
    }

    catalog_head('Examine ' . (string)$file['package_name']);
    echo '<div class="card hero"><h1>Examine ' . catalog_h($file['package_name']) . '</h1><p class="muted">Database-backed package header, names, imports, exports and dependency links.</p><p><a class="button" href="game-files.php?id=' . (int)$file['game_id'] . '">Back to files</a> <a class="button" href="file-info.php?id=' . $id . '">Details</a></p></div>';

    echo '<div class="card"><h2>Package header</h2><table>';
    foreach ([
        'Package version' => $file['package_version'],
        'Licensee version' => $file['licensee_version'],
        'GUID' => $file['package_guid'],
        'Name count' => $file['name_count'],
        'Import count' => $file['import_count'],
        'Export count' => $file['export_count'],
    ] as $label => $value) {
        echo '<tr><th>' . catalog_h($label) . '</th><td class="mono path">' . catalog_h((string)$value) . '</td></tr>';
    }
    echo '</table></div>';

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

    echo '<div class="card"><h2>Names</h2><table><tr><th>Index</th><th>Name</th><th>Flags</th></tr>';
    foreach ($names as $name) {
        $nameIndex = (int)$name['name_index'];
        echo '<tr id="name-' . $nameIndex . '"><td class="mono"><a class="xref" href="#name-' . $nameIndex . '">' . $nameIndex . '</a></td><td class="mono path">' . catalog_h($name['name_text']) . '</td><td class="mono">' . catalog_h((string)($name['flags'] ?? '')) . '</td></tr>';
    }
    echo '</table></div>';

    echo '<div class="card"><h2>Imports</h2><table><tr><th>Index</th><th>Package ref</th><th>Class package</th><th>Class</th><th>Object</th><th>Outer ref</th><th>Full path</th><th>Root</th><th>Dependency</th><th>Common</th></tr>';
    foreach ($imports as $imp) {
        $importIndex = (int)$imp['import_index'];
        $packageRef = -($importIndex + 1);
        $dep = $dependencyByImportId[(int)$imp['id']] ?? null;
        $depHtml = '<span class="muted">not built</span>';
        if ($dep) {
            $status = (string)$dep['status'];
            $depHtml = '<span class="dep ' . catalog_h($status) . '">' . catalog_h($status) . '</span>';
            if (!empty($dep['resolved_file_id'])) {
                $label = (string)($dep['resolved_package'] ?: $dep['resolved_file'] ?: ('file #' . (int)$dep['resolved_file_id']));
                $depHtml .= ' ' . examine_file_link((int)$dep['resolved_file_id'], $label);
            }
            if (!empty($dep['resolved_export_id']) && !empty($dep['resolved_export_path'])) {
                $depHtml .= '<br><span class="mono small path">' . catalog_h($dep['resolved_export_path']) . '</span>';
            }
        }

        echo '<tr id="import-' . $importIndex . '">';
        echo '<td class="mono"><a class="xref" href="#import-' . $importIndex . '">' . $importIndex . '</a></td>';
        echo '<td class="mono"><a class="xref" href="#import-' . $importIndex . '">' . $packageRef . '</a></td>';
        echo '<td>' . examine_name_link((string)$imp['class_package'], $nameLookup) . '</td>';
        echo '<td>' . examine_name_link((string)$imp['class_name'], $nameLookup) . '</td>';
        echo '<td>' . examine_name_link((string)$imp['object_name'], $nameLookup) . '</td>';
        echo '<td>' . examine_ref_link((int)$imp['outer_index']) . '</td>';
        echo '<td class="mono path">' . catalog_h($imp['full_path']) . '</td>';
        echo '<td class="mono path">' . catalog_h($imp['root_package']) . '</td>';
        echo '<td>' . $depHtml . '</td>';
        echo '<td>' . ((int)$imp['is_common'] ? 'yes' : 'no') . '</td>';
        echo '</tr>';
    }
    echo '</table></div>';

    echo '<div class="card"><h2>Exports</h2><table><tr><th>Index</th><th>Package ref</th><th>Class</th><th>Object</th><th>Outer ref</th><th>Local path</th><th>Full path</th><th>Flags</th><th>Serial size</th><th>Serial offset</th></tr>';
    foreach ($exports as $exp) {
        $exportIndex = (int)$exp['export_index'];
        $packageRef = $exportIndex + 1;
        echo '<tr id="export-' . $exportIndex . '">';
        echo '<td class="mono"><a class="xref" href="#export-' . $exportIndex . '">' . $exportIndex . '</a></td>';
        echo '<td class="mono"><a class="xref" href="#export-' . $exportIndex . '">' . $packageRef . '</a></td>';
        echo '<td class="mono path">' . catalog_h($exp['class_name']) . '</td>';
        echo '<td>' . examine_name_link((string)$exp['object_name'], $nameLookup) . '</td>';
        echo '<td>' . examine_ref_link((int)$exp['outer_index']) . '</td>';
        echo '<td class="mono path">' . catalog_h($exp['local_path']) . '</td>';
        echo '<td class="mono path">' . catalog_h($exp['full_path']) . '</td>';
        echo '<td class="mono">' . catalog_h((string)($exp['object_flags'] ?? '')) . '</td>';
        echo '<td class="mono">' . catalog_h((string)($exp['serial_size'] ?? '')) . '</td>';
        echo '<td class="mono">' . catalog_h((string)($exp['serial_offset'] ?? '')) . '</td>';
        echo '</tr>';
    }
    echo '</table></div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Examine error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
