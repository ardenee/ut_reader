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
.uvoc-table { min-width:880px; }
.uvoc-match { color:#b8f3cb; }
.uvoc-none { color:var(--muted); }
.uvoc-paths { margin:7px 0 0; padding-left:18px; max-width:620px; }
.uvoc-paths li { overflow-wrap:anywhere; }
.uvoc-signature { max-width:780px; }
.uvoc-signature th { width:220px; }
.uvoc-file { margin-bottom:18px; }
.uvoc-file:last-child { margin-bottom:0; }
@media (max-width:700px) { .uvoc-summary { grid-template-columns:1fr; } }
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
        echo '<p class="uvoc-note">A positive exact-object match means this file exported an object path currently required by a catalogued file in that game. A zero match does not prove the package cannot be useful; it only means no currently indexed dependency requested one of its exported paths exactly.</p>';

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

        echo '<h3>Dependency object comparison</h3>';
        if ($candidates === []) {
            echo CatalogUi::emptyState('No package-name dependency candidates', 'No indexed catalog Import currently requires this queued package name.');
        } else {
            echo '<div class="table-wrap"><table class="uvoc-table"><thead><tr><th>Game</th><th>Import Rows</th><th>Owner Files</th><th>Exact Export Matches</th><th>Not Matched Exactly</th><th>Sample Exact Matches</th></tr></thead><tbody>';
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
        echo '</div></section>';
    }

    catalog_foot();
} catch (Throwable $e) {
    error_log('[UnrealDB object check popup] ' . $e->getMessage());
    catalog_head('Queued Package Object Check Error');
    echo CatalogUi::alert('danger', 'Queued package Object Check could not be opened.', 'No queued file was changed. Close this popup and retry from Unverified Files.');
    echo '<p><button type="button" class="button secondary" onclick="window.close()">Close popup</button></p>';
    catalog_foot();
}
