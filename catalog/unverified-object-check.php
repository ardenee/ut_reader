<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/UnverifiedObjectCheck.php';

function uvoc_back_url(array $item): string
{
    $gameId = (int)($item['game']['id'] ?? 0);
    return 'unverified-files.php' . ($gameId > 0 ? '?source_game_id=' . $gameId : '');
}

function uvoc_return_anchor_url(array $item): string
{
    return uvoc_back_url($item) . '#unverified-file-' . rawurlencode((string)($item['token'] ?? ''));
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Queued Package Object Check')) {
        exit;
    }

    $token = trim((string)($_GET['token'] ?? ''));
    if ($token === '') {
        throw new RuntimeException('Choose a queued package from Unverified Files before running an object check.');
    }

    $result = uvoc_check($db, $config, $token);
    $item = $result['item'];
    $reader = $result['reader'];
    $candidates = $result['candidates'];
    $analysisError = $result['analysis_error'];

    catalog_head('Queued Package Object Check');
    echo <<<'CSS'
<style>
.uvoc-summary { display:grid; grid-template-columns:repeat(4,minmax(0,1fr)); gap:12px; margin:0 0 18px; }
.uvoc-note { border-left:4px solid #f6c453; padding-left:12px; }
.uvoc-table { min-width:980px; }
.uvoc-match { color:#b8f3cb; }
.uvoc-none { color:var(--muted); }
.uvoc-paths { margin:7px 0 0; padding-left:18px; max-width:620px; }
.uvoc-paths li { overflow-wrap:anywhere; }
.uvoc-signature { max-width:780px; }
.uvoc-signature th { width:220px; }
@media (max-width:700px) { .uvoc-summary { grid-template-columns:1fr; } }
</style>
CSS;
    echo CatalogUi::pageHeader(
        'Queued Package Object Check',
        'Reads this queued package without importing it, then tests its actual exported object paths against catalog dependency imports.',
        ['Back to Unverified Files' => uvoc_back_url($item)]
    );

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>' . catalog_h((string)$item['original_name']) . '</h2><p>Queued in ' . catalog_h((string)$item['game']['name']) . ' / unverified. Package name used for comparison: <span class="mono">' . catalog_h((string)$item['package_name']) . '</span>.</p></div></div><div class="ui-section__body">';
    echo '<p class="uvoc-note">This is stronger than the filename-only Reference Candidates hint. A positive exact-object match means this file exported an object path currently required by a catalogued file in that game. A zero match does not prove the package cannot be useful; it only means no currently indexed dependency requested one of the exported paths exactly.</p>';
    echo '</div></section>';

    if (is_array($analysisError)) {
        $signature = is_array($analysisError['signature'] ?? null) ? $analysisError['signature'] : [];
        echo CatalogUi::alert('warning', 'Object tables could not be read for this queued file.', (string)($analysisError['message'] ?? 'The file remains unchanged in the unverified queue.'));
        echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Package signature check</h2><p>This inspection did not import, move, or delete the queued file.</p></div></div><div class="ui-section__body">';
        echo '<table class="uvoc-signature">';
        echo '<tr><th>Expected Unreal package tag</th><td class="mono">' . catalog_h((string)($signature['expected_tag'] ?? '0x9E2A83C1')) . '</td></tr>';
        echo '<tr><th>Found tag</th><td class="mono">' . catalog_h((string)($signature['found_tag'] ?? 'unavailable')) . '</td></tr>';
        echo '<tr><th>First 4 bytes</th><td class="mono">' . catalog_h((string)($signature['found_hex'] ?? '')) . '</td></tr>';
        echo '<tr><th>ASCII interpretation</th><td class="mono">' . catalog_h((string)($signature['found_text'] ?? '')) . '</td></tr>';
        echo '</table>';
        echo '<p class="page-links"><a class="button secondary" href="' . catalog_h(uvoc_return_anchor_url($item)) . '">Return to file actions</a></p>';
        echo '</div></section>';
        catalog_foot();
        exit;
    }

    echo '<div class="uvoc-summary">';
    echo '<div class="stat"><h2>' . catalog_h((string)$reader['engine']) . '</h2><p>Detected reader</p></div>';
    echo '<div class="stat"><h2>' . (int)$reader['name_count'] . '</h2><p>Names read</p></div>';
    echo '<div class="stat"><h2>' . (int)$reader['import_count'] . '</h2><p>Imports read</p></div>';
    echo '<div class="stat"><h2>' . (int)$reader['export_count'] . '</h2><p>Exports read</p></div>';
    echo '</div>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Dependency object comparison</h2><p>Only games where existing catalog dependencies require package <span class="mono">' . catalog_h((string)$item['package_name']) . '</span> are shown.</p></div></div><div class="ui-section__body">';
    if ($candidates === []) {
        echo CatalogUi::emptyState('No package-name dependency candidates', 'No indexed catalog Import currently requires this queued package name. The file can still be imported manually into a chosen game for cataloging.');
    } else {
        echo '<div class="table-wrap"><table class="uvoc-table"><thead><tr><th>Game</th><th>Import Rows</th><th>Owner Files</th><th>Exact Export Matches</th><th>Not Matched Exactly</th><th>Sample Exact Matches</th><th>File Actions</th></tr></thead><tbody>';
        foreach ($candidates as $candidate) {
            echo '<tr>';
            echo '<td><a href="game-files.php?id=' . (int)$candidate['game_id'] . '">' . catalog_h((string)$candidate['game_name']) . '</a></td>';
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
            echo '</td>';
            echo '<td><a class="button secondary" href="' . catalog_h(uvoc_return_anchor_url($item)) . '">Return to file actions</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table></div>';
    }
    echo '</div></section>';

    catalog_foot();
} catch (Throwable $e) {
    error_log('[UnrealDB object check page] ' . $e->getMessage());
    catalog_head('Queued Package Object Check Error');
    echo CatalogUi::alert('danger', 'Queued package object check could not be opened.', 'The queued file was not changed. Return to Unverified Files and retry or inspect the original rejection reason.');
    echo CatalogUi::pageHeader('Queued Package Object Check', '', ['Back to Unverified Files' => 'unverified-files.php']);
    catalog_foot();
}
