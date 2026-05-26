<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';

function duplicates_is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    catalog_head('GUID duplicates');

    if (!duplicates_is_admin()) {
        echo '<div class="card"><h1>Admin required</h1><p>Log in through the main catalog admin page first.</p></div>';
        catalog_foot();
        exit;
    }

    echo '<div class="card"><h1>GUID duplicate report</h1><p class="muted">Shows packages with the same Unreal package GUID in the same game. This catches compressed/uncompressed duplicates that have different MD5 hashes.</p><p><a class="button" href="games.php">Games</a> <a class="button" href="source-scan.php">Source scanner</a> <a class="button" href="sources.php">Sources</a></p></div>';

    $groups = catalog_all($db, '
        SELECT f.game_id, g.name AS game_name, f.package_guid, COUNT(*) AS duplicate_count
        FROM ue_files f
        JOIN ue_games g ON g.id=f.game_id
        WHERE f.package_guid IS NOT NULL AND f.package_guid <> ""
        GROUP BY f.game_id, f.package_guid
        HAVING COUNT(*) > 1
        ORDER BY g.name, duplicate_count DESC, f.package_guid
    ');

    if (!$groups) {
        echo '<div class="card"><h2>No GUID duplicates found</h2><p class="muted">No duplicate package GUID groups currently exist.</p></div>';
        catalog_foot();
        exit;
    }

    echo '<div class="card"><h2>Duplicate groups</h2><table><tr><th>Game</th><th>Package GUID</th><th>Count</th></tr>';
    foreach ($groups as $group) {
        echo '<tr><td>' . catalog_h($group['game_name']) . '</td><td class="mono">' . catalog_h($group['package_guid']) . '</td><td>' . (int)$group['duplicate_count'] . '</td></tr>';
    }
    echo '</table></div>';

    foreach ($groups as $group) {
        $files = catalog_all($db, '
            SELECT f.*,
                   COUNT(DISTINCT d.id) AS dependency_count,
                   SUM(d.status="missing") AS missing_count,
                   SUM(d.status="resolved") AS resolved_count,
                   COUNT(DISTINCT l.id) AS source_location_count
            FROM ue_files f
            LEFT JOIN ue_dependencies d ON d.file_id=f.id
            LEFT JOIN ue_file_locations l ON l.file_id=f.id AND l.exists_in_source=1
            WHERE f.game_id=? AND f.package_guid=?
            GROUP BY f.id
            ORDER BY f.is_compressed ASC, f.file_size DESC, f.uploaded_at ASC, f.id ASC
        ', [(int)$group['game_id'], (string)$group['package_guid']]);

        echo '<div class="card"><h2>' . catalog_h($group['game_name']) . ' / <span class="mono">' . catalog_h($group['package_guid']) . '</span></h2>';
        echo '<p class="muted">Suggested canonical choice is usually the uncompressed package if present, otherwise the largest/oldest verified package. This page is report-only for now.</p>';
        echo '<table><tr><th>ID</th><th>Package</th><th>File</th><th>MD5</th><th>Size</th><th>Type</th><th>Deps</th><th>Sources</th><th>Uploaded</th><th>Open</th></tr>';
        foreach ($files as $file) {
            $compressed = (int)($file['is_compressed'] ?? 0) === 1;
            $type = '<span class="dep ' . ($compressed ? 'compressed' : 'uncompressed') . '">' . ($compressed ? 'compressed' : 'uncompressed') . '</span>';
            $deps = 'total ' . (int)$file['dependency_count'] . ' / resolved ' . (int)$file['resolved_count'] . ' / missing ' . (int)$file['missing_count'];
            echo '<tr>';
            echo '<td class="mono">' . (int)$file['id'] . '</td>';
            echo '<td class="mono">' . catalog_h($file['package_name']) . '</td>';
            echo '<td>' . catalog_h($file['original_name']) . '</td>';
            echo '<td class="mono small">' . catalog_h($file['md5']) . '</td>';
            echo '<td>' . catalog_h(catalog_bytes((int)$file['file_size'])) . '</td>';
            echo '<td>' . $type . '</td>';
            echo '<td class="small">' . catalog_h($deps) . '</td>';
            echo '<td>' . (int)$file['source_location_count'] . '</td>';
            echo '<td class="small">' . catalog_h($file['uploaded_at']) . '</td>';
            echo '<td><a href="file-info.php?id=' . (int)$file['id'] . '" target="_blank">info</a> | <a href="download-info.php?id=' . (int)$file['id'] . '" target="_blank">download</a> | <a href="index.php?page=file&id=' . (int)$file['id'] . '">admin</a></td>';
            echo '</tr>';
        }
        echo '</table></div>';
    }

    catalog_foot();
} catch (Throwable $e) {
    catalog_head('Duplicate report error');
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
