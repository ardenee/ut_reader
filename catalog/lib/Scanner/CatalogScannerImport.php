<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Imports one uploaded/staged Unreal package into the catalog using the existing scanner contract.
 * Role: Active legacy-compatible import workflow separated from scanner naming/support/dependency concerns.
 */
declare(strict_types=1);

function scanner_scan_uploaded_file(PDO $db, array $config, int $gameId, string $tmp, string $originalName, ?int $userId, bool $strictProfile = true, ?callable $progress = null, bool $allowProfileOverride = false, array $scannerOptions = []): array
{
    scanner_source_path_schema_ensure($db);
    $sourceRelativePath = scanner_normalize_source_relative_path((string)($scannerOptions['source_relative_path'] ?? ''));
    $deferDependencyRebuild = !empty($scannerOptions['defer_dependency_rebuild']) || (string)($_POST['operation'] ?? '') === 'sync_reimport';
    $submittedOriginalName = $originalName;
    $sourceOriginalName = scanner_original_name_from_source_relative($sourceRelativePath);
    if ($sourceOriginalName !== '') {
        $originalName = $sourceOriginalName;
    }
    $originalName = scanner_clean_original_filename($originalName);
    scanner_emit_percent($progress, 'start', 0, 'Preparing ' . $originalName);

    $game = catalog_one($db, 'SELECT * FROM ue_games WHERE id=?', [$gameId]);
    if (!$game) {
        throw new RuntimeException('Game not found');
    }
    $profile = gp_required_profile_for_game($db, $gameId);
    $profileEngine = strtoupper((string)$profile['engine_key']);
    $ext = catalog_clean_unreal_extension((string)pathinfo($originalName, PATHINFO_EXTENSION));
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

    $sourcePackageName = '';
    if (in_array($readerEngine, ['UE4', 'UE5'], true)) {
        $sourcePackageName = scanner_ue_package_name_from_source_relative($sourceRelativePath);
        if ($sourcePackageName === '') {
            throw new RuntimeException('UE4 package identity requires a mounted source-relative path, matching UT4 FPackageName::FilenameToLongPackageName behaviour. Reimport using Local Source Scan, folder upload, PAK import, or a source manifest path; single loose UE4 files cannot be catalogued safely.');
        }
        $packageName = $sourcePackageName;
    } else {
        $packageName = scanner_logical_package_name($originalName);
    }

    scanner_emit_percent($progress, 'scan', 4, 'Hashing file');
    $md5 = md5_file($tmp);
    $sha1 = sha1_file($tmp);
    if (!$md5 || !$sha1) {
        throw new RuntimeException('Could not hash file');
    }

    scanner_emit_percent($progress, 'scan', 7, 'Opening ' . $readerEngine . ' reader');
    $readerClass = scanner_load_reader_class($config, $readerEngine);
    $ue4ReaderOptions = [];
    if (in_array($readerEngine, ['UE4', 'UE5'], true)) {
        $ue4ReaderOptions = catalog_ue4_reader_options($config, $game, $profile);
        catalog_ue4_set_next_reader_options($ue4ReaderOptions);
    }
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
    $packageGuid = (string)($header['guid'] ?? '');
    $names = $pkg->getNames();
    $packageName = scanner_package_name_from_reader($packageName, $readerEngine, $names, $header);
    catalog_package_aliases_ensure($db);

    if ($packageGuid !== '') {
        $duplicate = catalog_one($db, 'SELECT id, original_name, package_name, package_guid, file_size, md5 FROM ue_files WHERE game_id=? AND package_guid=? AND md5=?', [$gameId, $packageGuid, $md5]);
    } else {
        $duplicate = catalog_one($db, 'SELECT id, original_name, package_name, package_guid, file_size, md5 FROM ue_files WHERE game_id=? AND md5=? AND (package_guid IS NULL OR package_guid="")', [$gameId, $md5]);
    }

    if ($duplicate) {
        $duplicateFileId = (int)$duplicate['id'];
        scanner_record_source_relative_path($db, $duplicateFileId, $sourceRelativePath);
        $duplicatePackageName = (string)$duplicate['package_name'];
        $meta = ['file_id' => $duplicateFileId, 'file_size' => (int)$size, 'file_size_text' => catalog_bytes((int)$size), 'package_name' => $packageName, 'package_guid' => $packageGuid, 'md5' => $md5, 'duplicate_file_id' => $duplicateFileId, 'duplicate_original_name' => catalog_clean_unreal_filename((string)$duplicate['original_name']), 'duplicate_package_name' => $duplicatePackageName, 'duplicate_md5' => (string)($duplicate['md5'] ?? '')];
        if (strcasecmp($duplicatePackageName, $packageName) === 0 || catalog_package_alias_exists($db, $duplicateFileId, $gameId, $packageName)) {
            scanner_emit_percent($progress, 'done', 100, 'Duplicate in selected game');
            return ['duplicate', $duplicateFileId, 'Duplicate in selected game', $classification, $meta];
        }
        catalog_package_alias_add($db, $duplicateFileId, $gameId, $packageName, $originalName, $packageGuid, $md5, (int)$size);
        $refreshWarning = '';
        if ($deferDependencyRebuild) {
            scanner_emit_percent($progress, 'dependencies', 99, 'Alias dependency refresh deferred to the final Full Sync pass');
        } else {
            try {
                scanner_rebuild_affected_dependencies_for_package($db, $config, $gameId, $packageName, $progress, 56, 99, $duplicateFileId);
            } catch (Throwable $refreshError) {
                error_log('[UnrealDB dependency refresh] alias_package=' . $packageName . ' file_id=' . $duplicateFileId . ' error=' . $refreshError->getMessage());
                $refreshWarning = '; dependency refresh warning logged for maintenance';
            }
        }
        scanner_emit_percent($progress, 'done', 100, 'Alias package added for existing file identity');
        $meta['alias_package_name'] = $packageName;
        $meta['alias_added'] = true;
        return ['alias', $duplicateFileId, 'Package alias added for existing file identity' . $refreshWarning, $classification, $meta];
    }

    scanner_emit_percent($progress, 'scan', 14, 'Reading names table');
    scanner_emit_percent($progress, 'scan', 17, 'Reading imports table');
    $imports = $pkg->getImports();
    scanner_emit_percent($progress, 'scan', 20, 'Reading exports table');
    $exports = $pkg->getExports();
    $nameCount = count($names);
    $importCount = count($imports);
    $exportCount = count($exports);
    scanner_emit_percent($progress, 'scan', 22, 'Read ' . $nameCount . ' names, ' . $importCount . ' imports, ' . $exportCount . ' exports');

    $cleanNote = $submittedOriginalName !== $originalName ? '; cleaned filename=' . $originalName . ' from upload=' . basename($submittedOriginalName) : '';
    $sourceNote = $sourceRelativePath !== '' ? '; source-relative=' . $sourceRelativePath : '';
    $sourcePackageNote = $sourcePackageName !== '' ? '; source package=' . $sourcePackageName : '';
    $parserNote = '';
    if (in_array($readerEngine, ['UE4', 'UE5'], true)) {
        $parserProfile = is_array($header['parserProfile'] ?? null) && $header['parserProfile'] ? $header['parserProfile'] : ($ue4ReaderOptions['parser_profile'] ?? catalog_ue4_parser_profile($config, $game, $profile));
        $parserKey = (string)($header['parserProfileKey'] ?? ($parserProfile['profile_key'] ?? 'standard-ue4'));
        $parserLabel = (string)($header['parserProfileLabel'] ?? ($parserProfile['label'] ?? 'Standard UE4 package parser'));
        $assumedParserVersion = (int)($header['assumedUnversionedParserVersion'] ?? ($parserProfile['assumed_unversioned_parser_version'] ?? 0));
        $parserNote = '; UE4 parser profile=' . $parserKey . ' (' . $parserLabel . ')' . ($assumedParserVersion > 0 ? '; assumed UE4 parser version=' . $assumedParserVersion : '');
    }
    $scanNotesAll = array_merge($scanNotes, ['Profile engine=' . $profileEngine . '; package reader=' . $readerEngine . $parserNote . '; package=' . $packageName . '; compatibility=' . ($classification['compatibility_status'] ?? 'native') . '; detection=' . $classification['confidence'] . $cleanNote . $sourceNote . $sourcePackageNote . '; ' . implode(' ', $classification['notes'])]);
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
    $storedFileCreated = false;
    if (is_file($dest)) {
        if (is_file($tmp) && !@unlink($tmp)) {
            throw new RuntimeException('Could not discard duplicate physical upload');
        }
    } elseif (!rename($tmp, $dest)) {
        throw new RuntimeException('Could not store upload');
    } else {
        $storedFileCreated = true;
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
        $stmt = $db->prepare('INSERT INTO ue_files(game_id,package_name,original_name,source_relative_path,stored_name,relative_path,extension,detected_engine_key,detected_package_version,detected_licensee_version,detection_confidence,compatibility_status,compatibility_label,detection_notes,file_size,md5,sha1,package_guid,package_version,licensee_version,name_count,import_count,export_count,scan_status,scan_notes,uploaded_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->execute([$gameId, $packageName, $originalName, $sourceRelativePath !== '' ? $sourceRelativePath : null, $storedName, $relativePath, $ext, $classification['detected_engine'], $classification['package_version'], $classification['licensee_version'], $classification['confidence'], $classification['compatibility_status'] ?? 'native', $classification['compatibility_label'] ?? null, implode("\n", $classification['notes']), $size, $md5, $sha1, $packageGuid, (int)($header['version'] ?? 0), (int)($header['licensee'] ?? ($header['licenseeVersion'] ?? 0)), $nameCount, $importCount, $exportCount, 'verified', $scanNotesText, $userId]);
        $fileId = (int)$db->lastInsertId();
        $progressDb('Writing file row');

        $batch = [];
        foreach ($names as $i => $name) {
            $batch[] = [$fileId, $i, (string)($name['name'] ?? $name['text'] ?? ''), isset($name['flags']) ? (int)$name['flags'] : null];
            $done = $i + 1;
            if (count($batch) >= 250 || $done === $nameCount) {
                $batchCount = count($batch);
                scanner_bulk_insert($db, 'ue_names', ['file_id', 'name_index', 'name_text', 'flags'], $batch);
                $batch = [];
                $progressDb('Writing names table ' . $done . '/' . $nameCount, $batchCount);
            }
        }

        $cache = [];
        $common = array_map('strtolower', $config['common_packages'] ?? []);
        $batch = [];
        foreach ($imports as $i => $imp) {
            $full = scanner_ref_path(-($i + 1), $imports, $exports, $cache);
            $parts = $full !== '' ? explode('.', $full) : [];
            $root = $parts[0] ?? '';
            $relative = count($parts) > 1 ? implode('.', array_slice($parts, 1)) : '';
            $object = (string)($imp['objectNameText'] ?? ($imp['ObjectName']['text'] ?? ''));
            $classPackage = (string)($imp['classPackageText'] ?? ($imp['ClassPackage']['text'] ?? ''));
            $className = (string)($imp['classNameText'] ?? ($imp['ClassName']['text'] ?? ''));
            $outer = (int)($imp['outerIndex'] ?? $imp['OuterIndex'] ?? $imp['outer'] ?? 0);
            $batch[] = [$fileId, $i, $classPackage, $className, $object, $outer, $full, $root, $relative, in_array(strtolower($root), $common, true) ? 1 : 0];
            $done = $i + 1;
            if (count($batch) >= 250 || $done === $importCount) {
                $batchCount = count($batch);
                scanner_bulk_insert(
                    $db,
                    'ue_imports',
                    ['file_id', 'import_index', 'class_package', 'class_name', 'object_name', 'outer_index', 'full_path', 'root_package', 'relative_object_path', 'is_common'],
                    $batch
                );
                $batch = [];
                $progressDb('Writing imports table ' . $done . '/' . $importCount, $batchCount);
            }
        }

        $batch = [];
        foreach ($exports as $i => $exp) {
            $local = scanner_ref_path($i + 1, $imports, $exports, $cache);
            $classRef = (int)($exp['classIndex'] ?? $exp['class'] ?? 0);
            $className = $classRef ? scanner_ref_path($classRef, $imports, $exports, $cache) : '';
            $outer = (int)($exp['outerIndex'] ?? $exp['packageIndex'] ?? $exp['outer'] ?? 0);
            $batch[] = [$fileId, $i, $className, (string)($exp['objectNameText'] ?? ''), $outer, $local, scanner_join_path_parts([$packageName, $local]), isset($exp['objectFlags']) ? (int)$exp['objectFlags'] : null, isset($exp['serialSize']) ? (int)$exp['serialSize'] : null, isset($exp['serialOffset']) ? (int)$exp['serialOffset'] : null];
            $done = $i + 1;
            if (count($batch) >= 250 || $done === $exportCount) {
                $batchCount = count($batch);
                scanner_bulk_insert(
                    $db,
                    'ue_exports',
                    ['file_id', 'export_index', 'class_name', 'object_name', 'outer_index', 'local_path', 'full_path', 'object_flags', 'serial_size', 'serial_offset'],
                    $batch
                );
                $batch = [];
                $progressDb('Writing exports table ' . $done . '/' . $exportCount, $batchCount);
            }
        }

        scanner_emit_percent($progress, 'dependencies', 36, 'Rebuilding dependencies for imported file');
        scanner_rebuild_dependencies($db, $config, $fileId, $progress, 36, 55, 'Imported file dependency links');
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        if ($storedFileCreated && is_file($dest)) {
            @unlink($dest);
        }
        throw $e;
    }

    $resultLabel = ($classification['compatibility_status'] ?? 'native') === 'legacy_compatible'
        ? ('; ' . (string)($classification['compatibility_label'] ?? 'legacy-compatible'))
        : '';
    $result = [
        'verified',
        $fileId,
        'Imported. Profile=' . $profileEngine . ', reader=' . $readerEngine
            . ', detection=' . $classification['confidence'] . $resultLabel
            . ', size=' . catalog_bytes((int)$size)
            . ', names=' . $nameCount . ', imports=' . $importCount . ', exports=' . $exportCount,
        $classification,
        [
            'file_id' => $fileId,
            'package_name' => $packageName,
            'package_guid' => $packageGuid,
            'file_size' => (int)$size,
            'file_size_text' => catalog_bytes((int)$size),
            'source_relative_path' => $sourceRelativePath,
        ],
    ];
    $result = \UnrealDb\Catalog\Infrastructure\Metadata\VerifiedFileCompactMetadataFinalizer::finalize(
        $db,
        $config,
        $result,
        null
    );

    $refreshWarning = '';
    if ($deferDependencyRebuild) {
        scanner_emit_percent($progress, 'dependencies', 99, 'Affected dependency refresh deferred to the final Full Sync pass');
    } else {
        try {
            scanner_rebuild_affected_dependencies($db, $config, $fileId, $progress, 56, 99);
        } catch (Throwable $refreshError) {
            error_log('[UnrealDB dependency refresh] imported_file_id=' . $fileId . ' error=' . $refreshError->getMessage());
            $refreshWarning = '; dependency refresh warning logged for maintenance';
        }
    }
    if ($refreshWarning !== '') {
        $result[2] = (string)$result[2] . $refreshWarning;
    }

    scanner_emit_percent($progress, 'done', 100, 'Imported ' . $nameCount . ' names, ' . $importCount . ' imports, ' . $exportCount . ' exports with compact metadata');
    return $result;
}
