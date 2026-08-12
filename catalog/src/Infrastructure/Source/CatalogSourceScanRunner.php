<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Orchestrates the local no-container source scan after source context has been validated.
 * Why: The active source-scan workflow should live under the namespaced architecture rather than a procedural catalog/lib runner.
 * Role: Infrastructure orchestration; discovery, fingerprints, identity/location persistence, profiled import and context loading are delegated collaborators.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Source;

use PDO;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogSourceIdentityQuery;

final class CatalogSourceScanRunner
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogParser.php';
    }

    /**
     * @param callable(array<string,mixed>):void|null $progress
     * @param array<string,mixed> $resume Durable scanner checkpoint from a prior worker attempt.
     * @return array<string,mixed>
     */
    public function run(
        int $sourceId,
        bool $importUnknown,
        bool $strictProfile,
        ?int $userId = null,
        ?callable $progress = null,
        array $resume = []
    ): array {
        $context = (new CatalogSourceScanContextLoader($this->db))->load($sourceId);
        $source = $context['source'];
        $profile = $context['profile'];
        $profileEngine = $context['profile_engine'];
        $basePath = $context['base_path'];

        $counterNames = [
            'found', 'redirect_archives', 'redirect_cache_hits', 'matched_md5', 'matched_guid', 'guid_ambiguous',
            'parse_failed', 'unknown', 'locations', 'imported', 'duplicates',
            'import_failed', 'staged_unverified', 'containers_skipped',
            'fingerprint_hits', 'cached_hashes', 'fingerprints_written', 'fingerprint_errors',
        ];
        $counters = array_fill_keys($counterNames, 0);
        foreach ($counterNames as $name) {
            if (isset($resume[$name]) && is_numeric($resume[$name])) {
                $counters[$name] = max(0, (int)$resume[$name]);
            }
        }
        $unknownSamples = is_array($resume['unknown_samples'] ?? null)
            ? array_slice(array_values($resume['unknown_samples']), 0, 50)
            : [];
        $parseFailedSamples = is_array($resume['parse_failed_samples'] ?? null)
            ? array_slice(array_values($resume['parse_failed_samples']), 0, 50)
            : [];
        $importSamples = is_array($resume['import_samples'] ?? null)
            ? array_slice(array_values($resume['import_samples']), 0, 50)
            : [];
        $lastRelativePath = $this->normalizeRelative((string)($resume['scan_last_relative_path'] ?? ''));

        $fingerprints = new CatalogSourceFingerprintSession($this->db);
        $fingerprints->applyCounters($counters);
        $fingerprintCacheAvailable = $fingerprints->available();
        $identities = new PdoCatalogSourceIdentityQuery($this->db);
        $locations = new CatalogSourceLocationRecorder($this->db);
        $imports = new CatalogSourceProfiledImportService(
            $this->db,
            $this->config,
            $identities,
            $locations,
            $fingerprints
        );

        $discovery = (new CatalogSourceScanDiscovery())->discover(
            $basePath,
            $profile,
            $this->config,
            $counters,
            $progress
        );
        $files = $discovery['files'];
        // Discovery is repeatable setup work; this count describes the current
        // source snapshot and must not be accumulated across process restarts.
        $counters['containers_skipped'] = (int)$discovery['containers_skipped'];

        $total = count($files);
        $startIndex = 0;
        if ($lastRelativePath !== '') {
            while ($startIndex < $total) {
                $candidate = $this->normalizeRelative((string)($files[$startIndex][1] ?? ''));
                if (strnatcasecmp($candidate, $lastRelativePath) > 0) {
                    break;
                }
                $startIndex++;
            }
        }

        CatalogSourceScanProgress::report($progress, [
            'stage' => 'scanning',
            'done' => $startIndex,
            'total' => max(1, $total),
            'percent' => (int)floor(($startIndex * 100) / max(1, $total)),
            'message' => $total > 0
                ? ($startIndex > 0
                    ? 'Resuming source scan after ' . $lastRelativePath . '; ' . $startIndex . '/' . $total . ' path(s) already durable.'
                    : 'Scanning ' . $total . ' package-like files.')
                : 'No package-like files were found.',
            'scan_last_relative_path' => $lastRelativePath,
            'unknown_samples' => $unknownSamples,
            'parse_failed_samples' => $parseFailedSamples,
            'import_samples' => $importSamples,
        ] + $counters);

        for ($index = $startIndex; $index < $total; $index++) {
            [$path, $relativePath] = $files[$index];
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
                            CatalogSourceScanPathPolicy::normalizedRelativePath($relativePath, $work)
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

                $work = CatalogSourceScanWorkFile::prepare($path);
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
                        $unknownSamples[] = CatalogSourceScanPathPolicy::sample($path, $work, 'could not hash file');
                    }
                    continue;
                }

                $file = $identities->findVerifiedByMd5((int)$source['game_id'], $md5);
                if (is_array($file)) {
                    $locations->recordMatched(
                        (int)$file['id'],
                        $sourceId,
                        $relativePath,
                        CatalogSourceScanPathPolicy::normalizedRelativePath($relativePath, $work)
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
                    $header = \catalog_try_read_package_header($this->config, $profileEngine, $work['path']);
                    $guid = \catalog_header_guid($header);
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
                            $parseFailedSamples[] = CatalogSourceScanPathPolicy::sample(
                                $path,
                                $work,
                                $parseError->getMessage()
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
                            $importSamples[] = CatalogSourceScanPathPolicy::sample(
                                $path,
                                $work,
                                (string)($result[2] ?? '')
                            );
                        }
                    } else {
                        $counters['import_failed']++;
                        if ($attempt['staged']) {
                            $counters['staged_unverified']++;
                        }
                        $scanError = $attempt['error'];
                        if (count($parseFailedSamples) < 50) {
                            $parseFailedSamples[] = CatalogSourceScanPathPolicy::sample(
                                $path,
                                $work,
                                'profiled import failed: '
                                . ($scanError instanceof Throwable ? $scanError->getMessage() : 'Unknown import error')
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
                            CatalogSourceScanPathPolicy::normalizedRelativePath($relativePath, $work)
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
                            $unknownSamples[] = CatalogSourceScanPathPolicy::sample(
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
                        $unknownSamples[] = CatalogSourceScanPathPolicy::sample(
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
                        $importSamples[] = CatalogSourceScanPathPolicy::sample(
                            $path,
                            $work,
                            (string)($result[2] ?? '')
                        );
                    }
                } else {
                    $counters['import_failed']++;
                    if ($attempt['staged']) {
                        $counters['staged_unverified']++;
                    }
                    $scanError = $attempt['error'];
                    if (count($unknownSamples) < 50) {
                        $unknownSamples[] = CatalogSourceScanPathPolicy::sample(
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
                    CatalogSourceScanWorkFile::cleanup($work);
                }
                $fingerprints->applyCounters($counters);
                $lastRelativePath = $this->normalizeRelative($relativePath);
                $done = $index + 1;
                CatalogSourceScanProgress::report($progress, [
                    'stage' => 'scanning',
                    'done' => $done,
                    'total' => max(1, $total),
                    'percent' => (int)floor(($done * 100) / max(1, $total)),
                    'message' => 'Processed ' . $done . '/' . $total . ': ' . basename($path),
                    'scan_last_relative_path' => $lastRelativePath,
                    'unknown_samples' => $unknownSamples,
                    'parse_failed_samples' => $parseFailedSamples,
                    'import_samples' => $importSamples,
                ] + $counters);
            }
        }

        $fingerprints->applyCounters($counters);
        CatalogSourceScanProgress::report($progress, [
            'stage' => 'complete',
            'done' => max(1, $total),
            'total' => max(1, $total),
            'percent' => 100,
            'message' => 'Source scan complete.',
            'scan_last_relative_path' => $lastRelativePath,
            'unknown_samples' => $unknownSamples,
            'parse_failed_samples' => $parseFailedSamples,
            'import_samples' => $importSamples,
        ] + $counters);

        return $counters + [
            'source' => $source,
            'fingerprint_cache_available' => $fingerprintCacheAvailable,
            'unknown_samples' => $unknownSamples,
            'parse_failed_samples' => $parseFailedSamples,
            'import_samples' => $importSamples,
            'scan_last_relative_path' => $lastRelativePath,
        ];
    }

    private function normalizeRelative(string $path): string
    {
        return trim(str_replace('\\', '/', $path), '/');
    }
}
