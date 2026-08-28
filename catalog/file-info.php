<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders and/or processes the catalog page for File info.
 * Why: It exists as a distinct user or administrator entry point for this catalog workflow.
 * Role: Web UI entry point; reusable application logic should be supplied by shared `lib`/`src` services rather than
 *       copied into peer pages.
 * Audit: Active page unless navigation/tests show otherwise; review large page-local helper blocks for extraction
 *        when similar logic appears elsewhere.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogDependencySchema.php';
require_once __DIR__ . '/lib/CatalogCompactDependencies.php';
require_once __DIR__ . '/lib/CatalogFileFeedback.php';

function file_info_type_from_extension(string $ext): array
{
    $ext = strtolower(trim($ext, '. '));

    return match ($ext) {
        'unr', 'un2', 'ut2', 'ut3', 'umap' => ['map', 'type-map'],
        'umx' => ['music', 'type-music'],
        'uax' => ['sound', 'type-sound'],
        'utx' => ['texture', 'type-texture'],
        'usx' => ['static mesh', 'type-static-mesh'],
        'ukx' => ['animation', 'type-animation'],
        'upx' => ['particle/effect', 'type-particle-effect'],
        'ugx' => ['gui', 'type-gui'],
        'con' => ['content', 'type-content'],
        'u', 'upk', 'uasset' => ['package', 'type-package'],
        default => [$ext !== '' ? $ext : 'unknown', 'type-unknown'],
    };
}

function file_info_source_label(string $source): string
{
    return match ($source) {
        'exact_package' => 'exact package',
        'exact_package_alias' => 'exact package alias',
        'exact_object' => 'exact object',
        'exact_object_alias' => 'exact object alias',
        'package_alias' => 'package alias',
        'package_alias_object' => 'alias object',
        'ue_asset_object_path' => 'UE asset path',
        'common_script' => 'common script',
        'asset_registry' => 'asset registry',
        'soft_reference' => 'soft reference',
        'redirector' => 'redirector',
        'none' => 'none',
        default => $source !== '' ? $source : 'unknown',
    };
}

function file_info_dependency_table(array $dependencies): string
{
    if ($dependencies === []) {
        return '<p class="muted">No dependencies in this status.</p>';
    }

    $html = '<table data-sortable-table><thead><tr><th>Status</th><th>Source</th><th>Confidence</th><th>Required object</th><th>Resolved package</th></tr></thead><tbody>';
    foreach ($dependencies as $dep) {
        $resolved = $dep['resolved_id']
            ? '<a href="file-info.php?id=' . (int)$dep['resolved_id'] . '">' . catalog_h($dep['resolved_package'] ?: $dep['resolved_file']) . '</a>'
            : '<span class="muted">not resolved</span>';
        $resolvedSort = (string)($dep['resolved_package'] ?: $dep['resolved_file'] ?: '');
        $source = (string)($dep['resolution_source'] ?? 'unknown');
        $confidence = (string)($dep['resolution_confidence'] ?? 'unknown');
        $html .= '<tr><td data-sort-value="' . catalog_h((string)$dep['status']) . '"><span class="dep ' . catalog_h($dep['status']) . '">' . catalog_h($dep['status']) . '</span></td>'
            . '<td data-sort-value="' . catalog_h($source) . '"><span class="dep resolution-source">' . catalog_h(file_info_source_label($source)) . '</span></td>'
            . '<td class="mono" data-sort-value="' . catalog_h($confidence) . '">' . catalog_h($confidence) . '</td>'
            . '<td class="mono path">' . catalog_h($dep['required_object_path']) . '</td>'
            . '<td data-sort-value="' . catalog_h($resolvedSort) . '">' . $resolved . '</td></tr>';
    }
    return $html . '</tbody></table>';
}


/** @param list<array<string,mixed>> $rows */
function file_info_related_files_table(array $rows, string $emptyMessage): string
{
    if ($rows === []) {
        return '<p class="muted">' . catalog_h($emptyMessage) . '</p>';
    }
    $html = '<table data-sortable-table><thead><tr><th>Package</th><th>File</th><th>GUID / MD5</th><th>Size</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $fileId = (int)($row['id'] ?? 0);
        if ($fileId < 1) {
            continue;
        }
        $href = 'file-info.php?id=' . $fileId;
        $identitySortValue = (string)($row['package_guid'] ?? '') . ' ' . (string)($row['md5'] ?? '');
        $html .= '<tr><td class="mono"><a href="' . $href . '">' . catalog_h((string)($row['package_name'] ?? '')) . '</a></td>'
            . '<td><a href="' . $href . '">' . catalog_h((string)($row['original_name'] ?? '')) . '</a></td>'
            . '<td class="mono small used-by-identity" data-sort-value="' . catalog_h($identitySortValue) . '">'
            . '<span>GUID: ' . catalog_h((string)($row['package_guid'] ?? '')) . '</span>'
            . '<span>MD5: ' . catalog_h((string)($row['md5'] ?? '')) . '</span></td>'
            . '<td data-sort-value="' . (int)($row['file_size'] ?? 0) . '">' . catalog_h(catalog_bytes((int)($row['file_size'] ?? 0))) . '</td></tr>';
    }
    return $html . '</tbody></table>';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_dependency_schema_ensure($db);

    $id = (int)($_GET['id'] ?? 0);
    $file = catalog_one($db, 'SELECT f.*, g.name game_name FROM ue_files f JOIN ue_games g ON g.id=f.game_id WHERE f.id=?', [$id]);
    if (!$file) {
        throw new RuntimeException('File not found');
    }

    $deps = catalog_dependency_rows($db, $config, $id);
    $dependencyStatuses = [
        'missing' => 'Missing',
        'package_only' => 'Package only',
        'resolved' => 'Resolved',
        'common' => 'Common',
    ];
    $dependencyGroups = array_fill_keys(array_keys($dependencyStatuses), []);
    foreach ($deps as $dep) {
        $status = (string)($dep['status'] ?? '');
        if (isset($dependencyGroups[$status])) {
            $dependencyGroups[$status][] = $dep;
        }
    }

    $requestedDependencyStatus = strtolower(trim((string)($_GET['dep_status'] ?? '')));
    $initialDependencyStatus = $requestedDependencyStatus;
    if ($initialDependencyStatus !== 'all' && !isset($dependencyStatuses[$initialDependencyStatus])) {
        $initialDependencyStatus = 'all';
        foreach (array_keys($dependencyStatuses) as $status) {
            if ($dependencyGroups[$status] !== []) {
                $initialDependencyStatus = $status;
                break;
            }
        }
    }

    catalog_head('File info');
    echo <<<'CSS'
<style>
.file-info-title {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
}

.file-info-title h1 {
    margin: 0;
}

.file-info-title .dep {
    margin: 0;
}

.file-info-context {
    margin: 8px 0;
}

.dependency-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    margin: 0 0 12px;
}

.dependency-tab {
    margin: 0;
    text-decoration: none;
}

.dependency-tab:hover {
    text-decoration: none;
    filter: brightness(1.13);
}

.dependency-tab.is-active {
    outline: 2px solid var(--blue);
    outline-offset: 2px;
    background: rgba(118, 169, 255, .13);
}

.dependency-tab.is-empty {
    opacity: .62;
}

.dependency-tab-panel[hidden] {
    display: none;
}

.dependency-status-summary {
    margin: 0 0 10px;
}

.used-by-identity {
    min-width: 355px;
}

.used-by-identity span {
    display: block;
}

.resolution-source {
    background: rgba(118, 169, 255, .12);
    border-color: rgba(118, 169, 255, .45);
}

[data-sortable-table] th {
    cursor: pointer;
    user-select: none;
}

[data-sortable-table] th::after {
    content: '↕';
    display: inline-block;
    margin-left: 7px;
    color: var(--muted);
    font-size: 11px;
    opacity: .7;
}

[data-sortable-table] th.is-sort-ascending::after {
    content: '▲';
    color: var(--blue);
    opacity: 1;
}

[data-sortable-table] th.is-sort-descending::after {
    content: '▼';
    color: var(--blue);
    opacity: 1;
}
</style>
CSS;

    $compressed = (int)($file['is_compressed'] ?? 0) === 1;
    [$fileType, $fileTypeClass] = file_info_type_from_extension((string)($file['extension'] ?? ''));
    $packageHref = 'file-examine.php?id=' . $id;
    $gameHref = 'game-files.php?id=' . (int)$file['game_id'];
    $tableHref = 'file-examine.php?id=' . $id;

    echo '<div class="card">';
    echo '<div class="file-info-title"><h1>' . catalog_h($file['package_name']) . '</h1><span class="dep file-type-pill ' . catalog_h($fileTypeClass) . '">' . catalog_h($fileType) . '</span></div>';
    echo '<p class="file-info-context"><a href="' . $packageHref . '" title="Examine this file">' . catalog_h($file['original_name']) . '</a> / <a href="' . $gameHref . '" title="Open game files">' . catalog_h($file['game_name']) . '</a></p>';
    echo '<p><a class="button" href="download-info.php?id=' . $id . '">Download options</a> <a class="button secondary" href="' . $packageHref . '">Examine file</a></p>';
    echo '<p><span class="dep ' . ($compressed ? 'compressed' : 'uncompressed') . '">' . ($compressed ? 'compressed' : 'uncompressed') . '</span> <span class="mono small">flags 0x' . strtoupper(str_pad(dechex((int)($file['compression_flags'] ?? 0)), 8, '0', STR_PAD_LEFT)) . '</span></p>';
    echo '<table><tr><th>MD5</th><td class="mono">' . catalog_h($file['md5']) . '</td></tr><tr><th>SHA1</th><td class="mono">' . catalog_h($file['sha1']) . '</td></tr><tr><th>GUID</th><td class="mono">' . catalog_h($file['package_guid']) . '</td></tr><tr><th>Status</th><td>' . catalog_h($file['scan_status']) . '</td></tr><tr><th>Tables</th><td><a href="' . $tableHref . '" title="Examine names, imports and exports">' . (int)$file['name_count'] . ' names / ' . (int)$file['import_count'] . ' imports / ' . (int)$file['export_count'] . ' exports</a></td></tr></table>';
    echo '</div>';

    echo catalog_file_feedback_form_html($id);

    $locations = catalog_all($db, 'SELECT s.name source_name, s.source_type, l.source_relative_path, l.last_seen_at FROM ue_file_locations l JOIN ue_sources s ON s.id=l.source_id WHERE l.file_id=? AND l.exists_in_source=1 ORDER BY s.name, l.source_relative_path', [$id]);
    echo '<div class="card"><h2>Source availability</h2>';
    if (!$locations) {
        echo '<p class="muted">No configured source currently records this file.</p>';
    } else {
        echo '<p class="muted">Only the source name/type and relative path are shown. Real source base paths are hidden.</p>';
        echo '<table data-sortable-table><thead><tr><th>Source</th><th>Type</th><th>Relative path</th><th>Last seen</th></tr></thead><tbody>';
        foreach ($locations as $loc) {
            echo '<tr><td>' . catalog_h($loc['source_name']) . '</td><td class="mono">' . catalog_h($loc['source_type']) . '</td><td class="mono path">' . catalog_h($loc['source_relative_path']) . '</td><td data-sort-value="' . catalog_h((string)$loc['last_seen_at']) . '">' . catalog_h($loc['last_seen_at']) . '</td></tr>';
        }
        echo '</tbody></table>';
    }
    echo '</div>';

    if (!empty($file['scan_notes'])) {
        echo '<div class="card"><h2>Scan notes</h2><pre class="mono">' . catalog_h($file['scan_notes']) . '</pre></div>';
    }

    echo '<div class="card" id="dependencies"><h2>Dependencies</h2>';
    if (!$deps) {
        echo '<p class="muted">No dependencies were recorded for this package.</p>';
    } else {
        echo '<nav class="dependency-tabs" aria-label="Dependency status tabs" role="tablist" data-dependency-default="' . catalog_h($initialDependencyStatus) . '">';
        $allActive = $initialDependencyStatus === 'all';
        echo '<a id="dependency-tab-all" class="dep dependency-tab' . ($allActive ? ' is-active' : '') . '" href="#dependency-all" data-dependency-tab="all" role="tab" aria-controls="dependency-all" aria-selected="' . ($allActive ? 'true' : 'false') . '">All: ' . count($deps) . '</a>';
        foreach ($dependencyStatuses as $status => $label) {
            $count = count($dependencyGroups[$status]);
            $active = $initialDependencyStatus === $status;
            echo '<a id="dependency-tab-' . catalog_h($status) . '" class="dep ' . catalog_h($status) . ' dependency-tab' . ($active ? ' is-active' : '') . ($count === 0 ? ' is-empty' : '') . '" href="#dependency-' . catalog_h($status) . '" data-dependency-tab="' . catalog_h($status) . '" role="tab" aria-controls="dependency-' . catalog_h($status) . '" aria-selected="' . ($active ? 'true' : 'false') . '">' . catalog_h($label) . ': ' . $count . '</a>';
        }
        echo '</nav>';

        $allHidden = $initialDependencyStatus !== 'all' ? ' hidden' : '';
        echo '<section id="dependency-all" class="dependency-tab-panel" data-dependency-panel="all" role="tabpanel" aria-labelledby="dependency-tab-all"' . $allHidden . '><p class="muted dependency-status-summary">Showing all dependencies: ' . count($deps) . '.</p>' . file_info_dependency_table($deps) . '</section>';
        foreach ($dependencyStatuses as $status => $label) {
            $hidden = $initialDependencyStatus !== $status ? ' hidden' : '';
            echo '<section id="dependency-' . catalog_h($status) . '" class="dependency-tab-panel" data-dependency-panel="' . catalog_h($status) . '" role="tabpanel" aria-labelledby="dependency-tab-' . catalog_h($status) . '"' . $hidden . '><p class="muted dependency-status-summary">Showing ' . catalog_h($label) . ': ' . count($dependencyGroups[$status]) . '.</p>' . file_info_dependency_table($dependencyGroups[$status]) . '</section>';
        }
    }
    echo '</div>';

    $usesById = [];
    foreach ($deps as $dep) {
        $targetId = (int)($dep['resolved_id'] ?? 0);
        if ($targetId < 1 || $targetId === $id || (string)($dep['status'] ?? '') === 'common') {
            continue;
        }
        $usesById[$targetId] = [
            'id' => $targetId,
            'package_name' => (string)($dep['resolved_package'] ?? ''),
            'original_name' => (string)($dep['resolved_file'] ?? ''),
            'package_guid' => (string)($dep['resolved_guid'] ?? ''),
            'md5' => (string)($dep['resolved_md5'] ?? ''),
            'file_size' => (int)($dep['resolved_size'] ?? 0),
        ];
    }
    $uses = array_values($usesById);
    usort($uses, static fn(array $left, array $right): int =>
        strnatcasecmp((string)$left['package_name'], (string)$right['package_name'])
        ?: strnatcasecmp((string)$left['original_name'], (string)$right['original_name'])
    );

    echo '<div class="card"><h2>Uses (' . count($uses) . ')</h2>'
        . file_info_related_files_table($uses, 'No resolved dependency files yet.')
        . '</div>';

    $usedBy = catalog_dependency_used_by_rows($db, $id, 200);
    echo '<div class="card"><h2>Used by (' . count($usedBy) . ')</h2>'
        . file_info_related_files_table($usedBy, 'No resolved reverse dependency links yet.')
        . '</div>';

    echo <<<'HTML'
<script>
(function () {
    'use strict';

    var dependencyTabs = Array.from(document.querySelectorAll('[data-dependency-tab]'));
    var dependencyPanels = Array.from(document.querySelectorAll('[data-dependency-panel]'));
    var dependencyNav = document.querySelector('[data-dependency-default]');

    function dependencyTabFromHash() {
        var hash = decodeURIComponent(window.location.hash.replace(/^#/, ''));
        if (hash.indexOf('dependency-') === 0) {
            return hash.slice('dependency-'.length);
        }
        return dependencyNav ? dependencyNav.dataset.dependencyDefault : 'all';
    }

    function activateDependencyTab(tabName) {
        if (!dependencyPanels.some(function (panel) { return panel.dataset.dependencyPanel === tabName; })) {
            tabName = dependencyNav ? dependencyNav.dataset.dependencyDefault : 'all';
        }
        dependencyPanels.forEach(function (panel) {
            panel.hidden = panel.dataset.dependencyPanel !== tabName;
        });
        dependencyTabs.forEach(function (tab) {
            var active = tab.dataset.dependencyTab === tabName;
            tab.classList.toggle('is-active', active);
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
        });
    }

    if (dependencyTabs.length) {
        dependencyTabs.forEach(function (tab) {
            tab.addEventListener('click', function (event) {
                event.preventDefault();
                var tabName = tab.dataset.dependencyTab;
                window.history.pushState(null, '', '#dependency-' + tabName);
                activateDependencyTab(tabName);
            });
        });
        window.addEventListener('hashchange', function () {
            activateDependencyTab(dependencyTabFromHash());
        });
        activateDependencyTab(dependencyTabFromHash());
    }

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
    catalog_head('Error');
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}