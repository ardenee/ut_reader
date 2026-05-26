<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogParser.php';

function http_scan_is_admin(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

function http_scan_allowed_extension(string $path, array $config): bool
{
    $path = parse_url($path, PHP_URL_PATH) ?: $path;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, $config['allowed_extensions'] ?? [], true);
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

function http_scan_make_url(string $baseUrl, string $relative): string
{
    if (preg_match('~^https?://~i', $relative)) {
        return $relative;
    }
    return rtrim($baseUrl, '/') . '/' . ltrim($relative, '/');
}

function http_scan_fetch_url(string $url, int $maxBytes, string $label): string
{
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 30,
            'header' => "User-Agent: UnrealFileCatalog/1.0\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $fp = @fopen($url, 'rb', false, $context);
    if (!$fp) {
        throw new RuntimeException('Could not open ' . $label . ' URL: ' . $url);
    }

    $data = '';
    while (!feof($fp) && strlen($data) <= $maxBytes) {
        $data .= fread($fp, 65536);
    }
    fclose($fp);

    if (strlen($data) > $maxBytes) {
        throw new RuntimeException(ucfirst($label) . ' too large; limit is ' . $maxBytes . ' bytes.');
    }

    return $data;
}

function http_scan_fetch_manifest(string $url, int $maxBytes = 5242880): string
{
    return http_scan_fetch_url($url, $maxBytes, 'manifest');
}

function http_scan_extract_manifest_paths(string $manifestText, array $config): array
{
    $paths = [];
    $trimmed = trim($manifestText);
    $json = json_decode($trimmed, true);
    if (is_array($json)) {
        $items = array_is_list($json) ? $json : (is_array($json['files'] ?? null) ? $json['files'] : []);
        foreach ($items as $item) {
            $path = is_array($item) ? (string)($item['path'] ?? $item['file'] ?? $item['name'] ?? $item['url'] ?? '') : (string)$item;
            $path = http_scan_clean_manifest_line($path);
            if ($path !== '' && http_scan_allowed_extension($path, $config)) {
                $paths[$path] = true;
            }
        }
        return array_keys($paths);
    }

    foreach (preg_split('/\R/', $manifestText) ?: [] as $line) {
        $path = http_scan_clean_manifest_line($line);
        if ($path !== '' && http_scan_allowed_extension($path, $config)) {
            $paths[$path] = true;
        }
    }
    return array_keys($paths);
}

function http_scan_head_size(string $url): ?int
{
    $headers = @get_headers($url, true);
    if (!$headers || !is_array($headers)) {
        return null;
    }
    $length = $headers['Content-Length'] ?? $headers['content-length'] ?? null;
    if (is_array($length)) {
        $length = end($length);
    }
    return ($length !== null && is_numeric($length)) ? (int)$length : null;
}

function http_scan_match_file(PDO $db, array $source, string $relativePath, ?int $remoteSize): ?array
{
    $basename = basename(parse_url($relativePath, PHP_URL_PATH) ?: $relativePath);
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

function http_scan_deep_guid_match(PDO $db, array $config, array $source, string $url, int $maxBytes): ?array
{
    $data = http_scan_fetch_url($url, $maxBytes, 'package');
    $tmp = tempnam(sys_get_temp_dir(), 'ue_http_scan_');
    if (!$tmp) {
        throw new RuntimeException('Could not create temp file for deep scan.');
    }
    try {
        file_put_contents($tmp, $data);
        unset($data);
        $header = catalog_try_read_package_header($config, (string)$source['engine_key'], $tmp);
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
    $source = catalog_one($db, 'SELECT s.*, g.name game_name, g.engine_key FROM ue_sources s JOIN ue_games g ON g.id=s.game_id WHERE s.id=?', [$sourceId]);
    if (!$source) {
        throw new RuntimeException('Source not found');
    }
    if (!in_array($source['source_type'], ['http_mirror', 'redirect_server'], true)) {
        throw new RuntimeException('This scanner only handles http_mirror and redirect_server sources.');
    }

    $manifestUrl = http_scan_make_url((string)$source['base_path'], $manifestName);
    $paths = http_scan_extract_manifest_paths(http_scan_fetch_manifest($manifestUrl), $config);
    $upsert = $db->prepare('INSERT INTO ue_file_locations(file_id,source_id,source_relative_path,exists_in_source,last_seen_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE exists_in_source=VALUES(exists_in_source), last_seen_at=NOW()');

    $matched = 0;
    $matchedGuid = 0;
    $unknown = 0;
    $ambiguous = 0;
    $deepFailed = 0;
    $samples = [];

    foreach ($paths as $relativePath) {
        $url = http_scan_make_url((string)$source['base_path'], $relativePath);
        $remoteSize = $checkRemoteSize ? http_scan_head_size($url) : null;
        $match = http_scan_match_file($db, $source, $relativePath, $remoteSize);

        if (!$match && $deepScan) {
            try {
                $match = http_scan_deep_guid_match($db, $config, $source, $url, $maxDeepBytes);
            } catch (Throwable $e) {
                $deepFailed++;
                if (count($samples) < 50) {
                    $samples[] = $relativePath . ' - deep scan failed: ' . $e->getMessage();
                }
                continue;
            }
        }

        if ($match && isset($match['file']) && is_array($match['file'])) {
            $upsert->execute([(int)$match['file']['id'], $sourceId, $relativePath, 1]);
            if (($match['status'] ?? '') === 'matched_guid') {
                $matchedGuid++;
            } else {
                $matched++;
            }
            continue;
        }

        if ($match && in_array($match['status'] ?? '', ['ambiguous', 'ambiguous_guid'], true)) {
            $ambiguous++;
            if (count($samples) < 50) {
                $samples[] = $relativePath . ' - ' . $match['status'] . (!empty($match['guid']) ? ': ' . $match['guid'] : '');
            }
            continue;
        }

        $unknown++;
        if (count($samples) < 50) {
            $reason = $match['status'] ?? 'unknown';
            $samples[] = $relativePath . ' - ' . $reason . ($remoteSize !== null ? ' - size ' . $remoteSize : '') . (!empty($match['guid']) ? ' - GUID ' . $match['guid'] : '');
        }
    }

    return [
        'source' => $source,
        'manifest_url' => $manifestUrl,
        'path_count' => count($paths),
        'matched' => $matched,
        'matched_guid' => $matchedGuid,
        'unknown' => $unknown,
        'ambiguous' => $ambiguous,
        'deep_failed' => $deepFailed,
        'samples' => $samples,
    ];
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    catalog_head('HTTP source scan');

    if (!http_scan_is_admin()) {
        echo '<div class="card"><h1>Admin required</h1><p>Log in through the main catalog admin page first.</p></div>';
        catalog_foot();
        exit;
    }

    echo '<div class="card"><h1>HTTP mirror / redirect source scanner</h1><p class="muted">Scans a manifest file from an HTTP mirror or redirect server. Optional deep scan temporarily downloads unknown files, reads their package GUID, links them, then discards the temp file.</p><p><a class="button" href="sources.php">Sources</a> <a class="button" href="source-scan.php">Local source scanner</a> <a class="button" href="games.php">Games</a></p></div>';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $sourceId = (int)($_POST['source_id'] ?? 0);
        $manifestName = trim((string)($_POST['manifest_name'] ?? 'files.txt'));
        $checkRemoteSize = isset($_POST['check_remote_size']);
        $deepScan = isset($_POST['deep_scan']);
        $maxDeepBytes = max(1024 * 1024, min(1024 * 1024 * 1024, (int)($_POST['max_deep_mb'] ?? 128) * 1024 * 1024));
        if ($manifestName === '') {
            throw new RuntimeException('Manifest name/path is required.');
        }

        $result = http_scan_source($db, $config, $sourceId, $manifestName, $checkRemoteSize, $deepScan, $maxDeepBytes);
        echo '<div class="card"><h2>Scan result</h2><table>';
        echo '<tr><th>Source</th><td>' . catalog_h($result['source']['name']) . '</td></tr>';
        echo '<tr><th>Game</th><td>' . catalog_h($result['source']['game_name']) . '</td></tr>';
        echo '<tr><th>Manifest URL</th><td class="mono path">' . catalog_h($result['manifest_url']) . '</td></tr>';
        echo '<tr><th>Package-like paths found</th><td>' . (int)$result['path_count'] . '</td></tr>';
        echo '<tr><th>Matched by name/size/package</th><td>' . (int)$result['matched'] . '</td></tr>';
        echo '<tr><th>Matched by deep GUID scan</th><td>' . (int)$result['matched_guid'] . '</td></tr>';
        echo '<tr><th>Unknown</th><td>' . (int)$result['unknown'] . '</td></tr>';
        echo '<tr><th>Ambiguous</th><td>' . (int)$result['ambiguous'] . '</td></tr>';
        echo '<tr><th>Deep scan failures</th><td>' . (int)$result['deep_failed'] . '</td></tr>';
        echo '</table></div>';
        if ($result['samples']) {
            echo '<div class="card"><h2>Unknown / ambiguous / failed samples</h2><table><tr><th>Path / reason</th></tr>';
            foreach ($result['samples'] as $sample) {
                echo '<tr><td class="mono path">' . catalog_h($sample) . '</td></tr>';
            }
            echo '</table></div>';
        }
    }

    $sources = catalog_all($db, 'SELECT s.*, g.name game_name FROM ue_sources s JOIN ue_games g ON g.id=s.game_id WHERE s.is_active=1 AND s.source_type IN ("http_mirror","redirect_server") ORDER BY g.name, s.name');
    echo '<div class="card"><h2>Run HTTP manifest scan</h2>';
    if (!$sources) {
        echo '<p class="muted">No HTTP mirror or redirect sources configured. Add one in <a href="sources.php">Sources</a>.</p>';
    } else {
        echo '<form method="post"><p><label>Source<br><select name="source_id">';
        foreach ($sources as $source) {
            $label = $source['game_name'] . ' - ' . $source['name'] . ' (' . $source['source_type'] . ')';
            echo '<option value="' . (int)$source['id'] . '">' . catalog_h($label) . '</option>';
        }
        echo '</select></label></p><p><label>Manifest path/name<br><input name="manifest_name" value="files.txt" style="min-width:360px"></label></p><p><label><input type="checkbox" name="check_remote_size" value="1"> Use HEAD requests to compare remote file sizes where possible</label></p><p><label><input type="checkbox" name="deep_scan" value="1"> Deep scan unknown files by temporary download + GUID parse</label></p><p><label>Max deep download per file MB<br><input name="max_deep_mb" value="128" style="width:120px"></label></p><button>Scan manifest</button></form>';
    }
    echo '</div>';

    $recent = catalog_all($db, 'SELECT l.*, f.package_name, f.original_name, s.name source_name FROM ue_file_locations l JOIN ue_files f ON f.id=l.file_id JOIN ue_sources s ON s.id=l.source_id WHERE s.source_type IN ("http_mirror","redirect_server") ORDER BY l.last_seen_at DESC LIMIT 100');
    echo '<div class="card"><h2>Recent HTTP source links</h2>';
    if (!$recent) {
        echo '<p class="muted">No HTTP source links recorded yet.</p>';
    } else {
        echo '<table><tr><th>Source</th><th>Package</th><th>File</th><th>Relative source path</th><th>Last seen</th></tr>';
        foreach ($recent as $row) {
            echo '<tr><td>' . catalog_h($row['source_name']) . '</td><td class="mono">' . catalog_h($row['package_name']) . '</td><td>' . catalog_h($row['original_name']) . '</td><td class="mono path">' . catalog_h($row['source_relative_path']) . '</td><td>' . catalog_h($row['last_seen_at']) . '</td></tr>';
        }
        echo '</table>';
    }
    echo '</div>';

    catalog_foot();
} catch (Throwable $e) {
    if (!headers_sent()) {
        catalog_head('HTTP source scan error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
