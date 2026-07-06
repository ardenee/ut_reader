<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/UnverifiedObjectCheck.php';

/** @return list<string> */
function uvoc_requested_tokens(): array
{
    $tokens = [];
    $requested = $_GET['tokens'] ?? [];
    if (is_string($requested)) {
        $requested = [$requested];
    }
    if (is_array($requested)) {
        foreach ($requested as $token) {
            if (!is_string($token)) {
                continue;
            }
            $token = trim($token);
            if ($token !== '') {
                $tokens[$token] = true;
            }
        }
    }

    $legacyToken = trim((string)($_GET['token'] ?? ''));
    if ($legacyToken !== '') {
        $tokens[$legacyToken] = true;
    }
    return array_keys($tokens);
}

function uvoc_render_signature_table(array $signature): void
{
    echo '<table class="uvoc-signature">';
    echo '<tr><th>Expected Unreal package tag</th><td class="mono">' . catalog_h((string)($signature['expected_tag'] ?? '0x9E2A83C1')) . '</td></tr>';
    echo '<tr><th>Found tag</th><td class="mono">' . catalog_h((string)($signature['found_tag'] ?? 'unavailable')) . '</td></tr>';
    echo '<tr><th>First 4 bytes</th><td class="mono">' . catalog_h((string)($signature['found_hex'] ?? '')) . '</td></tr>';
    echo '<tr><th>ASCII interpretation</th><td class="mono">' . catalog_h((string)($signature['found_text'] ?? '')) . '</td></tr>';
    echo '</table>';
}

function uvoc_value(mixed $value): string
{
    if ($value === null || $value === '') {
        return '<span class="muted">—</span>';
    }
    return catalog_h((string)$value);
}

function uvoc_tab_panel_id(int $fileIndex, string $tab): string
{
    return 'uvoc-file-' . $fileIndex . '-tab-' . $tab;
}

function uvoc_render_names_table(array $rows): void
{
    echo '<div class="table-wrap"><table class="uvoc-data-table"><thead><tr><th>#</th><th>Name</th><th>Flags</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr><td class="mono">' . (int)$row['name_index'] . '</td><td class="mono">' . uvoc_value($row['name_text']) . '</td><td class="mono">' . uvoc_value($row['flags']) . '</td></tr>';
    }
    echo '</tbody></table></div>';
}

function uvoc_render_imports_table(array $rows): void
{
    echo '<div class="table-wrap"><table class="uvoc-data-table uvoc-import-table"><thead><tr><th>#</th><th>Class Package</th><th>Class</th><th>Object</th><th>Outer</th><th>Root Package</th><th>Relative Object Path</th><th>Full Path</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td class="mono">' . (int)$row['import_index'] . '</td>';
        echo '<td class="mono">' . uvoc_value($row['class_package']) . '</td>';
        echo '<td class="mono">' . uvoc_value($row['class_name']) . '</td>';
        echo '<td class="mono">' . uvoc_value($row['object_name']) . '</td>';
        echo '<td class="mono">' . (int)$row['outer_index'] . '</td>';
        echo '<td class="mono">' . uvoc_value($row['root_package']) . '</td>';
        echo '<td class="mono">' . uvoc_value($row['relative_object_path']) . '</td>';
        echo '<td class="mono uvoc-path-cell">' . uvoc_value($row['full_path']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function uvoc_render_exports_table(array $rows): void
{
    echo '<div class="table-wrap"><table class="uvoc-data-table uvoc-export-table"><thead><tr><th>#</th><th>Class</th><th>Object</th><th>Outer</th><th>Local Path</th><th>Full Path</th><th>Flags</th><th>Serial Size</th><th>Serial Offset</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td class="mono">' . (int)$row['export_index'] . '</td>';
        echo '<td class="mono uvoc-path-cell">' . uvoc_value($row['class_name']) . '</td>';
        echo '<td class="mono">' . uvoc_value($row['object_name']) . '</td>';
        echo '<td class="mono">' . (int)$row['outer_index'] . '</td>';
        echo '<td class="mono uvoc-path-cell">' . uvoc_value($row['local_path']) . '</td>';
        echo '<td class="mono uvoc-path-cell">' . uvoc_value($row['full_path']) . '</td>';
        echo '<td class="mono">' . uvoc_value($row['object_flags']) . '</td>';
        echo '<td class="mono">' . uvoc_value($row['serial_size']) . '</td>';
        echo '<td class="mono">' . uvoc_value($row['serial_offset']) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div>';
}

function uvoc_render_dependency_table(array $candidates): void
{
    if ($candidates === []) {
        echo CatalogUi::emptyState('No package-name dependency candidates', 'No indexed catalog Import currently requires this queued package name.');
        return;
    }

    echo '<div class="table-wrap"><table class="uvoc-data-table uvoc-dependency-table"><thead><tr><th>Game</th><th>Import Rows</th><th>Owner Files</th><th>Exact Export Matches</th><th>Not Matched Exactly</th><th>Sample Exact Matches</th></tr></thead><tbody>';
    foreach ($candidates as $candidate) {
        echo '<tr>';
        echo '<td><a href="game-files.php?id=' . (int)$candidate['game_id'] . '" target="_blank" rel="noopener">' . catalog_h((string)$candidate['game_name']) . '</a></td>';
        echo '<td>' . (int)$candidate['import_count'] . '</td>';
        echo '<td>' . (int)$candidate['owner_count'] . '</td>';
        echo '<td class="uvoc-match">' . (int)$candidate['exact_object_matches'] . '</td>';
        echo '<td>' . (int)$candidate['unmatched_object_count'] . '</td>';
        echo '<td>';
        if ($candidate['matched_paths'] === []) {
            echo '<span class="uvoc-none">No exact exported object paths matched.</span>';
        } else {
            echo '<ul class="uvoc-paths mono small">';
            foreach ($candidate['matched_paths'] as $path) {
                echo '<li>' . catalog_h((string)$path) . '</li>';
            }
            echo '</ul>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}

function uvoc_render_table_tabs(int $fileIndex, array $reader, array $candidates): void
{
    $tables = is_array($reader['tables'] ?? null) ? $reader['tables'] : [];
    $names = is_array($tables['names'] ?? null) ? $tables['names'] : [];
    $imports = is_array($tables['imports'] ?? null) ? $tables['imports'] : [];
    $exports = is_array($tables['exports'] ?? null) ? $tables['exports'] : [];
    $tabs = [
        'names' => 'Names (' . count($names) . ')',
        'imports' => 'Imports (' . count($imports) . ')',
        'exports' => 'Exports (' . count($exports) . ')',
        'dependencies' => 'Dependency matches (' . count($candidates) . ')',
    ];

    echo '<section class="uvoc-table-tabs" data-uvoc-tabs>'; 
    echo '<div class="uvoc-tab-list" role="tablist" aria-label="Parsed package tables">';
    foreach ($tabs as $tab => $label) {
        $panelId = uvoc_tab_panel_id($fileIndex, $tab);
        $active = $tab === 'names';
        echo '<button type="button" class="uvoc-tab' . ($active ? ' is-active' : '') . '" role="tab" aria-selected="' . ($active ? 'true' : 'false') . '" aria-controls="' . catalog_h($panelId) . '" data-uvoc-tab="' . catalog_h($panelId) . '">' . catalog_h($label) . '</button>';
    }
    echo '</div>';

    foreach (array_keys($tabs) as $tab) {
        $panelId = uvoc_tab_panel_id($fileIndex, $tab);
        $active = $tab === 'names';
        echo '<div id="' . catalog_h($panelId) . '" class="uvoc-tab-panel' . ($active ? ' is-active' : '') . '" role="tabpanel">';
        echo '<div class="uvoc-tab-filter"><label>Filter <input type="search" class="uvoc-table-filter" data-uvoc-filter="' . catalog_h($panelId) . '" placeholder="Filter this table"></label><span class="uvoc-filter-count" data-uvoc-filter-count="' . catalog_h($panelId) . '"></span></div>';
        if ($tab === 'names') {
            uvoc_render_names_table($names);
        } elseif ($tab === 'imports') {
            uvoc_render_imports_table($imports);
        } elseif ($tab === 'exports') {
            uvoc_render_exports_table($exports);
        } else {
            uvoc_render_dependency_table($candidates);
        }
        echo '</div>';
    }
    echo '</section>';
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Queued Package Object Check')) {
        exit;
    }

    $tokens = uvoc_requested_tokens();
    if ($tokens === []) {
        throw new RuntimeException('Select queued files on Unverified Files before running Object Check.');
    }

    $checks = [];
    foreach ($tokens as $token) {
        try {
            $checks[] = ['result' => uvoc_check($db, $config, $token), 'error' => null];
        } catch (Throwable $error) {
            error_log('[UnrealDB object check popup] ' . $error->getMessage());
            $checks[] = ['result' => null, 'error' => 'The queued file could not be opened: ' . $error->getMessage()];
        }
    }

    catalog_head('Queued Package Object Check');
    echo <<<'CSS'
<style>
.uvoc-toolbar { display:flex; justify-content:space-between; align-items:center; gap:12px; flex-wrap:wrap; margin-bottom:16px; }
.uvoc-summary { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin:0 0 18px; }
.uvoc-note { border-left:4px solid #f6c453; padding-left:12px; }
.uvoc-match { color:#b8f3cb; }
.uvoc-none { color:var(--muted); }
.uvoc-paths { margin:7px 0 0; padding-left:18px; max-width:620px; }
.uvoc-paths li { overflow-wrap:anywhere; }
.uvoc-signature { max-width:780px; }
.uvoc-signature th { width:220px; }
.uvoc-file { margin-bottom:18px; }
.uvoc-file:last-child { margin-bottom:0; }
.uvoc-table-tabs { margin-top:18px; }
.uvoc-tab-list { display:flex; gap:4px; flex-wrap:wrap; border-bottom:1px solid var(--line2); }
.uvoc-tab { appearance:none; border:1px solid transparent; border-bottom:0; border-radius:7px 7px 0 0; padding:8px 11px; color:var(--muted); background:transparent; cursor:pointer; }
.uvoc-tab:hover { color:var(--text); background:rgba(255,255,255,.035); }
.uvoc-tab.is-active { color:var(--text); border-color:var(--line2); background:rgba(255,255,255,.055); }
.uvoc-tab-panel { display:none; padding-top:12px; }
.uvoc-tab-panel.is-active { display:block; }
.uvoc-tab-filter { display:flex; align-items:center; gap:10px; justify-content:space-between; flex-wrap:wrap; margin:0 0 9px; }
.uvoc-tab-filter label { display:flex; align-items:center; gap:7px; color:var(--muted); font-size:13px; }
.uvoc-tab-filter input { min-width:280px; }
.uvoc-filter-count { color:var(--muted); font-size:12px; }
.uvoc-data-table { min-width:840px; }
.uvoc-import-table { min-width:1250px; }
.uvoc-export-table { min-width:1280px; }
.uvoc-path-cell { overflow-wrap:anywhere; }
@media (max-width:700px) { .uvoc-summary { grid-template-columns:1fr; } .uvoc-tab-filter input { min-width:0; width:100%; } }
</style>
CSS;
    echo CatalogUi::pageHeader(
        'Queued Package Object Check',
        count($tokens) . ' selected queued file(s) inspected in this popup. Object Check does not import, move, or delete files.'
    );
    echo '<div class="uvoc-toolbar"><p class="muted">Only files with the official Unreal package tag can have Names, Imports, and Exports compared.</p><button type="button" class="button secondary" onclick="window.close()">Close popup</button></div>';

    foreach ($checks as $index => $check) {
        if ($check['result'] === null) {
            echo '<section class="ui-section uvoc-file"><div class="ui-section__header"><div><h2>Selected file ' . ($index + 1) . '</h2></div></div><div class="ui-section__body">';
            echo CatalogUi::alert('danger', 'Object Check could not open this selected file.', (string)$check['error']);
            echo '</div></section>';
            continue;
        }

        $result = $check['result'];
        $item = $result['item'];
        $reader = $result['reader'];
        $candidates = $result['candidates'];
        $analysisError = $result['analysis_error'];

        echo '<section class="ui-section uvoc-file"><div class="ui-section__header"><div><h2>' . catalog_h((string)$item['original_name']) . '</h2><p>Queued in ' . catalog_h((string)$item['game']['name']) . ' / unverified. Package-name comparison key: <span class="mono">' . catalog_h((string)$item['package_name']) . '</span>.</p></div></div><div class="ui-section__body">';
        echo '<p class="uvoc-note">The tabs below show the file’s actual parsed Names, Imports, and Exports tables. Use Imports and Exports to identify package families, classes, maps, textures, scripts, and object paths that suggest the correct game.</p>';

        if (is_array($analysisError)) {
            $signature = is_array($analysisError['signature'] ?? null) ? $analysisError['signature'] : [];
            echo CatalogUi::alert('warning', 'Object tables could not be read for this queued file.', (string)($analysisError['message'] ?? 'The queued file was not changed.'));
            uvoc_render_signature_table($signature);
            echo '</div></section>';
            continue;
        }

        echo '<div class="uvoc-summary">';
        echo '<div class="stat"><h2>' . catalog_h((string)$reader['engine']) . '</h2><p>Detected reader</p></div>';
        echo '<div class="stat"><h2>' . (int)$reader['name_count'] . '</h2><p>Names read</p></div>';
        echo '<div class="stat"><h2>' . (int)$reader['import_count'] . '</h2><p>Imports read</p></div>';
        echo '<div class="stat"><h2>' . (int)$reader['export_count'] . '</h2><p>Exports read</p></div>';
        echo '</div>';

        uvoc_render_table_tabs($index, $reader, $candidates);
        echo '</div></section>';
    }

    echo <<<'JS'
<script>
(function () {
    'use strict';

    document.querySelectorAll('[data-uvoc-tabs]').forEach(function (container) {
        var tabs = Array.prototype.slice.call(container.querySelectorAll('.uvoc-tab'));
        var panels = Array.prototype.slice.call(container.querySelectorAll('.uvoc-tab-panel'));
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var targetId = tab.getAttribute('data-uvoc-tab');
                tabs.forEach(function (item) {
                    var active = item === tab;
                    item.classList.toggle('is-active', active);
                    item.setAttribute('aria-selected', active ? 'true' : 'false');
                });
                panels.forEach(function (panel) {
                    panel.classList.toggle('is-active', panel.id === targetId);
                });
            });
        });
    });

    document.querySelectorAll('.uvoc-table-filter').forEach(function (input) {
        input.addEventListener('input', function () {
            var panel = document.getElementById(input.getAttribute('data-uvoc-filter'));
            if (!panel) return;
            var query = input.value.trim().toLowerCase();
            var rows = Array.prototype.slice.call(panel.querySelectorAll('tbody tr'));
            var shown = 0;
            rows.forEach(function (row) {
                var visible = query === '' || row.textContent.toLowerCase().indexOf(query) !== -1;
                row.hidden = !visible;
                if (visible) shown++;
            });
            var count = document.querySelector('[data-uvoc-filter-count="' + input.getAttribute('data-uvoc-filter') + '"]');
            if (count) {
                count.textContent = query === '' ? rows.length + ' rows' : shown + ' of ' + rows.length + ' rows';
            }
        });
        input.dispatchEvent(new Event('input'));
    });
})();
</script>
JS;

    catalog_foot();
} catch (Throwable $e) {
    error_log('[UnrealDB object check popup] ' . $e->getMessage());
    catalog_head('Queued Package Object Check Error');
    echo CatalogUi::alert('danger', 'Queued package Object Check could not be opened.', 'No queued file was changed. Close this popup and retry from Unverified Files.');
    echo '<p><button type="button" class="button secondary" onclick="window.close()">Close popup</button></p>';
    catalog_foot();
}
