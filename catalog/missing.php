<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Application\Dependency\CatalogMissingDetailListService;
use UnrealDb\Catalog\Application\Dependency\CatalogMissingFileListService;
use UnrealDb\Catalog\Application\Pagination\CatalogKeysetPaginator;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyPackageSummary;

catalog_start_session();

function missing_page_int(string $key, int $default = 0): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? $default : max(0, (int)$value);
}

function missing_selected_package(): string
{
    return substr(trim((string)($_GET['package'] ?? '')), 0, 255);
}

function missing_selected_view(): string
{
    $view = strtolower(trim((string)($_GET['view'] ?? 'objects')));
    return in_array($view, ['objects', 'files'], true) ? $view : 'objects';
}

function missing_page_url(array $params = []): string
{
    $query = array_filter($params, static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== 0);
    return 'missing.php' . ($query === [] ? '' : '?' . http_build_query($query));
}

function missing_file_reference_html(int $fileId, string $packageName, string $originalName): string
{
    return '<strong class="mono"><a href="file-info.php?id=' . $fileId . '">' . catalog_h($packageName) . '</a></strong>'
        . '<br><span class="muted small"><a href="file-examine.php?id=' . $fileId . '">' . catalog_h($originalName) . '</a></span>';
}

function missing_import_class(string $classPackage, string $className): string
{
    return implode('.', array_values(array_filter([$classPackage, $className], static fn(string $part): bool => $part !== '')));
}

function missing_files_pagination(
    int $pageNo,
    int $totalPages,
    int $totalFiles,
    bool $hasPrevious,
    bool $hasNext,
    string $previousCursor,
    string $nextCursor
): string {
    $html = '<div class="missing-pagination"><span class="muted">Page ' . $pageNo . ' of ' . $totalPages . ' (' . $totalFiles . ' files)</span>';
    if ($hasPrevious) {
        $html .= '<a class="button secondary" href="' . catalog_h(missing_page_url()) . '">First</a>';
        $html .= '<a class="button secondary" href="' . catalog_h(missing_page_url([
            'files_cursor' => $previousCursor,
            'files_move' => 'prev',
            'files_page' => max(1, $pageNo - 1),
        ])) . '">Previous</a>';
    }
    if ($hasNext) {
        $html .= '<a class="button secondary" href="' . catalog_h(missing_page_url([
            'files_cursor' => $nextCursor,
            'files_move' => 'next',
            'files_page' => min($totalPages, $pageNo + 1),
        ])) . '">Next</a>';
        $html .= '<a class="button secondary" href="' . catalog_h(missing_page_url([
            'files_move' => 'last',
            'files_page' => $totalPages,
        ])) . '">Last</a>';
    }
    return $html . '</div>';
}

/** @param array<string,mixed> $baseParams */
function missing_detail_pagination(
    array $baseParams,
    int $pageNo,
    int $totalPages,
    int $totalRows,
    string $rowLabel,
    bool $hasPrevious,
    bool $hasNext,
    string $previousCursor,
    string $nextCursor
): string {
    $html = '<div class="missing-pagination"><span class="muted">Page ' . $pageNo . ' of ' . $totalPages
        . ' (' . $totalRows . ' ' . catalog_h($rowLabel) . ')</span>';
    if ($hasPrevious) {
        $html .= '<a class="button secondary" href="' . catalog_h(missing_page_url($baseParams)) . '">First</a>';
        $html .= '<a class="button secondary" href="' . catalog_h(missing_page_url($baseParams + [
            'detail_cursor' => $previousCursor,
            'detail_move' => 'prev',
            'detail_page' => max(1, $pageNo - 1),
        ])) . '">Previous</a>';
    }
    if ($hasNext) {
        $html .= '<a class="button secondary" href="' . catalog_h(missing_page_url($baseParams + [
            'detail_cursor' => $nextCursor,
            'detail_move' => 'next',
            'detail_page' => min($totalPages, $pageNo + 1),
        ])) . '">Next</a>';
        $html .= '<a class="button secondary" href="' . catalog_h(missing_page_url($baseParams + [
            'detail_move' => 'last',
            'detail_page' => $totalPages,
        ])) . '">Last</a>';
    }
    return $html . '</div>';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Missing Files')) {
        exit;
    }

    $summaryAvailable = (new PdoDependencyPackageSummary($db))->available();
    $selectedPackage = missing_selected_package();
    $selectedFileId = missing_page_int('file_id');
    $selectedView = missing_selected_view();
    $perPage = 200;
    $detailPerPage = 200;

    if ($summaryAvailable) {
        $filesWithMissing = catalog_count($db, 'SELECT COUNT(DISTINCT file_id) c FROM ue_dependency_package_summaries WHERE missing_count>0');
        $missingObjects = catalog_count($db, 'SELECT COALESCE(SUM(missing_count),0) c FROM ue_dependency_package_summaries');
        $missingPackages = catalog_count($db, 'SELECT COUNT(DISTINCT required_package) c FROM ue_dependency_package_summaries WHERE missing_count>0');
        $resolved = catalog_count($db, 'SELECT COALESCE(SUM(resolved_count),0) c FROM ue_dependency_package_summaries');
    } else {
        $filesWithMissing = catalog_count($db, 'SELECT COUNT(DISTINCT file_id) c FROM ue_dependencies WHERE status="missing"');
        $missingObjects = catalog_count($db, 'SELECT COUNT(*) c FROM ue_dependencies WHERE status="missing"');
        $missingPackages = catalog_count($db, 'SELECT COUNT(DISTINCT required_package) c FROM ue_dependencies WHERE status="missing" AND required_package<>""');
        $resolved = catalog_count($db, 'SELECT COUNT(*) c FROM ue_dependencies WHERE status="resolved"');
    }

    $filePageCount = max(1, (int)ceil($filesWithMissing / $perPage));
    $filesMove = strtolower(trim((string)($_GET['files_move'] ?? 'first')));
    $filesMove = in_array($filesMove, ['first', 'next', 'prev', 'last'], true) ? $filesMove : 'first';
    $filePage = max(1, min($filePageCount, missing_page_int('files_page', $filesMove === 'last' ? $filePageCount : 1)));
    if ($filesMove === 'first') {
        $filePage = 1;
    } elseif ($filesMove === 'last') {
        $filePage = $filePageCount;
    }

    $cursorContext = json_encode([
        'page' => 'missing-files',
        'limit' => $perPage,
        'summary' => $summaryAvailable,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $cursorToken = trim((string)($_GET['files_cursor'] ?? ''));
    $cursor = $cursorToken !== '' ? CatalogKeysetPaginator::decode($config, $cursorContext, $cursorToken) : null;
    if ($cursorToken !== '' && $cursor === null) {
        $filesMove = 'first';
        $filePage = 1;
    }

    $fileListPage = CatalogMissingFileListService::fetchCursorPage($db, $summaryAvailable, $perPage, $cursor, $filesMove);
    $affectedFiles = $fileListPage['rows'];
    if ($affectedFiles === [] && $filesWithMissing > 0 && $filesMove !== 'first') {
        $filesMove = 'first';
        $filePage = 1;
        $fileListPage = CatalogMissingFileListService::fetchCursorPage($db, $summaryAvailable, $perPage, null, 'first');
        $affectedFiles = $fileListPage['rows'];
    }
    $previousCursor = is_array($fileListPage['first_cursor'])
        ? CatalogKeysetPaginator::encode($config, $cursorContext, $fileListPage['first_cursor'])
        : '';
    $nextCursor = is_array($fileListPage['last_cursor'])
        ? CatalogKeysetPaginator::encode($config, $cursorContext, $fileListPage['last_cursor'])
        : '';
    $filePagination = missing_files_pagination(
        $filePage,
        $filePageCount,
        $filesWithMissing,
        $filePage > 1 && (bool)$fileListPage['has_previous'],
        $filePage < $filePageCount && (bool)$fileListPage['has_next'],
        $previousCursor,
        $nextCursor
    );

    $detailMode = '';
    $detailRows = [];
    $detailTotal = 0;
    $detailBaseParams = [];
    $detailOwner = null;
    if ($selectedFileId > 0) {
        $detailMode = 'file_objects';
        $detailBaseParams = ['file_id' => $selectedFileId];
        $detailTotal = catalog_count($db, 'SELECT COUNT(*) c FROM ue_dependencies WHERE status="missing" AND file_id=?', [$selectedFileId]);
        $detailOwner = catalog_one(
            $db,
            'SELECT f.id file_id,f.package_name owner_package_name,f.original_name owner_original_name,g.id game_id,g.name game_name '
            . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id WHERE f.id=?',
            [$selectedFileId]
        );
    } elseif ($selectedPackage !== '' && $selectedView === 'files') {
        $detailMode = 'package_files';
        $detailBaseParams = ['package' => $selectedPackage, 'view' => 'files'];
        $detailTotal = $summaryAvailable
            ? catalog_count($db, 'SELECT COUNT(*) c FROM ue_dependency_package_summaries WHERE required_package=? AND missing_count>0', [$selectedPackage])
            : catalog_count($db, 'SELECT COUNT(DISTINCT file_id) c FROM ue_dependencies WHERE status="missing" AND required_package=?', [$selectedPackage]);
    } elseif ($selectedPackage !== '') {
        $detailMode = 'package_objects';
        $detailBaseParams = ['package' => $selectedPackage, 'view' => 'objects'];
        $detailTotal = catalog_count($db, 'SELECT COUNT(*) c FROM ue_dependencies WHERE status="missing" AND required_package=?', [$selectedPackage]);
    }

    $detailPageCount = max(1, (int)ceil($detailTotal / $detailPerPage));
    $detailMove = strtolower(trim((string)($_GET['detail_move'] ?? 'first')));
    $detailMove = in_array($detailMove, ['first', 'next', 'prev', 'last'], true) ? $detailMove : 'first';
    $detailPage = max(1, min($detailPageCount, missing_page_int('detail_page', $detailMove === 'last' ? $detailPageCount : 1)));
    if ($detailMove === 'first') {
        $detailPage = 1;
    } elseif ($detailMove === 'last') {
        $detailPage = $detailPageCount;
    }

    $detailPagination = '';
    if ($detailMode !== '') {
        $detailContext = json_encode([
            'page' => 'missing-detail',
            'mode' => $detailMode,
            'package' => $selectedPackage,
            'file_id' => $selectedFileId,
            'limit' => $detailPerPage,
            'summary' => $summaryAvailable,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $detailCursorToken = trim((string)($_GET['detail_cursor'] ?? ''));
        $detailCursor = $detailCursorToken !== ''
            ? CatalogKeysetPaginator::decode($config, $detailContext, $detailCursorToken)
            : null;
        if ($detailCursorToken !== '' && $detailCursor === null) {
            $detailMove = 'first';
            $detailPage = 1;
        }

        $detailListPage = match ($detailMode) {
            'file_objects' => CatalogMissingDetailListService::fetchFileObjects($db, $selectedFileId, $detailPerPage, $detailCursor, $detailMove),
            'package_files' => CatalogMissingDetailListService::fetchPackageFiles($db, $summaryAvailable, $selectedPackage, $detailPerPage, $detailCursor, $detailMove),
            default => CatalogMissingDetailListService::fetchPackageObjects($db, $selectedPackage, $detailPerPage, $detailCursor, $detailMove),
        };
        $detailRows = $detailListPage['rows'];
        if ($detailRows === [] && $detailTotal > 0 && $detailMove !== 'first') {
            $detailMove = 'first';
            $detailPage = 1;
            $detailListPage = match ($detailMode) {
                'file_objects' => CatalogMissingDetailListService::fetchFileObjects($db, $selectedFileId, $detailPerPage, null, 'first'),
                'package_files' => CatalogMissingDetailListService::fetchPackageFiles($db, $summaryAvailable, $selectedPackage, $detailPerPage, null, 'first'),
                default => CatalogMissingDetailListService::fetchPackageObjects($db, $selectedPackage, $detailPerPage, null, 'first'),
            };
            $detailRows = $detailListPage['rows'];
        }
        $detailPreviousCursor = is_array($detailListPage['first_cursor'])
            ? CatalogKeysetPaginator::encode($config, $detailContext, $detailListPage['first_cursor'])
            : '';
        $detailNextCursor = is_array($detailListPage['last_cursor'])
            ? CatalogKeysetPaginator::encode($config, $detailContext, $detailListPage['last_cursor'])
            : '';
        $detailPagination = missing_detail_pagination(
            $detailBaseParams,
            $detailPage,
            $detailPageCount,
            $detailTotal,
            $detailMode === 'package_files' ? 'files' : 'objects',
            $detailPage > 1 && (bool)$detailListPage['has_previous'],
            $detailPage < $detailPageCount && (bool)$detailListPage['has_next'],
            $detailPreviousCursor,
            $detailNextCursor
        );
    }

    $approved = catalog_count($db, 'SELECT COUNT(*) c FROM ue_federation_request_items WHERE status="approved"');
    $imported = catalog_count($db, 'SELECT COUNT(*) c FROM ue_federation_request_items WHERE status="imported"');

    catalog_head('Missing Files');
    catalog_page_header(
        'Missing Files',
        'See which catalog files require each missing package or object, then inspect the owning file before requesting a dependency.',
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
.missing-file-table { min-width:1120px; }
.missing-package-table { min-width:700px; }
.missing-detail-table { min-width:1080px; }
.missing-file-name { min-width:225px; }
.missing-package-list { min-width:260px; max-width:460px; overflow-wrap:anywhere; }
.missing-object-path { min-width:310px; max-width:560px; overflow-wrap:anywhere; }
.missing-pagination { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin:12px 0 0; }
.missing-pagination .muted { margin-right:4px; }
.missing-detail-links { display:flex; gap:7px; flex-wrap:wrap; margin:0 0 12px; }
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

    if ($detailMode === 'file_objects') {
        echo '<div class="card"><h2>Missing dependency objects for file</h2>';
        echo '<p class="missing-detail-links"><a class="button secondary" href="missing.php">Clear object list</a></p>';
        if ($detailOwner) {
            echo '<p>Requiring file: ' . missing_file_reference_html((int)$detailOwner['file_id'], (string)$detailOwner['owner_package_name'], (string)$detailOwner['owner_original_name'])
                . ' · <a href="game-files.php?id=' . (int)$detailOwner['game_id'] . '">' . catalog_h((string)$detailOwner['game_name']) . '</a></p>';
        }
        if ($detailRows === []) {
            echo '<p class="muted">No missing dependency rows currently match this file.</p>';
        } else {
            echo '<div class="table-wrap"><table class="missing-detail-table"><thead><tr><th>Missing Package</th><th>Required Object Path</th><th>Import Class</th><th>Import Path</th></tr></thead><tbody>';
            foreach ($detailRows as $row) {
                $importClass = missing_import_class((string)$row['class_package'], (string)$row['class_name']);
                echo '<tr><td class="mono"><a href="' . catalog_h(missing_page_url(['package' => (string)$row['required_package'], 'view' => 'objects'])) . '">' . catalog_h((string)$row['required_package']) . '</a></td>';
                echo '<td class="mono missing-object-path">' . catalog_h((string)$row['required_object_path']) . '</td>';
                echo '<td class="mono">' . ($importClass !== '' ? catalog_h($importClass) : '<span class="muted">—</span>') . '</td>';
                echo '<td class="mono missing-object-path">' . (trim((string)$row['import_full_path']) !== '' ? catalog_h((string)$row['import_full_path']) : '<span class="muted">—</span>') . '</td></tr>';
            }
            echo '</tbody></table></div>';
            if ($detailPageCount > 1) {
                echo $detailPagination;
            }
        }
        echo '</div>';
    } elseif ($detailMode === 'package_files') {
        echo '<div class="card"><h2>Files requiring package: <span class="mono">' . catalog_h($selectedPackage) . '</span></h2>';
        echo '<p class="missing-detail-links"><a class="button secondary" href="' . catalog_h(missing_page_url(['package' => $selectedPackage, 'view' => 'objects'])) . '">View missing object rows</a> <a class="button secondary" href="missing.php">Clear package detail</a></p>';
        if ($detailRows === []) {
            echo '<p class="muted">No missing dependency rows currently match this package name.</p>';
        } else {
            echo '<div class="table-wrap"><table class="missing-detail-table"><thead><tr><th>Game</th><th>Requiring File</th><th>Missing Object Rows</th></tr></thead><tbody>';
            foreach ($detailRows as $row) {
                echo '<tr><td><a href="game-files.php?id=' . (int)$row['game_id'] . '">' . catalog_h((string)$row['game_name']) . '</a></td>';
                echo '<td class="missing-file-name">' . missing_file_reference_html((int)$row['file_id'], (string)$row['owner_package_name'], (string)$row['owner_original_name']) . '</td>';
                echo '<td><a href="' . catalog_h(missing_page_url(['file_id' => (int)$row['file_id']])) . '">' . (int)$row['missing_object_rows'] . '</a></td></tr>';
            }
            echo '</tbody></table></div>';
            if ($detailPageCount > 1) {
                echo $detailPagination;
            }
        }
        echo '</div>';
    } elseif ($detailMode === 'package_objects') {
        echo '<div class="card"><h2>Missing objects for package: <span class="mono">' . catalog_h($selectedPackage) . '</span></h2>';
        echo '<p class="missing-detail-links"><a class="button secondary" href="' . catalog_h(missing_page_url(['package' => $selectedPackage, 'view' => 'files'])) . '">View requiring files</a> <a class="button secondary" href="missing.php">Clear package detail</a></p>';
        if ($detailRows === []) {
            echo '<p class="muted">No missing dependency rows currently match this package name.</p>';
        } else {
            echo '<div class="table-wrap"><table class="missing-detail-table"><thead><tr><th>Game</th><th>Requiring File</th><th>Required Object Path</th><th>Import Class</th><th>Import Path</th></tr></thead><tbody>';
            foreach ($detailRows as $row) {
                $importClass = missing_import_class((string)$row['class_package'], (string)$row['class_name']);
                echo '<tr><td><a href="game-files.php?id=' . (int)$row['game_id'] . '">' . catalog_h((string)$row['game_name']) . '</a></td>';
                echo '<td class="missing-file-name">' . missing_file_reference_html((int)$row['file_id'], (string)$row['owner_package_name'], (string)$row['owner_original_name']) . '</td>';
                echo '<td class="mono missing-object-path">' . catalog_h((string)$row['required_object_path']) . '</td>';
                echo '<td class="mono">' . ($importClass !== '' ? catalog_h($importClass) : '<span class="muted">—</span>') . '</td>';
                echo '<td class="mono missing-object-path">' . (trim((string)$row['import_full_path']) !== '' ? catalog_h((string)$row['import_full_path']) : '<span class="muted">—</span>') . '</td></tr>';
            }
            echo '</tbody></table></div>';
            if ($detailPageCount > 1) {
                echo $detailPagination;
            }
        }
        echo '</div>';
    }

    echo '<div class="card"><h2>Files with missing dependencies</h2>';
    if ($affectedFiles === []) {
        echo '<p class="muted">No catalog files currently have missing dependencies.</p>';
    } else {
        echo '<div class="table-wrap"><table class="missing-file-table"><thead><tr><th>Game</th><th>Requiring File</th><th>Missing Packages</th><th>Missing Object Rows</th><th>Package Names</th></tr></thead><tbody>';
        foreach ($affectedFiles as $row) {
            $names = array_values(array_filter(array_map('trim', explode(', ', (string)($row['missing_package_names'] ?? ''))), static fn(string $name): bool => $name !== ''));
            echo '<tr><td><a href="game-files.php?id=' . (int)$row['game_id'] . '">' . catalog_h((string)$row['game_name']) . '</a></td>';
            echo '<td class="missing-file-name">' . missing_file_reference_html((int)$row['file_id'], (string)$row['package_name'], (string)$row['original_name']) . '</td>';
            echo '<td>' . (int)$row['missing_package_count'] . '</td>';
            echo '<td><a href="' . catalog_h(missing_page_url(['file_id' => (int)$row['file_id']])) . '">' . (int)$row['missing_object_rows'] . '</a></td><td class="mono missing-package-list">';
            if ($names === []) {
                echo '<span class="muted">—</span>';
            } else {
                foreach ($names as $index => $name) {
                    echo ($index > 0 ? '<br>' : '') . '<a href="' . catalog_h(missing_page_url(['package' => $name, 'view' => 'objects'])) . '">' . catalog_h($name) . '</a>';
                }
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        if ($filePageCount > 1) {
            echo $filePagination;
        }
    }
    echo '</div>';

    $rows = $summaryAvailable
        ? catalog_all($db, 'SELECT required_package,SUM(missing_count) missing_object_rows,COUNT(*) requiring_file_count FROM ue_dependency_package_summaries WHERE missing_count>0 GROUP BY required_package ORDER BY missing_object_rows DESC,requiring_file_count DESC,required_package')
        : catalog_all($db, 'SELECT required_package,COUNT(*) missing_object_rows,COUNT(DISTINCT file_id) requiring_file_count FROM ue_dependencies WHERE status="missing" AND required_package<>"" GROUP BY required_package ORDER BY missing_object_rows DESC,requiring_file_count DESC,required_package');

    echo '<div class="card"><h2>Missing packages</h2>';
    if ($rows === []) {
        echo '<p class="muted">No missing packages currently recorded.</p>';
    } else {
        echo '<div class="table-wrap"><table class="missing-package-table"><thead><tr><th>Package</th><th>Missing Object Rows</th><th>Requiring Files</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $package = (string)$row['required_package'];
            $objectUrl = missing_page_url(['package' => $package, 'view' => 'objects']);
            $filesUrl = missing_page_url(['package' => $package, 'view' => 'files']);
            echo '<tr><td class="mono"><a href="' . catalog_h($objectUrl) . '">' . catalog_h($package) . '</a></td>';
            echo '<td><a href="' . catalog_h($objectUrl) . '">' . (int)$row['missing_object_rows'] . '</a></td>';
            echo '<td><a href="' . catalog_h($filesUrl) . '">' . (int)$row['requiring_file_count'] . '</a></td></tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('Missing files error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($error->getMessage()) . '</p></div>';
    catalog_foot();
}
