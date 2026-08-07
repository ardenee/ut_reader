<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for Missing Dependencies —.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/BaseGameProtection.php';

use UnrealDb\Catalog\Application\Dependency\CatalogDependencyReadSource;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyPackageSummary;

catalog_start_session();

function game_missing_int(string $key, int $default = 0): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    return $value === false || $value === null ? $default : max(0, (int)$value);
}

function game_missing_type(): string
{
    $type = strtolower(trim((string)($_GET['dependency_type'] ?? 'all')));
    return $type === 'base_game' ? 'base_game' : 'all';
}

/** @param array<string,mixed> $params */
function game_missing_url(int $gameId, string $type, array $params = []): string
{
    $query = array_merge([
        'game_id' => $gameId,
        'dependency_type' => $type,
    ], $params);
    $query = array_filter($query, static fn(mixed $value): bool => $value !== null && $value !== '' && $value !== 0);
    return 'game-missing.php' . ($query === [] ? '' : '?' . http_build_query($query));
}

function game_missing_pagination(
    int $gameId,
    string $type,
    string $pageKey,
    int $page,
    int $pages,
    array $preserve = []
): string {
    if ($pages <= 1) {
        return '';
    }
    $html = '<div class="missing-pagination"><span class="muted">Page ' . $page . ' of ' . $pages . '</span>';
    if ($page > 1) {
        $html .= '<a class="button secondary" href="' . catalog_h(game_missing_url($gameId, $type, $preserve + [$pageKey => 1])) . '">First</a>';
        $html .= '<a class="button secondary" href="' . catalog_h(game_missing_url($gameId, $type, $preserve + [$pageKey => $page - 1])) . '">Previous</a>';
    }
    if ($page < $pages) {
        $html .= '<a class="button secondary" href="' . catalog_h(game_missing_url($gameId, $type, $preserve + [$pageKey => $page + 1])) . '">Next</a>';
        $html .= '<a class="button secondary" href="' . catalog_h(game_missing_url($gameId, $type, $preserve + [$pageKey => $pages])) . '">Last</a>';
    }
    return $html . '</div>';
}

function game_missing_import_class(string $classPackage, string $className): string
{
    return implode('.', array_values(array_filter([$classPackage, $className], static fn(string $part): bool => trim($part) !== '')));
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Game Missing Dependencies')) {
        exit;
    }
    base_game_ensure($db);

    $games = catalog_all($db, 'SELECT id,name,slug FROM ue_games ORDER BY name');
    $gameId = game_missing_int('game_id');
    $game = $gameId > 0 ? catalog_one($db, 'SELECT id,name,slug FROM ue_games WHERE id=?', [$gameId]) : null;
    if (!$game) {
        throw new RuntimeException('Choose a valid game from the Games page.');
    }

    $type = game_missing_type();
    $baseGameOnly = $type === 'base_game';
    $selectedPackage = substr(trim((string)($_GET['package'] ?? '')), 0, 255);
    $perPage = 200;

    $dependencySource = CatalogDependencyReadSource::sql($db);
    $summaryAvailable = (new PdoDependencyPackageSummary($db))->available();
    $summaryWhere = 's.game_id=? AND s.missing_count>0';
    $summaryArgs = [$gameId];
    $dependencyWhere = 'd.status="missing" AND f.game_id=?';
    $dependencyArgs = [$gameId];
    if ($baseGameOnly) {
        $summaryWhere .= ' AND ' . base_game_package_exists_sql('s.required_package', 's.game_id');
        $dependencyWhere .= ' AND ' . base_game_dependency_is_official_sql('f', 'd');
    }

    if ($summaryAvailable) {
        $missingObjects = catalog_count(
            $db,
            'SELECT COALESCE(SUM(s.missing_count),0) c FROM ue_dependency_package_summaries s WHERE ' . $summaryWhere,
            $summaryArgs
        );
        $missingPackages = catalog_count(
            $db,
            'SELECT COUNT(DISTINCT s.required_package) c FROM ue_dependency_package_summaries s WHERE ' . $summaryWhere,
            $summaryArgs
        );
        $filesWithMissing = catalog_count(
            $db,
            'SELECT COUNT(DISTINCT s.file_id) c FROM ue_dependency_package_summaries s WHERE ' . $summaryWhere,
            $summaryArgs
        );
    } else {
        $missingObjects = catalog_count(
            $db,
            'SELECT COUNT(*) c FROM ' . $dependencySource . ' d JOIN ue_files f ON f.id=d.file_id WHERE ' . $dependencyWhere,
            $dependencyArgs
        );
        $missingPackages = catalog_count(
            $db,
            'SELECT COUNT(DISTINCT d.required_package) c FROM ' . $dependencySource . ' d JOIN ue_files f ON f.id=d.file_id WHERE ' . $dependencyWhere . ' AND d.required_package<>""',
            $dependencyArgs
        );
        $filesWithMissing = catalog_count(
            $db,
            'SELECT COUNT(DISTINCT d.file_id) c FROM ' . $dependencySource . ' d JOIN ue_files f ON f.id=d.file_id WHERE ' . $dependencyWhere,
            $dependencyArgs
        );
    }

    $filePages = max(1, (int)ceil($filesWithMissing / $perPage));
    $filePage = max(1, min($filePages, game_missing_int('file_page', 1)));
    $fileOffset = ($filePage - 1) * $perPage;
    if ($summaryAvailable) {
        $fileRows = catalog_all(
            $db,
            'SELECT f.id file_id,f.package_name,f.original_name,g.name game_name,'
            . 'SUM(s.missing_count) missing_object_rows,COUNT(*) missing_package_count,'
            . 'GROUP_CONCAT(s.required_package ORDER BY s.required_package SEPARATOR ", ") missing_package_names '
            . 'FROM ue_dependency_package_summaries s '
            . 'JOIN ue_files f ON f.id=s.file_id JOIN ue_games g ON g.id=s.game_id '
            . 'WHERE ' . $summaryWhere . ' '
            . 'GROUP BY f.id,f.package_name,f.original_name,g.name '
            . 'ORDER BY missing_object_rows DESC,missing_package_count DESC,f.package_name,f.original_name,f.id '
            . 'LIMIT ' . $perPage . ' OFFSET ' . $fileOffset,
            $summaryArgs
        );
    } else {
        $fileRows = catalog_all(
            $db,
            'SELECT f.id file_id,f.package_name,f.original_name,g.name game_name,'
            . 'COUNT(d.id) missing_object_rows,COUNT(DISTINCT d.required_package) missing_package_count,'
            . 'GROUP_CONCAT(DISTINCT d.required_package ORDER BY d.required_package SEPARATOR ", ") missing_package_names '
            . 'FROM ' . $dependencySource . ' d JOIN ue_files f ON f.id=d.file_id JOIN ue_games g ON g.id=f.game_id '
            . 'WHERE ' . $dependencyWhere . ' '
            . 'GROUP BY f.id,f.package_name,f.original_name,g.name '
            . 'ORDER BY missing_object_rows DESC,missing_package_count DESC,f.package_name,f.original_name,f.id '
            . 'LIMIT ' . $perPage . ' OFFSET ' . $fileOffset,
            $dependencyArgs
        );
    }

    $packagePages = max(1, (int)ceil($missingPackages / $perPage));
    $packagePage = max(1, min($packagePages, game_missing_int('package_page', 1)));
    $packageOffset = ($packagePage - 1) * $perPage;
    if ($summaryAvailable) {
        $packageRows = catalog_all(
            $db,
            'SELECT s.required_package,SUM(s.missing_count) missing_object_rows,COUNT(*) requiring_file_count '
            . 'FROM ue_dependency_package_summaries s WHERE ' . $summaryWhere . ' '
            . 'GROUP BY s.required_package '
            . 'ORDER BY missing_object_rows DESC,requiring_file_count DESC,s.required_package '
            . 'LIMIT ' . $perPage . ' OFFSET ' . $packageOffset,
            $summaryArgs
        );
    } else {
        $packageRows = catalog_all(
            $db,
            'SELECT d.required_package,COUNT(*) missing_object_rows,COUNT(DISTINCT d.file_id) requiring_file_count '
            . 'FROM ' . $dependencySource . ' d JOIN ue_files f ON f.id=d.file_id '
            . 'WHERE ' . $dependencyWhere . ' AND d.required_package<>"" '
            . 'GROUP BY d.required_package '
            . 'ORDER BY missing_object_rows DESC,requiring_file_count DESC,d.required_package '
            . 'LIMIT ' . $perPage . ' OFFSET ' . $packageOffset,
            $dependencyArgs
        );
    }

    $detailRows = [];
    $detailTotal = 0;
    $detailPage = 1;
    $detailPages = 1;
    if ($selectedPackage !== '') {
        $detailWhere = $dependencyWhere . ' AND d.required_package=?';
        $detailArgs = array_merge($dependencyArgs, [$selectedPackage]);
        $detailTotal = catalog_count(
            $db,
            'SELECT COUNT(*) c FROM ' . $dependencySource . ' d JOIN ue_files f ON f.id=d.file_id WHERE ' . $detailWhere,
            $detailArgs
        );
        $detailPages = max(1, (int)ceil($detailTotal / $perPage));
        $detailPage = max(1, min($detailPages, game_missing_int('detail_page', 1)));
        $detailOffset = ($detailPage - 1) * $perPage;
        $detailRows = catalog_all(
            $db,
            'SELECT d.required_package,d.required_object_path,f.id file_id,f.package_name owner_package_name,'
            . 'f.original_name owner_original_name,g.name game_name,d.class_package,d.class_name,d.import_full_path '
            . 'FROM ' . $dependencySource . ' d JOIN ue_files f ON f.id=d.file_id '
            . 'JOIN ue_games g ON g.id=f.game_id '
            . 'WHERE ' . $detailWhere . ' '
            . 'ORDER BY f.package_name,f.original_name,d.required_object_path,d.id '
            . 'LIMIT ' . $perPage . ' OFFSET ' . $detailOffset,
            $detailArgs
        );
    }

    $typeLabel = $baseGameOnly ? 'Official base-game dependencies only' : 'All missing dependencies';

    catalog_head('Missing Dependencies — ' . (string)$game['name']);
    echo <<<'CSS'
<style>
.game-missing-filter { display:flex;align-items:end;gap:10px;flex-wrap:wrap; }
.game-missing-filter label { display:grid;gap:5px; }
.game-missing-path { min-width:300px;max-width:600px;overflow-wrap:anywhere; }
.game-missing-file { min-width:240px; }
.game-missing-package-list { min-width:260px;max-width:500px;overflow-wrap:anywhere; }
.missing-pagination { display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:12px; }
</style>
CSS;
    echo CatalogUi::pageHeader(
        'Missing Dependencies — ' . (string)$game['name'],
        $typeLabel . '. Every count and table on this page is scoped to the selected game and dependency type.',
        ['Games' => 'games.php', 'Global Missing Files' => 'missing.php', 'Game Files' => 'game-files.php?id=' . $gameId]
    );

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Filter</h2><p>Switch game or dependency type without returning to Games.</p></div></div><div class="ui-section__body">';
    echo '<form class="game-missing-filter" method="get"><label>Game<select name="game_id">';
    foreach ($games as $candidate) {
        echo '<option value="' . (int)$candidate['id'] . '"' . ((int)$candidate['id'] === $gameId ? ' selected' : '') . '>' . catalog_h((string)$candidate['name']) . '</option>';
    }
    echo '</select></label><label>Dependency type<select name="dependency_type">'
        . '<option value="all"' . (!$baseGameOnly ? ' selected' : '') . '>All missing dependencies</option>'
        . '<option value="base_game"' . ($baseGameOnly ? ' selected' : '') . '>Official base-game dependencies only</option>'
        . '</select></label><button type="submit">Apply filters</button></form></div></section>';

    echo '<div class="grid">';
    catalog_stat_card('Missing dependency objects', $missingObjects, '', $missingObjects > 0 ? 'attention' : 'good');
    catalog_stat_card('Missing packages', $missingPackages, '', $missingPackages > 0 ? 'attention' : 'good');
    catalog_stat_card('Files with missing dependencies', $filesWithMissing, '', $filesWithMissing > 0 ? 'attention' : 'good');
    echo '</div>';

    if ($selectedPackage !== '') {
        echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Missing objects for package: <span class="mono">' . catalog_h($selectedPackage) . '</span></h2>'
            . '<p>' . number_format($detailTotal) . ' matching object row' . ($detailTotal === 1 ? '' : 's') . ' for ' . catalog_h((string)$game['name']) . '.</p></div>'
            . '<a class="button secondary" href="' . catalog_h(game_missing_url($gameId, $type)) . '">Clear package detail</a></div><div class="ui-section__body">';
        if ($detailRows === []) {
            echo CatalogUi::emptyState('No matching object rows', 'This package no longer has missing dependency objects in the selected scope.');
        } else {
            echo '<div class="table-wrap"><table><thead><tr><th>Requiring File</th><th>Required Object Path</th><th>Import Class</th><th>Import Path</th></tr></thead><tbody>';
            foreach ($detailRows as $row) {
                $importClass = game_missing_import_class((string)$row['class_package'], (string)$row['class_name']);
                echo '<tr><td class="game-missing-file"><strong class="mono"><a href="file-info.php?id=' . (int)$row['file_id'] . '">' . catalog_h((string)$row['owner_package_name']) . '</a></strong>'
                    . '<br><a class="muted small" href="file-examine.php?id=' . (int)$row['file_id'] . '">' . catalog_h((string)$row['owner_original_name']) . '</a></td>';
                echo '<td class="mono game-missing-path">' . catalog_h((string)$row['required_object_path']) . '</td>';
                echo '<td class="mono">' . ($importClass !== '' ? catalog_h($importClass) : '<span class="muted">—</span>') . '</td>';
                echo '<td class="mono game-missing-path">' . (trim((string)$row['import_full_path']) !== '' ? catalog_h((string)$row['import_full_path']) : '<span class="muted">—</span>') . '</td></tr>';
            }
            echo '</tbody></table></div>';
            echo game_missing_pagination($gameId, $type, 'detail_page', $detailPage, $detailPages, ['package' => $selectedPackage]);
        }
        echo '</div></section>';
    }

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Files with missing dependencies</h2><p>' . number_format($filesWithMissing) . ' file' . ($filesWithMissing === 1 ? '' : 's') . ' in the selected scope.</p></div></div><div class="ui-section__body">';
    if ($fileRows === []) {
        echo CatalogUi::emptyState('No files with missing dependencies', 'Nothing currently matches the selected game and dependency type.');
    } else {
        echo '<div class="table-wrap"><table><thead><tr><th>Requiring File</th><th>Missing Packages</th><th>Missing Object Rows</th><th>Package Names</th></tr></thead><tbody>';
        foreach ($fileRows as $row) {
            $names = array_values(array_filter(array_map('trim', explode(', ', (string)($row['missing_package_names'] ?? ''))), static fn(string $name): bool => $name !== ''));
            echo '<tr><td class="game-missing-file"><strong class="mono"><a href="file-info.php?id=' . (int)$row['file_id'] . '">' . catalog_h((string)$row['package_name']) . '</a></strong>'
                . '<br><a class="muted small" href="file-examine.php?id=' . (int)$row['file_id'] . '">' . catalog_h((string)$row['original_name']) . '</a></td>';
            echo '<td>' . (int)$row['missing_package_count'] . '</td><td>' . (int)$row['missing_object_rows'] . '</td><td class="mono game-missing-package-list">';
            if ($names === []) {
                echo '<span class="muted">—</span>';
            } else {
                foreach ($names as $index => $name) {
                    echo ($index > 0 ? '<br>' : '') . '<a href="' . catalog_h(game_missing_url($gameId, $type, ['package' => $name])) . '">' . catalog_h($name) . '</a>';
                }
            }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
        echo game_missing_pagination($gameId, $type, 'file_page', $filePage, $filePages, $selectedPackage !== '' ? ['package' => $selectedPackage] : []);
    }
    echo '</div></section>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Missing packages</h2><p>' . number_format($missingPackages) . ' package' . ($missingPackages === 1 ? '' : 's') . ' in the selected scope.</p></div></div><div class="ui-section__body">';
    if ($packageRows === []) {
        echo CatalogUi::emptyState('No missing packages', 'Nothing currently matches the selected game and dependency type.');
    } else {
        echo '<div class="table-wrap"><table><thead><tr><th>Package</th><th>Missing Object Rows</th><th>Requiring Files</th></tr></thead><tbody>';
        foreach ($packageRows as $row) {
            $package = (string)$row['required_package'];
            $url = game_missing_url($gameId, $type, ['package' => $package]);
            echo '<tr><td class="mono"><a href="' . catalog_h($url) . '">' . catalog_h($package) . '</a></td>'
                . '<td><a href="' . catalog_h($url) . '">' . (int)$row['missing_object_rows'] . '</a></td>'
                . '<td><a href="' . catalog_h($url) . '">' . (int)$row['requiring_file_count'] . '</a></td></tr>';
        }
        echo '</tbody></table></div>';
        echo game_missing_pagination($gameId, $type, 'package_page', $packagePage, $packagePages, $selectedPackage !== '' ? ['package' => $selectedPackage] : []);
    }
    echo '</div></section>';

    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('Game missing dependencies error');
    }
    echo CatalogUi::alert('danger', $error->getMessage(), 'The filtered missing-dependency page could not be loaded.');
    catalog_foot();
}
