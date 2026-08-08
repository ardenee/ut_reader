<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Provides the compatibility entry point for the local source scan after PAK containers are queued separately.
 * Why: Package matching semantics remain stable while discovery, fingerprinting, identity persistence and profiled import handling move behind namespaced collaborators.
 * Role: Transitional source-scan orchestration; package parsing/redirect helpers remain in CatalogSourceScan.php during staged cleanup.
 */
declare(strict_types=1);

require_once __DIR__ . '/CatalogSourceScan.php';

use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogSourceIdentityQuery;
use UnrealDb\Catalog\Infrastructure\Source\CatalogSourceFingerprintSession;
use UnrealDb\Catalog\Infrastructure\Source\CatalogSourceLocationRecorder;
use UnrealDb\Catalog\Infrastructure\Source\CatalogSourceProfiledImportService;
use UnrealDb\Catalog\Infrastructure\Source\CatalogSourceScanDiscovery;

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

    $fingerprints = new CatalogSourceFingerprintSession($db);
    $fingerprints->applyCounters($counters);
    $fingerprintCacheAvailable = $fingerprints->available();
    $identities = new PdoCatalogSourceIdentityQuery($db);
    $locations = new CatalogSourceLocationRecorder($db);
    $imports = new CatalogSourceProfiledImportService($db, $config, $identities, $locations, $fingerprints);

    $discovery = (new CatalogSourceScanDiscovery())->discover(
        $basePath,
        $profile,
        $config,
        $counters,
        $progress
    );
    $files = $discovery['files'];
    $counters['containers_skipped'] = (int)$discovery['containers_skipped'];

    $total = count($files);
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
            $fingerprint = $fingerprints->probeAndLookup($path, $sourceId, $relativePath);
            $probe = $fingerprint['probe'];
            $cached = $fingerprint['cached'];

            if (is_array($cached)) {
                $cachedFile = $fingerprints->resolveVerifiedFile($cached, (int)$source['game_id']);
                if (is_array($cachedFile)) {
                    $work = $fingerprints->cachedWork($path, $cached);
                    $locations->recordMatched(
                        (int)$cachedFile['id'],
                        $sourceId,
                        $relativePath,
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
                    $fingerprints->remember(
                        $sourceId,
                        $relativePath,
                        $probe,
                        $work,
                        (string)($cached['content_md5'] ?? $cachedFile['md5'] ?? ''),
                        (string)($cached['content_sha1'] ?? $cachedFile['sha1'] ?? ''),
                        (string)($cached['package_guid'] ?? $cachedFile['package_guid'] ?? ''),
                        $cachedFile,
                        $method
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

            $file = $identities->findVerifiedByMd5((int)$source['game_id'], $md5);
            if (is_array($file)) {
                $locations->recordMatched(
                    (int)$file['id'],
                    $sourceId,
                    $relativePath,
                    catalog_source_scan_normalized_relative_path($relativePath, $work)
                );
                $counters['matched_md5']++;
                $counters['locations']++;
                $fingerprints->remember(
                    $sourceId,
                    $relativePath,
                    $probe,
                    $work,
                    $md5,
                    (string)($file['sha1'] ?? ''),
                    (string)($file['package_guid'] ?? ''),
                    $file,
                    'md5'
                );
                continue;
            }

            $guid = '';
            try {
                $header = catalog_try_read_package_header($config, $profileEngine, $work['path']);
                $guid = catalog_header_guid($header);
            } catch (Throwable $parseError) {
                $fingerprints->remember(
                    $sourceId,
                    $relativePath,
                    $probe,
                    $work,
                    $md5,
                    null,
                    null,
                    null,
                    null
                );
                if (!$importUnknown) {
                    $counters['parse_failed']++;
                    if (count($parseFailedSamples) < 50) {
                        $parseFailedSamples[] = catalog_source_scan_sample($path, $work, $parseError->getMessage());
                    }
                    continue;
                }

                $attempt = $imports->attempt(
                    $source,
                    $work,
                    $relativePath,
                    $strictProfile,
                    $userId,
                    $sourceId,
                    $probe,
                    $md5,
                    '',
                    false
                );
                if ($attempt['ok']) {
                    $accounting = $attempt['accounting'];
                    $counters['imported'] += $accounting['imported'];
                    $counters['duplicates'] += $accounting['duplicates'];
                    $counters['locations'] += $accounting['locations'];
                    $result = $attempt['result'];
                    if (is_array($result) && count($importSamples) < 50) {
                        $importSamples[] = catalog_source_scan_sample($path, $work, (string)($result[2] ?? ''));
                    }
                } else {
                    $counters['import_failed']++;
                    if ($attempt['staged']) {
                        $counters['staged_unverified']++;
                    }
                    $scanError = $attempt['error'];
                    if (count($parseFailedSamples) < 50) {
                        $parseFailedSamples[] = catalog_source_scan_sample(
                            $path,
                            $work,
                            'profiled import failed: ' . ($scanError instanceof Throwable ? $scanError->getMessage() : 'Unknown import error')
                        );
                    }
                }
                continue;
            }

            if ($guid !== '') {
                $matches = $identities->findVerifiedByGuid((int)$source['game_id'], $guid);
                if (count($matches) === 1) {
                    $locations->recordMatched(
                        (int)$matches[0]['id'],
                        $sourceId,
                        $relativePath,
                        catalog_source_scan_normalized_relative_path($relativePath, $work)
                    );
                    $counters['matched_guid']++;
                    $counters['locations']++;
                    $fingerprints->remember(
                        $sourceId,
                        $relativePath,
                        $probe,
                        $work,
                        $md5,
                        null,
                        $guid,
                        $matches[0],
                        'guid'
                    );
                    continue;
                }
                if (count($matches) > 1) {
                    $counters['guid_ambiguous']++;
                    $fingerprints->remember(
                        $sourceId,
                        $relativePath,
                        $probe,
                        $work,
                        $md5,
                        null,
                        $guid,
                        null,
                        null
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
                $fingerprints->remember(
                    $sourceId,
                    $relativePath,
                    $probe,
                    $work,
                    $md5,
                    null,
                    $guid,
                    null,
                    null
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

            $attempt = $imports->attempt(
                $source,
                $work,
                $relativePath,
                $strictProfile,
                $userId,
                $sourceId,
                $probe,
                $md5,
                $guid,
                true
            );
            if ($attempt['ok']) {
                $accounting = $attempt['accounting'];
                $counters['imported'] += $accounting['imported'];
                $counters['duplicates'] += $accounting['duplicates'];
                $counters['locations'] += $accounting['locations'];
                $result = $attempt['result'];
                if (is_array($result) && count($importSamples) < 50) {
                    $importSamples[] = catalog_source_scan_sample($path, $work, (string)($result[2] ?? ''));
                }
            } else {
                $counters['import_failed']++;
                if ($attempt['staged']) {
                    $counters['staged_unverified']++;
                }
                $scanError = $attempt['error'];
                if (count($unknownSamples) < 50) {
                    $unknownSamples[] = catalog_source_scan_sample(
                        $path,
                        $work,
                        ($guid === '' ? 'no GUID' : 'GUID not in catalog: ' . $guid)
                        . '; profiled import failed: '
                        . ($scanError instanceof Throwable ? $scanError->getMessage() : 'Unknown import error')
                    );
                }
            }
        } catch (Throwable $error) {
            $counters['parse_failed']++;
            if ($importUnknown && is_array($work)) {
                try {
                    if ($imports->stageFailure($source, $work, $relativePath, $error, $userId)) {
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
            $fingerprints->applyCounters($counters);
            $done = $index + 1;
            catalog_source_scan_report($progress, [
                'stage' => 'scanning', 'done' => $done, 'total' => max(1, $total),
                'percent' => (int)floor(($done * 100) / max(1, $total)),
                'message' => 'Processed ' . $done . '/' . $total . ': ' . basename($path),
            ] + $counters);
        }
    }

    $fingerprints->applyCounters($counters);
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
