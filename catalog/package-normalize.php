<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders the legacy package-root normalizer and accepts administrator selections.
 * Why: Compact metadata inspection/mutation, identity writes and dependency repair now belong to a maintenance service.
 * Role: Presentation adapter for UE1/UE2/UE3 package-root normalization.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogLegacyPackageNormalizationService;

catalog_start_session();

const PACKAGE_NORMALIZE_MAX_ROWS = 950;

function package_normalize_int(string $key, int $default, int $min, int $max): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        $value = $default;
    }
    return max($min, min($max, (int)$value));
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Package root normalizer')) {
        exit;
    }

    $service = new CatalogLegacyPackageNormalizationService($db, $config);
    $games = $service->legacyGames();
    $gameId = $service->normalizeGameId(package_normalize_int('game_id', 0, 0, PHP_INT_MAX));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('package-normalize');
        $posted = is_array($_POST['file_ids'] ?? null) ? $_POST['file_ids'] : [];
        $result = $service->normalize(
            array_map('intval', $posted),
            PACKAGE_NORMALIZE_MAX_ROWS,
            (string)($_POST['rebuild_dependencies'] ?? '') === '1'
        );

        $_SESSION['package_normalize_flash'] = 'Normalized ' . $result['changed'] . ' file(s).'
            . ($result['rebuild']
                ? ' Dependency refresh package checks=' . $result['affected_packages'] . ', warnings=' . $result['rebuild_warnings'] . '.'
                : ' Durable projection reconciliation was queued.');
        header('Location: package-normalize.php' . ($gameId > 0 ? '?game_id=' . $gameId : ''));
        exit;
    }

    $dirtyRows = $service->dirtyRows($gameId, PACKAGE_NORMALIZE_MAX_ROWS);
    catalog_head('Package root normalizer');
    catalog_flash($_SESSION['package_normalize_flash'] ?? null);
    unset($_SESSION['package_normalize_flash']);

    echo <<<'CSS'
<style>
.package-normalize-controls { display:flex; align-items:end; gap:10px; flex-wrap:wrap; margin:0 0 12px; }
.package-normalize-controls label { display:grid; gap:5px; }
.package-normalize-table { min-width:1250px; }
.package-normalize-table th, .package-normalize-table td { vertical-align:top; }
.package-normalize-select { width:42px; text-align:center; white-space:nowrap; }
.package-normalize-size { white-space:nowrap; }
.package-normalize-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:12px 0; }
.package-normalize-diff span { display:block; }
</style>
CSS;

    echo CatalogUi::pageHeader(
        'Package root normalizer',
        'Repair legacy UE1/UE2/UE3 catalog rows that still contain browser/Windows filename suffixes in package names, original filenames, or export full paths.',
        ['Source Identity Repair (UE4/UE5)' => 'source-identity-repair.php', 'Duplicates' => 'duplicates.php', 'Full Sync' => 'full-sync.php', 'Games' => 'games.php']
    );

    echo '<div class="card"><h2>What this fixes</h2><p>Legacy Unreal package headers do not provide a separate catalog package-root field, so older imports could preserve browser-renamed filenames such as <span class="mono">of1 (2).utx</span>. This maintenance page rewrites catalog metadata to the clean logical root, for example <span class="mono">of1</span>, and rewrites exports from <span class="mono">of1 (2).Palette3</span> to <span class="mono">of1.Palette3</span>.</p><p class="muted">UE4 and UE5 are deliberately excluded. Their canonical package identities come from mounted source paths such as <span class="mono">/Engine/BasicShapes/Cube</span>, and characters such as <span class="mono">+</span> are valid. Use Source Identity Repair for those engines. The stored package file remains hash-named.</p></div>';

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Filter</h2><p>Shows up to ' . PACKAGE_NORMALIZE_MAX_ROWS . ' dirty legacy rows per run.</p></div></div><div class="ui-section__body">';
    echo '<form class="package-normalize-controls" method="get"><label for="game_id">Game<select id="game_id" name="game_id"><option value="0">All legacy games</option>';
    foreach ($games as $game) {
        echo '<option value="' . (int)$game['id'] . '"' . ($gameId === (int)$game['id'] ? ' selected' : '') . '>' . catalog_h($game['name']) . '</option>';
    }
    echo '</select></label>' . CatalogUi::button('Apply filter', ['type' => 'submit', 'variant' => 'secondary']) . '</form></div></section>';

    if ($dirtyRows === []) {
        echo CatalogUi::emptyState('No dirty legacy package roots found', 'No UE1/UE2/UE3 package names, original filenames, or export full paths need normalization for the selected filter.');
        catalog_foot();
        exit;
    }

    echo '<form method="post" onsubmit="return confirm(\'Normalize selected legacy package roots and export full paths?\')">';
    echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('package-normalize')) . '">';
    echo '<div class="package-normalize-actions"><label><input type="checkbox" id="select-all-dirty" checked> Select all visible</label><label><input type="checkbox" name="rebuild_dependencies" value="1" checked> Refresh affected dependency links</label><button type="submit">Normalize selected</button></div>';
    echo '<div class="ui-table-region"><table class="package-normalize-table"><thead><tr><th class="package-normalize-select">Use</th><th>Game</th><th>ID</th><th>Package</th><th>File</th><th>GUID / MD5</th><th class="package-normalize-size">Size</th><th>Dirty exports</th></tr></thead><tbody>';
    foreach ($dirtyRows as $row) {
        $id = (int)$row['id'];
        echo '<tr>';
        echo '<td class="package-normalize-select"><input class="dirty-file-box" type="checkbox" name="file_ids[]" value="' . $id . '" checked></td>';
        echo '<td>' . catalog_h($row['game_name']) . '</td>';
        echo '<td class="mono"><a href="file-info.php?id=' . $id . '">' . $id . '</a></td>';
        echo '<td class="mono package-normalize-diff"><span>old: ' . catalog_h($row['package_name']) . '</span><span>new: ' . catalog_h($row['clean_package_name']) . '</span></td>';
        echo '<td class="mono package-normalize-diff"><span>old: ' . catalog_h($row['original_name']) . '</span><span>new: ' . catalog_h($row['clean_original_name']) . '</span></td>';
        echo '<td class="mono small"><span>GUID ' . catalog_h($row['package_guid']) . '</span><br><span>MD5 ' . catalog_h($row['md5']) . '</span></td>';
        echo '<td class="package-normalize-size">' . catalog_h(catalog_bytes((int)$row['file_size'])) . '</td>';
        echo '<td>' . (int)$row['dirty_export_count'] . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div><div class="package-normalize-actions"><button type="submit">Normalize selected</button></div></form>';

    echo <<<'JS'
<script>
(function () {
    var selectAll = document.getElementById('select-all-dirty');
    if (!selectAll) return;
    var boxes = Array.from(document.querySelectorAll('.dirty-file-box'));
    selectAll.addEventListener('change', function () {
        boxes.forEach(function (box) { box.checked = selectAll.checked; });
    });
})();
</script>
JS;

    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('Package normalizer error');
    }
    echo CatalogUi::alert('danger', $error->getMessage(), 'Package root normalization failed.');
    catalog_foot();
}
