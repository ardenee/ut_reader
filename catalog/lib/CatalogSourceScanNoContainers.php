<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSourceScan.php';

/**
 * Local source scan variant used by the durable worker after PAK containers have
 * been queued separately. It preserves the normal package matching/import logic
 * while excluding .pak files from MD5/header parsing and unverified staging.
 *
 * @param array<string,mixed> $config
 * @param callable(array<string,mixed>):void|null $progress
 * @return array<string,mixed>
 */
function catalog_source_scan_run_without_containers(
    PDO $db,
    array $config,
    int $sourceId,
    bool $importUnknown,
    bool $strictProfile,
    ?int $userId = null,
    ?callable $progress = null
): array {
    $source = catalog_one(
        $db,
        'SELECT s.*,g.name game_name,g.slug game_slug,p.engine_key profile_engine '
        . 'FROM ue_sources s JOIN ue_games g ON g.id=s.game_id '
        . 'LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 WHERE s.id=?',
        [$sourceId]
    );
    if (!$source) {
        throw new RuntimeException('Source not found.');
    }
    if ((string)$source['source_type'] !== 'local_path') {
        throw new RuntimeException('Only local folder sources can be scanned by this job.');
    }

    $profile = gp_required_profile_for_game($db, (int)$source['game_id']);
    $profileEngine = strtoupper((string)$profile['engine_key']);
    $basePath = rtrim((string)$source['base_path'], DIRECTORY_SEPARATOR);
    if (!is_dir($basePath) || !is_readable($basePath)) {
        throw new RuntimeException('Source path is not readable: ' . $basePath);
    }

    $counters = array_fill_keys([
        'found', 'redirect_archives', 'matched_md5', 'matched_guid', 'guid_ambiguous',
        'parse_failed', 'unknown', 'locations', 'imported', 'duplicates',
        'import_failed', 'staged_unverified', 'containers_skipped',
    ], 0);
    $unknownSamples = [];
    $parseFailedSamples = [];
    $importSamples = [];
    $files = [];

    catalog_source_scan_report($progress, [
        'stage' => 'discovering', 'done' => 0, 'total' => 0, 'percent' => 0,
        'message' => 'Discovering package files in ' . $basePath,
    ]);

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($basePath, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo || !$item->isFile()) {
            continue;
        }
        $path = $item->getPathname();
        if (strtolower((string)pathinfo($path, PATHINFO_EXTENSION)) === 'pak') {
            $counters['containers_skipped']++;
            continue;
        }
        if (!catalog_source_scan_allowed_file($path, $profile, $config)) {
            continue;
        }
        $files[] = [$path, catalog_source_scan_relative_path($basePath, $path)];
        if ((count($files) % 250) === 0) {
            catalog_source_scan_report($progress, [
                'stage' => 'discovering', 'done' => count($files), 'total' => 0, 'percent' => 0,
                'message' => 'Discovered ' . count($files) . ' package-like files.',
            ] + $counters);
        }
    }

    $total = count($files);
    $upsert = $db->prepare(
        'INSERT INTO ue_file_locations(file_id,source_id,source_relative_path,exists_in_source,last_seen_at) '
        . 'VALUES(?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE exists_in_source=VALUES(exists_in_source),last_seen_at=NOW()'
    );
    catalog_source_scan_report($progress, [
        'stage' => 'scanning', 'done' => 0, 'total' => max(1, $total), 'percent' => 0,
        'message' => $total > 0 ? 'Scanning ' . $total . ' package-like files.' : 'No package-like files were found.',
    ] + $counters);

    foreach ($files as $index => [$path, $relativePath]) {
        $counters['found']++;
        $work = null;
        try {
            $work = catalog_source_scan_work_file($path);
            if ($work['redirect']) {
                $counters['redirect_archives']++;
            }

            $md5 = md5_file($work['path']);
            if ($md5 === false) {
                $counters['unknown']++;
                if (count($unknownSamples) < 50) {
                    $unknownSamples[] = catalog_source_scan_sample($path, $work, 'could not hash file');
                }
                continue;
            }

            $file = catalog_one($db, 'SELECT id FROM ue_files WHERE game_id=? AND scan_status="verified" AND md5=? LIMIT 1', [(int)$source['game_id'], $md5]);
            if ($file) {
                catalog_source_scan_record_location($upsert, (int)$file['id'], $sourceId, $relativePath);
                scanner_record_source_relative_path($db, (int)$file['id'], catalog_source_scan_normalized_relative_path($relativePath, $work));
                $counters['matched_md5']++;
                $counters['locations']++;
                continue;
            }

            try {
                $header = catalog_try_read_package_header($config, $profileEngine, $work['path']);
                $guid = catalog_header_guid($header);
            } catch (Throwable $parseError) {
                if (!$importUnknown) {
                    $counters['parse_failed']++;
                    if (count($parseFailedSamples) < 50) {
                        $parseFailedSamples[] = catalog_source_scan_sample($path, $work, $parseError->getMessage());
                    }
                    continue;
                }
                try {
                    $result = catalog_source_scan_import_work_file($db, $config, $source, $work, $relativePath, $strictProfile, $userId);
                    catalog_source_scan_record_import_result($upsert, $sourceId, $relativePath, $result, $counters['imported'], $counters['duplicates'], $counters['locations']);
                    if (count($importSamples) < 50) {
                        $importSamples[] = catalog_source_scan_sample($path, $work, (string)$result[2]);
                    }
                } catch (Throwable $scanError) {
                    $counters['import_failed']++;
                    try {
                        if (catalog_source_scan_stage_failed($db, $config, $source, $work, $relativePath, $scanError, $userId)) {
                            $counters['staged_unverified']++;
                        }
                    } catch (Throwable $stageError) {
                        $scanError = $stageError;
                    }
                    if (count($parseFailedSamples) < 50) {
                        $parseFailedSamples[] = catalog_source_scan_sample($path, $work, 'profiled import failed: ' . $scanError->getMessage());
                    }
                }
                continue;
            }

            if ($guid !== '') {
                $matches = catalog_all($db, 'SELECT id FROM ue_files WHERE game_id=? AND scan_status="verified" AND package_guid=? ORDER BY id', [(int)$source['game_id'], $guid]);
                if (count($matches) === 1) {
                    catalog_source_scan_record_location($upsert, (int)$matches[0]['id'], $sourceId, $relativePath);
                    scanner_record_source_relative_path($db, (int)$matches[0]['id'], catalog_source_scan_normalized_relative_path($relativePath, $work));
                    $counters['matched_guid']++;
                    $counters['locations']++;
                    continue;
                }
                if (count($matches) > 1) {
                    $counters['guid_ambiguous']++;
                    if (count($unknownSamples) < 50) {
                        $unknownSamples[] = catalog_source_scan_sample($path, $work, 'GUID matches multiple catalog files: ' . $guid);
                    }
                    continue;
                }
            }

            if (!$importUnknown) {
                $counters['unknown']++;
                if (count($unknownSamples) < 50) {
                    $unknownSamples[] = catalog_source_scan_sample($path, $work, $guid === '' ? 'no GUID found' : 'GUID not in catalog: ' . $guid);
                }
                continue;
            }

            try {
                $result = catalog_source_scan_import_work_file($db, $config, $source, $work, $relativePath, $strictProfile, $userId);
                catalog_source_scan_record_import_result($upsert, $sourceId, $relativePath, $result, $counters['imported'], $counters['duplicates'], $counters['locations']);
                if (count($importSamples) < 50) {
                    $importSamples[] = catalog_source_scan_sample($path, $work, (string)$result[2]);
                }
            } catch (Throwable $scanError) {
                $counters['import_failed']++;
                try {
                    if (catalog_source_scan_stage_failed($db, $config, $source, $work, $relativePath, $scanError, $userId)) {
                        $counters['staged_unverified']++;
                    }
                } catch (Throwable $stageError) {
                    $scanError = $stageError;
                }
                if (count($unknownSamples) < 50) {
                    $unknownSamples[] = catalog_source_scan_sample($path, $work, ($guid === '' ? 'no GUID' : 'GUID not in catalog: ' . $guid) . '; profiled import failed: ' . $scanError->getMessage());
                }
            }
        } catch (Throwable $error) {
            $counters['parse_failed']++;
            if ($importUnknown && is_array($work)) {
                try {
                    if (catalog_source_scan_stage_failed($db, $config, $source, $work, $relativePath, $error, $userId)) {
                        $counters['staged_unverified']++;
                    }
                } catch (Throwable $stageError) {
                    $error = $stageError;
                }
            }
            if (count($parseFailedSamples) < 50) {
                $parseFailedSamples[] = $path . ' - ' . $error->getMessage();
            }
        } finally {
            if (is_array($work)) {
                catalog_source_scan_cleanup_work_file($work);
            }
            $done = $index + 1;
            catalog_source_scan_report($progress, [
                'stage' => 'scanning', 'done' => $done, 'total' => max(1, $total),
                'percent' => (int)floor(($done * 100) / max(1, $total)),
                'message' => 'Processed ' . $done . '/' . $total . ': ' . basename($path),
            ] + $counters);
        }
    }

    catalog_source_scan_report($progress, [
        'stage' => 'complete', 'done' => max(1, $total), 'total' => max(1, $total),
        'percent' => 100, 'message' => 'Source scan complete.',
    ] + $counters);

    return $counters + [
        'source' => $source,
        'unknown_samples' => $unknownSamples,
        'parse_failed_samples' => $parseFailedSamples,
        'import_samples' => $importSamples,
    ];
}
