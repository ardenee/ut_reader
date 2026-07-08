<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/GameProfiles.php';
require_once __DIR__ . '/CatalogReaderResolver.php';
require_once __DIR__ . '/CatalogDependencyResolver.php';
require_once __DIR__ . '/CatalogAffectedDependencyRefreshService.php';

function scanner_clean_name(string $s): string
{
    return trim(str_replace(["\0", '/', "\\"], ['', '.', '.'], $s));
}

function scanner_slug_text(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-') ?: 'item';
}

function scanner_join_path_parts(array $parts): string
{
    return implode('.', array_values(array_filter(array_map('scanner_clean_name', $parts), static fn($v) => $v !== '')));
}

function scanner_logical_package_name(string $originalName): string
{
    $name = scanner_clean_name(pathinfo($originalName, PATHINFO_FILENAME));

    /*
     * Windows/browser duplicate suffixes are not part of Unreal package names.
     * Export full paths must be rooted at the logical package, not at the local
     * upload filename, otherwise "xutfx (2).utx" creates "xutfx (2).Object"
     * exports that cannot satisfy imports for "xutfx.Object".
     */
    do {
        $previous = $name;
        $name = preg_replace('/\s+\([0-9]+\)$/', '', $name) ?? $name;
    } while ($name !== $previous);

    return scanner_clean_name($name);
}

function scanner_store_failed_upload(array $config, string $tmp, string $originalName, string $gameSlug, string $reason): void
{
    if (!is_file($tmp)) {
        return;
    }
    $dir = rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR) . '/games/' . scanner_slug_text($gameSlug) . '/unverified';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace('/[^A-Za-z0-9._-]+/', '_', basename($originalName));
    @rename($tmp, $dir . '/' . $name);
    @file_put_contents($dir . '/' . $name . '.txt', $reason);
}

function scanner_profile_extensions(array $profile, array $config): array
{
    $profileExts = json_decode((string)($profile['allowed_extensions_json'] ?? '[]'), true);
    if (!is_array($profileExts) || !$profileExts) {
        $profileExts = $config['allowed_extensions'] ?? [];
    }

    $out = [];
    foreach ($profileExts as $ext) {
        $ext = strtolower(trim((string)$ext));
        $ext = ltrim($ext, '.');
        if ($ext !== '') {
            $out[] = $ext;
        }
    }

    return array_values(array_unique($out));
}

function scanner_emit_progress(?callable $progress, string $stage, int $done, int $total, string $message): void
{
    if (!$progress) {
        return;
    }
    $total = max(1, $total);
    $done = max(0, min($done, $total));
    $progress([
        'stage' => $stage,
        'done' => $done,
        'total' => $total,
        'percent' => (int)round(($done / $total) * 100),
        'message' => $message,
    ]);
}

function scanner_emit_percent(?callable $progress, string $stage, int $percent, string $message): void
{
    scanner_emit_progress($progress, $stage, max(0, min(100, $percent)), 100, $message);
}

function scanner_range_percent(int $start, int $end, int $done, int $total): int
{
    $total = max(1, $total);
    $done = max(0, min($done, $total));
    return $start + (int)floor((($end - $start) * $done) / $total);
}

function scanner_load_reader_class(array $config, string $engineKey): string
{
    return CatalogReaderResolver::resolve(
        $config,
        $engineKey,
        'Reader not found for package engine',
        'Reader file loaded for package engine ',
        ['UE4', 'UE5']
    );
}

function scanner_split_reader_issues(array $issues): array
{
    $fatal = [];
    $notes = [];
    foreach ($issues as $issue) {
        $text = trim((string)$issue);
        if ($text === '') {
            continue;
        }
        if (str_starts_with($text, 'Package is unversioned; using assumed UE4 version ')) {
            $notes[] = $text;
            continue;
        }
        $fatal[] = $text;
    }
    return [$fatal, $notes];
}

function scanner_ref_path(int $ref, array $imports, array $exports, array &$cache, array $seen = []): string
{
    if ($ref === 0) {
        return '';
    }
    if (isset($cache[$ref])) {
        return $cache[$ref];
    }
    if (isset($seen[$ref])) {
        return '';
    }
    $seen[$ref] = true;

    if ($ref < 0) {
        $row = $imports[-$ref - 1] ?? null;
        if (!$row) {
            return '';
        }
        $outer = (int)($row['outerIndex'] ?? $row['OuterIndex'] ?? $row['outer'] ?? 0);
        $name = (string)($row['objectNameText'] ?? ($row['ObjectName']['text'] ?? ''));
        return $cache[$ref] = scanner_join_path_parts([scanner_ref_path($outer, $imports, $exports, $cache, $seen), $name]);
    }

    $row = $exports[$ref - 1] ?? null;
    if (!$row) {
        return '';
    }
    $outer = (int)($row['outerIndex'] ?? $row['packageIndex'] ?? $row['outer'] ?? 0);
    $name = (string)($row['objectNameText'] ?? '');
    return $cache[$ref] = scanner_join_path_parts([scanner_ref_path($outer, $imports, $exports, $cache, $seen), $name]);
}

function scanner_rebuild_dependencies(PDO $db, array $config, int $fileId, ?callable $progress = null, int $startPercent = 0, int $endPercent = 100, string $prefix = 'Rebuilding dependencies'): void
{
    scanner_emit_percent($progress, 'dependencies', $startPercent, $prefix . ': clearing old links');
    $db->prepare('DELETE FROM ue_dependencies WHERE file_id=?')->execute([$fileId]);
    $file = catalog_one($db, 'SELECT game_id FROM ue_files WHERE id=?', [$fileId]);
    if (!$file) {
        scanner_emit_percent($progress, 'dependencies', $endPercent, $prefix . ': skipped missing file');
        return;
    }

    $imports = catalog_all($db, 'SELECT * FROM ue_imports WHERE file_id=? ORDER BY import_index', [$fileId]);
    $resolutions = CatalogDependencyResolver::resolve($db, (int)$file['game_id'], $fileId, $imports);
    $total = max(1, count($imports));
    $insert = $db->prepare('INSERT INTO ue_dependencies(file_id,import_id,required_package,required_object_path,resolved_file_id,resolved_export_id,status) VALUES(?,?,?,?,?,?,?)');
    foreach ($imports as $i => $imp) {
        $resolution = $resolutions[(int)$imp['id']] ?? [
            'status' => 'missing',
            'resolved_file_id' => null,
            'resolved_export_id' => null,
        ];
        $insert->execute([
            $fileId,
            $imp['id'],
            $imp['root_package'],
            $imp['full_path'],
            $resolution['resolved_file_id'],
            $resolution['resolved_export_id'],
            $resolution['status'],
        ]);

        $done = $i + 1;
        if (($done % 10) === 0 || $done === $total) {
            scanner_emit_percent($progress, 'dependencies', scanner_range_percent($startPercent, $endPercent, $done, $total), $prefix . ': import ' . $done . '/' . $total);
        }
    }
}

function scanner_rebuild_game(PDO $db, array $config, int $gameId, ?callable $progress = null, int $startPercent = 56, int $endPercent = 99): void
{
    $files = catalog_all($db, 'SELECT id, package_name FROM ue_files WHERE game_id=? ORDER BY package_name, id', [$gameId]);
    $total = max(1, count($files));
    if (!$files) {
        scanner_emit_percent($progress, 'dependencies', $endPercent, 'Refreshing game dependency links: no files');
        return;
    }

    foreach ($files as $i => $file) {
        $fileStart = scanner_range_percent($startPercent, $endPercent, $i, $total);
        $fileEnd = scanner_range_percent($startPercent, $endPercent, $i + 1, $total);
        scanner_rebuild_dependencies($db, $config, (int)$file['id'], $progress, $fileStart, $fileEnd, 'Refreshing game dependency links ' . ($i + 1) . '/' . (string)$total . ' (' . (string)$file['package_name'] . ')');
    }
}

function scanner_rebuild_affected_dependencies(PDO $db, array $config, int $newFileId, ?callable $progress = null, int $startPercent = 56, int $endPercent = 99): void
{
    $file = catalog_one($db, 'SELECT game_id, package_name FROM ue_files WHERE id=?', [$newFileId]);
    if (!$file) {
        scanner_emit_percent($progress, 'dependencies', $endPercent, 'Refreshing affected dependency links: imported file missing');
        return;
    }

    $affectedFileIds = CatalogAffectedDependencyRefreshService::findAffectedFileIds(
        $db,
        (int)$file['game_id'],
        $newFileId,
        (string)$file['package_name']
    );
    $total = count($affectedFileIds);
    if ($total === 0) {
        scanner_emit_percent($progress, 'dependencies', $endPercent, 'Refreshing affected dependency links: no existing files affected');
        return;
    }

    foreach ($affectedFileIds as $index => $fileId) {
        $fileStart = scanner_range_percent($startPercent, $endPercent, $index, $total);
        $fileEnd = scanner_range_percent($startPercent, $endPercent, $index + 1, $total);
        scanner_rebuild_dependencies(
            $db,
            $config,
            $fileId,
            $progress,
            $fileStart,
            $fileEnd,
            'Refreshing affected dependency links ' . ($index + 1) . '/' . $total
        );
    }
}

/**
 * $strictProfile enforces the target profile's engine/version compatibility.
 * $allowProfileOverride is reserved for explicit administrator reassignment of
 * files from the unverified queue: it permits an otherwise unsupported target
 * extension and uses the detected package reader while preserving target-game
 * catalog ownership.
 */
function scanner_scan_uploaded_file(PDO $db, array $config, int $gameId, string $tmp, string $originalName, ?int $userId, bool $strictProfile = true, ?callable $progress = null, bool $allowProfileOverride = false): array
{
    scanner_emit_percent($progress, 'start', 0, 'Preparing ' . $originalName);

    $game = catalog_one($db, 'SELECT * FROM ue_games WHERE id=?', [$gameId]);
    if (!$game) {
        throw new RuntimeException('Game not found');
    }
    $profile = gp_required_profile_for_game($db, $gameId);
    $profileEngine = strtoupper((string)$profile['engine_key']);

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    $profileExtensions = scanner_profile_extensions($profile, $config);
    $extensionOutsideProfile = !in_array($ext, $profileExtensions, true);
    if ($extensionOutsideProfile && !$allowProfileOverride) {
        throw new RuntimeException('Extension not allowed by assigned profile: ' . $ext . '. Allowed: ' . implode(', ', $profileExtensions));
    }

    $size = filesize($tmp) ?: 0;
    if ($size <= 0 || $size > (int)$config['max_upload_bytes']) {
        throw new RuntimeException('Bad file size: ' . catalog_bytes((int)$size));
    }

    scanner_emit_percent($progress, 'scan', 2, 'Reading package header');
    $classification = gp_classify_file($db, $gameId, $tmp, $originalName);
    if ($strictProfile && empty($classification['ok_for_selected_game'])) {
        $suggested = [];
        foreach ($classification['suggested_games'] as $s) {
            $suggested[] = $s['game_name'] . ' (' . $s['engine_key'] . ')';
        }
        throw new RuntimeException('Game/profile mismatch. Detected=' . ($classification['detected_engine'] ?? 'unknown') . ', profile=' . ($classification['selected_engine'] ?? 'unknown') . '. ' . implode(' ', $classification['notes']) . ($suggested ? ' Suggested: ' . implode(', ', $suggested) : ''));
    }

    $readerEngine = strtoupper((string)($classification['reader_engine'] ?? $profileEngine));
    $detectedEngine = strtoupper((string)($classification['detected_engine'] ?? ''));
    if ((!$strictProfile || $allowProfileOverride) && in_array($detectedEngine, ['UE1', 'UE2', 'UE3', 'UE4', 'UE5'], true)) {
        $readerEngine = $detectedEngine;
    }
    if ($readerEngine === '') {
        $readerEngine = $profileEngine;
    }

    scanner_emit_percent($progress, 'scan', 4, 'Hashing file');
    $md5 = md5_file($tmp);
    $sha1 = sha1_file($tmp);
    if (!$md5 || !$sha1) {
        throw new RuntimeException('Could not hash file');
    }

    $duplicate = catalog_one($db, 'SELECT id, original_name FROM ue_files WHERE game_id=? AND md5=?', [$gameId, $md5]);
    if ($duplicate) {
        scanner_emit_percent($progress, 'done', 100, 'Duplicate in selected game');
        return ['duplicate', (int)$duplicate['id'], 'Duplicate in selected game: ' . $duplicate['original_name'], $classification];
    }

    scanner_emit_percent($progress, 'scan', 7, 'Opening ' . $readerEngine . ' reader');
    $readerClass = scanner_load_reader_class($config, $readerEngine);
    $pkg = new $readerClass($tmp);

    scanner_emit_percent($progress, 'scan', 9, 'Validating package');
    $issues = method_exists($pkg, 'validatePackage') ? $pkg->validatePackage() : (method_exists($pkg, 'getDebugErrors') ? $pkg->getDebugErrors() : []);
    [$fatalIssues, $scanNotes] = scanner_split_reader_issues($issues);
    if ($fatalIssues) {
        throw new RuntimeException(implode("\n", $fatalIssues));
    }

    foreach (['getHeader', 'getNames', 'getImports', 'getExports'] as $method) {
        if (!method_exists($pkg, $method)) {
            throw new RuntimeException('Reader is missing method: ' . $method);
        }
    }

    scanner_emit_percent($progress, 'scan', 11, 'Reading header');
    $header = $pkg->getHeader();
    scanner_emit_percent($progress, 'scan', 14, 'Reading names table');
    $names = $pkg->getNames();
    scanner_emit_percent($progress, 'scan', 17, 'Reading imports table');
    $imports = $pkg->getImports();
    scanner_emit_percent($progress, 'scan', 20, 'Reading exports table');
    $exports = $pkg->getExports();

    $nameCount = count($names);
    $importCount = count($imports);
    $exportCount = count($exports);
    scanner_emit_percent($progress, 'scan', 22, 'Read ' . $nameCount . ' names, ' . $importCount . ' imports, ' . $exportCount . ' exports');
    $packageName = scanner_logical_package_name($originalName);
    $scanNotesAll = array_merge($scanNotes, ['Profile engine=' . $profileEngine . '; package reader=' . $readerEngine . '; package=' . $packageName . '; compatibility=' . ($classification['compatibility_status'] ?? 'native') . '; detection=' . $classification['confidence'] . '; ' . implode(' ', $classification['notes'])]);
    if ($extensionOutsideProfile) {
        $scanNotesAll[] = 'Administrator override: extension .' . $ext . ' is outside the assigned profile extension list.';
    }
    if (!$strictProfile && ($detectedEngine !== '' && $detectedEngine !== $profileEngine)) {
        $scanNotesAll[] = 'Administrator compatibility override: catalogued under ' . $profileEngine . ' game using detected ' . $detectedEngine . ' reader.';
    }
    $scanNotesText = $scanNotesAll ? implode("\n", $scanNotesAll) : null;

    scanner_emit_percent($progress, 'database', 23, 'Storing file');
    $dir = rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR) . '/games/' . scanner_slug_text((string)$game['slug']) . '/verified';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create storage folder: ' . $dir);
    }

    $storedName = $md5 . '.' . $ext;
    $dest = $dir . '/' . $storedName;
    if (!rename($tmp, $dest)) {
        throw new RuntimeException('Could not store upload');
    }
    $relativePath = 'storage/games/' . scanner_slug_text((string)$game['slug']) . '/verified/' . $storedName;

    $totalRows = max(1, $nameCount + $importCount + $exportCount + 1);
    $writtenRows = 0;
    $progressDb = static function (string $message, int $rowsDone = 1) use ($progress, &$writtenRows, $totalRows): void {
        $writtenRows = min($totalRows, $writtenRows + max(1, $rowsDone));
        scanner_emit_percent($progress, 'database', scanner_range_percent(23, 35, $writtenRows, $totalRows), $message);
    };

    try {
        $db->beginTransaction();
        $stmt = $db->prepare('INSERT INTO ue_files(game_id,package_name,original_name,stored_name,relative_path,extension,detected_engine_key,detected_package_version,detected_licensee_version,detection_confidence,compatibility_status,compatibility_label,detection_notes,file_size,md5,sha1,package_guid,package_version,licensee_version,name_count,import_count,export_count,scan_status,scan_notes,uploaded_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$gameId, $packageName, $originalName, $storedName, $relativePath, $ext, $classification['detected_engine'], $classification['package_version'], $classification['licensee_version'], $classification['confidence'], $classification['compatibility_status'] ?? 'native', $classification['compatibility_label'] ?? null, implode("\n", $classification['notes']), $size, $md5, $sha1, (string)($header['guid'] ?? ''), (int)($header['version'] ?? 0), (int)($header['licensee'] ?? ($header['licenseeVersion'] ?? 0)), $nameCount, $importCount, $exportCount, 'verified', $scanNotesText, $userId]);
        $fileId = (int)$db->lastInsertId();
        $progressDb('Writing file row');

        $stmt = $db->prepare('INSERT INTO ue_names(file_id,name_index,name_text,flags) VALUES(?,?,?,?)');
        foreach ($names as $i => $name) {
            $stmt->execute([$fileId, $i, (string)($name['name'] ?? $name['text'] ?? ''), isset($name['flags']) ? (int)$name['flags'] : null]);
            $done = $i + 1;
            if (($done % 10) === 0 || $done === $nameCount) {
                $progressDb('Writing names table ' . $done . '/' . $nameCount, 10);
            }
        }

        $cache = [];
        $common = array_map('strtolower', $config['common_packages'] ?? []);
        $stmt = $db->prepare('INSERT INTO ue_imports(file_id,import_index,class_package,class_name,object_name,outer_index,full_path,root_package,relative_object_path,is_common) VALUES(?,?,?,?,?,?,?,?,?,?)');
        foreach ($imports as $i => $imp) {
            $full = scanner_ref_path(-($i + 1), $imports, $exports, $cache);
            $parts = $full !== '' ? explode('.', $full) : [];
            $root = $parts[0] ?? '';
            $relative = count($parts) > 1 ? implode('.', array_slice($parts, 1)) : '';
            $object = (string)($imp['objectNameText'] ?? ($imp['ObjectName']['text'] ?? ''));
            $classPackage = (string)($imp['classPackageText'] ?? ($imp['ClassPackage']['text'] ?? ''));
            $className = (string)($imp['classNameText'] ?? ($imp['ClassName']['text'] ?? ''));
            $outer = (int)($imp['outerIndex'] ?? $imp['OuterIndex'] ?? $imp['outer'] ?? 0);
            $stmt->execute([$fileId, $i, $classPackage, $className, $object, $outer, $full, $root, $relative, in_array(strtolower($root), $common, true) ? 1 : 0]);
            $done = $i + 1;
            if (($done % 10) === 0 || $done === $importCount) {
                $progressDb('Writing imports table ' . $done . '/' . $importCount, 10);
            }
        }

        $stmt = $db->prepare('INSERT INTO ue_exports(file_id,export_index,class_name,object_name,outer_index,local_path,full_path,object_flags,serial_size,serial_offset) VALUES(?,?,?,?,?,?,?,?,?,?)');
        foreach ($exports as $i => $exp) {
            $local = scanner_ref_path($i + 1, $imports, $exports, $cache);
            $classRef = (int)($exp['classIndex'] ?? $exp['class'] ?? 0);
            $className = $classRef ? scanner_ref_path($classRef, $imports, $exports, $cache) : '';
            $outer = (int)($exp['outerIndex'] ?? $exp['packageIndex'] ?? $exp['outer'] ?? 0);
            $stmt->execute([$fileId, $i, $className, (string)($exp['objectNameText'] ?? ''), $outer, $local, scanner_join_path_parts([$packageName, $local]), isset($exp['objectFlags']) ? (int)$exp['objectFlags'] : null, isset($exp['serialSize']) ? (int)$exp['serialSize'] : null, isset($exp['serialOffset']) ? (int)$exp['serialOffset'] : null]);
            $done = $i + 1;
            if (($done % 10) === 0 || $done === $exportCount) {
                $progressDb('Writing exports table ' . $done . '/' . $exportCount, 10);
            }
        }

        scanner_emit_percent($progress, 'dependencies', 36, 'Rebuilding dependencies for imported file');
        scanner_rebuild_dependencies($db, $config, $fileId, $progress, 36, 55, 'Imported file dependency links');
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        @unlink($dest);
        throw $e;
    }

    $refreshWarning = '';
    try {
        scanner_rebuild_affected_dependencies($db, $config, $fileId, $progress, 56, 99);
    } catch (Throwable $refreshError) {
        error_log('[UnrealDB dependency refresh] imported_file_id=' . $fileId . ' error=' . $refreshError->getMessage());
        $refreshWarning = '; dependency refresh warning logged for maintenance';
    }

    scanner_emit_percent($progress, 'done', 100, 'Imported ' . $nameCount . ' names, ' . $importCount . ' imports, ' . $exportCount . ' exports');
    $resultLabel = ($classification['compatibility_status'] ?? 'native') === 'legacy_compatible' ? ('; ' . (string)($classification['compatibility_label'] ?? 'legacy-compatible')) : '';
    return ['verified', $fileId, 'Imported. Profile=' . $profileEngine . ', reader=' . $readerEngine . ', detection=' . $classification['confidence'] . $resultLabel . ', names=' . $nameCount . ', imports=' . $importCount . ', exports=' . $exportCount . $refreshWarning, $classification];
}
