<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogParser.php';
require_once __DIR__ . '/lib/CatalogScanner.php';
require_once __DIR__ . '/lib/GameProfiles.php';
require_once __DIR__ . '/lib/TrustedHttpSourceClient.php';

catalog_start_session();

function http_scan_allowed_extension(string $path, array $profile, array $config): bool
{
    $ext = catalog_clean_unreal_extension((string)pathinfo($path, PATHINFO_EXTENSION));
    $extensions = gp_extensions($profile);
    if ($extensions === []) {
        $extensions = scanner_profile_extensions($profile, $config);
    }
    return in_array($ext, $extensions, true);
}

function http_scan_clean_manifest_line(string $line): string
{
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
        return '';
    }
    if (str_contains($line, ',')) {
        $parts = str_getcsv($line);
        $line = trim((string)($parts[0] ?? ''));
    }
    return trim($line, " \t\r\n\"'");
}

function http_scan_extract_manifest_paths(string $manifestText, array $profile, array $config): array
{
    $paths = [];
    $trimmed = trim($manifestText);
    $json = json_decode($trimmed, true);
    $items = is_array($json)
        ? (array_is_list($json) ? $json : (is_array($json['files'] ?? null) ? $json['files'] : []))
        : preg_split('/\R/', $manifestText);

    foreach ($items ?: [] as $item) {
        $path = is_array($item) ? (string)($item['path'] ?? $item['file'] ?? $item['name'] ?? '') : (string)$item;
        $path = http_scan_clean_manifest_line($path);
        if ($path !== '' && http_scan_allowed_extension($path, $profile, $config)) {
            $paths[$path] = true;
        }
    }

    return array_keys($paths);
}

function http_scan_match_file(PDO $db, array $source, string $relativePath, ?int $remoteSize): ?array
{
    $basename = basename($relativePath);
    $matches = catalog_all($db, 'SELECT id, package_name, original_name, file_size, md5, package_guid FROM ue_files WHERE game_id=? AND original_name=? AND scan_status="verified" ORDER BY id', [(int)$source['game_id'], $basename]);
    if (count($matches) === 1) {
        return ['status' => 'matched_name', 'file' => $matches[0]];
    }
    if (count($matches) > 1 && $remoteSize === null) {
        return ['status' => 'ambiguous', 'file' => null];
    }

    if ($remoteSize !== null) {
        $matches = catalog_all($db, 'SELECT id, package_name, original_name, file_size, md5, package_guid FROM ue_files WHERE game_id=? AND original_name=? AND file_size=? AND scan_status="verified" ORDER BY id', [(int)$source['game_id'], $basename, $remoteSize]);
        if (count($matches) === 1) {
            return ['status' => 'matched_name_size', 'file' => $matches[0]];
        }
        if (count($matches) > 1) {
            return ['status' => 'ambiguous', 'file' => null];
        }
    }

    $sourcePackage = scanner_ue_package_name_from_source_relative($relativePath);
    if ($sourcePackage !== '') {
        $matches = catalog_all($db, 'SELECT id, package_name, original_name, file_size, md5, package_guid FROM ue_files WHERE game_id=? AND package_name=? AND scan_status="verified" ORDER BY id', [(int)$source['game_id'], $sourcePackage]);
        if (count($matches) === 1) {
            return ['status' => 'matched_source_package', 'file' => $matches[0]];
        }
        if (count($matches) > 1) {
            return ['status' => 'ambiguous', 'file' => null];
        }
    }

    $stem = pathinfo($basename, PATHINFO_FILENAME);
    if ($stem !== '') {
        $matches = catalog_all($db, 'SELECT id, package_name, original_name, file_size, md5, package_guid FROM ue_files WHERE game_id=? AND package_name=? AND scan_status="verified" ORDER BY id', [(int)$source['game_id'], $stem]);
        if (count($matches) === 1) {
            return ['status' => 'matched_package_name', 'file' => $matches[0]];
        }
        if (count($matches) > 1) {
            return ['status' => 'ambiguous', 'file' => null];
        }
    }

    return null;
}

function http_scan_deep_guid_match(PDO $db, array $config, array $source, array $target, string $url, int $maxBytes): ?array
{
    $tmp = tempnam(sys_get_temp_dir(), 'ue_http_scan_');
    if ($tmp === false) {
        throw new RuntimeException('Could not create a temporary deep-scan file.');
    }
    @unlink($tmp);

    try {
        TrustedHttpSourceClient::toFile($target, $url, $tmp, $maxBytes, 'package');
        $engine = gp_engine_for_game($db, (int)$source['game_id']);
        $header = catalog_try_read_package_header($config, $engine, $tmp);
        $guid = catalog_header_guid($header);
        if ($guid === '') {
            return ['status' => 'no_guid', 'file' => null, 'guid' => ''];
        }
        $matches = catalog_all($db, 'SELECT id, package_name, original_name, file_size, md5, package_guid FROM ue_files WHERE game_id=? AND package_guid=? AND scan_status="verified" ORDER BY id', [(int)$source['game_id'], $guid]);
        if (count($matches) === 1) {
            return ['status' => 'matched_guid', 'file' => $matches[0], 'guid' => $guid];
        }
        if (count($matches) > 1) {
            return ['status' => 'ambiguous_guid', 'file' => null, 'guid' => $guid];
        }
        return ['status' => 'unknown_guid', 'file' => null, 'guid' => $guid];
    } finally {
        @unlink($tmp);
    }
}

function http_scan_source(PDO $db, array $config, int $sourceId, string $manifestName, bool $checkRemoteSize, bool $deepScan, int $maxDeepBytes): array
{
    $source = catalog_one($db, 'SELECT s.*, g.name game_name, p.engine_key profile_engine FROM ue_sources s JOIN ue_games g ON g.id=s.game_id LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE s.id=?', [$sourceId]);
    if (!$source) {
        throw new RuntimeException('Source not found.');
    }
    if (!in_array($source['source_type'], ['http_mirror', 'redirect_server'], true)) {
        throw new RuntimeException('This scanner only accepts HTTP mirror and redirect-server sources.');
    }
    $profile = gp_required_profile_for_game($db, (int)$source['game_id']);

    $target = TrustedHttpSourceClient::source((string)$source['base_path']);
    $manifestUrl = TrustedHttpSourceClient::relativeUrl($target, $manifestName);
    $manifest = TrustedHttpSourceClient::bytes($target, $manifestUrl, 5 * 1024 * 1024, 'manifest');
    $paths = http_scan_extract_manifest_paths($manifest, $profile, $config);
    if (count($paths) > 50000) {
        throw new RuntimeException('Manifest contains more than the 50,000 allowed package entries.');
    }

    $upsert = $db->prepare('INSERT INTO ue_file_locations(file_id,source_id,source_relative_path,exists_in_source,last_seen_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE exists_in_source=VALUES(exists_in_source), last_seen_at=NOW()');
    $result = ['source' => $source, 'manifest_url' => $manifestUrl, 'path_count' => count($paths), 'matched' => 0, 'matched_guid' => 0, 'unknown' => 0, 'ambiguous' => 0, 'deep_failed' => 0, 'invalid_paths' => 0, 'samples' => []];
    $deepLimit = 100;
    $deepUsed = 0;

    foreach ($paths as $relativePath) {
        try {
            $url = TrustedHttpSourceClient::relativeUrl($target, $relativePath);
        } catch (Throwable) {
            $result['invalid_paths']++;
            continue;
        }
        $remoteSize = $checkRemoteSize ? TrustedHttpSourceClient::headSize($target, $url) : null;
        $match = http_scan_match_file($db, $source, $relativePath, $remoteSize);

        if (!$match && $deepScan && $deepUsed < $deepLimit) {
            $deepUsed++;
            try {
                $match = http_scan_deep_guid_match($db, $config, $source, $target, $url, $maxDeepBytes);
            } catch (Throwable $e) {
                $result['deep_failed']++;
                if (count($result['samples']) < 50) {
                    $result['samples'][] = $relativePath . ' - deep scan failed';
                }
                continue;
            }
        }

        if ($match && isset($match['file']) && is_array($match['file'])) {
            $upsert->execute([(int)$match['file']['id'], $sourceId, $relativePath, 1]);
            scanner_record_source_relative_path($db, (int)$match['file']['id'], $relativePath);
            if (($match['status'] ?? '') === 'matched_guid') {
                $result['matched_guid']++;
            } else {
                $result['matched']++;
            }
            continue;
        }
        if ($match && in_array($match['status'] ?? '', ['ambiguous', 'ambiguous_guid'], true)) {
            $result['ambiguous']++;
            if (count($result['samples']) < 50) {
                $result['samples'][] = $relativePath . ' - ' . $match['status'];
            }
            continue;
        }

        $result['unknown']++;
        if (count($result['samples']) < 50) {
            $result['samples'][] = $relativePath . ' - ' . ($match['status'] ?? 'unknown');
        }
    }

    return $result;
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    if (!catalog_require_admin_page('HTTP source scan')) {
        exit;
    }

    $result = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('http_source_scan');
        $sourceId = (int)($_POST['source_id'] ?? 0);
        $manifestName = trim((string)($_POST['manifest_name'] ?? 'files.txt'));
        if ($manifestName === '') {
            throw new RuntimeException('Manifest name/path is required.');
        }
        $checkRemoteSize = isset($_POST['check_remote_size']);
        $deepScan = isset($_POST['deep_scan']);
        $maxDeepBytes = max(1024 * 1024, min(256 * 1024 * 1024, (int)($_POST['max_deep_mb'] ?? 128) * 1024 * 1024));
        $result = http_scan_source($db, $config, $sourceId, $manifestName, $checkRemoteSize, $deepScan, $maxDeepBytes);
    }

    $sources = catalog_all($db, 'SELECT s.id, s.name, s.source_type, s.base_path, g.name game_name FROM ue_sources s JOIN ue_games g ON g.id=s.game_id WHERE s.is_active=1 AND s.source_type IN ("http_mirror","redirect_server") ORDER BY g.name, s.name');
    catalog_head('HTTP source scan');
    catalog_page_header('HTTP source scanner', 'Scans a trusted HTTPS mirror manifest using the selected game profile extension list. Matched source-relative paths are preserved for UE4 package identity and later Full Sync reimports.', ['Sources' => 'sources.php', 'Local Source Scan' => 'source-scan.php', 'Unverified Files' => 'unverified-files.php', 'Games' => 'games.php']);

    if ($result !== null) {
        echo '<div class="card"><h2>Scan result</h2><table>';
        foreach (['source' => 'Source', 'manifest_url' => 'Manifest URL', 'path_count' => 'Package-like paths', 'matched' => 'Matched by name/size/package', 'matched_guid' => 'Matched by deep GUID', 'unknown' => 'Unknown', 'ambiguous' => 'Ambiguous', 'deep_failed' => 'Deep-scan failures', 'invalid_paths' => 'Rejected manifest paths'] as $key => $label) {
            $value = $key === 'source' ? (string)$result['source']['name'] : (string)$result[$key];
            echo '<tr><th>' . catalog_h($label) . '</th><td>' . catalog_h($value) . '</td></tr>';
        }
        echo '</table>';
        if ($result['samples']) {
            echo '<h3>Examples</h3><ul>';
            foreach ($result['samples'] as $sample) {
                echo '<li class="mono">' . catalog_h($sample) . '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';
    }

    echo '<section class="card"><h2>Run scan</h2>';
    if (!$sources) {
        echo '<p class="muted">No active HTTP mirror or redirect-server sources are configured.</p>';
    } else {
        echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('http_source_scan')) . '">';
        echo '<p><label>Source <select name="source_id" required>';
        foreach ($sources as $source) {
            echo '<option value="' . (int)$source['id'] . '">' . catalog_h($source['game_name'] . ' — ' . $source['name']) . '</option>';
        }
        echo '</select></label></p>';
        echo '<p><label>Manifest relative path <input name="manifest_name" value="files.txt" required></label></p>';
        echo '<p><label><input type="checkbox" name="check_remote_size" checked> Compare remote Content-Length when available</label></p>';
        echo '<p><label><input type="checkbox" name="deep_scan"> Deep-scan unknown packages for GUIDs (maximum 100 files)</label></p>';
        echo '<p><label>Maximum bytes per deep scan <input type="number" name="max_deep_mb" min="1" max="256" value="128"> MB</label></p>';
        echo '<button type="submit">Run secure source scan</button></form>';
    }
    echo '</section>';
    catalog_foot();
} catch (Throwable $e) {
    error_log('[UnrealDB][' . catalog_request_id() . '] HTTP source scan: ' . get_class($e) . ': ' . $e->getMessage());
    if (!headers_sent()) {
        catalog_head('HTTP source scan');
    }
    echo '<div class="msg err"><strong>HTTP source scan failed.</strong> ' . catalog_h(catalog_public_error_message()) . '</div>';
    catalog_foot();
}
