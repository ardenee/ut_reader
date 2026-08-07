<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides shared catalog helper functions for catalog source scan no containers.
 * Why: It centralizes behavior reused by multiple pages, APIs, workers, or maintenance scripts instead of repeating
 *      that behavior at each call site.
 * Role: Legacy/shared library layer; some files are transitional bridges while newer implementation code lives under
 *       `catalog/src`.
 * Audit: Shared code: reuse or migrate this responsibility before adding another implementation with the same
 *        purpose.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSourceScan.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoSourceFileFingerprintCache;

/** @return array<string,mixed>|null */
function catalog_source_scan_catalog_identity(PDO $db, int $fileId): ?array
{
    if ($fileId < 1) {
        return null;
    }
    $row = catalog_one(
        $db,
        'SELECT id,md5,sha1,package_guid FROM ue_files WHERE id=? AND scan_status="verified" LIMIT 1',
        [$fileId]
    );
    return is_array($row) ? $row : null;
}

/** @return array{path:string,name:string,temp:bool,redirect:bool,source_extension:string} */
function catalog_source_scan_cached_work(string $path, array $cached): array
{
    $redirect = (int)($cached['is_redirect'] ?? 0) === 1;
    $name = trim((string)($cached['work_name'] ?? ''));
    if ($name === '') {
        $name = catalog_clean_unreal_filename(basename($path));
    }
    return [
        'path' => $path,
        'name' => $name,
        'temp' => false,
        'redirect' => $redirect,
        'source_extension' => $redirect ? strtolower((string)pathinfo($path, PATHINFO_EXTENSION)) : '',
    ];
}

/**
 * @param array{file_size:int,modified_at:int,quick_fingerprint:string}|null $probe
 * @param array{path:string,name:string,temp:bool,redirect:bool,source_extension:string} $work
 * @param array<string,mixed>|null $file
 */
function catalog_source_scan_remember_fingerprint(
    PdoSourceFileFingerprintCache $cache,
    bool $cacheAvailable,
    int $sourceId,
    string $relativePath,
    ?array $probe,
    array $work,
    ?string $md5,
    ?string $sha1,
    ?string $guid,
    ?array $file,
    ?string $method,
    int &$writes,
    int &$errors
): void {
    if (!$cacheAvailable || $probe === null) {
        return;
    }
    try {
        $cache->remember(
            $sourceId,
            $relativePath,
            $probe,
            (string)$work['name'],
            (bool)$work['redirect'],
            $md5,
            $sha1,
            $guid,
            $file,
            $method
        );
        $writes++;
    } catch (Throwable $error) {
        $errors++;
        error_log('[UnrealDB source fingerprint] ' . $error->getMessage());
    }
}

/**
 * Local source scan variant used by the durable worker after PAK containers have
 * been queued separately. It preserves the normal package matching/import logic
 * while excluding .pak files from package MD5/header parsing and staging.
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
        'found', 'redirect_archives', 'redirect_cache_hits', 'matched_md5', 'matched_guid', 'guid_ambiguous',
        'parse_failed', 'unknown', 'locations', 'imported', 'duplicates',
        'import_failed', 'staged_unverified', 'containers_skipped',
        'fingerprint_hits', 'cached_hashes', 'fingerprints_written', 'fingerprint_errors',
    ], 0);
    $unknownSamples = [];
    $parseFailedSamples = [];
    $importSamples = [];
    $files = [];
    $fingerprints = new PdoSourceFileFingerprintCache($db);
    try {
        $fingerprintCacheAvailable = $fingerprints->isAvailable();
    } catch (Throwable $error) {
        $fingerprintCacheAvailable = false;
        $counters['fingerprint_errors']++;
        error_log('[UnrealDB source fingerprint availability] ' . $error->getMessage());
    }

    catalog_source_scan_report($progress, [
        'stage' => 'discovering', 'done' => 0, 'total' => 0, 'percent' => 0,
        'message' => 'Discovering package files in ' . $basePath,
    ] + $counters);

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
        $probe = null;
        $cached = null;
        try {
            if ($fingerprintCacheAvailable) {
                try {
                    $probe = $fingerprints->probe($path);
                    $cached = $fingerprints->lookup($sourceId, $relativePath, $probe);
                } catch (Throwable $fingerprintError) {
                    $counters['fingerprint_errors']++;
                    error_log('[UnrealDB source fingerprint probe] ' . $fingerprintError->getMessage());
                    $probe = null;
                    $cached = null;
                }
            }

            if (is_array($cached)) {
                $cachedFile = $fingerprints->resolveVerifiedFile($cached, (int)$source['game_id']);
                if (is_array($cachedFile)) {
                    $work = catalog_source_scan_cached_work($path, $cached);
                    catalog_source_scan_record_location($upsert, (int)$cachedFile['id'], $sourceId, $relativePath);
                    scanner_record_source_relative_path(
                        $db,
                        (int)$cachedFile['id'],
                        catalog_source_scan_normalized_relative_path($relativePath, $work)
                    );
                    $method = (string)($cachedFile['_cache_match_method'] ?? $cached['match_method'] ?? 'md5');
                    if ($method === 'guid') {
                        $counters['matched_guid']++;
                    } else {
                        $counters['matched_md5']++;
                    }
                    if ((bool)$work['redirect']) {
                        $counters['redirect_cache_hits']++;
                    }
                    $counters['fingerprint_hits']++;
                    $counters['locations']++;
                    catalog_source_scan_remember_fingerprint(
                        $fingerprints,
                        $fingerprintCacheAvailable,
                        $sourceId,
                        $relativePath,
                        $probe,
                        $work,
                        (string)($cached['content_md5'] ?? $cachedFile['md5'] ?? ''),
                        (string)($cached['content_sha1'] ?? $cachedFile['sha1'] ?? ''),
                        (string)($cached['package_guid'] ?? $cachedFile['package_guid'] ?? ''),
                        $cachedFile,
                        $method,
                        $counters['fingerprints_written'],
                        $counters['fingerprint_errors']
                    );
                    continue;
                }
            }

            $work = catalog_source_scan_work_file($path);
            if ($work['redirect']) {
                $counters['redirect_archives']++;
            }

            $cachedMd5 = is_array($cached) ? strtolower(trim((string)($cached['content_md5'] ?? ''))) : '';
            if (preg_match('/^[a-f0-9]{32}$/', $cachedMd5) === 1) {
                $md5 = $cachedMd5;
                $counters['cached_hashes']++;
            } else {
                $md5 = md5_file($work['path']);
            }
            if ($md5 === false || $md5 === '') {
                $counters['unknown']++;
                if (count($unknownSamples) < 50) {
                    $unknownSamples[] = catalog_source_scan_sample($path, $work, 'could not hash file');
                }
                continue;
            }

            $file = catalog_one(
                $db,
                'SELECT id,md5,sha1,package_guid FROM ue_files '
                . 'WHERE game_id=? AND scan_status="verified" AND md5=? LIMIT 1',
                [(int)$source['game_id'], $md5]
            );
            if ($file) {
                catalog_source_scan_record_location($upsert, (int)$file['id'], $sourceId, $relativePath);
                scanner_record_source_relative_path(
                    $db,
                    (int)$file['id'],
                    catalog_source_scan_normalized_relative_path($relativePath, $work)
                );
                $counters['matched_md5']++;
                $counters['locations']++;
                catalog_source_scan_remember_fingerprint(
                    $fingerprints,
                    $fingerprintCacheAvailable,
                    $sourceId,
                    $relativePath,
                    $probe,
                    $work,
                    $md5,
                    (string)($file['sha1'] ?? ''),
                    (string)($file['package_guid'] ?? ''),
                    $file,
                    'md5',
                    $counters['fingerprints_written'],
                    $counters['fingerprint_errors']
                );
                continue;
            }

            $guid = '';
            try {
                $header = catalog_try_read_package_header($config, $profileEngine, $work['path']);
                $guid = catalog_header_guid($header);
            } catch (Throwable $parseError) {
                catalog_source_scan_remember_fingerprint(
                    $fingerprints,
                    $fingerprintCacheAvailable,
                    $sourceId,
                    $relativePath,
                    $probe,
                    $work,
                    $md5,
                    null,
                    null,
                    null,
                    null,
                    $counters['fingerprints_written'],
                    $counters['fingerprint_errors']
                );
                if (!$importUnknown) {
                    $counters['parse_failed']++;
                    if (count($parseFailedSamples) < 50) {
                        $parseFailedSamples[] = catalog_source_scan_sample($path, $work, $parseError->getMessage());
                    }
                    continue;
                }
                try {
                    $result = catalog_source_scan_import_work_file($db, $config, $source, $work, $relativePath, $strictProfile, $userId);
                    catalog_source_scan_record_import_result(
                        $upsert,
                        $sourceId,
                        $relativePath,
                        $result,
                        $counters['imported'],
                        $counters['duplicates'],
                        $counters['locations']
                    );
                    $importedFile = catalog_source_scan_catalog_identity($db, (int)($result[1] ?? 0));
                    catalog_source_scan_remember_fingerprint(
                        $fingerprints,
                        $fingerprintCacheAvailable,
                        $sourceId,
                        $relativePath,
                        $probe,
                        $work,
                        $md5,
                        (string)($importedFile['sha1'] ?? ''),
                        (string)($importedFile['package_guid'] ?? ''),
                        $importedFile,
                        ($result[0] ?? '') === 'duplicate' ? 'duplicate' : 'import',
                        $counters['fingerprints_written'],
                        $counters['fingerprint_errors']
                    );
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
                        $parseFailedSamples[] = catalog_source_scan_sample(
                            $path,
                            $work,
                            'profiled import failed: ' . $scanError->getMessage()
                        );
                    }
                }
                continue;
            }

            if ($guid !== '') {
                $matches = catalog_all(
                    $db,
                    'SELECT id,md5,sha1,package_guid FROM ue_files '
                    . 'WHERE game_id=? AND scan_status="verified" AND package_guid=? ORDER BY id',
                    [(int)$source['game_id'], $guid]
                );
                if (count($matches) === 1) {
                    catalog_source_scan_record_location($upsert, (int)$matches[0]['id'], $sourceId, $relativePath);
                    scanner_record_source_relative_path(
                        $db,
                        (int)$matches[0]['id'],
                        catalog_source_scan_normalized_relative_path($relativePath, $work)
                    );
                    $counters['matched_guid']++;
                    $counters['locations']++;
                    catalog_source_scan_remember_fingerprint(
                        $fingerprints,
                        $fingerprintCacheAvailable,
                        $sourceId,
                        $relativePath,
                        $probe,
                        $work,
                        $md5,
                        null,
                        $guid,
                        $matches[0],
                        'guid',
                        $counters['fingerprints_written'],
                        $counters['fingerprint_errors']
                    );
                    continue;
                }
                if (count($matches) > 1) {
                    $counters['guid_ambiguous']++;
                    catalog_source_scan_remember_fingerprint(
                        $fingerprints,
                        $fingerprintCacheAvailable,
                        $sourceId,
                        $relativePath,
                        $probe,
                        $work,
                        $md5,
                        null,
                        $guid,
                        null,
                        null,
                        $counters['fingerprints_written'],
                        $counters['fingerprint_errors']
                    );
                    if (count($unknownSamples) < 50) {
                        $unknownSamples[] = catalog_source_scan_sample(
                            $path,
                            $work,
                            'GUID matches multiple catalog files: ' . $guid
                        );
                    }
                    continue;
                }
            }

            if (!$importUnknown) {
                $counters['unknown']++;
                catalog_source_scan_remember_fingerprint(
                    $fingerprints,
                    $fingerprintCacheAvailable,
                    $sourceId,
                    $relativePath,
                    $probe,
                    $work,
                    $md5,
                    null,
                    $guid,
                    null,
                    null,
                    $counters['fingerprints_written'],
                    $counters['fingerprint_errors']
                );
                if (count($unknownSamples) < 50) {
                    $unknownSamples[] = catalog_source_scan_sample(
                        $path,
                        $work,
                        $guid === '' ? 'no GUID found' : 'GUID not in catalog: ' . $guid
                    );
                }
                continue;
            }

            try {
                $result = catalog_source_scan_import_work_file($db, $config, $source, $work, $relativePath, $strictProfile, $userId);
                catalog_source_scan_record_import_result(
                    $upsert,
                    $sourceId,
                    $relativePath,
                    $result,
                    $counters['imported'],
                    $counters['duplicates'],
                    $counters['locations']
                );
                $importedFile = catalog_source_scan_catalog_identity($db, (int)($result[1] ?? 0));
                catalog_source_scan_remember_fingerprint(
                    $fingerprints,
                    $fingerprintCacheAvailable,
                    $sourceId,
                    $relativePath,
                    $probe,
                    $work,
                    $md5,
                    (string)($importedFile['sha1'] ?? ''),
                    (string)($importedFile['package_guid'] ?? $guid),
                    $importedFile,
                    ($result[0] ?? '') === 'duplicate' ? 'duplicate' : 'import',
                    $counters['fingerprints_written'],
                    $counters['fingerprint_errors']
                );
                if (count($importSamples) < 50) {
                    $importSamples[] = catalog_source_scan_sample($path, $work, (string)$result[2]);
                }
            } catch (Throwable $scanError) {
                $counters['import_failed']++;
                catalog_source_scan_remember_fingerprint(
                    $fingerprints,
                    $fingerprintCacheAvailable,
                    $sourceId,
                    $relativePath,
                    $probe,
                    $work,
                    $md5,
                    null,
                    $guid,
                    null,
                    null,
                    $counters['fingerprints_written'],
                    $counters['fingerprint_errors']
                );
                try {
                    if (catalog_source_scan_stage_failed($db, $config, $source, $work, $relativePath, $scanError, $userId)) {
                        $counters['staged_unverified']++;
                    }
                } catch (Throwable $stageError) {
                    $scanError = $stageError;
                }
                if (count($unknownSamples) < 50) {
                    $unknownSamples[] = catalog_source_scan_sample(
                        $path,
                        $work,
                        ($guid === '' ? 'no GUID' : 'GUID not in catalog: ' . $guid)
                        . '; profiled import failed: ' . $scanError->getMessage()
                    );
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
        'fingerprint_cache_available' => $fingerprintCacheAvailable,
        'unknown_samples' => $unknownSamples,
        'parse_failed_samples' => $parseFailedSamples,
        'import_samples' => $importSamples,
    ];
}
