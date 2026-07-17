<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogScanner.php';

const PACKAGE_NORMALIZE_MAX_ROWS = 950;

function package_normalize_int(string $key, int $default, int $min, int $max): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        $value = $default;
    }
    return max($min, min($max, (int)$value));
}

function package_normalize_is_modern_engine(array $file): bool
{
    $detected = strtoupper(trim((string)($file['detected_engine_key'] ?? '')));
    $profile = strtoupper(trim((string)($file['profile_engine'] ?? '')));
    return in_array($detected, ['UE4', 'UE5'], true) || in_array($profile, ['UE4', 'UE5'], true);
}

function package_normalize_assert_legacy_engine(array $file): void
{
    if (package_normalize_is_modern_engine($file)) {
        throw new RuntimeException(
            'UE4/UE5 package identities must not be processed by the legacy package-root normalizer. '
            . 'Use Source Identity Repair so mounted paths such as /Engine/... and valid characters such as + are preserved.'
        );
    }
}

function package_normalize_clean_package(array $file): string
{
    $package = trim((string)($file['package_name'] ?? ''));
    if ($package === '') {
        $cleanFile = catalog_clean_unreal_filename((string)($file['original_name'] ?? ''));
        $package = (string)pathinfo($cleanFile, PATHINFO_FILENAME);
    }
    return catalog_clean_unreal_package_stem($package);
}

function package_normalize_clean_original(array $file): string
{
    return catalog_clean_unreal_filename((string)($file['original_name'] ?? ''));
}

function package_normalize_export_dirty_count(PDO $db, int $fileId, string $cleanPackage): int
{
    return (int)(catalog_one(
        $db,
        'SELECT COUNT(*) c
         FROM ue_exports
         WHERE file_id=?
           AND full_path<>CASE WHEN local_path<>"" THEN CONCAT(?, ".", local_path) ELSE ? END',
        [$fileId, $cleanPackage, $cleanPackage]
    )['c'] ?? 0);
}

/** @return array{changed:bool,game_id:int,old_package:string,new_package:string,old_original:string,new_original:string,export_rows:int} */
function package_normalize_file(PDO $db, int $fileId): array
{
    $file = catalog_one(
        $db,
        'SELECT f.id, f.game_id, f.package_name, f.original_name, f.detected_engine_key, p.engine_key profile_engine
         FROM ue_files f
         JOIN ue_games g ON g.id=f.game_id
         LEFT JOIN ue_game_profiles p ON p.id=g.profile_id
         WHERE f.id=?',
        [$fileId]
    );
    if (!$file) {
        throw new RuntimeException('File ID ' . $fileId . ' no longer exists.');
    }
    package_normalize_assert_legacy_engine($file);

    $cleanPackage = package_normalize_clean_package($file);
    $cleanOriginal = package_normalize_clean_original($file);
    $oldPackage = (string)$file['package_name'];
    $oldOriginal = (string)$file['original_name'];
    $exportDirty = package_normalize_export_dirty_count($db, $fileId, $cleanPackage);
    $changed = $oldPackage !== $cleanPackage || $oldOriginal !== $cleanOriginal || $exportDirty > 0;

    if ($changed) {
        $db->prepare('UPDATE ue_files SET package_name=?, original_name=? WHERE id=?')->execute([$cleanPackage, $cleanOriginal, $fileId]);
        $db->prepare('UPDATE ue_exports SET full_path=CASE WHEN local_path<>"" THEN CONCAT(?, ".", local_path) ELSE ? END WHERE file_id=?')->execute([$cleanPackage, $cleanPackage, $fileId]);
    }

    return [
        'changed' => $changed,
        'game_id' => (int)$file['game_id'],
        'old_package' => $oldPackage,
        'new_package' => $cleanPackage,
        'old_original' => $oldOriginal,
        'new_original' => $cleanOriginal,
        'export_rows' => $exportDirty,
    ];
}

/** @return list<array<string,mixed>> */
function package_normalize_dirty_rows(PDO $db, int $gameId): array
{
    $sql = 'SELECT f.id, f.game_id, g.name game_name, f.package_name, f.original_name, f.package_guid, f.md5, f.file_size, f.scan_status,
                   f.detected_engine_key, p.engine_key profile_engine
            FROM ue_files f
            JOIN ue_games g ON g.id=f.game_id
            LEFT JOIN ue_game_profiles p ON p.id=g.profile_id
            WHERE f.scan_status<>"failed"
              AND UPPER(COALESCE(f.detected_engine_key,"")) NOT IN ("UE4","UE5")
              AND UPPER(COALESCE(p.engine_key,"")) NOT IN ("UE4","UE5")';
    $args = [];
    if ($gameId > 0) {
        $sql .= ' AND f.game_id=?';
        $args[] = $gameId;
    }
    $sql .= ' ORDER BY g.name, f.package_name, f.original_name, f.id';

    $rows = catalog_all($db, $sql, $args);
    $dirty = [];
    foreach ($rows as $row) {
        package_normalize_assert_legacy_engine($row);
        $cleanPackage = package_normalize_clean_package($row);
        $cleanOriginal = package_normalize_clean_original($row);
        $exportDirty = package_normalize_export_dirty_count($db, (int)$row['id'], $cleanPackage);
        if ((string)$row['package_name'] === $cleanPackage && (string)$row['original_name'] === $cleanOriginal && $exportDirty === 0) {
            continue;
        }
        $row['clean_package_name'] = $cleanPackage;
        $row['clean_original_name'] = $cleanOriginal;
        $row['dirty_export_count'] = $exportDirty;
        $dirty[] = $row;
        if (count($dirty) >= PACKAGE_NORMALIZE_MAX_ROWS) {
            break;
        }
    }
    return $dirty;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('Package root normalizer')) {
        exit;
    }

    $games = catalog_all(
        $db,
        'SELECT g.id, g.name, p.engine_key
         FROM ue_games g
         LEFT JOIN ue_game_profiles p ON p.id=g.profile_id
         WHERE UPPER(COALESCE(p.engine_key,"")) NOT IN ("UE4","UE5")
         ORDER BY g.name'
    );
    $gameId = package_normalize_int('game_id', 0, 0, PHP_INT_MAX);
    $knownGameIds = array_map(static fn(array $game): int => (int)$game['id'], $games);
    if ($gameId > 0 && !in_array($gameId, $knownGameIds, true)) {
        $gameId = 0;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('package-normalize');
        $ids = $_POST['file_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_values(array_filter(array_unique(array_map('intval', $ids)), static fn(int $id): bool => $id > 0));
        if (count($ids) > PACKAGE_NORMALIZE_MAX_ROWS) {
            throw new RuntimeException('Too many files selected. Process at most ' . PACKAGE_NORMALIZE_MAX_ROWS . ' files at once.');
        }
        if ($ids === []) {
            throw new RuntimeException('Select at least one file to normalize.');
        }

        $changed = [];
        $affectedPackages = [];
        $db->beginTransaction();
        try {
            foreach ($ids as $fileId) {
                $result = package_normalize_file($db, $fileId);
                if ($result['changed']) {
                    $changed[] = $result;
                    $affectedPackages[(int)$result['game_id'] . ':' . $result['new_package']] = [(int)$result['game_id'], (string)$result['new_package']];
                    if ((string)$result['old_package'] !== '' && (string)$result['old_package'] !== (string)$result['new_package']) {
                        $affectedPackages[(int)$result['game_id'] . ':' . $result['old_package']] = [(int)$result['game_id'], (string)$result['old_package']];
                    }
                }
            }
            $db->commit();
        } catch (Throwable $error) {
            $db->rollBack();
            throw $error;
        }

        $rebuild = (string)($_POST['rebuild_dependencies'] ?? '') === '1';
        $rebuildWarnings = 0;
        if ($rebuild) {
            foreach ($affectedPackages as [$affectedGameId, $packageName]) {
                try {
                    scanner_rebuild_affected_dependencies_for_package($db, $config, (int)$affectedGameId, (string)$packageName, null, 0, 100);
                } catch (Throwable $error) {
                    $rebuildWarnings++;
                    error_log('[UnrealDB package normalize] dependency refresh failed for game=' . (int)$affectedGameId . ' package=' . (string)$packageName . ': ' . $error->getMessage());
                }
            }
        }

        $_SESSION['package_normalize_flash'] = 'Normalized ' . count($changed) . ' file(s).' . ($rebuild ? ' Dependency refresh package checks=' . count($affectedPackages) . ', warnings=' . $rebuildWarnings . '.' : ' Dependency refresh was not run.');
        header('Location: package-normalize.php' . ($gameId > 0 ? '?game_id=' . $gameId : ''));
        exit;
    }

    $dirtyRows = package_normalize_dirty_rows($db, $gameId);
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
