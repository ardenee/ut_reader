<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/CatalogParser.php';
require_once __DIR__ . '/GameProfiles.php';

function catalog_import_clean_name(string $s): string
{
    return trim(str_replace(["\0", '/', "\\"], ['', '.', '.'], $s));
}

function catalog_import_slug_text(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? '';
    return trim($s, '-') ?: 'item';
}

function catalog_import_join_path_parts(array $parts): string
{
    return implode('.', array_values(array_filter(array_map('catalog_import_clean_name', $parts), static fn($v) => $v !== '')));
}

function catalog_import_ref_path(int $ref, array $imports, array $exports, array &$cache, array $seen = []): string
{
    if ($ref === 0) return '';
    if (isset($cache[$ref])) return $cache[$ref];
    if (isset($seen[$ref])) return '';
    $seen[$ref] = true;

    if ($ref < 0) {
        $row = $imports[-$ref - 1] ?? null;
        if (!$row) return '';
        $outer = (int)($row['outerIndex'] ?? $row['OuterIndex'] ?? $row['outer'] ?? 0);
        $name = (string)($row['objectNameText'] ?? ($row['ObjectName']['text'] ?? ''));
        return $cache[$ref] = catalog_import_join_path_parts([catalog_import_ref_path($outer, $imports, $exports, $cache, $seen), $name]);
    }

    $row = $exports[$ref - 1] ?? null;
    if (!$row) return '';
    $outer = (int)($row['outerIndex'] ?? $row['packageIndex'] ?? $row['outer'] ?? 0);
    $name = (string)($row['objectNameText'] ?? '');
    return $cache[$ref] = catalog_import_join_path_parts([catalog_import_ref_path($outer, $imports, $exports, $cache, $seen), $name]);
}

function catalog_import_split_reader_issues(array $issues): array
{
    $fatal = [];
    $notes = [];
    foreach ($issues as $issue) {
        $text = trim((string)$issue);
        if ($text === '') continue;
        if (str_starts_with($text, 'Package is unversioned; using assumed UE4 version ')) {
            $notes[] = $text;
            continue;
        }
        $fatal[] = $text;
    }
    return [$fatal, $notes];
}

function catalog_import_rebuild_dependencies(PDO $db, array $config, int $fileId): void
{
    $db->prepare('DELETE FROM ue_dependencies WHERE file_id=?')->execute([$fileId]);
    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=?', [$fileId]);
    if (!$file) return;

    $insert = $db->prepare('INSERT INTO ue_dependencies(file_id,import_id,required_package,required_object_path,resolved_file_id,resolved_export_id,status) VALUES(?,?,?,?,?,?,?)');
    foreach (catalog_all($db, 'SELECT * FROM ue_imports WHERE file_id=?', [$fileId]) as $imp) {
        $status = 'missing';
        $resolvedFile = null;
        $resolvedExport = null;
        if ((int)$imp['is_common'] === 1) {
            $status = 'common';
        } elseif ((string)$imp['relative_object_path'] === '') {
            $match = catalog_one($db, 'SELECT id FROM ue_files WHERE game_id=? AND package_name=? AND scan_status="verified" ORDER BY (id=?) DESC, uploaded_at DESC LIMIT 1', [$file['game_id'], $imp['root_package'], $fileId]);
            if ($match) {
                $status = 'package_only';
                $resolvedFile = (int)$match['id'];
            }
        } else {
            $match = catalog_one($db, 'SELECT e.id export_id, f.id file_id FROM ue_exports e JOIN ue_files f ON f.id=e.file_id WHERE f.game_id=? AND e.full_path=? AND f.scan_status="verified" ORDER BY (f.id=?) DESC, f.uploaded_at DESC LIMIT 1', [$file['game_id'], $imp['full_path'], $fileId]);
            if ($match) {
                $status = 'resolved';
                $resolvedFile = (int)$match['file_id'];
                $resolvedExport = (int)$match['export_id'];
            }
        }
        $insert->execute([$fileId, $imp['id'], $imp['root_package'], $imp['full_path'], $resolvedFile, $resolvedExport, $status]);
    }
}

function catalog_import_rebuild_game(PDO $db, array $config, int $gameId): void
{
    foreach (catalog_all($db, 'SELECT id FROM ue_files WHERE game_id=? AND scan_status="verified"', [$gameId]) as $file) {
        catalog_import_rebuild_dependencies($db, $config, (int)$file['id']);
    }
}

function catalog_import_detect_game(PDO $db, string $extension): ?array
{
    $extension = strtolower($extension);
    $rows = catalog_all($db, 'SELECT g.*, p.engine_key profile_engine, p.allowed_extensions_json FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 ORDER BY g.id');
    foreach ($rows as $row) {
        $exts = gp_extensions($row);
        if (!$exts || in_array($extension, $exts, true)) return $row;
    }
    return $rows[0] ?? null;
}

function catalog_import_file(PDO $db, array $config, string $sourcePath, string $originalName, ?int $preferredGameId = null, ?int $uploadedBy = null): array
{
    if (!is_file($sourcePath)) throw new RuntimeException('Import source file missing: ' . $sourcePath);

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, $config['allowed_extensions'] ?? [], true)) throw new RuntimeException('Extension not allowed: ' . $ext);

    $size = filesize($sourcePath) ?: 0;
    if ($size <= 0 || $size > (int)($config['max_upload_bytes'] ?? PHP_INT_MAX)) throw new RuntimeException('Bad file size: ' . catalog_bytes((int)$size));

    $md5 = md5_file($sourcePath);
    $sha1 = sha1_file($sourcePath);
    if (!$md5 || !$sha1) throw new RuntimeException('Could not hash import source file.');

    $duplicate = catalog_one($db, 'SELECT id, original_name FROM ue_files WHERE md5=?', [$md5]);
    if ($duplicate) return ['status' => 'duplicate_md5', 'file_id' => (int)$duplicate['id'], 'message' => 'Duplicate MD5: ' . $duplicate['original_name']];

    $game = $preferredGameId ? catalog_one($db, 'SELECT * FROM ue_games WHERE id=?', [$preferredGameId]) : catalog_import_detect_game($db, $ext);
    if (!$game) throw new RuntimeException('Could not detect target game for extension: ' . $ext);
    $profileEngine = gp_engine_for_game($db, (int)$game['id']);

    $readerClass = catalog_load_reader_class($config, $profileEngine);
    $pkg = new $readerClass($sourcePath);
    $issues = method_exists($pkg, 'validatePackage') ? $pkg->validatePackage() : (method_exists($pkg, 'getDebugErrors') ? $pkg->getDebugErrors() : []);
    [$fatalIssues, $scanNotes] = catalog_import_split_reader_issues($issues);
    if ($fatalIssues) throw new RuntimeException(implode("\n", $fatalIssues));

    foreach (['getHeader', 'getNames', 'getImports', 'getExports'] as $method) {
        if (!method_exists($pkg, $method)) throw new RuntimeException('Reader is missing method: ' . $method);
    }

    $header = $pkg->getHeader();
    $guid = (string)($header['guid'] ?? '');
    if ($guid !== '') {
        $guidDuplicate = catalog_one($db, 'SELECT id, original_name FROM ue_files WHERE game_id=? AND package_guid=? AND scan_status="verified" LIMIT 1', [(int)$game['id'], $guid]);
        if ($guidDuplicate) return ['status' => 'duplicate_guid', 'file_id' => (int)$guidDuplicate['id'], 'message' => 'Duplicate package GUID: ' . $guidDuplicate['original_name']];
    }

    $names = $pkg->getNames();
    $imports = $pkg->getImports();
    $exports = $pkg->getExports();
    $packageName = catalog_import_clean_name(pathinfo($originalName, PATHINFO_FILENAME));
    $scanNotes[] = 'Profile engine=' . $profileEngine;
    $scanNotesText = $scanNotes ? implode("\n", $scanNotes) : null;

    $dir = rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR) . '/games/' . catalog_import_slug_text((string)$game['slug']) . '/verified';
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Could not create storage folder: ' . $dir);

    $storedName = $md5 . '.' . $ext;
    $dest = $dir . '/' . $storedName;
    if (!rename($sourcePath, $dest)) throw new RuntimeException('Could not move imported file into verified storage.');
    $relativePath = 'storage/games/' . catalog_import_slug_text((string)$game['slug']) . '/verified/' . $storedName;

    $db->beginTransaction();
    try {
        $stmt = $db->prepare('INSERT INTO ue_files(game_id,package_name,original_name,stored_name,relative_path,extension,file_size,md5,sha1,package_guid,is_compressed,compression_flags,package_version,licensee_version,name_count,import_count,export_count,scan_status,scan_notes,uploaded_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([(int)$game['id'], $packageName, $originalName, $storedName, $relativePath, $ext, $size, $md5, $sha1, $guid, !empty($header['compressed']) ? 1 : 0, (int)($header['compressionFlags'] ?? 0), (int)($header['version'] ?? 0), (int)($header['licensee'] ?? ($header['licenseeVersion'] ?? 0)), count($names), count($imports), count($exports), 'verified', $scanNotesText, $uploadedBy]);
        $fileId = (int)$db->lastInsertId();

        $stmt = $db->prepare('INSERT INTO ue_names(file_id,name_index,name_text,flags) VALUES(?,?,?,?)');
        foreach ($names as $i => $name) {
            $stmt->execute([$fileId, $i, (string)($name['name'] ?? $name['text'] ?? ''), isset($name['flags']) ? (int)$name['flags'] : null]);
        }

        $cache = [];
        $common = array_map('strtolower', $config['common_packages'] ?? []);
        $stmt = $db->prepare('INSERT INTO ue_imports(file_id,import_index,class_package,class_name,object_name,outer_index,full_path,root_package,relative_object_path,is_common) VALUES(?,?,?,?,?,?,?,?,?,?)');
        foreach ($imports as $i => $imp) {
            $full = catalog_import_ref_path(-($i + 1), $imports, $exports, $cache);
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
            $local = catalog_import_ref_path($i + 1, $imports, $exports, $cache);
            $classRef = (int)($exp['classIndex'] ?? $exp['class'] ?? 0);
            $className = $classRef ? catalog_import_ref_path($classRef, $imports, $exports, $cache) : '';
            $outer = (int)($exp['outerIndex'] ?? $exp['packageIndex'] ?? $exp['outer'] ?? 0);
            $stmt->execute([$fileId, $i, $className, (string)($exp['objectNameText'] ?? ''), $outer, $local, catalog_import_join_path_parts([$packageName, $local]), isset($exp['objectFlags']) ? (int)$exp['objectFlags'] : null, isset($exp['serialSize']) ? (int)$exp['serialSize'] : null, isset($exp['serialOffset']) ? (int)$exp['serialOffset'] : null]);
        }

        catalog_import_rebuild_dependencies($db, $config, $fileId);
        $db->commit();
        catalog_import_rebuild_game($db, $config, (int)$game['id']);
        return ['status' => 'verified', 'file_id' => $fileId, 'game_id' => (int)$game['id'], 'message' => 'Imported and verified'];
    } catch (Throwable $e) {
        $db->rollBack();
        @unlink($dest);
        throw $e;
    }
}
