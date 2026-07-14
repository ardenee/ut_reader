<?php
declare(strict_types=1);

session_start();
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/lib/CatalogSupport.php';
require_once __DIR__ . '/lib/CatalogParser.php';
require_once __DIR__ . '/lib/CatalogScanner.php';
require_once __DIR__ . '/lib/CatalogRedirectArchive.php';
require_once __DIR__ . '/lib/GameProfiles.php';

function clean_relative_path(string $base, string $path): string
{
    $base = rtrim(str_replace('\\', '/', realpath($base) ?: $base), '/') . '/';
    $path = str_replace('\\', '/', realpath($path) ?: $path);
    if (str_starts_with($path, $base)) {
        return ltrim(substr($path, strlen($base)), '/');
    }
    return basename($path);
}

/**
 * Local sources belong to one game, therefore their extension allowance comes
 * from that game’s active profile. The global config list is only a legacy
 * fallback when a profile has no explicit extension list. Redirect-compressed
 * .uz/.uz2/.uz3 files are accepted here because they are decompressed before
 * matching/importing.
 */
function allowed_source_extension(string $path, array $profile, array $config): bool
{
    if (catalog_redirect_archive_is_supported_filename($path)) {
        return true;
    }

    $cleanName = catalog_clean_unreal_filename(basename($path));
    $ext = catalog_clean_unreal_extension((string)pathinfo($cleanName, PATHINFO_EXTENSION));
    return in_array($ext, scanner_profile_extensions($profile, $config), true);
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

/** @return array{path:string,name:string,temp:bool,redirect:bool,source_extension:string} */
function source_scan_work_file(string $path): array
{
    $name = basename($path);
    if (!catalog_redirect_archive_is_supported_filename($name)) {
        return [
            'path' => $path,
            'name' => catalog_clean_unreal_filename($name),
            'temp' => false,
            'redirect' => false,
            'source_extension' => '',
        ];
    }

    $decoded = catalog_redirect_archive_decompress_to_temp($path, $name);
    return [
        'path' => (string)$decoded['path'],
        'name' => catalog_clean_unreal_filename((string)$decoded['filename']),
        'temp' => true,
        'redirect' => true,
        'source_extension' => (string)$decoded['source_extension'],
    ];
}

function source_scan_cleanup_work_file(array $work): void
{
    if (!empty($work['temp']) && is_file((string)$work['path'])) {
        @unlink((string)$work['path']);
    }
}

function source_scan_scanner_relative_path(string $relative, array $work): string
{
    $relative = scanner_normalize_source_relative_path($relative);
    if ($relative === '' || empty($work['redirect'])) {
        return $relative;
    }

    $dir = trim(str_replace('\\', '/', dirname($relative)), '. /');
    $name = (string)($work['name'] ?? '');
    return scanner_normalize_source_relative_path(($dir !== '' ? $dir . '/' : '') . $name);
}

function source_scan_import_work_file(PDO $db, array $config, array $source, array $work, string $relative, bool $strictProfile): array
{
    $tmp = source_scan_tmp_copy((string)$work['path']);
    $sourceRelativePath = source_scan_scanner_relative_path($relative, $work);
    return scanner_scan_uploaded_file(
        $db,
        $config,
        (int)$source['game_id'],
        $tmp,
        (string)$work['name'],
        $_SESSION['user']['id'] ?? null,
        $strictProfile,
        null,
        false,
        ['source_relative_path' => $sourceRelativePath]
    );
}

function source_scan_result_sample(string $path, array $work, string $message): string
{
    if (!empty($work['redirect'])) {
        return $path . ' → ' . (string)$work['name'] . ' - ' . $message;
    }
    return $path . ' - ' . $message;
}

function source_scan_record_import_result(PDO $db, PDOStatement $upsert, int $sourceId, string $relative, array $result, int &$imported, int &$duplicates, int &$locations): void
{
    if (($result[0] ?? '') === 'duplicate') {
        $duplicates++;
        if (!empty($result[1])) {
            record_file_location($db, $upsert, (int)$result[1], $sourceId, $relative);
            $locations++;
        }
        return;
    }

    $imported++;
    record_file_location($db, $upsert, (int)$result[1], $sourceId, $relative);
    $locations++;
}

function scan_local_source(PDO $db, array $config, int $sourceId, bool $importUnknown, bool $strictProfile): array
{
    $source = catalog_one($db, 'SELECT s.*, g.name game_name, g.slug game_slug, p.engine_key profile_engine FROM ue_sources s JOIN ue_games g ON g.id=s.game_id LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE s.id=?', [$sourceId]);
    if (!$source) {
        throw new RuntimeException('Source not found');
    }
    if ($source['source_type'] !== 'local_path') {
        throw new RuntimeException('Only local folder sources can be scanned by this page.');
    }
    $profile = gp_required_profile_for_game($db, (int)$source['game_id']);
    $profileEngine = strtoupper((string)$profile['engine_key']);

    $basePath = rtrim((string)$source['base_path'], DIRECTORY_SEPARATOR);
    if (!is_dir($basePath) || !is_readable($basePath)) {
        throw new RuntimeException('Source path is not readable: ' . $basePath);
    }

    $found = 0;
    $redirectArchives = 0;
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

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS), RecursiveIteratorIterator::SELF_FIRST);
    $upsert = $db->prepare('INSERT INTO ue_file_locations(file_id,source_id,source_relative_path,exists_in_source,last_seen_at) VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE exists_in_source=VALUES(exists_in_source), last_seen_at=NOW()');

    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo || !$item->isFile()) {
            continue;
        }

        $path = $item->getPathname();
        if (!allowed_source_extension($path, $profile, $config)) {
            continue;
        }

        $found++;
        $relative = clean_relative_path($basePath, $path);
        $work = null;

        try {
            $work = source_scan_work_file($path);
            if (!empty($work['redirect'])) {
                $redirectArchives++;
            }

            $md5 = md5_file((string)$work['path']);
            if (!$md5) {
                $unknown++;
                if (count($unknownSamples) < 50) {
                    $unknownSamples[] = source_scan_result_sample($path, $work, 'could not hash file');
                }
                continue;
            }

            $file = catalog_one($db, 'SELECT id FROM ue_files WHERE game_id=? AND md5=? LIMIT 1', [(int)$source['game_id'], $md5]);
            if ($file) {
                record_file_location($db, $upsert, (int)$file['id'], $sourceId, $relative);
                scanner_record_source_relative_path($db, (int)$file['id'], source_scan_scanner_relative_path($relative, $work));
                $matchedMd5++;
                $locations++;
                continue;
            }

            try {
                $header = catalog_try_read_package_header($config, $profileEngine, (string)$work['path']);
                $guid = catalog_header_guid($header);
            } catch (Throwable $e) {
                if ($importUnknown) {
                    try {
                        $result = source_scan_import_work_file($db, $config, $source, $work, $relative, $strictProfile);
                        source_scan_record_import_result($db, $upsert, $sourceId, $relative, $result, $imported, $duplicates, $locations);
                        if (count($importSamples) < 50) {
                            $importSamples[] = source_scan_result_sample($path, $work, (string)$result[2]);
                        }
                        continue;
                    } catch (Throwable $scanError) {
                        $importFailed++;
                        if (count($parseFailedSamples) < 50) {
                            $parseFailedSamples[] = source_scan_result_sample($path, $work, 'profiled import failed: ' . $scanError->getMessage());
                        }
                        continue;
                    }
                }

                $parseFailed++;
                if (count($parseFailedSamples) < 50) {
                    $parseFailedSamples[] = source_scan_result_sample($path, $work, $e->getMessage());
                }
                continue;
            }

            if ($guid === '') {
                if ($importUnknown) {
                    try {
                        $result = source_scan_import_work_file($db, $config, $source, $work, $relative, $strictProfile);
                        source_scan_record_import_result($db, $upsert, $sourceId, $relative, $result, $imported, $duplicates, $locations);
                        if (count($importSamples) < 50) {
                            $importSamples[] = source_scan_result_sample($path, $work, (string)$result[2]);
                        }
                        continue;
                    } catch (Throwable $scanError) {
                        $importFailed++;
                        if (count($unknownSamples) < 50) {
                            $unknownSamples[] = source_scan_result_sample($path, $work, 'no GUID; profiled import failed: ' . $scanError->getMessage());
                        }
                        continue;
                    }
                }

                $unknown++;
                if (count($unknownSamples) < 50) {
                    $unknownSamples[] = source_scan_result_sample($path, $work, 'no GUID found');
                }
                continue;
            }

            $matches = catalog_all($db, 'SELECT id, original_name, md5 FROM ue_files WHERE game_id=? AND package_guid=? ORDER BY id', [(int)$source['game_id'], $guid]);
            if (count($matches) === 1) {
                record_file_location($db, $upsert, (int)$matches[0]['id'], $sourceId, $relative);
                scanner_record_source_relative_path($db, (int)$matches[0]['id'], source_scan_scanner_relative_path($relative, $work));
                $matchedGuid++;
                $locations++;
                continue;
            }

            if (count($matches) > 1) {
                $guidAmbiguous++;
                if (count($unknownSamples) < 50) {
                    $unknownSamples[] = source_scan_result_sample($path, $work, 'GUID matches multiple catalog files: ' . $guid);
                }
                continue;
            }

            if ($importUnknown) {
                try {
                    $result = source_scan_import_work_file($db, $config, $source, $work, $relative, $strictProfile);
                    source_scan_record_import_result($db, $upsert, $sourceId, $relative, $result, $imported, $duplicates, $locations);
                    if (count($importSamples) < 50) {
                        $importSamples[] = source_scan_result_sample($path, $work, (string)$result[2]);
                    }
                    continue;
                } catch (Throwable $scanError) {
                    $importFailed++;
                    if (count($unknownSamples) < 50) {
                        $unknownSamples[] = source_scan_result_sample($path, $work, 'GUID not in catalog: ' . $guid . '; profiled import failed: ' . $scanError->getMessage());
                    }
                    continue;
                }
            }

            $unknown++;
            if (count($unknownSamples) < 50) {
                $unknownSamples[] = source_scan_result_sample($path, $work, 'GUID not in catalog: ' . $guid);
            }
        } catch (Throwable $error) {
            $parseFailed++;
            if (count($parseFailedSamples) < 50) {
                $parseFailedSamples[] = $path . ' - ' . $error->getMessage();
            }
        } finally {
            if (is_array($work)) {
                source_scan_cleanup_work_file($work);
            }
        }
    }

    return [
        'source' => $source,
        'found' => $found,
        'redirect_archives' => $redirectArchives,
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

    if (!catalog_require_admin_page('Source scan')) {
        exit;
    }

    catalog_head('Source scan');
    catalog_page_header('Source scanner', 'Recursively scan game-owned folders and subfolders, including redirect-compressed .uz/.uz2/.uz3 files. Source-relative paths are preserved and used as UE4 package identity context during import and later Full Sync.', ['Game Sources' => 'sources.php', 'HTTP Source Scan' => 'http-source-scan.php', 'Upload Files' => 'profiled-upload.php', 'Unverified Files' => 'unverified-files.php']);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        catalog_check_csrf('source_scan');
        $sourceId = (int)($_POST['source_id'] ?? 0);
        $importUnknown = (string)($_POST['import_unknown'] ?? '0') === '1';
        $strictProfile = (string)($_POST['strict_profile'] ?? '1') === '1';
        $result = scan_local_source($db, $config, $sourceId, $importUnknown, $strictProfile);
        echo '<div class="card"><h2>Scan result</h2>';
        echo '<table><tr><th>Source</th><td>' . catalog_h($result['source']['name']) . '</td></tr>';
        echo '<tr><th>Game</th><td>' . catalog_h($result['source']['game_name']) . '</td></tr>';
        echo '<tr><th>Profile engine</th><td>' . catalog_h($result['source']['profile_engine'] ?? '') . '</td></tr>';
        echo '<tr><th>Package-like files found</th><td>' . (int)$result['found'] . '</td></tr>';
        echo '<tr><th>Redirect archives decompressed</th><td>' . (int)$result['redirect_archives'] . '</td></tr>';
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

    $sources = catalog_all($db, 'SELECT s.*, g.name game_name, p.engine_key profile_engine FROM ue_sources s JOIN ue_games g ON g.id=s.game_id LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE s.is_active=1 ORDER BY g.name, s.name');
    echo '<div class="card"><h2>Run scan</h2>';
    if (!$sources) {
        echo '<p class="muted">No sources configured. Add one in <a href="sources.php">Game Sources</a>.</p>';
    } else {
        echo '<form method="post"><input type="hidden" name="csrf" value="' . catalog_h(catalog_csrf('source_scan')) . '"><p><label>Source<br><select name="source_id">';
        foreach ($sources as $source) {
            $label = $source['game_name'] . ' / ' . ($source['profile_engine'] ?: 'no profile') . ' - ' . $source['name'] . ' (' . $source['source_type'] . ')';
            echo '<option value="' . (int)$source['id'] . '">' . catalog_h($label) . '</option>';
        }
        echo '</select></label></p>';
        echo '<p><label><input type="checkbox" name="import_unknown" value="1"> Import unknown files into this game using its active profile</label></p>';
        echo '<p><label>Profile mismatch handling<br><select name="strict_profile"><option value="1" selected>Strict: reject mismatches</option><option value="0">Loose: use detected reader where possible</option></select></label></p>';
        echo '<p class="muted">The scan is recursive through the selected source folder and subfolders. It uses the selected source game’s profile extension list, plus .uz/.uz2/.uz3 redirect archives which are decompressed before matching/importing. Source-relative paths are preserved for UE4 package identity and Full Sync reimports.</p>';
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
    if (!headers_sent()) {
        catalog_head('Source scan error');
    }
    echo '<div class="card"><h1>Error</h1><p>' . catalog_h($e->getMessage()) . '</p></div>';
    catalog_foot();
}
