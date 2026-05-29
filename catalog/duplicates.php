<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';

function duplicates_csrf(): string
{
    $_SESSION['duplicates_csrf'] ??= bin2hex(random_bytes(16));
    return $_SESSION['duplicates_csrf'];
}

function duplicates_check_csrf(): void
{
    if (($_POST['csrf'] ?? '') !== ($_SESSION['duplicates_csrf'] ?? '')) {
        throw new RuntimeException('Bad CSRF token');
    }
}

function duplicate_same_group(PDO $db, int $canonicalId, int $duplicateId): bool
{
    $rows = catalog_all($db, 'SELECT id, game_id, package_guid FROM ue_files WHERE id IN (?,?)', [$canonicalId, $duplicateId]);
    if (count($rows) !== 2) {
        return false;
    }
    $a = $rows[0];
    $b = $rows[1];
    return (int)$a['game_id'] === (int)$b['game_id'] && (string)$a['package_guid'] !== '' && (string)$a['package_guid'] === (string)$b['package_guid'];
}

function retire_duplicate_file(PDO $db, int $canonicalId, int $duplicateId): void
{
    if ($canonicalId === $duplicateId) {
        return;
    }
    if (!duplicate_same_group($db, $canonicalId, $duplicateId)) {
        throw new RuntimeException('File ' . $duplicateId . ' is not in the same GUID group as canonical file ' . $canonicalId);
    }

    $locations = catalog_all($db, 'SELECT * FROM ue_file_locations WHERE file_id=?', [$duplicateId]);
    $insertLocation = $db->prepare('INSERT INTO ue_file_locations(file_id,source_id,source_relative_path,exists_in_source,last_seen_at) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE exists_in_source=VALUES(exists_in_source), last_seen_at=VALUES(last_seen_at)');
    foreach ($locations as $loc) {
        $insertLocation->execute([$canonicalId, (int)$loc['source_id'], (string)$loc['source_relative_path'], (int)$loc['exists_in_source'], $loc['last_seen_at']]);
    }

    $deps = catalog_all($db, 'SELECT id, required_object_path FROM ue_dependencies WHERE resolved_file_id=?', [$duplicateId]);
    $updateDep = $db->prepare('UPDATE ue_dependencies SET resolved_file_id=?, resolved_export_id=?, status=? WHERE id=?');
    foreach ($deps as $dep) {
        $export = catalog_one($db, 'SELECT id FROM ue_exports WHERE file_id=? AND full_path=? LIMIT 1', [$canonicalId, (string)$dep['required_object_path']]);
        $updateDep->execute([$canonicalId, $export ? (int)$export['id'] : null, $export ? 'resolved' : 'package_only', (int)$dep['id']]);
    }

    $db->prepare('UPDATE ue_files SET scan_status="duplicate", scan_notes=CONCAT(COALESCE(scan_notes,""), ? ) WHERE id=?')->execute(["\nRetired as duplicate of file ID " . $canonicalId . " on " . date('Y-m-d H:i:s'), $duplicateId]);
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!catalog_support_is_admin()) {
            throw new RuntimeException('Admin required');
        }
        duplicates_check_csrf();
        $canonicalId = (int)($_POST['canonical_id'] ?? 0);
        $duplicateIds = array_map('intval', $_POST['duplicate_ids'] ?? []);
        $duplicateIds = array_values(array_filter(array_unique($duplicateIds), static fn($v) => $v > 0 && $v !== $canonicalId));
        if ($canonicalId <= 0 || !$duplicateIds) {
            throw new RuntimeException('Choose a canonical file and at least one duplicate file to retire.');
        }

        $db->beginTransaction();
        try {
            foreach ($duplicateIds as $duplicateId) {
                retire_duplicate_file($db, $canonicalId, $duplicateId);
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }

        $_SESSION['flash_duplicates'] = 'Retired ' . count($duplicateIds) . ' duplicate file(s) into canonical file ID ' . $canonicalId . '.';
        header('Location: duplicates.php');
        exit;
    }

    if (!catalog_require_admin_page('GUID duplicates')) {
        exit;
    }

    catalog_head('GUID duplicates');
    catalog_flash($_SESSION['flash_duplicates'] ?? null);
    unset($_SESSION['flash_duplicates']);

    catalog_page_header('GUID duplicate manager', 'Shows active verified packages with the same Unreal package GUID in the same game. This catches compressed/uncompressed duplicates that have different MD5 hashes.', ['Games' => 'games.php', 'Source Scanner' => 'source-scan.php', 'Sources' => 'sources.php']);

    $groups = catalog_all($db, '
        SELECT f.game_id, g.name AS game_name, f.package_guid, COUNT(*) AS duplicate_count
        FROM ue_files f
        JOIN ue_games g ON g.id=f.game_id
        WHERE f.package_guid IS NOT NULL AND f.package_guid <> "" AND f.scan_status="verified"
        GROUP BY f.game_id, f.package_guid
        HAVING COUNT(*) > 1
        ORDER BY g.name, duplicate_count DESC, f.package_guid
    ');

    if (!$groups) {
        echo '<div class="card"><h2>No active GUID duplicates found</h2><p class="muted">No active duplicate package GUID groups currently exist.</p></div>';
        catalog_foot();
        exit;
    }

    echo '<div class="card"><h2>Duplicate groups</h2><table><tr><th>Game</th><th>Package GUID</th><th>Count</th></tr>';
    foreach ($groups as $group) {
        echo '<tr><td>' . catalog_h($group['game_name']) . '</td><td class="mono">' . catalog_h($group['package_guid']) . '</td><td>' . (int)$group['duplicate_count'] . '</td></tr>';
    }
    echo '</table></div>';

    foreach ($groups as $group) {
        $files = catalog_all($db, '
            SELECT f.*,
                   COUNT(DISTINCT d.id) AS dependency_count,
                   COALESCE(SUM(d.status="missing"),0) AS missing_count,
                   COALESCE(SUM(d.status="resolved"),0) AS resolved_count,
                   COUNT(DISTINCT l.id) AS source_location_count
            FROM ue_files f
            LEFT JOIN ue_dependencies d ON d.file_id=f.id
            LEFT JOIN ue_file_locations l ON l.file_id=f.id AND l.exists_in_source=1
            WHERE f.game_id=? AND f.package_guid=? AND f.scan_status="verified"
            GROUP BY f.id
            ORDER BY f.is_compressed ASC, f.file_size DESC, f.uploaded_at ASC, f.id ASC
        ', [(int)$group['game_id'], (string)$group['package_guid']]);

        $suggestedCanonical = $files[0]['id'] ?? 0;
        echo '<div class="card"><h2>' . catalog_h($group['game_name']) . ' / <span class="mono">' . catalog_h($group['package_guid']) . '</span></h2>';
        echo '<p class="muted">Choose one canonical file, tick duplicate rows to retire, then apply. Source locations and incoming dependency links are moved to the canonical file. Retired rows remain in the database for audit.</p>';
        echo '<form method="post" onsubmit="return confirm(\'Retire selected duplicates into the canonical file?\')">';
        echo '<input type="hidden" name="csrf" value="' . catalog_h(duplicates_csrf()) . '">';
        echo '<table><tr><th>Canonical</th><th>Retire</th><th>ID</th><th>Package</th><th>File</th><th>MD5</th><th>Size</th><th>Type</th><th>Deps</th><th>Sources</th><th>Uploaded</th><th>Open</th></tr>';
        foreach ($files as $file) {
            $compressed = (int)($file['is_compressed'] ?? 0) === 1;
            $type = '<span class="dep ' . ($compressed ? 'compressed' : 'uncompressed') . '">' . ($compressed ? 'compressed' : 'uncompressed') . '</span>';
            $deps = 'total ' . (int)$file['dependency_count'] . ' / resolved ' . (int)$file['resolved_count'] . ' / missing ' . (int)$file['missing_count'];
            $id = (int)$file['id'];
            echo '<tr>';
            echo '<td><input type="radio" name="canonical_id" value="' . $id . '" ' . ($id === (int)$suggestedCanonical ? 'checked' : '') . '></td>';
            echo '<td><input type="checkbox" name="duplicate_ids[]" value="' . $id . '"></td>';
            echo '<td class="mono">' . $id . '</td>';
            echo '<td class="mono">' . catalog_h($file['package_name']) . '</td>';
            echo '<td>' . catalog_h($file['original_name']) . '</td>';
            echo '<td class="mono small">' . catalog_h($file['md5']) . '</td>';
            echo '<td>' . catalog_h(catalog_bytes((int)$file['file_size'])) . '</td>';
            echo '<td>' . $type . '</td>';
            echo '<td class="small">' . catalog_h($deps) . '</td>';
            echo '<td>' . (int)$file['source_location_count'] . '</td>';
            echo '<td class="small">' . catalog_h($file['uploaded_at']) . '</td>';
            echo '<td><a href="file-info.php?id=' . $id . '" target="_blank">info</a> | <a href="download-info.php?id=' . $id . '" target="_blank">download</a> | <a href="index.php?page=file&id=' . $id . '">admin</a></td>';
            echo '</tr>';
        }
        echo '</table><p><button>Retire selected duplicates into canonical</button></p></form></div>';
    }

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('Duplicate manager error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
