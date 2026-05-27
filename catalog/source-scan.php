<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogParser.php';
require_once __DIR__ . '/lib/CatalogScanner.php';

function is_admin_user(): bool
{
    return ($_SESSION['user']['role'] ?? '') === 'admin';
}

function source_scan_csrf(): string
{
    $_SESSION['source_scan_csrf'] ??= bin2hex(random_bytes(16));
    return $_SESSION['source_scan_csrf'];
}

function source_scan_check_csrf(): void
{
    if (($_POST['csrf'] ?? '') !== ($_SESSION['source_scan_csrf'] ?? '')) {
        throw new RuntimeException('Bad CSRF token');
    }
}

function clean_relative_path(string $base, string $path): string
{
    $base = rtrim(str_replace('\\', '/', realpath($base) ?: $base), '/') . '/';
    $path = str_replace('\\', '/', realpath($path) ?: $path);
    if (str_starts_with($path, $base)) {
        return ltrim(substr($path, strlen($base)), '/');
    }
    return basename($path);
}

function allowed_source_extension(string $path, array $config): bool
{
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return in_array($ext, $config['allowed_extensions'] ?? [], true);
}

function record_file_location(PDO $db, PDOStatement $upsert, int $fileId, int $sourceId, string $relativePath): void
{
    $upsert->execute([$fileId, $sourceId, $relativePath, 1]);
}

function source_scan_tmp_copy(string $path): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'ue_src_scan_');
    if (!$tmp) {
        throw new RuntimeException('Could not create temporary file for profiled source import.');
    }
    if (!copy($path, $tmp)) {
        @unlink($tmp);
        throw new RuntimeException('Could not copy source file to temporary scan file.');
    }
    return $tmp;
}

function scan_local_source(PDO $db, array $config, int $sourceId, bool $importUnknown, bool $strictProfile): array
{
    $source = catalog_one($db, 'SELECT s.*, g.name game_name, g.engine_key, g.slug game_slug FROM ue_sources s JOIN ue_games g ON g.id=s.game_id WHERE s.id=?', [$sourceId]);
    if (!$source) {
        throw new RuntimeException('Source not found');
    }
    if ($source['source_type'] !== 'local_path') {
        throw new RuntimeException('Only local_path sources can be scanned by this page right now.');
    }

    $basePath = rtrim((string)$source['base_path'], DIRECTORY_SEPARATOR);
    if (!is_dir($basePath) || !is_readable($basePath)) {
        throw new RuntimeException('Source path is not readable: ' . $basePath);
    }

    $found = 0;
    $matchedMd5 = 0;
    $matchedGuid = 0;
    $guidAmbiguous = 0;
    $parseFailed = 0;
    $unknown = 0;
    $locations = 0;
    $imported = 0;
    $duplicates = 0;
    $importFailed = 0;
    $unknownSamples = [];
    $parseFailedSamples = [];
    $importSamples = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    $upsert = $db->prepare('INSERT INTO ue_file_locations(file_id,source_id,source_relative_path,exists_in_source,last_seen_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE exists_in_source=VALUES(exists_in_source), last_seen_at=NOW()');

    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo || !$item->isFile()) {
            continue;
        }

        $path = $item->getPathname();
        if (!allowed_source_extension($path, $config)) {
            continue;
        }

        $found++;
        $relative = clean_relative_path($basePath, $path);
        $md5 = md5_file($path);
        if (!$md5) {
            $unknown++;
            if (count($unknownSamples) < 50) {
                $unknownSamples[] = $path;
            }
            continue;
        }

        $file = catalog_one($db, 'SELECT id FROM ue_files WHERE game_id=? AND md5=? LIMIT 1', [(int)$source['game_id'], $md5]);
        if ($file) {
            record_file_location($db, $upsert, (int)$file['id'], $sourceId, $relative);
            $matchedMd5++;
            $locations++;
            continue;
        }

        try {
            $header = catalog_try_read_package_header($config, (string)$source['engine_key'], $path);
            $guid = catalog_header_guid($header);
        } catch (Throwable $e) {
            if ($importUnknown) {
                try {
                    $tmp = source_scan_tmp_copy($path);
                    $result = scanner_scan_uploaded_file($db, $config, (int)$source['game_id'], $tmp, basename($path), $_SESSION['user']['id'] ?? null, $strictProfile);
                    if ($result[0] === 'duplicate') {
                        $duplicates++;
                        if (!empty($result[1])) {
                            record_file_location($db, $upsert, (int)$result[1], $sourceId, $relative);
                            $locations++;
                        }
                    } else {
                        $imported++;
                        record_file_location($db, $upsert, (int)$result[1], $sourceId, $relative);
                        $locations++;
                    }
                    if (count($importSamples) < 50) {
                        $importSamples[] = $path . ' - ' . $result[2];
                    }
                    continue;
                } catch (Throwable $scanError) {
                    $importFailed++;
                    if (isset($tmp) && is_file($tmp)) {
                        @unlink($tmp);
                    }
                    if (count($parseFailedSamples) < 50) {
                        $parseFailedSamples[] = $path . ' - profiled import failed: ' . $scanError->getMessage();
                    }
                    continue;
                }
            }

            $parseFailed++;
            if (count($parseFailedSamples) < 50) {
                $parseFailedSamples[] = $path . ' - ' . $e->getMessage();
            }
            continue;
        }

        if ($guid === '') {
            if ($importUnknown) {
                try {
                    $tmp = source_scan_tmp_copy($path);
                    $result = scanner_scan_uploaded_file($db, $config, (int)$source['game_id'], $tmp, basename($path), $_SESSION['user']['id'] ?? null, $strictProfile);
                    if ($result[0] === 'duplicate') {
                        $duplicates++;
                        if (!empty($result[1])) {
                            record_file_location($db, $upsert, (int)$result[1], $sourceId, $relative);
                            $locations++;
                        }
                    } else {
                        $imported++;
                        record_file_location($db, $upsert, (int)$result[1], $sourceId, $relative);
                        $locations++;
                    }
                    if (count($importSamples) < 50) {
                        $importSamples[] = $path . ' - ' . $result[2];
                    }
                    continue;
                } catch (Throwable $scanError) {
                    $importFailed++;
                    if (isset($tmp) && is_file($tmp)) {
                        @unlink($tmp);
                    }
                    if (count($unknownSamples) < 50) {
                        $unknownSamples[] = $path . ' - no GUID; profiled import failed: ' . $scanError->getMessage();
                    }
                    continue;
                }
            }

            $unknown++;
            if (count($unknownSamples) < 50) {
                $unknownSamples[] = $path . ' - no GUID found';
            }
            continue;
        }

        $matches = catalog_all($db, 'SELECT id, original_name, md5 FROM ue_files WHERE game_id=? AND package_guid=? ORDER BY id', [(int)$source['game_id'], $guid]);
        if (count($matches) === 1) {
            record_file_location($db, $upsert, (int)$matches[0]['id'], $sourceId, $relative);
            $matchedGuid++;
            $locations++;
            continue;
        }

        if (count($matches) > 1) {
            $guidAmbiguous++;
            if (count($unknownSamples) < 50) {
                $unknownSamples[] = $path . ' - GUID matches multiple catalog files: ' . $guid;
            }
            continue;
        }

        if ($importUnknown) {
            try {
                $tmp = source_scan_tmp_copy($path);
                $result = scanner_scan_uploaded_file($db, $config, (int)$source['game_id'], $tmp, basename($path), $_SESSION['user']['id'] ?? null, $strictProfile);
                if ($result[0] === 'duplicate') {
                    $duplicates++;
                    if (!empty($result[1])) {
                        record_file_location($db, $upsert, (int)$result[1], $sourceId, $relative);
                        $locations++;
                    }
                } else {
                    $imported++;
                    record_file_location($db, $upsert, (int)$result[1], $sourceId, $relative);
                    $locations++;
                }
                if (count($importSamples) < 50) {
                    $importSamples[] = $path . ' - ' . $result[2];
                }
                continue;
            } catch (Throwable $scanError) {
                $importFailed++;
                if (isset($tmp) && is_file($tmp)) {
                    @unlink($tmp);
                }
                if (count($unknownSamples) < 50) {
                    $unknownSamples[] = $path . ' - GUID not in catalog: ' . $guid . '; profiled import failed: ' . $scanError->getMessage();
                }
                continue;
            }
        }

        $unknown++;
        if (count($unknownSamples) < 50) {
            $unknownSamples[] = $path . ' - GUID not in catalog: ' . $guid;
        }
    }

    return [
        'source' => $source,
        'found' => $found,
        'matched_md5' => $matchedMd5,
        'matched_guid' => $matchedGuid,
        'guid_ambiguous' => $guidAmbiguous,
        'parse_failed' => $parseFailed,
        'unknown' => $unknown,
        'locations' => $locations,
        'imported' => $imported,
        'duplicates' => $duplicates,
        'import_failed' => $importFailed,
        'unknown_samples' => $unknownSamples,
        'parse_failed_samples' => $parseFailedSamples,
        'import_samples' => $importSamples,
    ];
}

try {
    $config = catalog_config();
    $db = catalog_db($config);

    catalog_head('Source scan');

    if (!is_admin_user()) {
        echo '<div class="card"><h1>Admin required</h1><p>Log in through the main catalog admin page first.</p></div>';
        catalog_foot();
        exit;
    }

    echo '<div class="card hero"><h1>Source scanner</h1><p class="muted">Scans configured local source folders and records where known catalog files exist. It can now optionally import unknown files through the profiled scanner, using game profile version/extension checks.</p></div>';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        source_scan_check_csrf();
        $sourceId = (int)($_POST['source_id'] ?? 0);
        $importUnknown = (string)($_POST['import_unknown'] ?? '0') === '1';
        $strictProfile = (string)($_POST['strict_profile'] ?? '1') === '1';
        $result = scan_local_source($db, $config, $sourceId, $importUnknown, $strictProfile);
        echo '<div class="card"><h2>Scan result</h2>';
        echo '<table><tr><th>Source</th><td>' . catalog_h($result['source']['name']) . '</td></tr>';
        echo '<tr><th>Game</th><td>' . catalog_h($result['source']['game_name']) . '</td></tr>';
        echo '<tr><th>Package-like files found</th><td>' . (int)$result['found'] . '</td></tr>';
        echo '<tr><th>Matched by MD5</th><td>' . (int)$result['matched_md5'] . '</td></tr>';
        echo '<tr><th>Matched by GUID</th><td>' . (int)$result['matched_guid'] . '</td></tr>';
        echo '<tr><th>Ambiguous GUID matches</th><td>' . (int)$result['guid_ambiguous'] . '</td></tr>';
        echo '<tr><th>Parse failed</th><td>' . (int)$result['parse_failed'] . '</td></tr>';
        echo '<tr><th>Unknown / not cataloged</th><td>' . (int)$result['unknown'] . '</td></tr>';
        echo '<tr><th>Imported by profiled scanner</th><td>' . (int)$result['imported'] . '</td></tr>';
        echo '<tr><th>Duplicate imports</th><td>' . (int)$result['duplicates'] . '</td></tr>';
        echo '<tr><th>Profiled import failed</th><td>' . (int)$result['import_failed'] . '</td></tr>';
        echo '<tr><th>Locations recorded</th><td>' . (int)$result['locations'] . '</td></tr></table>';
        echo '</div>';

        if ($result['import_samples']) {
            echo '<div class="card"><h2>Profiled import samples</h2><table><tr><th>Path / result</th></tr>';
            foreach ($result['import_samples'] as $sample) {
                echo '<tr><td class="mono path">' . catalog_h($sample) . '</td></tr>';
            }
            echo '</table></div>';
        }

        if ($result['unknown_samples']) {
            echo '<div class="card"><h2>Unknown / ambiguous samples</h2><p class="muted">These files were found in the source but were not linked automatically.</p><table><tr><th>Path / reason</th></tr>';
            foreach ($result['unknown_samples'] as $sample) {
                echo '<tr><td class="mono path">' . catalog_h($sample) . '</td></tr>';
            }
            echo '</table></div>';
        }

        if ($result['parse_failed_samples']) {
            echo '<div class="card"><h2>Parse failed samples</h2><table><tr><th>Path / reason</th></tr>';
            foreach ($result['parse_failed_samples'] as $sample) {
                echo '<tr><td class="mono path">' . catalog_h($sample) . '</td></tr>';
            }
            echo '</table></div>';
        }
    }

    $sources = catalog_all($db, 'SELECT s.*, g.name game_name FROM ue_sources s JOIN ue_games g ON g.id=s.game_id WHERE s.is_active=1 ORDER BY g.name, s.name');
    echo '<div class="card"><h2>Run scan</h2>';
    if (!$sources) {
        echo '<p class="muted">No sources configured. Add one in <a href="sources.php">Sources</a>.</p>';
    } else {
        echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(source_scan_csrf()) . '"><p><label>Source<br><select name="source_id">';
        foreach ($sources as $source) {
            $label = $source['game_name'] . ' - ' . $source['name'] . ' (' . $source['source_type'] . ')';
            echo '<option value="' . (int)$source['id'] . '">' . catalog_h($label) . '</option>';
        }
        echo '</select></label></p>';
        echo '<p><label><input type="checkbox" name="import_unknown" value="1"> Import unknown files into catalog using profiled scanner</label></p>';
        echo '<p><label>Profile mismatch handling<br><select name="strict_profile"><option value="1" selected>Strict: reject mismatches</option><option value="0">Loose: try parser anyway</option></select></label></p>';
        echo '<p class="muted">Without import enabled, the scan only links existing catalog files by MD5/GUID. With import enabled, unmatched files are copied to a temp file and scanned through CatalogScanner.php before being stored as verified.</p>';
        echo '<button>Scan selected source</button></form>';
    }
    echo '</div>';

    $recent = catalog_all($db, 'SELECT l.*, f.package_name, f.original_name, s.name source_name FROM ue_file_locations l JOIN ue_files f ON f.id=l.file_id JOIN ue_sources s ON s.id=l.source_id ORDER BY l.last_seen_at DESC LIMIT 100');
    echo '<div class="card"><h2>Recent source links</h2>';
    if (!$recent) {
        echo '<p class="muted">No source links recorded yet.</p>';
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
    catalog_head('Source scan error');
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
