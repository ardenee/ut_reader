<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $id = (int)($_GET['id'] ?? 0);
    $file = catalog_one($db, 'SELECT f.*, g.name game_name FROM ue_files f JOIN ue_games g ON g.id=f.game_id WHERE f.id=?', [$id]);
    if (!$file) {
        throw new RuntimeException('File not found');
    }

    catalog_head('File info');
    $compressed = (int)($file['is_compressed'] ?? 0) === 1;
    echo '<div class="card"><h1>' . catalog_h($file['package_name']) . '</h1>';
    echo '<p>' . catalog_h($file['original_name']) . ' / ' . catalog_h($file['game_name']) . '</p>';
    echo '<p><span class="dep ' . ($compressed ? 'compressed' : 'uncompressed') . '">' . ($compressed ? 'compressed' : 'uncompressed') . '</span> <span class="mono small">flags 0x' . strtoupper(str_pad(dechex((int)($file['compression_flags'] ?? 0)), 8, '0', STR_PAD_LEFT)) . '</span></p>';
    echo '<table><tr><th>MD5</th><td class="mono">' . catalog_h($file['md5']) . '</td></tr><tr><th>SHA1</th><td class="mono">' . catalog_h($file['sha1']) . '</td></tr><tr><th>GUID</th><td class="mono">' . catalog_h($file['package_guid']) . '</td></tr><tr><th>Status</th><td>' . catalog_h($file['scan_status']) . '</td></tr><tr><th>Tables</th><td>' . (int)$file['name_count'] . ' names / ' . (int)$file['import_count'] . ' imports / ' . (int)$file['export_count'] . ' exports</td></tr></table>';
    echo '</div>';

    $locations = catalog_all($db, 'SELECT s.name source_name, s.source_type, l.source_relative_path, l.last_seen_at FROM ue_file_locations l JOIN ue_sources s ON s.id=l.source_id WHERE l.file_id=? AND l.exists_in_source=1 ORDER BY s.name, l.source_relative_path', [$id]);
    echo '<div class="card"><h2>Source availability</h2>';
    if (!$locations) {
        echo '<p class="muted">No configured source currently records this file.</p>';
    } else {
        echo '<p class="muted">Only the source name/type and relative path are shown. Real source base paths are hidden.</p>';
        echo '<table><tr><th>Source</th><th>Type</th><th>Relative path</th><th>Last seen</th></tr>';
        foreach ($locations as $loc) {
            echo '<tr><td>' . catalog_h($loc['source_name']) . '</td><td class="mono">' . catalog_h($loc['source_type']) . '</td><td class="mono path">' . catalog_h($loc['source_relative_path']) . '</td><td>' . catalog_h($loc['last_seen_at']) . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    if (!empty($file['scan_notes'])) {
        echo '<div class="card"><h2>Scan notes</h2><pre class="mono">' . catalog_h($file['scan_notes']) . '</pre></div>';
    }

    $deps = catalog_all($db, 'SELECT d.*, rf.package_name resolved_package, rf.original_name resolved_file, rf.id resolved_id FROM ue_dependencies d LEFT JOIN ue_files rf ON rf.id=d.resolved_file_id WHERE d.file_id=? ORDER BY FIELD(d.status,"missing","package_only","resolved","common"), d.required_package, d.required_object_path', [$id]);
    echo '<div class="card"><h2>Dependencies</h2><table><tr><th>Status</th><th>Required object</th><th>Resolved package</th></tr>';
    foreach ($deps as $dep) {
        $resolved = $dep['resolved_id'] ? '<a target="_blank" href="file-info.php?id=' . (int)$dep['resolved_id'] . '">' . catalog_h($dep['resolved_package'] ?: $dep['resolved_file']) . '</a>' : '<span class="muted">not resolved</span>';
        echo '<tr><td><span class="dep ' . catalog_h($dep['status']) . '">' . catalog_h($dep['status']) . '</span></td><td class="mono path">' . catalog_h($dep['required_object_path']) . '</td><td>' . $resolved . '</td></tr>';
    }
    echo '</table></div>';

    $usedBy = catalog_all($db, 'SELECT DISTINCT src.id, src.package_name, src.original_name FROM ue_dependencies d JOIN ue_files src ON src.id=d.file_id WHERE d.resolved_file_id=? ORDER BY src.package_name, src.original_name LIMIT 200', [$id]);
    echo '<div class="card"><h2>Used by</h2>';
    if (!$usedBy) {
        echo '<p class="muted">No resolved reverse links yet.</p>';
    } else {
        echo '<table><tr><th>Package</th><th>File</th></tr>';
        foreach ($usedBy as $row) {
            echo '<tr><td class="mono">' . catalog_h($row['package_name']) . '</td><td><a target="_blank" href="file-info.php?id=' . (int)$row['id'] . '">' . catalog_h($row['original_name']) . '</a></td></tr>';
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
