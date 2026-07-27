<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Application\Maintenance\CatalogProjectionReconciliationQueue;

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

function guid_normalize_is_zero(string $guid): bool
{
    $guid = strtoupper(trim($guid));
    return $guid === '' || $guid === '00000000-00000000-00000000-00000000';
}

function guid_normalize_candidate_from_path(string $path): string
{
    $bytes = @file_get_contents($path, false, null, 0, 64);
    if (!is_string($bytes) || strlen($bytes) < 52) {
        return '';
    }
    $tag = (int)(unpack('V', substr($bytes, 0, 4))[1] ?? 0);
    if ($tag !== 0x9E2A83C1) {
        return '';
    }
    $parts = [
        (int)(unpack('V', substr($bytes, 36, 4))[1] ?? 0),
        (int)(unpack('V', substr($bytes, 40, 4))[1] ?? 0),
        (int)(unpack('V', substr($bytes, 44, 4))[1] ?? 0),
        (int)(unpack('V', substr($bytes, 48, 4))[1] ?? 0),
    ];
    if ($parts === [0, 0, 0, 0]) {
        return '';
    }
    return sprintf('%08X-%08X-%08X-%08X', $parts[0], $parts[1], $parts[2], $parts[3]);
}

function guid_normalize_stored_path(array $config, array $file): ?string
{
    $storageRoot = realpath(rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR));
    $storedPath = realpath(__DIR__ . '/' . (string)$file['relative_path']);
    if (!$storageRoot || !$storedPath || !str_starts_with($storedPath, $storageRoot) || !is_file($storedPath)) {
        return null;
    }
    return $storedPath;
}

function guid_normalize_candidate_for_file(array $config, array $file): string
{
    $storedPath = guid_normalize_stored_path($config, $file);
    return $storedPath ? guid_normalize_candidate_from_path($storedPath) : '';
}

/** @return list<array<string,mixed>> */
function guid_normalize_rows(PDO $db, array $config, int $gameId): array
{
    $sql = 'SELECT f.id,f.game_id,g.name game_name,f.package_name,f.original_name,f.extension,f.package_guid,f.md5,f.file_size,f.relative_path,f.uploaded_at '
        . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
        . 'WHERE f.scan_status="verified" AND (f.package_guid IS NULL OR f.package_guid="" OR f.package_guid="00000000-00000000-00000000-00000000")';
    $args = [];
    if ($gameId > 0) {
        $sql .= ' AND f.game_id=?';
        $args[] = $gameId;
    }
    $sql .= ' ORDER BY g.name,f.package_name,f.original_name,f.id LIMIT ' . GUID_NORMALIZE_MAX_ROWS;

    $rows = [];
    foreach (catalog_all($db, $sql, $args) as $row) {
        $candidate = guid_normalize_candidate_for_file($config, $row);
        if ($candidate !== '') {
            $row['candidate_guid'] = $candidate;
            $rows[] = $row;
        }
    }
    return $rows;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Zero GUID repair')) {
        exit;
    }

    $games = catalog_all($db, 'SELECT id,name FROM ue_games ORDER BY name');
    $gameId = guid_normalize_int('game_id', 0, 0, PHP_INT_MAX);
    $knownGameIds = array_map(static fn(array $game): int => (int)$game['id'], $games);
    if ($gameId > 0 && !in_array($gameId, $knownGameIds, true)) {
        $gameId = 0;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('guid-normalize');
        $ids = is_array($_POST['file_ids'] ?? null) ? $_POST['file_ids'] : [];
        $ids = array_values(array_filter(array_unique(array_map('intval', $ids)), static fn(int $id): bool => $id > 0));
        if ($ids === []) {
            throw new RuntimeException('Select at least one file to repair.');
        }
        if (count($ids) > GUID_NORMALIZE_MAX_ROWS) {
            throw new RuntimeException('Too many files selected. Process at most ' . GUID_NORMALIZE_MAX_ROWS . ' files at once.');
        }

        $fixed = 0;
        $skipped = 0;
        $contexts = [];
        foreach ($ids as $id) {
            $file = catalog_one(
                $db,
                'SELECT id,game_id,package_name,package_guid,relative_path FROM ue_files WHERE id=?',
                [$id]
            );
            if (!$file || !guid_normalize_is_zero((string)($file['package_guid'] ?? ''))) {
                $skipped++;
                continue;
            }
            $candidate = guid_normalize_candidate_for_file($config, $file);
            if ($candidate === '') {
                $skipped++;
                continue;
            }
            $db->prepare('UPDATE ue_files SET package_guid=? WHERE id=?')->execute([$candidate, $id]);
            $contexts[] = [
                'file_id' => $id,
                'game_id' => (int)$file['game_id'],
                'package_name' => (string)$file['package_name'],
            ];
            $fixed++;
        }

        foreach ($contexts as $context) {
            CatalogProjectionReconciliationQueue::enqueue(
                $db,
                (int)$context['file_id'],
                [(int)$context['game_id']],
                [(string)$context['package_name']],
                $config
            );
        }

        $_SESSION['guid_normalize_flash'] = 'Fixed ' . $fixed . ' zero GUID row(s). Skipped=' . $skipped . '.';
        header('Location: guid-normalize.php' . ($gameId > 0 ? '?game_id=' . $gameId : ''));
        exit;
    }

    $rows = guid_normalize_rows($db, $config, $gameId);
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
