<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $gameId = (int)($_GET['id'] ?? 0);
    $game = catalog_one($db, 'SELECT * FROM ue_games WHERE id=?', [$gameId]);
    if (!$game) {
        throw new RuntimeException('Game not found');
    }

    $files = catalog_all($db, "SELECT f.*, SUM(d.status='resolved') resolved_count, SUM(d.status='missing') missing_count, SUM(d.status='package_only') package_only_count, SUM(d.status='common') common_count, COUNT(DISTINCT l.id) source_location_count FROM ue_files f LEFT JOIN ue_dependencies d ON d.file_id=f.id LEFT JOIN ue_file_locations l ON l.file_id=f.id AND l.exists_in_source=1 WHERE f.game_id=? GROUP BY f.id ORDER BY f.package_name,f.original_name", [$gameId]);

    catalog_head($game['name']);
    echo '<script src="assets/catalog-popups.js"></script>';
    echo '<div class="card"><h1>' . catalog_h($game['name']) . '</h1><p class="muted">Files, dependency status, hidden-path downloads and popup details.</p><p><a class="button" href="games.php">Back to games</a> <a class="button" href="index.php?page=game&id=' . (int)$gameId . '">Upload/admin view</a></p></div>';

    echo '<div class="card"><h2>Files</h2><table><tr><th>Package</th><th>File</th><th>Identity</th><th>Size</th><th>Type</th><th>Dependencies</th><th>Sources</th><th>Actions</th></tr>';
    foreach ($files as $file) {
        $deps = '';
        foreach (['resolved','missing','package_only','common'] as $key) {
            $count = (int)($file[$key . '_count'] ?? 0);
            if ($count) {
                $deps .= '<span class="dep ' . $key . '">' . $key . ': ' . $count . '</span>';
            }
        }
        $deps = $deps ?: '<span class="muted">none</span>';
        $compressed = (int)($file['is_compressed'] ?? 0) === 1;
        $type = '<span class="dep ' . ($compressed ? 'compressed' : 'uncompressed') . '">' . ($compressed ? 'compressed' : 'uncompressed') . '</span>';
        $sources = (int)($file['source_location_count'] ?? 0);
        $sourceText = $sources ? '<span class="dep resolved">locations: ' . $sources . '</span>' : '<span class="muted">none</span>';
        $id = (int)$file['id'];
        echo '<tr>';
        echo '<td class="mono">' . catalog_h($file['package_name']) . '</td>';
        echo '<td>' . catalog_h($file['original_name']) . '</td>';
        echo '<td><span class="mono small">GUID ' . catalog_h($file['package_guid']) . '</span><br><span class="mono small">MD5 ' . catalog_h($file['md5']) . '</span></td>';
        echo '<td>' . catalog_h(catalog_bytes((int)$file['file_size'])) . '</td>';
        echo '<td>' . $type . '</td>';
        echo '<td>' . $deps . '</td>';
        echo '<td>' . $sourceText . '</td>';
        echo '<td><a href="file-info.php?id=' . $id . '" onclick="return catalogPopup(this.href,\'fileInfo' . $id . '\',1100,780)">details</a> | <a href="download-info.php?id=' . $id . '" onclick="return catalogPopup(this.href,\'downloadInfo' . $id . '\',1000,760)">download</a> | <a href="index.php?page=examine&id=' . $id . '">examine</a></td>';
        echo '</tr>';
    }
    echo '</table></div>';
    catalog_foot();
} catch (Throwable $e) {
    catalog_head('Error');
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
