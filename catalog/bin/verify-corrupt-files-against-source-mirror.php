#!/usr/bin/env php
<?php
/**
 * Read-only comparison of retained verified packages against their original
 * source-relative path under an Unreal Archive/local mirror.
 *
 * Resolves nested ZIP/7z/RAR/UMOD-family chains using the production archive
 * extractor, compares exact bytes with the catalogued size/MD5/SHA1, and runs
 * the current production package inspector against the source copy.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/operational.php';

use UnrealDb\Catalog\Infrastructure\Archive\CatalogArchiveExtractor;
use UnrealDb\Catalog\Infrastructure\Import\CatalogInvalidPackageException;
use UnrealDb\Catalog\Infrastructure\Import\CatalogVerifiedPackageInspector;
use UnrealDb\Catalog\Domain\Jobs\JobType;

/** @return array<string,string|bool> */
function mirror_options(array $argv): array
{
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $pair = explode('=', substr($arg, 2), 2);
        $out[$pair[0]] = $pair[1] ?? true;
    }
    return $out;
}

/** @return list<int> */
function mirror_ids(string $value): array
{
    $ids = [];
    foreach (preg_split('/[,\s]+/', trim($value)) ?: [] as $part) {
        $id = (int)$part;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    return array_values($ids);
}

function mirror_rel(string $value): string
{
    $value = trim(str_replace('\\', '/', $value), '/');
    $parts = [];
    foreach (explode('/', $value) as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            throw new RuntimeException('Source-relative path contains parent traversal.');
        }
        $parts[] = $part;
    }
    return implode('/', $parts);
}

/** @return list<string> */
function mirror_parts(string $value): array
{
    $value = mirror_rel($value);
    return $value === '' ? [] : explode('/', $value);
}

/**
 * @return array{path:string,temporary:bool,chain:list<string>,cleanup:list<string>}
 */
function mirror_resolve_source(
    string $sourceRoot,
    string $relative,
    CatalogArchiveExtractor $extractor,
    int $maxBytes
): array {
    $parts = mirror_parts($relative);
    if ($parts === []) {
        throw new RuntimeException('Source-relative path is empty.');
    }

    $cleanup = [];
    $chain = [];
    $cursor = rtrim($sourceRoot, "\\/");

    try {
        for ($i = 0, $count = count($parts); $i < $count; $i++) {
            $candidate = $cursor . DIRECTORY_SEPARATOR . $parts[$i];
            if (is_dir($candidate)) {
                $cursor = $candidate;
                continue;
            }
            if (!is_file($candidate)) {
                throw new RuntimeException(
                    'Mirror path component was not found: ' . implode('/', array_slice($parts, 0, $i + 1))
                );
            }

            $chain[] = $candidate;
            if ($i === $count - 1) {
                return [
                    'path' => realpath($candidate) ?: $candidate,
                    'temporary' => false,
                    'chain' => $chain,
                    'cleanup' => $cleanup,
                ];
            }
            if (!CatalogArchiveExtractor::isArchiveName($parts[$i])) {
                throw new RuntimeException(
                    'Mirror path reached a non-archive file before the final member: ' . $parts[$i]
                );
            }

            $archivePath = $candidate;
            $archiveName = $parts[$i];
            $remaining = array_slice($parts, $i + 1);

            while (true) {
                $entries = $extractor->entries($archivePath, $archiveName);
                $remainingPath = strtolower(mirror_rel(implode('/', $remaining)));
                $exact = null;
                $nested = null;
                $nestedParts = 0;

                foreach ($entries as $entry) {
                    if (!is_array($entry) || empty($entry['safe'])) {
                        continue;
                    }
                    $entryPath = mirror_rel((string)($entry['path'] ?? ''));
                    if ($entryPath === '') {
                        continue;
                    }
                    $entryLower = strtolower($entryPath);
                    if ($entryLower === $remainingPath) {
                        $exact = $entry;
                        break;
                    }

                    if (!CatalogArchiveExtractor::isArchiveName(basename($entryPath))) {
                        continue;
                    }
                    $entryParts = mirror_parts($entryPath);
                    if (count($entryParts) >= count($remaining)) {
                        continue;
                    }
                    $prefix = strtolower(implode('/', array_slice($remaining, 0, count($entryParts))));
                    if ($prefix === $entryLower && count($entryParts) > $nestedParts) {
                        $nested = $entry;
                        $nestedParts = count($entryParts);
                    }
                }

                if (is_array($exact)) {
                    $temporary = $extractor->extractToTemp(
                        $archivePath,
                        $archiveName,
                        $exact,
                        max($maxBytes, (int)($exact['size'] ?? 0), 1)
                    );
                    $cleanup[] = $temporary;
                    $chain[] = $archiveName . '!/' . (string)$exact['path'];
                    return [
                        'path' => $temporary,
                        'temporary' => true,
                        'chain' => $chain,
                        'cleanup' => $cleanup,
                    ];
                }

                if (!is_array($nested)) {
                    throw new RuntimeException(
                        'Archive does not contain the recorded source path: '
                        . $archiveName . '!/' . implode('/', $remaining)
                    );
                }

                $temporary = $extractor->extractToTemp(
                    $archivePath,
                    $archiveName,
                    $nested,
                    max($maxBytes, (int)($nested['size'] ?? 0), 1)
                );
                $cleanup[] = $temporary;
                $chain[] = $archiveName . '!/' . (string)$nested['path'];
                $archivePath = $temporary;
                $archiveName = basename((string)$nested['path']);
                $remaining = array_slice($remaining, $nestedParts);
                if ($remaining === []) {
                    return [
                        'path' => $temporary,
                        'temporary' => true,
                        'chain' => $chain,
                        'cleanup' => $cleanup,
                    ];
                }
            }
        }

        throw new RuntimeException('Source path could not be resolved.');
    } catch (Throwable $error) {
        foreach (array_reverse($cleanup) as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        throw $error;
    }
}

/** @param list<string> $paths */
function mirror_cleanup(array $paths): void
{
    foreach (array_reverse($paths) as $path) {
        if (is_file($path)) {
            @unlink($path);
        }
    }
}

try {
    $options = mirror_options($argv);
    $sourceRootInput = trim((string)($options['source-root'] ?? ''));
    if ($sourceRootInput === '') {
        throw new InvalidArgumentException('--source-root is required.');
    }
    $sourceRoot = realpath($sourceRootInput);
    if ($sourceRoot === false || !is_dir($sourceRoot)) {
        throw new RuntimeException('Source mirror root does not exist: ' . $sourceRootInput);
    }

    $application = catalog_operational_application();
    $db = $application->db;
    $config = $application->config;
    $fileIds = mirror_ids((string)($options['file-ids'] ?? ''));
    $gameId = max(0, (int)($options['game-id'] ?? 0));

    $rows = [];
    if ($fileIds !== []) {
        $sql = implode(',', array_fill(0, count($fileIds), '?'));
        $statement = $db->prepare(
            'SELECT f.id,f.game_id,f.original_name,f.source_relative_path,f.relative_path,f.file_size,'
            . 'f.md5,f.sha1,f.package_version,f.licensee_version,g.name game_name '
            . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
            . 'WHERE f.id IN (' . $sql . ') ORDER BY f.id'
        );
        $statement->execute($fileIds);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } elseif ($gameId > 0) {
        // Current actionable Full Sync file failures only. This keeps the default
        // game-scoped diagnostic bounded instead of walking historical jobs.
        $statement = $db->prepare(
            'SELECT DISTINCT f.id,f.game_id,f.original_name,f.source_relative_path,f.relative_path,f.file_size,'
            . 'f.md5,f.sha1,f.package_version,f.licensee_version,g.name game_name '
            . 'FROM ue_background_jobs j '
            . 'JOIN ue_files f ON f.id=CAST(JSON_UNQUOTE(JSON_EXTRACT(j.payload_json,"$.file_id")) AS UNSIGNED) '
            . 'JOIN ue_games g ON g.id=f.game_id '
            . 'WHERE j.job_type=? AND j.status IN ("failed","dead_letter") AND f.game_id=? '
            . 'ORDER BY f.id'
        );
        $statement->execute([JobType::FULL_SYNC_FILE, $gameId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } else {
        throw new InvalidArgumentException('Supply --file-ids=... or --game-id=...');
    }

    $extractor = new CatalogArchiveExtractor($config);
    $inspector = new CatalogVerifiedPackageInspector($db, $config);
    $maxBytes = max(1, (int)($config['max_upload_bytes'] ?? 2147483648));
    $results = [];

    foreach ($rows as $file) {
        $fileId = (int)$file['id'];
        $relative = mirror_rel((string)($file['source_relative_path'] ?? ''));
        $result = [
            'file_id' => $fileId,
            'game_id' => (int)$file['game_id'],
            'game' => (string)$file['game_name'],
            'file_name' => (string)$file['original_name'],
            'source_relative_path' => $relative,
            'catalog_size' => (int)$file['file_size'],
            'catalog_md5' => strtolower((string)$file['md5']),
            'catalog_sha1' => strtolower((string)$file['sha1']),
            'package_version' => (int)$file['package_version'],
            'licensee_version' => (int)$file['licensee_version'],
            'source_resolved' => false,
            'source_exact_catalog_bytes' => false,
            'source_valid_now' => false,
        ];

        $resolved = null;
        try {
            $resolved = mirror_resolve_source($sourceRoot, $relative, $extractor, $maxBytes);
            $sourcePath = $resolved['path'];
            $size = filesize($sourcePath);
            $md5 = md5_file($sourcePath);
            $sha1 = sha1_file($sourcePath);
            $result['source_resolved'] = true;
            $result['source_chain'] = $resolved['chain'];
            $result['source_size'] = $size === false ? -1 : (int)$size;
            $result['source_md5'] = is_string($md5) ? strtolower($md5) : '';
            $result['source_sha1'] = is_string($sha1) ? strtolower($sha1) : '';
            $result['source_exact_catalog_bytes'] =
                (int)$result['source_size'] === (int)$file['file_size']
                && $result['source_md5'] === strtolower((string)$file['md5'])
                && $result['source_sha1'] === strtolower((string)$file['sha1']);

            try {
                $inspection = $inspector->inspect(
                    (int)$file['game_id'],
                    $sourcePath,
                    (string)$file['original_name'],
                    false,
                    $relative
                );
                $result['source_valid_now'] = true;
                $result['source_reader_engine'] = (string)$inspection->readerEngine;
            } catch (CatalogInvalidPackageException $invalid) {
                $result['source_validation_error'] = trim($invalid->getMessage());
                $result['source_validation_code'] = $invalid->validationCode();
                $result['source_validation_arguments'] = $invalid->validationArguments();
            } catch (Throwable $validationError) {
                $result['source_validation_error'] = get_class($validationError) . ': '
                    . trim($validationError->getMessage());
            }
        } catch (Throwable $sourceError) {
            $result['source_error'] = get_class($sourceError) . ': ' . trim($sourceError->getMessage());
        } finally {
            if (is_array($resolved)) {
                mirror_cleanup((array)($resolved['cleanup'] ?? []));
            }
        }

        $results[] = $result;
        $label = !empty($result['source_exact_catalog_bytes'])
            ? 'same-bytes'
            : (!empty($result['source_resolved']) ? 'different-bytes' : 'unresolved');
        $valid = !empty($result['source_valid_now']) ? 'valid' : 'invalid';
        fwrite(STDERR, '[mirror] #' . $fileId . ' ' . (string)$file['original_name']
            . ' -> ' . $label . ', ' . $valid . "\n");
    }

    $sameInvalid = 0;
    $differentValid = 0;
    $differentInvalid = 0;
    $unresolved = 0;
    foreach ($results as $result) {
        if (empty($result['source_resolved'])) {
            $unresolved++;
        } elseif (!empty($result['source_exact_catalog_bytes']) && empty($result['source_valid_now'])) {
            $sameInvalid++;
        } elseif (empty($result['source_exact_catalog_bytes']) && !empty($result['source_valid_now'])) {
            $differentValid++;
        } elseif (empty($result['source_exact_catalog_bytes'])) {
            $differentInvalid++;
        }
    }

    echo json_encode([
        'ok' => true,
        'source_root' => $sourceRoot,
        'checked' => count($results),
        'same_invalid_bytes_as_catalog' => $sameInvalid,
        'different_valid_source_bytes' => $differentValid,
        'different_invalid_source_bytes' => $differentInvalid,
        'unresolved' => $unresolved,
        'results' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
