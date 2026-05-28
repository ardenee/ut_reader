<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/GameProfiles.php';

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

function scanner_load_reader_class(array $config, string $engineKey): string
{
    $engineKey = strtoupper(trim($engineKey));
    $readerConfig = $config['engine_readers'][$engineKey] ?? [];

    if ($engineKey === 'UE3') {
        $catalogReader = realpath(__DIR__ . '/../parsers/UE3CatalogReader.php');
        if ($catalogReader && is_file($catalogReader)) {
            require_once $catalogReader;
            if (class_exists('CatalogUE3PackageReader', false)) {
                return 'CatalogUE3PackageReader';
            }
        }
    }

    $rel = $readerConfig['reader'] ?? '';
    $path = realpath(__DIR__ . '/../' . $rel);
    if (!$path || !is_file($path)) {
        throw new RuntimeException('Reader not found for profile engine ' . $engineKey . ': ' . $rel);
    }

    require_once $path;

    $candidates = [];
    if (!empty($readerConfig['class'])) {
        $candidates[] = (string)$readerConfig['class'];
    }
    $candidates[] = match ($engineKey) {
        'UE4', 'UE5' => 'UnrealPackageReader4',
        default => 'UnrealPackageReader',
    };
    $candidates[] = 'UnrealPackageReader';
    $candidates[] = 'UnrealPackageReader4';

    foreach (array_unique($candidates) as $class) {
        if ($class !== '' && class_exists($class, false)) {
            return $class;
        }
    }

    throw new RuntimeException('Reader file loaded for profile engine ' . $engineKey . ', but no supported reader class was found.');
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

function scanner_rebuild_dependencies(PDO $db, array $config, int $fileId): void
{
    $db->prepare('DELETE FROM ue_dependencies WHERE file_id=?')->execute([$fileId]);
    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=?', [$fileId]);
    if (!$file) {
        return;
    }

    $insert = $db->prepare('INSERT INTO ue_dependencies(file_id,import_id,required_package,required_object_path,resolved_file_id,resolved_export_id,status) VALUES(?,?,?,?,?,?,?)');
    foreach (catalog_all($db, 'SELECT * FROM ue_imports WHERE file_id=?', [$fileId]) as $imp) {
        $status = 'missing';
        $resolvedFile = null;
        $resolvedExport = null;
        if ((int)$imp['is_common'] === 1) {
            $status = 'common';
        } elseif ((string)$imp['relative_object_path'] === '') {
            $match = catalog_one($db, 'SELECT id FROM ue_files WHERE game_id=? AND package_name=? AND id<>? ORDER BY uploaded_at DESC LIMIT 1', [$file['game_id'], $imp['root_package'], $fileId]);
            if ($match) {
                $status = 'package_only';
                $resolvedFile = (int)$match['id'];
            }
        } else {
            $match = catalog_one($db, 'SELECT e.id export_id, f.id file_id FROM ue_exports e JOIN ue_files f ON f.id=e.file_id WHERE f.game_id=? AND e.full_path=? AND f.id<>? ORDER BY f.uploaded_at DESC LIMIT 1', [$file['game_id'], $imp['full_path'], $fileId]);
            if ($match) {
                $status = 'resolved';
                $resolvedFile = (int)$match['file_id'];
                $resolvedExport = (int)$match['export_id'];
            }
        }
        $insert->execute([$fileId, $imp['id'], $imp['root_package'], $imp['full_path'], $resolvedFile, $resolvedExport, $status]);
    }
}

function scanner_rebuild_game(PDO $db, array $config, int $gameId): void
{
    foreach (catalog_all($db, 'SELECT id FROM ue_files WHERE game_id=?', [$gameId]) as $file) {
        scanner_rebuild_dependencies($db, $config, (int)$file['id']);
    }
}

function scanner_scan_uploaded_file(PDO $db, array $config, int $gameId, string $tmp, string $originalName, ?int $userId, bool $strictProfile = true): array
{
    $game = catalog_one($db, 'SELECT * FROM ue_games WHERE id=?', [$gameId]);
    if (!$game) {
        throw new RuntimeException('Game not found');
    }
    $profile = gp_required_profile_for_game($db, $gameId);
    $profileEngine = strtoupper((string)$profile['engine_key']);

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, $config['allowed_extensions'], true)) {
        throw new RuntimeException('Extension not allowed globally: ' . $ext);
    }

    $size = filesize($tmp) ?: 0;
    if ($size <= 0 || $size > (int)$config['max_upload_bytes']) {
        throw new RuntimeException('Bad file size: ' . catalog_bytes((int)$size));
    }

    $classification = gp_classify_file($db, $gameId, $tmp, $originalName);
    if ($strictProfile && empty($classification['ok_for_selected_game'])) {
        $suggested = [];
        foreach ($classification['suggested_games'] as $s) {
            $suggested[] = $s['game_name'] . ' (' . $s['engine_key'] . ')';
        }
        throw new RuntimeException('Game/profile mismatch. Detected=' . ($classification['detected_engine'] ?? 'unknown') . ', profile=' . ($classification['selected_engine'] ?? 'unknown') . '. ' . implode(' ', $classification['notes']) . ($suggested ? ' Suggested: ' . implode(', ', $suggested) : ''));
    }

    $md5 = md5_file($tmp);
    $sha1 = sha1_file($tmp);
    if (!$md5 || !$sha1) {
        throw new RuntimeException('Could not hash file');
    }

    $duplicate = catalog_one($db, 'SELECT id, original_name FROM ue_files WHERE md5=?', [$md5]);
    if ($duplicate) {
        return ['duplicate', (int)$duplicate['id'], 'Duplicate MD5: ' . $duplicate['original_name'], $classification];
    }

    $readerClass = scanner_load_reader_class($config, $profileEngine);
    $pkg = new $readerClass($tmp);
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

    $header = $pkg->getHeader();
    $names = $pkg->getNames();
    $imports = $pkg->getImports();
    $exports = $pkg->getExports();
    $packageName = scanner_clean_name(pathinfo($originalName, PATHINFO_FILENAME));
    $scanNotesAll = array_merge($scanNotes, ['Profile engine=' . $profileEngine . '; detection=' . $classification['confidence'] . '; ' . implode(' ', $classification['notes'])]);
    $scanNotesText = $scanNotesAll ? implode("\n", $scanNotesAll) : null;

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

    $db->beginTransaction();
    try {
        $stmt = $db->prepare('INSERT INTO ue_files(game_id,package_name,original_name,stored_name,relative_path,extension,detected_engine_key,detected_package_version,detected_licensee_version,detection_confidence,detection_notes,file_size,md5,sha1,package_guid,package_version,licensee_version,name_count,import_count,export_count,scan_status,scan_notes,uploaded_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$gameId, $packageName, $originalName, $storedName, $relativePath, $ext, $classification['detected_engine'], $classification['package_version'], $classification['licensee_version'], $classification['confidence'], implode("\n", $classification['notes']), $size, $md5, $sha1, (string)($header['guid'] ?? ''), (int)($header['version'] ?? 0), (int)($header['licensee'] ?? ($header['licenseeVersion'] ?? 0)), count($names), count($imports), count($exports), 'verified', $scanNotesText, $userId]);
        $fileId = (int)$db->lastInsertId();

        $stmt = $db->prepare('INSERT INTO ue_names(file_id,name_index,name_text,flags) VALUES(?,?,?,?)');
        foreach ($names as $i => $name) {
            $stmt->execute([$fileId, $i, (string)($name['name'] ?? $name['text'] ?? ''), isset($name['flags']) ? (int)$name['flags'] : null]);
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
        }

        $stmt = $db->prepare('INSERT INTO ue_exports(file_id,export_index,class_name,object_name,outer_index,local_path,full_path,object_flags,serial_size,serial_offset) VALUES(?,?,?,?,?,?,?,?,?,?)');
        foreach ($exports as $i => $exp) {
            $local = scanner_ref_path($i + 1, $imports, $exports, $cache);
            $classRef = (int)($exp['classIndex'] ?? $exp['class'] ?? 0);
            $className = $classRef ? scanner_ref_path($classRef, $imports, $exports, $cache) : '';
            $outer = (int)($exp['outerIndex'] ?? $exp['packageIndex'] ?? $exp['outer'] ?? 0);
            $stmt->execute([$fileId, $i, $className, (string)($exp['objectNameText'] ?? ''), $outer, $local, scanner_join_path_parts([$packageName, $local]), isset($exp['objectFlags']) ? (int)$exp['objectFlags'] : null, isset($exp['serialSize']) ? (int)$exp['serialSize'] : null, isset($exp['serialOffset']) ? (int)$exp['serialOffset'] : null]);
        }

        scanner_rebuild_dependencies($db, $config, $fileId);
        $db->commit();
        scanner_rebuild_game($db, $config, $gameId);
        return ['verified', $fileId, 'Imported. Profile=' . $profileEngine . ', detection=' . $classification['confidence'], $classification];
    } catch (Throwable $e) {
        $db->rollBack();
        @unlink($dest);
        throw $e;
    }
}
