<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Renders Zero GUID repair and accepts administrator selections.
 * Why: Header parsing, storage validation, identity mutation and projection repair now live in a maintenance service.
 * Role: Presentation adapter only.
 */
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Maintenance\CatalogZeroGuidRepairService;

catalog_start_session();

const GUID_NORMALIZE_MAX_ROWS = 950;

function guid_normalize_int(string $key, int $default, int $min, int $max): int
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
    if (!catalog_require_admin_page('Zero GUID repair')) {
        exit;
    }

    $service = new CatalogZeroGuidRepairService($db, $config);
    $games = $service->games();
    $gameId = $service->normalizeGameId(guid_normalize_int('game_id', 0, 0, PHP_INT_MAX));

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('guid-normalize');
        $posted = is_array($_POST['file_ids'] ?? null) ? $_POST['file_ids'] : [];
        $result = $service->repair(array_map('intval', $posted), GUID_NORMALIZE_MAX_ROWS);

        $_SESSION['guid_normalize_flash'] = 'Fixed ' . $result['fixed'] . ' zero GUID row(s). Skipped=' . $result['skipped'] . '.';
        header('Location: guid-normalize.php' . ($gameId > 0 ? '?game_id=' . $gameId : ''));
        exit;
    }

    $rows = $service->repairableRows($gameId, GUID_NORMALIZE_MAX_ROWS);
    catalog_head('Zero GUID repair');
    catalog_flash($_SESSION['guid_normalize_flash'] ?? null);
    unset($_SESSION['guid_normalize_flash']);

    echo <<<'CSS'
<style>
.guid-normalize-controls { display:flex; align-items:end; gap:10px; flex-wrap:wrap; margin:0 0 12px; }
.guid-normalize-controls label { display:grid; gap:5px; }
.guid-normalize-actions { display:flex; align-items:center; gap:10px; flex-wrap:wrap; margin:12px 0; }
.guid-normalize-table { min-width:1180px; }
.guid-normalize-table th, .guid-normalize-table td { vertical-align:top; }
.guid-normalize-select { width:42px; text-align:center; white-space:nowrap; }
.guid-normalize-size { white-space:nowrap; }
</style>
CSS;

    echo CatalogUi::pageHeader(
        'Zero GUID repair',
        'Fix active verified rows whose stored package GUID is blank or 00000000-00000000-00000000-00000000 by reading the stored package header fallback GUID.',
        ['Upload Files' => 'profiled-upload.php', 'Package Normalizer' => 'package-normalize.php', 'Games' => 'games.php']
    );

    echo '<section class="ui-section"><div class="ui-section__header"><div><h2>Filter</h2><p>Shows up to ' . GUID_NORMALIZE_MAX_ROWS . ' repairable rows per run.</p></div></div><div class="ui-section__body">';
    echo '<form class="guid-normalize-controls" method="get"><label for="game_id">Game<select id="game_id" name="game_id"><option value="0">All games</option>';
    foreach ($games as $game) {
        echo '<option value="' . (int)$game['id'] . '"' . ($gameId === (int)$game['id'] ? ' selected' : '') . '>' . catalog_h($game['name']) . '</option>';
    }
    echo '</select></label>' . CatalogUi::button('Apply filter', ['type' => 'submit', 'variant' => 'secondary']) . '</form></div></section>';

    if ($rows === []) {
        echo CatalogUi::emptyState('No repairable zero GUID rows found', 'No active verified zero-GUID rows have a readable fallback GUID in the selected filter.');
        catalog_foot();
        exit;
    }

    echo '<form method="post" onsubmit="return confirm(\'Repair selected zero GUID rows?\')">';
    echo '<input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('guid-normalize')) . '">';
    echo '<div class="guid-normalize-actions"><label><input type="checkbox" id="select-all-guid" checked> Select all visible</label><button type="submit">Repair selected</button></div>';
    echo '<div class="ui-table-region"><table class="guid-normalize-table"><thead><tr><th class="guid-normalize-select">Use</th><th>Game</th><th>ID</th><th>Package</th><th>File</th><th>Current GUID</th><th>Header GUID</th><th>MD5</th><th class="guid-normalize-size">Size</th></tr></thead><tbody>';
    foreach ($rows as $row) {
        $id = (int)$row['id'];
        echo '<tr>';
        echo '<td class="guid-normalize-select"><input class="guid-file-box" type="checkbox" name="file_ids[]" value="' . $id . '" checked></td>';
        echo '<td>' . catalog_h($row['game_name']) . '</td>';
        echo '<td class="mono"><a href="file-info.php?id=' . $id . '">' . $id . '</a></td>';
        echo '<td class="mono">' . catalog_h($row['package_name']) . '</td>';
        echo '<td class="mono"><a href="file-examine.php?id=' . $id . '">' . catalog_h($row['original_name']) . '</a></td>';
        echo '<td class="mono small">' . catalog_h((string)($row['package_guid'] ?? '')) . '</td>';
        echo '<td class="mono small">' . catalog_h($row['candidate_guid']) . '</td>';
        echo '<td class="mono small">' . catalog_h($row['md5']) . '</td>';
        echo '<td class="guid-normalize-size">' . catalog_h(catalog_bytes((int)$row['file_size'])) . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table></div><div class="guid-normalize-actions"><button type="submit">Repair selected</button></div></form>';

    echo <<<'JS'
<script>
(function () {
    var selectAll = document.getElementById('select-all-guid');
    if (!selectAll) return;
    var boxes = Array.from(document.querySelectorAll('.guid-file-box'));
    selectAll.addEventListener('change', function () {
        boxes.forEach(function (box) { box.checked = selectAll.checked; });
    });
})();
</script>
JS;

    catalog_foot();
} catch (Throwable $error) {
    if (!headers_sent()) {
        catalog_head('Zero GUID repair error');
    }
    echo CatalogUi::alert('danger', $error->getMessage(), 'Zero GUID repair failed.');
    catalog_foot();
}
