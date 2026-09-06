#!/usr/bin/env php
<?php
/**
 * Recover verified packages whose catalog rows still exist but whose canonical
 * physical storage files are missing.
 *
 * The database is authoritative for identity. Recovery never inserts, updates,
 * or deletes catalog rows. Candidate source bytes must match file size, MD5 and
 * SHA1 exactly before they may be copied back to the existing canonical path.
 *
 * Dry run is the default. Apply mode also refuses to run while background jobs
 * are active and refuses partial recovery unless --allow-partial is explicit.
 *
 * Examples:
 *   php catalog/bin/recover-missing-verified-packages.php --search-root="L:\\dl"
 *   php catalog/bin/recover-missing-verified-packages.php --search-root="L:\\dl" --game-ids=3,5
 *   php catalog/bin/recover-missing-verified-packages.php --search-root="L:\\dl" --apply
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/lib/CatalogSupport.php';

$options = getopt('', [
    'apply',
    'allow-partial',
    'force-running-jobs',
    'search-root:',
    'game-ids:',
    'file-ids:',
]);

$apply = array_key_exists('apply', $options);
$allowPartial = array_key_exists('allow-partial', $options);
$forceRunningJobs = array_key_exists('force-running-jobs', $options);

/** @return list<int> */
function recovery_parse_positive_ids(string $raw): array
{
    $ids = [];
    foreach (preg_split('/[\s,;]+/', trim($raw)) ?: [] as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
    ksort($ids, SORT_NUMERIC);
    return array_values($ids);
}

/** @return list<string> */
function recovery_search_roots(array $argv, mixed $getoptValue): array
{
    $roots = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (str_starts_with((string)$argument, '--search-root=')) {
            $roots[] = substr((string)$argument, strlen('--search-root='));
        }
    }
    if ($roots === [] && is_string($getoptValue)) {
        $roots[] = $getoptValue;
    } elseif ($roots === [] && is_array($getoptValue)) {
        foreach ($getoptValue as $value) {
            if (is_string($value)) {
                $roots[] = $value;
            }
        }
    }

    $normalized = [];
    foreach ($roots as $root) {
        $root = rtrim(trim($root, " \t\n\r\0\x0B\"'"), "\\/");
        if ($root === '' || !is_dir($root)) {
            throw new RuntimeException('Recovery search root does not exist or is not a directory: ' . $root);
        }
        $real = realpath($root);
        $key = strtolower(str_replace('\\', '/', $real !== false ? $real : $root));
        $normalized[$key] = $real !== false ? $real : $root;
    }
    return array_values($normalized);
}

function recovery_normalize_relative(string $path): string
{
    $path = trim(str_replace('\\', '/', $path));
    $path = ltrim($path, '/');
    while (str_contains($path, '//')) {
        $path = str_replace('//', '/', $path);
    }
    return $path;
}

function recovery_safe_relative(string $path): bool
{
    if ($path === '' || str_contains($path, "\0")) {
        return false;
    }
    if (preg_match('~^[A-Za-z]:/~', $path) === 1 || str_starts_with($path, '//')) {
        return false;
    }
    foreach (explode('/', $path) as $part) {
        if ($part === '..') {
            return false;
        }
    }
    return true;
}

/** @param array<string,mixed> $file */
function recovery_destination_path(string $storageRoot, array $file): string
{
    $relative = recovery_normalize_relative((string)($file['relative_path'] ?? ''));
    if (!recovery_safe_relative($relative)) {
        throw new RuntimeException(
            'Verified file #' . (int)($file['id'] ?? 0) . ' has an invalid relative_path: ' . $relative
        );
    }
    if (!str_starts_with(strtolower($relative), 'storage/')) {
        throw new RuntimeException(
            'Verified file #' . (int)($file['id'] ?? 0)
            . ' does not use a canonical storage/ relative_path: ' . $relative
        );
    }
    $withinStorage = substr($relative, strlen('storage/'));
    if (!recovery_safe_relative($withinStorage)) {
        throw new RuntimeException('Invalid path inside catalog storage for file #' . (int)($file['id'] ?? 0) . '.');
    }
    return rtrim($storageRoot, "\\/") . DIRECTORY_SEPARATOR
        . str_replace('/', DIRECTORY_SEPARATOR, $withinStorage);
}

/** @param array<string,mixed> $file @return array{ok:bool,error:string} */
function recovery_verify_exact_file(string $path, array $file): array
{
    if (!is_file($path)) {
        return ['ok' => false, 'error' => 'not_a_file'];
    }

    $expectedSize = max(0, (int)($file['file_size'] ?? 0));
    $expectedMd5 = strtolower(trim((string)($file['md5'] ?? '')));
    $expectedSha1 = strtolower(trim((string)($file['sha1'] ?? '')));
    if ($expectedSize < 1 || preg_match('/^[0-9a-f]{32}$/', $expectedMd5) !== 1
        || preg_match('/^[0-9a-f]{40}$/', $expectedSha1) !== 1) {
        return ['ok' => false, 'error' => 'catalog_identity_incomplete'];
    }

    clearstatcache(true, $path);
    $size = @filesize($path);
    if (!is_int($size) || $size !== $expectedSize) {
        return ['ok' => false, 'error' => 'size_mismatch'];
    }

    $md5 = @md5_file($path);
    if (!is_string($md5) || strtolower($md5) !== $expectedMd5) {
        return ['ok' => false, 'error' => 'md5_mismatch'];
    }

    $sha1 = @sha1_file($path);
    if (!is_string($sha1) || strtolower($sha1) !== $expectedSha1) {
        return ['ok' => false, 'error' => 'sha1_mismatch'];
    }

    return ['ok' => true, 'error' => ''];
}

/** @param array<string,mixed> $file */
function recovery_candidate_key(array $file): string
{
    return strtolower((string)($file['original_name'] ?? '')) . "\0" . (string)max(0, (int)($file['file_size'] ?? 0));
}

/** @return list<string> */
function recovery_source_relatives(PDO $db, array $file): array
{
    $paths = [];
    $primary = recovery_normalize_relative((string)($file['source_relative_path'] ?? ''));
    if ($primary !== '' && recovery_safe_relative($primary)) {
        $paths[strtolower($primary)] = $primary;
    }

    $statement = $db->prepare(
        'SELECT source_relative_path FROM ue_file_locations WHERE file_id=? ORDER BY id'
    );
    $statement->execute([(int)$file['id']]);
    while (($value = $statement->fetchColumn()) !== false) {
        $relative = recovery_normalize_relative((string)$value);
        if ($relative !== '' && recovery_safe_relative($relative)) {
            $paths[strtolower($relative)] = $relative;
        }
    }
    return array_values($paths);
}

/** @param array<string,mixed> $file @param list<string> $sourceRelatives */
function recovery_try_direct_candidates(array $file, array $sourceRelatives, array $searchRoots): ?array
{
    foreach ($searchRoots as $searchRoot) {
        foreach ($sourceRelatives as $relative) {
            $candidate = rtrim($searchRoot, "\\/") . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $verified = recovery_verify_exact_file($candidate, $file);
            if ($verified['ok']) {
                return [
                    'path' => realpath($candidate) ?: $candidate,
                    'kind' => 'recorded_source_path',
                ];
            }
        }
    }
    return null;
}

/** @param array<int,array<string,mixed>> $missing @return array<int,array{path:string,kind:string}> */
function recovery_recursive_find(array $missing, array $searchRoots): array
{
    $wanted = [];
    foreach ($missing as $index => $file) {
        $wanted[recovery_candidate_key($file)][] = $index;
    }
    $found = [];
    $inspected = 0;

    foreach ($searchRoots as $searchRoot) {
        if (count($found) >= count($missing)) {
            break;
        }
        fwrite(STDERR, '[scan] searching ' . $searchRoot . ' for ' . (count($missing) - count($found)) . " unresolved package(s)\n");
        $directory = new RecursiveDirectoryIterator($searchRoot, FilesystemIterator::SKIP_DOTS);
        $iterator = new RecursiveIteratorIterator(
            $directory,
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo || !$entry->isFile()) {
                continue;
            }
            $inspected++;
            if (($inspected % 100000) === 0) {
                fwrite(STDERR, '[scan] inspected ' . number_format($inspected) . " files\n");
            }

            $key = strtolower($entry->getFilename()) . "\0" . (string)$entry->getSize();
            foreach ($wanted[$key] ?? [] as $index) {
                if (isset($found[$index])) {
                    continue;
                }
                $path = $entry->getPathname();
                $verified = recovery_verify_exact_file($path, $missing[$index]);
                if (!$verified['ok']) {
                    continue;
                }
                $found[$index] = [
                    'path' => realpath($path) ?: $path,
                    'kind' => 'recursive_search',
                ];
                fwrite(
                    STDERR,
                    '[scan] exact #' . (int)$missing[$index]['id'] . ' '
                    . (string)$missing[$index]['original_name'] . ' -> ' . $path . "\n"
                );
            }
            if (count($found) >= count($missing)) {
                break;
            }
        }
    }

    fwrite(
        STDERR,
        '[scan] complete: exact=' . count($found)
        . ', unresolved=' . (count($missing) - count($found))
        . ', files_inspected=' . number_format($inspected) . "\n"
    );
    return $found;
}

/** @param array<string,mixed> $file */
function recovery_atomic_restore(string $source, string $destination, array $file): void
{
    $before = recovery_verify_exact_file($source, $file);
    if (!$before['ok']) {
        throw new RuntimeException(
            'Recovery source changed or no longer matches file #' . (int)$file['id'] . ': ' . $source
        );
    }

    $directory = dirname($destination);
    if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Could not create canonical verified directory: ' . $directory);
    }

    if (is_file($destination)) {
        $existing = recovery_verify_exact_file($destination, $file);
        if ($existing['ok']) {
            return;
        }
        throw new RuntimeException(
            'Refusing to overwrite a non-matching file already present at canonical path: ' . $destination
        );
    }

    $temporary = $destination . '.restore-' . bin2hex(random_bytes(8)) . '.tmp';
    if (!@copy($source, $temporary)) {
        throw new RuntimeException('Could not copy recovery source into canonical storage staging path.');
    }
    try {
        $mtime = @filemtime($source);
        if (is_int($mtime) && $mtime > 0) {
            @touch($temporary, $mtime);
        }
        $copied = recovery_verify_exact_file($temporary, $file);
        if (!$copied['ok']) {
            throw new RuntimeException(
                'Copied recovery file failed exact verification for file #' . (int)$file['id']
                . ': ' . $copied['error']
            );
        }

        if (is_file($destination)) {
            $existing = recovery_verify_exact_file($destination, $file);
            if (!$existing['ok']) {
                throw new RuntimeException('Canonical destination appeared during recovery with different bytes.');
            }
            return;
        }
        if (!@rename($temporary, $destination)) {
            throw new RuntimeException('Could not atomically publish restored package: ' . $destination);
        }
        $final = recovery_verify_exact_file($destination, $file);
        if (!$final['ok']) {
            throw new RuntimeException('Published recovery file failed final exact verification: ' . $destination);
        }
    } finally {
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }
}

try {
    $config = catalog_config();
    $db = catalog_db($config);
    $storageRoot = rtrim(trim((string)($config['storage_path'] ?? '')), "\\/");
    if ($storageRoot === '' || !is_dir($storageRoot)) {
        throw new RuntimeException('Configured catalog storage_path is unavailable: ' . $storageRoot);
    }

    $gameIds = recovery_parse_positive_ids((string)($options['game-ids'] ?? ''));
    $fileIds = recovery_parse_positive_ids((string)($options['file-ids'] ?? ''));

    $where = ['f.scan_status="verified"'];
    $args = [];
    if ($gameIds !== []) {
        $where[] = 'f.game_id IN (' . implode(',', array_fill(0, count($gameIds), '?')) . ')';
        array_push($args, ...$gameIds);
    }
    if ($fileIds !== []) {
        $where[] = 'f.id IN (' . implode(',', array_fill(0, count($fileIds), '?')) . ')';
        array_push($args, ...$fileIds);
    }

    $statement = $db->prepare(
        'SELECT f.id,f.game_id,g.name game_name,g.slug game_slug,f.package_name,f.original_name,'
        . 'f.source_relative_path,f.relative_path,f.file_size,f.md5,f.sha1 '
        . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
        . 'WHERE ' . implode(' AND ', $where) . ' ORDER BY f.game_id,f.id'
    );
    $statement->execute($args);

    $checked = 0;
    $missing = [];
    while (($file = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
        $checked++;
        $destination = recovery_destination_path($storageRoot, $file);
        $file['destination'] = $destination;
        // Recovery discovery is intentionally existence-only for healthy rows.
        // Hashing every verified package would turn a small recovery into a
        // full multi-terabyte integrity pass. Exact size/MD5/SHA1 verification
        // is performed only for missing-row recovery candidates and copied bytes.
        if (!is_file($destination)) {
            $missing[] = $file;
        }
    }

    if ($missing === []) {
        echo json_encode([
            'ok' => true,
            'apply' => $apply,
            'verified_rows_checked' => $checked,
            'missing_physical_packages' => 0,
            'exact_sources_found' => 0,
            'unresolved' => 0,
            'restored_now' => 0,
            'message' => 'All selected verified catalog rows have exact canonical physical packages.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    }

    $searchRoots = recovery_search_roots($argv, $options['search-root'] ?? null);
    if ($searchRoots === []) {
        throw new RuntimeException(
            'Missing physical packages were found. Supply at least one --search-root, for example --search-root="L:\\dl".'
        );
    }

    $sources = [];
    foreach ($missing as $index => $file) {
        $direct = recovery_try_direct_candidates(
            $file,
            recovery_source_relatives($db, $file),
            $searchRoots
        );
        if ($direct !== null) {
            $sources[$index] = $direct;
            fwrite(
                STDERR,
                '[direct] exact #' . (int)$file['id'] . ' ' . (string)$file['original_name']
                . ' -> ' . (string)$direct['path'] . "\n"
            );
        }
    }

    $unresolvedForScan = [];
    $unresolvedToOriginal = [];
    foreach ($missing as $index => $file) {
        if (isset($sources[$index])) {
            continue;
        }
        $unresolvedToOriginal[count($unresolvedForScan)] = $index;
        $unresolvedForScan[] = $file;
    }
    if ($unresolvedForScan !== []) {
        $scanned = recovery_recursive_find($unresolvedForScan, $searchRoots);
        foreach ($scanned as $scanIndex => $source) {
            $sources[$unresolvedToOriginal[$scanIndex]] = $source;
        }
    }

    $plan = [];
    $unresolved = [];
    foreach ($missing as $index => $file) {
        $source = $sources[$index] ?? null;
        $row = [
            'file_id' => (int)$file['id'],
            'game_id' => (int)$file['game_id'],
            'game' => (string)$file['game_name'],
            'original_name' => (string)$file['original_name'],
            'size' => (int)$file['file_size'],
            'md5' => strtolower((string)$file['md5']),
            'sha1' => strtolower((string)$file['sha1']),
            'source_relative_path' => (string)($file['source_relative_path'] ?? ''),
            'destination' => (string)$file['destination'],
            'source_kind' => is_array($source) ? (string)$source['kind'] : null,
            'source_path' => is_array($source) ? (string)$source['path'] : null,
            'status' => is_array($source) ? 'ready' : 'unresolved',
        ];
        $plan[] = $row;
        if (!is_array($source)) {
            $unresolved[] = $row;
        }
    }

    $runningJobs = 0;
    if ($apply) {
        $runningJobs = (int)$db->query(
            'SELECT COUNT(*) FROM ue_background_jobs WHERE status="running"'
        )->fetchColumn();
        if ($runningJobs > 0 && !$forceRunningJobs) {
            echo json_encode([
                'ok' => false,
                'apply' => true,
                'verified_rows_checked' => $checked,
                'missing_physical_packages' => count($missing),
                'exact_sources_found' => count($sources),
                'unresolved' => count($unresolved),
                'running_jobs' => $runningJobs,
                'error' => 'Refusing recovery while background jobs are running. Stop the worker pool first, or use --force-running-jobs only if you have separately guaranteed storage is quiescent.',
                'plan' => $plan,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
            exit(6);
        }
        if ($unresolved !== [] && !$allowPartial) {
            echo json_encode([
                'ok' => false,
                'apply' => true,
                'verified_rows_checked' => $checked,
                'missing_physical_packages' => count($missing),
                'exact_sources_found' => count($sources),
                'unresolved' => count($unresolved),
                'running_jobs' => $runningJobs,
                'error' => 'Not every missing package has an exact recovery source. Apply is all-or-nothing by default; inspect unresolved rows or use --allow-partial explicitly.',
                'plan' => $plan,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
            exit(7);
        }
    }

    $restored = 0;
    $applyResults = [];
    if ($apply) {
        foreach ($missing as $index => $file) {
            $source = $sources[$index] ?? null;
            if (!is_array($source)) {
                continue;
            }
            fwrite(
                STDERR,
                '[restore] #' . (int)$file['id'] . ' ' . (string)$file['original_name']
                . ' -> ' . (string)$file['destination'] . "\n"
            );
            recovery_atomic_restore((string)$source['path'], (string)$file['destination'], $file);
            $restored++;
            $applyResults[] = [
                'file_id' => (int)$file['id'],
                'game_id' => (int)$file['game_id'],
                'game' => (string)$file['game_name'],
                'original_name' => (string)$file['original_name'],
                'status' => 'restored',
                'source_kind' => (string)$source['kind'],
                'source_path' => (string)$source['path'],
                'destination' => (string)$file['destination'],
            ];
        }
    }

    $result = [
        'ok' => !$apply || $unresolved === [] || $allowPartial,
        'apply' => $apply,
        'storage_root' => $storageRoot,
        'search_roots' => $searchRoots,
        'verified_rows_checked' => $checked,
        'missing_physical_packages' => count($missing),
        'exact_sources_found' => count($sources),
        'unresolved' => count($unresolved),
        'running_jobs' => $runningJobs,
        'restored_now' => $restored,
        'plan' => $plan,
    ];
    if ($apply) {
        $result['results'] = $applyResults;
        $result['next'] = $unresolved === []
            ? 'Recovery bytes are restored. Re-run Full Sync; stable ue_files IDs remain unchanged.'
            : 'Partial recovery completed. Resolve the remaining packages before treating storage as healthy.';
    } else {
        $result['next'] = $unresolved === []
            ? 'Dry run only. Stop the Background Jobs worker pool, then rerun with --apply.'
            : 'Dry run only. Resolve the unresolved package sources before applying; no catalog or storage changes were made.';
    }

    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit($unresolved === [] ? 0 : 3);
} catch (Throwable $error) {
    echo json_encode([
        'ok' => false,
        'apply' => $apply,
        'error' => $error->getMessage(),
        'type' => get_class($error),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit(1);
}
