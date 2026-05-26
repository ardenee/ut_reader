<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $id = (int)($_GET['id'] ?? 0);
    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=?', [$id]);
    if (!$file) {
        throw new RuntimeException('File not found');
    }

    catalog_head('Download');
    echo '<div class="card"><h1>Download</h1><p><strong>' . catalog_h($file['package_name']) . '</strong><br>' . catalog_h($file['original_name']) . '</p>';
    echo '<p class="muted">The real storage location is hidden. Downloads are served through the catalog controller.</p>';
    echo '<p><a class="button" href="index.php?page=download&id=' . (int)$file['id'] . '">Download selected file</a></p></div>';

    $deps = catalog_all($db, 'SELECT DISTINCT rf.id, rf.package_name, rf.original_name, rf.file_size, rf.md5, rf.is_compressed FROM ue_dependencies d JOIN ue_files rf ON rf.id=d.resolved_file_id WHERE d.file_id=? AND d.status="resolved" ORDER BY rf.package_name, rf.original_name', [$id]);
    echo '<div class="card"><h2>Resolved dependency files</h2>';
    if (!$deps) {
        echo '<p class="muted">No resolved dependency files are available for this package yet.</p>';
    } else {
        echo '<table><tr><th>Package</th><th>File</th><th>Size</th><th>Type</th><th>Download</th></tr>';
        foreach ($deps as $dep) {
            $compressed = (int)($dep['is_compressed'] ?? 0) === 1;
            echo '<tr><td class="mono">' . catalog_h($dep['package_name']) . '</td><td>' . catalog_h($dep['original_name']) . '<br><span class="mono small">' . catalog_h($dep['md5']) . '</span></td><td>' . catalog_h(catalog_bytes((int)$dep['file_size'])) . '</td><td><span class="dep ' . ($compressed ? 'compressed' : 'uncompressed') . '">' . ($compressed ? 'compressed' : 'uncompressed') . '</span></td><td><a class="button" href="index.php?page=download&id=' . (int)$dep['id'] . '">download</a></td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    $missing = catalog_all($db, 'SELECT required_package, required_object_path FROM ue_dependencies WHERE file_id=? AND status="missing" ORDER BY required_package, required_object_path LIMIT 500', [$id]);
    echo '<div class="card"><h2>Missing dependency objects</h2>';
    if (!$missing) {
        echo '<p class="muted">No missing dependency objects.</p>';
    } else {
        echo '<table><tr><th>Required package</th><th>Required object</th></tr>';
        foreach ($missing as $row) {
            echo '<tr><td class="mono">' . catalog_h($row['required_package']) . '</td><td class="mono path">' . catalog_h($row['required_object_path']) . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    catalog_head('Error');
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
