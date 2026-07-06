<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';

function missing_page_int(string $key, int $default = 0): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        return $default;
    }
    return max(0, (int)$value);
}

function missing_selected_package(): string
{
    $value = trim((string)($_GET['package'] ?? ''));
    return substr($value, 0, 255);
}

function missing_page_url(array $params = []): string
{
    $query = [];
    foreach ($params as $key => $value) {
        if ($value !== null && $value !== '' && $value !== 0) {
            $query[$key] = $value;
        }
    }
    return 'missing.php' . ($query === [] ? '' : '?' . http_build_query($query));
}

function missing_file_links(int $fileId): string
{
    return '<a class="button secondary" href="file-info.php?id=' . $fileId . '">Info</a>'
        . ' <a class="button secondary" href="file-examine.php?id=' . $fileId . '">Examine</a>';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if (!catalog_require_admin_page('Missing Files')) {
        exit;
    }

    $selectedPackage = missing_selected_package();
    $perPage = 200;
    $filesWithMissing = catalog_count(
        $db,
        'SELECT COUNT(DISTINCT file_id) c FROM ue_dependencies WHERE status="missing"'
    );
    $filePageCount = max(1, (int)ceil($filesWithMissing / $perPage));
    $filePage = max(1, min($filePageCount, missing_page_int('files_page', 1)));
    $fileOffset = ($filePage - 1) * $perPage;

    catalog_head('Missing Files');

    $missingObjects = catalog_count($db, 'SELECT COUNT(*) c FROM ue_dependencies WHERE status="missing"');
    $missingPackages = catalog_count($db, 'SELECT COUNT(DISTINCT required_package) c FROM ue_dependencies WHERE status="missing" AND required_package IS NOT NULL AND required_package<>""');
    $resolved = catalog_count($db, 'SELECT COUNT(*) c FROM ue_dependencies WHERE status="resolved"');
    $approved = catalog_count($db, 'SELECT COUNT(*) c FROM ue_federation_request_items WHERE status="approved"');
    $imported = catalog_count($db, 'SELECT COUNT(*) c FROM ue_federation_request_items WHERE status="imported"');

    catalog_page_header(
        'Missing Files',
        'See which catalog files require each missing package or object, then use those links to inspect the owning file before requesting a dependency.',
        [
            'Generate Request' => 'federation/request-generate.php',
            'Request Status' => 'federation/request-status.php',
            'Approved Downloads' => 'federation/approved-downloads.php',
            'Parent Inventory' => 'federation/peer-inventory.php',
            'Conflicts' => 'federation/conflicts.php',
        ]
    );

    echo <<<'CSS'
<style>
.missing-file-table { min-width:1240px; }
.missing-package-table { min-width:860px; }
.missing-detail-table { min-width:1280px; }
.missing-file-name { min-width:225px; }
.missing-package-list { min-width:260px; max-width:460px; overflow-wrap:anywhere; }
.missing-object-path { min-width:310px; max-width:560px; overflow-wrap:anywhere; }
.missing-actions { white-space:nowrap; min-width:132px; }
.missing-pagination { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin:12px 0 0; }
.missing-pagination .muted { margin-right:4px; }
</style>
CSS;

    echo '<div class="grid">';
    catalog_stat_card('Missing dependency objects', $missingObjects, '', $missingObjects > 0 ? 'attention' : 'good');
    catalog_stat_card('Missing packages', $missingPackages, '', $missingPackages > 0 ? 'attention' : 'good');
    catalog_stat_card('Files with missing dependencies', $filesWithMissing, '', $filesWithMissing > 0 ? 'attention' : 'good');
    catalog_stat_card('Resolved dependency rows', $resolved);
    catalog_stat_card('Approved request items', $approved);
    catalog_stat_card('Imported request items', $imported);
    echo '</div>';

    echo '<div class="card"><h2>Repair workflow</h2><div class="grid">';
    catalog_tool_card('1. View parent inventory', 'federation/peer-inventory.php', 'See what the parent/peers have compared with this site.');
    catalog_tool_card('2. Generate missing-file request', 'federation/request-generate.php', 'Submit missing dependency list to the parent for approval.');
    catalog_tool_card('3. Check request status', 'federation/request-status.php', 'Poll parent approval/denial status and cancel active requests.');
    catalog_tool_card('4. Queue approved downloads', 'federation/approved-downloads.php', 'Queue parent-approved files for controlled federation download.');
    catalog_tool_card('5. Run transfers/imports', 'transfers.php', 'Download/import approved files using the worker.');
    catalog_tool_card('6. Review conflicts', 'federation/conflicts.php', 'Check GUID/package/hash mismatches before trusting matches.');
    echo '</div></div>';

    if ($selectedPackage !== '') {
        $detailRows = catalog_all(
            $db,
            'SELECT d.id dependency_id, d.required_object_path, d.required_package, '
            . 'f.id file_id, f.package_name owner_package_name, f.original_name owner_original_name, '
            . 'g.id game_id, g.name game_name, '
            . 'i.class_package, i.class_name, i.object_name import_object_name, i.full_path import_full_path '
            . 'FROM ue_dependencies d '
            . 'JOIN ue_files f ON f.id=d.file_id '
            . 'JOIN ue_games g ON g.id=f.game_id '
            . 'LEFT JOIN ue_imports i ON i.id=d.import_id '
            . 'WHERE d.status="missing" AND d.required_package=? '
            . 'ORDER BY g.name, f.package_name, f.original_name, d.required_object_path',
            [$selectedPackage]
        );

        echo '<div class="card"><h2>Files requiring package: <span class="mono">' . catalog_h($selectedPackage) . '</span></h2>';
        echo '<p class="muted">Each row is a missing dependency owned by the file in the Requiring File column. Inspect that file to see its full Imports/Exports tables.</p>';
        echo '<p><a class="button secondary" href="' . catalog_h(missing_page_url(['files_page' => $filePage])) . '">Clear package detail</a></p>';
        if ($detailRows === []) {
            echo '<p class="muted">No missing dependency rows currently match this package name.</p>';
        } else {
            echo '<div class="table-wrap"><table class="missing-detail-table"><thead><tr><th>Game</th><th>Requiring File</th><th>Required Object Path</th><th>Import Class</th><th>Import Path</th><th>Actions</th></tr></thead><tbody>';
            foreach ($detailRows as $row) {
                $importClass = trim((string)$row['class_package'] . '.' . (string)$row['class_name']);
                echo '<tr>';
                echo '<td><a href="game-files.php?id=' . (int)$row['game_id'] . '">' . catalog_h((string)$row['game_name']) . '</a></td>';
                echo '<td class="missing-file-name"><strong class="mono">' . catalog_h((string)$row['owner_package_name']) . '</strong><br><span class="muted small">' . catalog_h((string)$row['owner_original_name']) . '</span></td>';
                echo '<td class="mono missing-object-path">' . catalog_h((string)$row['required_object_path']) . '</td>';
                echo '<td class="mono">' . catalog_h($importClass !== '.' ? $importClass : '') . '</td>';
                echo '<td class="mono missing-object-path">' . catalog_h((string)$row['import_full_path']) . '</td>';
                echo '<td class="missing-actions">' . missing_file_links((int)$row['file_id']) . '</td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
        }
        echo '</div>';
    }

    $affectedFiles = catalog_all(
        $db,
        'SELECT f.id file_id, f.package_name, f.original_name, g.id game_id, g.name game_name, '
        . 'COUNT(d.id) missing_object_rows, COUNT(DISTINCT d.required_package) missing_package_count, '
        . 'GROUP_CONCAT(DISTINCT d.required_package ORDER BY d.required_package SEPARATOR ", ") missing_package_names '
        . 'FROM ue_dependencies d '
        . 'JOIN ue_files f ON f.id=d.file_id '
        . 'JOIN ue_games g ON g.id=f.game_id '
        . 'WHERE d.status="missing" '
        . 'GROUP BY f.id, f.package_name, f.original_name, g.id, g.name '
        . 'ORDER BY missing_object_rows DESC, missing_package_count DESC, g.name, f.package_name, f.original_name '
        . 'LIMIT ' . $perPage . ' OFFSET ' . $fileOffset
    );

    echo '<div class="card"><h2>Files with missing dependencies</h2>';
    echo '<p class="muted">These are the actual catalog files whose Import tables contain missing dependency rows. Use Info or Examine to inspect the owner file, or open a missing package to see every required object path.</p>';
    if ($affectedFiles === []) {
        echo '<p class="muted">No catalog files currently have missing dependencies.</p>';
    } else {
        echo '<div class="table-wrap"><table class="missing-file-table"><thead><tr><th>Game</th><th>Requiring File</th><th>Missing Packages</th><th>Missing Object Rows</th><th>Package Names</th><th>Actions</th></tr></thead><tbody>';
        foreach ($affectedFiles as $row) {
            $packageNames = trim((string)($row['missing_package_names'] ?? ''));
            echo '<tr>';
            echo '<td><a href="game-files.php?id=' . (int)$row['game_id'] . '">' . catalog_h((string)$row['game_name']) . '</a></td>';
            echo '<td class="missing-file-name"><strong class="mono">' . catalog_h((string)$row['package_name']) . '</strong><br><span class="muted small">' . catalog_h((string)$row['original_name']) . '</span></td>';
            echo '<td>' . (int)$row['missing_package_count'] . '</td>';
            echo '<td>' . (int)$row['missing_object_rows'] . '</td>';
            echo '<td class="mono missing-package-list">';
            if ($packageNames === '') {
                echo '<span class="muted">—</span>';
            } else {
                $names = array_values(array_filter(array_map('trim', explode(', ', $packageNames)), static fn(string $name): bool => $name !== ''));
                foreach ($names as $index => $name) {
                    if ($index > 0) {
                        echo '<br>';
                    }
                    echo '<a href="' . catalog_h(missing_page_url(['package' => $name, 'files_page' => $filePage])) . '">' . catalog_h($name) . '</a>';
                }
            }
            echo '</td>';
            echo '<td class="missing-actions">' . missing_file_links((int)$row['file_id']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';

        if ($filePageCount > 1) {
            echo '<div class="missing-pagination"><span class="muted">Page ' . $filePage . ' of ' . $filePageCount . ' (' . $filesWithMissing . ' files)</span>';
            if ($filePage > 1) {
                echo '<a class="button secondary" href="' . catalog_h(missing_page_url(['files_page' => $filePage - 1])) . '">Previous</a>';
            }
            if ($filePage < $filePageCount) {
                echo '<a class="button secondary" href="' . catalog_h(missing_page_url(['files_page' => $filePage + 1])) . '">Next</a>';
            }
            echo '</div>';
        }
    }
    echo '</div>';

    $rows = catalog_all(
        $db,
        'SELECT required_package, COUNT(*) missing_object_rows, COUNT(DISTINCT file_id) requiring_file_count '
        . 'FROM ue_dependencies '
        . 'WHERE status="missing" AND required_package IS NOT NULL AND required_package<>"" '
        . 'GROUP BY required_package '
        . 'ORDER BY missing_object_rows DESC, requiring_file_count DESC, required_package '
        . 'LIMIT 100'
    );
    echo '<div class="card"><h2>Top missing packages</h2>';
    echo '<p class="muted">Open a package to see every file and required object path that depends on it.</p>';
    if (!$rows) {
        echo '<p class="muted">No missing packages currently recorded.</p>';
    } else {
        echo '<div class="table-wrap"><table class="missing-package-table"><thead><tr><th>Package</th><th>Missing Object Rows</th><th>Requiring Files</th><th>Details</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $package = (string)$row['required_package'];
            $detailUrl = missing_page_url(['package' => $package, 'files_page' => $filePage]);
            echo '<tr><td class="mono"><a href="' . catalog_h($detailUrl) . '">' . catalog_h($package) . '</a></td><td>' . (int)$row['missing_object_rows'] . '</td><td>' . (int)$row['requiring_file_count'] . '</td><td><a class="button secondary" href="' . catalog_h($detailUrl) . '">View requiring files</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Missing files error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
