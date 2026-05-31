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

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $id = examine_page_int('id', 0, 1, PHP_INT_MAX);
    $file = catalog_one($db, 'SELECT f.*, g.name game_name, g.id game_id FROM ue_files f JOIN ue_games g ON g.id=f.game_id WHERE f.id=?', [$id]);
    if (!$file) {
        throw new RuntimeException('File not found');
    }

    $namesLimit = examine_page_int('names_limit', 250, 1, 5000);
    $importsLimit = examine_page_int('imports_limit', 500, 1, 5000);
    $exportsLimit = examine_page_int('exports_limit', 500, 1, 5000);

    catalog_head('Examine ' . (string)$file['package_name']);
    echo '<div class="card hero"><h1>Examine ' . catalog_h($file['package_name']) . '</h1><p class="muted">Database-backed package header, names, imports and exports.</p><p><a class="button" href="game-files.php?id=' . (int)$file['game_id'] . '">Back to files</a> <a class="button" href="file-info.php?id=' . $id . '">Details</a></p></div>';

    echo '<div class="card"><h2>Package header</h2><table>';
    foreach ([
        'Game' => $file['game_name'],
        'Original file' => $file['original_name'],
        'Package name' => $file['package_name'],
        'Extension' => $file['extension'],
        'Detected engine' => $file['detected_engine_key'] ?? '',
        'Package version' => $file['package_version'],
        'Licensee version' => $file['licensee_version'],
        'Detected package version' => $file['detected_package_version'] ?? '',
        'Detected licensee version' => $file['detected_licensee_version'] ?? '',
        'Detection confidence' => $file['detection_confidence'] ?? '',
        'GUID' => $file['package_guid'],
        'MD5' => $file['md5'],
        'SHA1' => $file['sha1'],
        'Size' => catalog_bytes((int)$file['file_size']),
        'Stored path' => $file['relative_path'],
        'Scan status' => $file['scan_status'],
        'Uploaded' => $file['uploaded_at'],
        'Table counts' => (int)$file['name_count'] . ' names / ' . (int)$file['import_count'] . ' imports / ' . (int)$file['export_count'] . ' exports',
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

    $names = catalog_all($db, 'SELECT * FROM ue_names WHERE file_id=? ORDER BY name_index LIMIT ' . $namesLimit, [$id]);
    echo '<div class="card"><h2>Names</h2><p class="muted small">Showing up to ' . $namesLimit . ' rows.</p><table><tr><th>Index</th><th>Name</th><th>Flags</th></tr>';
    foreach ($names as $name) {
        echo '<tr><td class="mono">' . (int)$name['name_index'] . '</td><td class="mono path">' . catalog_h($name['name_text']) . '</td><td class="mono">' . catalog_h((string)($name['flags'] ?? '')) . '</td></tr>';
    }
    echo '</table></div>';

    $imports = catalog_all($db, 'SELECT * FROM ue_imports WHERE file_id=? ORDER BY import_index LIMIT ' . $importsLimit, [$id]);
    echo '<div class="card"><h2>Imports</h2><p class="muted small">Showing up to ' . $importsLimit . ' rows.</p><table><tr><th>Index</th><th>Class package</th><th>Class</th><th>Object</th><th>Outer</th><th>Full path</th><th>Root</th><th>Common</th></tr>';
    foreach ($imports as $imp) {
        echo '<tr><td class="mono">' . (int)$imp['import_index'] . '</td><td class="mono path">' . catalog_h($imp['class_package']) . '</td><td class="mono path">' . catalog_h($imp['class_name']) . '</td><td class="mono path">' . catalog_h($imp['object_name']) . '</td><td class="mono">' . (int)$imp['outer_index'] . '</td><td class="mono path">' . catalog_h($imp['full_path']) . '</td><td class="mono path">' . catalog_h($imp['root_package']) . '</td><td>' . ((int)$imp['is_common'] ? 'yes' : 'no') . '</td></tr>';
    }
    echo '</table></div>';

    $exports = catalog_all($db, 'SELECT * FROM ue_exports WHERE file_id=? ORDER BY export_index LIMIT ' . $exportsLimit, [$id]);
    echo '<div class="card"><h2>Exports</h2><p class="muted small">Showing up to ' . $exportsLimit . ' rows.</p><table><tr><th>Index</th><th>Class</th><th>Object</th><th>Outer</th><th>Local path</th><th>Full path</th><th>Flags</th><th>Serial size</th><th>Serial offset</th></tr>';
    foreach ($exports as $exp) {
        echo '<tr><td class="mono">' . (int)$exp['export_index'] . '</td><td class="mono path">' . catalog_h($exp['class_name']) . '</td><td class="mono path">' . catalog_h($exp['object_name']) . '</td><td class="mono">' . (int)$exp['outer_index'] . '</td><td class="mono path">' . catalog_h($exp['local_path']) . '</td><td class="mono path">' . catalog_h($exp['full_path']) . '</td><td class="mono">' . catalog_h((string)($exp['object_flags'] ?? '')) . '</td><td class="mono">' . catalog_h((string)($exp['serial_size'] ?? '')) . '</td><td class="mono">' . catalog_h((string)($exp['serial_offset'] ?? '')) . '</td></tr>';
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
