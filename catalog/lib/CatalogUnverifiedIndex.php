<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';
require_once __DIR__ . '/CatalogScanner.php';
require_once __DIR__ . '/CatalogRedirectArchive.php';
require_once __DIR__ . '/CatalogPackageAliases.php';

/**
 * Database-backed staging for files in storage/upload-bucket and
 * storage/games/<slug>/unverified.
 *
 * Unverified rows deliberately have game_id=NULL. The physical queue is recorded
 * in the unverified_queue_* columns until the row is promoted to a verified game.
 */

function catalog_unverified_schema_ensure(PDO $db): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $gameId = catalog_one($db, 'SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_files" AND COLUMN_NAME="game_id"');
    if ($gameId && strtoupper((string)$gameId['IS_NULLABLE']) !== 'YES') {
        $db->exec('ALTER TABLE ue_files MODIFY game_id INT UNSIGNED NULL');
    }

    $status = catalog_one($db, 'SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_files" AND COLUMN_NAME="scan_status"');
    if ($status && !str_contains(strtolower((string)$status['COLUMN_TYPE']), "'unverified'")) {
        $db->exec("ALTER TABLE ue_files MODIFY scan_status ENUM('verified','unverified','duplicate','failed') NOT NULL DEFAULT 'verified'");
    }

    $columns = [
        'unverified_queue_key' => 'ALTER TABLE ue_files ADD COLUMN unverified_queue_key CHAR(64) NULL AFTER scan_status',
        'unverified_queue_game_id' => 'ALTER TABLE ue_files ADD COLUMN unverified_queue_game_id INT UNSIGNED NULL AFTER unverified_queue_key',
        'unverified_queue_name' => 'ALTER TABLE ue_files ADD COLUMN unverified_queue_name VARCHAR(255) NULL AFTER unverified_queue_game_id',
        'unverified_reason' => 'ALTER TABLE ue_files ADD COLUMN unverified_reason TEXT NULL AFTER unverified_queue_name',
    ];
    foreach ($columns as $name => $sql) {
        $exists = catalog_one($db, 'SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_files" AND COLUMN_NAME=?', [$name]);
        if ((int)($exists['c'] ?? 0) === 0) {
            $db->exec($sql);
        }
    }

    $indexes = [
        'uq_ue_files_unverified_queue_key' => 'ALTER TABLE ue_files ADD UNIQUE KEY uq_ue_files_unverified_queue_key (unverified_queue_key)',
        'idx_ue_files_scan_status' => 'ALTER TABLE ue_files ADD KEY idx_ue_files_scan_status (scan_status)',
        'idx_ue_files_unverified_queue' => 'ALTER TABLE ue_files ADD KEY idx_ue_files_unverified_queue (unverified_queue_game_id, unverified_queue_name)',
    ];
    foreach ($indexes as $name => $sql) {
        $exists = catalog_one($db, 'SELECT COUNT(*) c FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_files" AND INDEX_NAME=?', [$name]);
        if ((int)($exists['c'] ?? 0) === 0) {
            $db->exec($sql);
        }
    }

    scanner_source_path_schema_ensure($db);
    $done = true;
}

function catalog_unverified_queue_key(int $queueGameId, string $queueName): string
{
    return hash('sha256', $queueGameId . "\0" . basename($queueName));
}

function catalog_unverified_storage_relative(array $config, string $path): string
{
    $storage = realpath(rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR));
    $real = realpath($path);
    if ($storage !== false && $real !== false) {
        $prefix = rtrim(str_replace('\\', '/', $storage), '/') . '/';
        $normalized = str_replace('\\', '/', $real);
        if (str_starts_with($normalized, $prefix)) {
            return 'storage/' . ltrim(substr($normalized, strlen($prefix)), '/');
        }
    }
    return str_replace('\\', '/', $path);
}

/** @return array{path:string,name:string,temporary:bool,source_name:string} */
function catalog_unverified_prepare_path(string $path, string $originalName): array
{
    $clean = scanner_clean_original_filename($originalName);
    if (!catalog_redirect_archive_is_supported_filename($originalName)) {
        return ['path' => $path, 'name' => $clean, 'temporary' => false, 'source_name' => $originalName];
    }

    $decoded = catalog_redirect_archive_decompress_to_temp($path, $originalName);
    return [
        'path' => (string)$decoded['path'],
        'name' => scanner_clean_original_filename((string)$decoded['filename']),
        'temporary' => true,
        'source_name' => $originalName,
    ];
}

function catalog_unverified_detect_engine(string $path, string $name): array
{
    $summary = gp_read_legacy_summary($path);
    $engine = strtoupper((string)($summary['engine_hint'] ?? gp_detect_from_extension((string)pathinfo($name, PATHINFO_EXTENSION)) ?? 'UNKNOWN'));
    return [$engine, $summary];
}

/**
 * @return array{header:array<string,mixed>,names:array<int,mixed>,imports:array<int,mixed>,exports:array<int,mixed>,notes:list<string>}
 */
function catalog_unverified_parse(PDO $db, array $config, string $path, string $name, int $queueGameId, string $sourceRelativePath): array
{
    [$engine] = catalog_unverified_detect_engine($path, $name);
    if (!in_array($engine, ['UE1', 'UE2', 'UE3', 'UE4', 'UE5'], true)) {
        throw new RuntimeException('No Unreal package reader could be selected from the file header or extension.');
    }

    if (in_array($engine, ['UE4', 'UE5'], true) && $queueGameId > 0) {
        try {
            $game = catalog_one($db, 'SELECT * FROM ue_games WHERE id=?', [$queueGameId]) ?: [];
            $profile = gp_profile_for_game($db, $queueGameId) ?: [];
            catalog_ue4_set_next_reader_options(catalog_ue4_reader_options($config, $game, $profile));
        } catch (Throwable $error) {
            error_log('[UnrealDB unverified index] UE4 profile options: ' . $error->getMessage());
        }
    }

    $readerClass = scanner_load_reader_class($config, $engine);
    $reader = new $readerClass($path);
    $issues = method_exists($reader, 'validatePackage') ? $reader->validatePackage() : (method_exists($reader, 'getDebugErrors') ? $reader->getDebugErrors() : []);
    [$fatal, $notes] = scanner_split_reader_issues(is_array($issues) ? $issues : []);
    if ($fatal !== []) {
        throw new RuntimeException(implode("\n", $fatal));
    }
    foreach (['getHeader', 'getNames', 'getImports', 'getExports'] as $method) {
        if (!method_exists($reader, $method)) {
            throw new RuntimeException('Reader is missing method: ' . $method);
        }
    }

    $header = $reader->getHeader();
    $names = $reader->getNames();
    $imports = $reader->getImports();
    $exports = $reader->getExports();
    if (!is_array($header) || !is_array($names) || !is_array($imports) || !is_array($exports)) {
        throw new RuntimeException('Reader returned an invalid package table.');
    }

    return ['header' => $header, 'names' => $names, 'imports' => $imports, 'exports' => $exports, 'notes' => array_values($notes)];
}

function catalog_unverified_find(PDO $db, int $queueGameId, string $queueName): ?array
{
    catalog_unverified_schema_ensure($db);
    return catalog_one($db, 'SELECT * FROM ue_files WHERE scan_status="unverified" AND unverified_queue_key=? LIMIT 1', [catalog_unverified_queue_key($queueGameId, $queueName)]);
}

/**
 * @return array{status:string,file_id:int,message:string,parse_error:?string}
 */
function catalog_unverified_index_path(
    PDO $db,
    array $config,
    int $queueGameId,
    string $queueName,
    string $path,
    string $originalName,
    string $reason = '',
    ?int $uploadedBy = null,
    string $sourceRelativePath = '',
    bool $force = false
): array {
    catalog_unverified_schema_ensure($db);
    $queueName = basename($queueName);
    if ($queueName === '' || !is_file($path)) {
        throw new RuntimeException('The queued file is unavailable.');
    }

    $key = catalog_unverified_queue_key($queueGameId, $queueName);
    $existing = catalog_one($db, 'SELECT * FROM ue_files WHERE unverified_queue_key=? LIMIT 1', [$key]);
    if ($existing && !$force) {
        return ['status' => 'existing', 'file_id' => (int)$existing['id'], 'message' => 'Already indexed', 'parse_error' => null];
    }

    $prepared = catalog_unverified_prepare_path($path, $originalName);
    $parsePath = $prepared['path'];
    $parsedName = $prepared['name'];
    $temporary = $prepared['temporary'];

    try {
        $size = (int)(filesize($parsePath) ?: 0);
        if ($size <= 0) {
            throw new RuntimeException('Queued file is empty.');
        }
        $md5 = md5_file($parsePath);
        $sha1 = sha1_file($parsePath);
        if (!is_string($md5) || !is_string($sha1)) {
            throw new RuntimeException('Could not hash the queued file.');
        }

        [$detectedEngine, $summary] = catalog_unverified_detect_engine($parsePath, $parsedName);
        $sourceRelativePath = scanner_normalize_source_relative_path($sourceRelativePath);
        $packageName = in_array($detectedEngine, ['UE4', 'UE5'], true) && $sourceRelativePath !== ''
            ? scanner_ue_package_name_from_source_relative($sourceRelativePath)
            : scanner_logical_package_name($parsedName);
        if ($packageName === '') {
            $packageName = scanner_logical_package_name($parsedName);
        }

        $header = [];
        $names = [];
        $imports = [];
        $exports = [];
        $notes = [];
        $parseError = null;
        try {
            $parsed = catalog_unverified_parse($db, $config, $parsePath, $parsedName, $queueGameId, $sourceRelativePath);
            $header = $parsed['header'];
            $names = $parsed['names'];
            $imports = $parsed['imports'];
            $exports = $parsed['exports'];
            $notes = $parsed['notes'];
        } catch (Throwable $error) {
            $parseError = trim($error->getMessage()) ?: 'Package tables could not be read.';
            $notes[] = 'Unverified table parse failed: ' . $parseError;
        }

        if (in_array($detectedEngine, ['UE4', 'UE5'], true) && $sourceRelativePath === '') {
            $notes[] = 'UE4 long package identity is provisional because no mounted source-relative path was recorded.';
        }
        if ($reason !== '') {
            $notes[] = 'Queue reason: ' . $reason;
        }

        $guid = trim((string)($header['guid'] ?? ''));
        $version = array_key_exists('version', $header) ? (int)$header['version'] : ($summary['ok'] ? (int)($summary['version'] ?? 0) : 0);
        $licensee = array_key_exists('licensee', $header)
            ? (int)$header['licensee']
            : (array_key_exists('licenseeVersion', $header) ? (int)$header['licenseeVersion'] : (($summary['licensee'] ?? null) !== null ? (int)$summary['licensee'] : 0));
        $confidence = $parseError === null ? 'high' : (!empty($summary['ok']) ? 'medium' : 'unknown');
        $relativePath = catalog_unverified_storage_relative($config, $path);
        $scanNotes = implode("\n", array_values(array_filter(array_map('trim', $notes), static fn(string $v): bool => $v !== '')));

        $db->beginTransaction();
        try {
            if ($existing) {
                $fileId = (int)$existing['id'];
                $db->prepare('DELETE FROM ue_dependencies WHERE file_id=?')->execute([$fileId]);
                $db->prepare('DELETE FROM ue_exports WHERE file_id=?')->execute([$fileId]);
                $db->prepare('DELETE FROM ue_imports WHERE file_id=?')->execute([$fileId]);
                $db->prepare('DELETE FROM ue_names WHERE file_id=?')->execute([$fileId]);
                $stmt = $db->prepare('UPDATE ue_files SET game_id=NULL,package_name=?,original_name=?,source_relative_path=?,stored_name=?,relative_path=?,extension=?,detected_engine_key=?,detected_package_version=?,detected_licensee_version=?,detection_confidence=?,compatibility_status="unverified",compatibility_label=NULL,detection_notes=?,file_size=?,md5=?,sha1=?,package_guid=?,is_compressed=?,compression_flags=?,package_version=?,licensee_version=?,name_count=?,import_count=?,export_count=?,scan_status="unverified",scan_notes=?,uploaded_by=COALESCE(?,uploaded_by),unverified_queue_game_id=?,unverified_queue_name=?,unverified_reason=? WHERE id=?');
                $stmt->execute([$packageName, $parsedName, $sourceRelativePath !== '' ? $sourceRelativePath : null, $queueName, $relativePath, catalog_clean_unreal_extension((string)pathinfo($parsedName, PATHINFO_EXTENSION)), $detectedEngine, $summary['ok'] ? (int)($summary['version'] ?? 0) : null, ($summary['licensee'] ?? null) !== null ? (int)$summary['licensee'] : null, $confidence, $scanNotes, $size, strtolower($md5), strtolower($sha1), $guid !== '' ? $guid : null, !empty($header['compressed']) ? 1 : 0, (int)($header['compressionFlags'] ?? 0), $version, $licensee, count($names), count($imports), count($exports), $scanNotes, $uploadedBy, $queueGameId, $queueName, $reason, $fileId]);
            } else {
                $stmt = $db->prepare('INSERT INTO ue_files(game_id,package_name,original_name,source_relative_path,stored_name,relative_path,extension,detected_engine_key,detected_package_version,detected_licensee_version,detection_confidence,compatibility_status,compatibility_label,detection_notes,file_size,md5,sha1,package_guid,is_compressed,compression_flags,package_version,licensee_version,name_count,import_count,export_count,scan_status,scan_notes,uploaded_by,unverified_queue_key,unverified_queue_game_id,unverified_queue_name,unverified_reason) VALUES(NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,? ,"unverified",?,?,?,?,?,?)');
                $stmt->execute([$packageName, $parsedName, $sourceRelativePath !== '' ? $sourceRelativePath : null, $queueName, $relativePath, catalog_clean_unreal_extension((string)pathinfo($parsedName, PATHINFO_EXTENSION)), $detectedEngine, $summary['ok'] ? (int)($summary['version'] ?? 0) : null, ($summary['licensee'] ?? null) !== null ? (int)$summary['licensee'] : null, $confidence, 'unverified', null, $scanNotes, $size, strtolower($md5), strtolower($sha1), $guid !== '' ? $guid : null, !empty($header['compressed']) ? 1 : 0, (int)($header['compressionFlags'] ?? 0), $version, $licensee, count($names), count($imports), count($exports), $scanNotes, $uploadedBy, $key, $queueGameId, $queueName, $reason]);
                $fileId = (int)$db->lastInsertId();
            }

            $insertName = $db->prepare('INSERT INTO ue_names(file_id,name_index,name_text,flags) VALUES(?,?,?,?)');
            foreach ($names as $i => $name) {
                $insertName->execute([$fileId, (int)$i, (string)($name['name'] ?? $name['text'] ?? ''), isset($name['flags']) ? (int)$name['flags'] : null]);
            }

            $cache = [];
            $common = array_map('strtolower', $config['common_packages'] ?? []);
            $insertImport = $db->prepare('INSERT INTO ue_imports(file_id,import_index,class_package,class_name,object_name,outer_index,full_path,root_package,relative_object_path,is_common) VALUES(?,?,?,?,?,?,?,?,?,?)');
            foreach ($imports as $i => $imp) {
                $full = scanner_ref_path(-((int)$i + 1), $imports, $exports, $cache);
                $parts = $full !== '' ? explode('.', $full) : [];
                $root = (string)($parts[0] ?? '');
                $relative = count($parts) > 1 ? implode('.', array_slice($parts, 1)) : '';
                $insertImport->execute([$fileId, (int)$i, (string)($imp['classPackageText'] ?? ($imp['ClassPackage']['text'] ?? '')), (string)($imp['classNameText'] ?? ($imp['ClassName']['text'] ?? '')), (string)($imp['objectNameText'] ?? ($imp['ObjectName']['text'] ?? '')), (int)($imp['outerIndex'] ?? $imp['OuterIndex'] ?? $imp['outer'] ?? 0), $full, $root, $relative, in_array(strtolower($root), $common, true) ? 1 : 0]);
            }

            $insertExport = $db->prepare('INSERT INTO ue_exports(file_id,export_index,class_name,object_name,outer_index,local_path,full_path,object_flags,serial_size,serial_offset) VALUES(?,?,?,?,?,?,?,?,?,?)');
            foreach ($exports as $i => $exp) {
                $local = scanner_ref_path((int)$i + 1, $imports, $exports, $cache);
                $classRef = (int)($exp['classIndex'] ?? $exp['class'] ?? 0);
                $insertExport->execute([$fileId, (int)$i, $classRef !== 0 ? scanner_ref_path($classRef, $imports, $exports, $cache) : '', (string)($exp['objectNameText'] ?? ''), (int)($exp['outerIndex'] ?? $exp['packageIndex'] ?? $exp['outer'] ?? 0), $local, scanner_join_path_parts([$packageName, $local]), isset($exp['objectFlags']) ? (int)$exp['objectFlags'] : null, isset($exp['serialSize']) ? (int)$exp['serialSize'] : null, isset($exp['serialOffset']) ? (int)$exp['serialOffset'] : null]);
            }

            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $error;
        }

        return ['status' => $existing ? 'updated' : 'indexed', 'file_id' => $fileId, 'message' => $parseError === null ? 'Indexed package tables' : 'Indexed metadata; package tables could not be read', 'parse_error' => $parseError];
    } finally {
        if ($temporary && is_file($parsePath)) {
            @unlink($parsePath);
        }
    }
}

function catalog_unverified_index_item(PDO $db, array $config, array $item, ?int $uploadedBy = null, bool $force = false): array
{
    return catalog_unverified_index_path(
        $db,
        $config,
        (int)($item['game']['id'] ?? 0),
        (string)$item['queue_name'],
        (string)$item['path'],
        (string)$item['original_name'],
        (string)($item['reason'] ?? ''),
        $uploadedBy,
        (string)($item['source_relative_path'] ?? ''),
        $force
    );
}

function catalog_unverified_delete_database_row(PDO $db, int $queueGameId, string $queueName): void
{
    catalog_unverified_schema_ensure($db);
    $db->prepare('DELETE FROM ue_files WHERE scan_status="unverified" AND unverified_queue_key=?')->execute([catalog_unverified_queue_key($queueGameId, $queueName)]);
}

function catalog_unverified_update_queue(PDO $db, array $config, array $sourceItem, int $newQueueGameId, string $newQueueName, string $newPath): void
{
    catalog_unverified_schema_ensure($db);
    $oldKey = catalog_unverified_queue_key((int)$sourceItem['game']['id'], (string)$sourceItem['queue_name']);
    $newKey = catalog_unverified_queue_key($newQueueGameId, $newQueueName);
    $db->prepare('UPDATE ue_files SET unverified_queue_key=?,unverified_queue_game_id=?,unverified_queue_name=?,stored_name=?,relative_path=? WHERE scan_status="unverified" AND unverified_queue_key=?')->execute([$newKey, $newQueueGameId, $newQueueName, $newQueueName, catalog_unverified_storage_relative($config, $newPath), $oldKey]);
}

/** @return list<array<string,mixed>> */
function catalog_unverified_game_matches(PDO $db, int $fileId): array
{
    catalog_unverified_schema_ensure($db);
    $file = catalog_one($db, 'SELECT * FROM ue_files WHERE id=? AND scan_status="unverified"', [$fileId]);
    if (!$file) {
        return [];
    }

    $evidenceRows = catalog_all(
        $db,
        'SELECT g.id game_id,g.name game_name,COUNT(*) import_count,COUNT(DISTINCT d.file_id) owner_count,'
        . ' SUM(CASE WHEN EXISTS(SELECT 1 FROM ue_exports q WHERE q.file_id=? AND LOWER(q.full_path)=LOWER(d.required_object_path)) THEN 1 ELSE 0 END) exact_object_matches'
        . ' FROM ue_dependencies d JOIN ue_files owner ON owner.id=d.file_id AND owner.scan_status="verified" JOIN ue_games g ON g.id=owner.game_id'
        . ' WHERE LOWER(d.required_package)=LOWER(?) GROUP BY g.id,g.name',
        [$fileId, (string)$file['package_name']]
    );
    $evidence = [];
    foreach ($evidenceRows as $row) {
        $evidence[(int)$row['game_id']] = $row;
    }

    $games = catalog_all($db, 'SELECT g.id,g.name,p.* FROM ue_games g LEFT JOIN ue_game_profiles p ON p.id=g.profile_id AND p.is_active=1 ORDER BY g.name');
    $rows = [];
    foreach ($games as $game) {
        $gameId = (int)$game['id'];
        $ev = $evidence[$gameId] ?? [];
        $imports = (int)($ev['import_count'] ?? 0);
        $exact = (int)($ev['exact_object_matches'] ?? 0);
        $owners = (int)($ev['owner_count'] ?? 0);
        $exts = gp_extensions($game);
        $extensionOk = $exts === [] || in_array(catalog_clean_unreal_extension((string)$file['extension']), $exts, true);
        $detected = strtoupper((string)($file['detected_engine_key'] ?? ''));
        $profileEngine = strtoupper((string)($game['engine_key'] ?? ''));
        $compatibility = gp_compatibility_for_file($game, (string)$file['extension'], $file['detected_package_version'] !== null ? (int)$file['detected_package_version'] : null, $file['detected_licensee_version'] !== null ? (int)$file['detected_licensee_version'] : null, $detected);
        $engineOk = $profileEngine !== '' && ($detected === $profileEngine || $compatibility !== null);
        $version = $file['detected_package_version'] !== null ? (int)$file['detected_package_version'] : null;
        $versionOk = true;
        if ($version !== null && $version >= 0 && $compatibility === null) {
            if ($game['package_version_min'] !== null && $version < (int)$game['package_version_min']) $versionOk = false;
            if ($game['package_version_max'] !== null && $version > (int)$game['package_version_max']) $versionOk = false;
        }
        $compatible = $extensionOk && $engineOk && $versionOk;
        $rate = $imports > 0 ? round(($exact / $imports) * 100, 1) : null;

        if ($compatible && $exact > 0) {
            $assessment = ($rate !== null && $rate >= 75) || $exact === $imports ? 'likely' : 'possible';
            $rank = 1;
        } elseif ($compatible && $imports > 0) {
            $assessment = 'package_only';
            $rank = 2;
        } elseif ($compatible) {
            $assessment = 'compatible';
            $rank = 3;
        } elseif ($imports > 0) {
            $assessment = 'conflict';
            $rank = 4;
        } else {
            $assessment = 'incompatible';
            $rank = 5;
        }

        $rows[] = [
            'game_id' => $gameId,
            'game_name' => (string)$game['name'],
            'profile_name' => (string)($game['profile_name'] ?? ''),
            'engine_key' => $profileEngine,
            'compatible' => $compatible,
            'assessment' => $assessment,
            'rank' => $rank,
            'import_count' => $imports,
            'owner_count' => $owners,
            'exact_object_matches' => $exact,
            'unmatched_object_count' => max(0, $imports - $exact),
            'match_percent' => $rate,
            'extension_ok' => $extensionOk,
            'engine_ok' => $engineOk,
            'version_ok' => $versionOk,
        ];
    }

    usort($rows, static fn(array $a, array $b): int => ($a['rank'] <=> $b['rank']) ?: ($b['exact_object_matches'] <=> $a['exact_object_matches']) ?: (($b['match_percent'] ?? -1) <=> ($a['match_percent'] ?? -1)) ?: ($b['owner_count'] <=> $a['owner_count']) ?: strcmp((string)$a['game_name'], (string)$b['game_name']));
    return $rows;
}

/**
 * Move one queue item while keeping its database row attached to the physical file.
 */
function catalog_unverified_move_item(PDO $db, array $config, array $source, int $targetGameId): array
{
    $target = catalog_one($db, 'SELECT id,name,slug,profile_id FROM ue_games WHERE id=?', [$targetGameId]);
    if (!$target) throw new RuntimeException('Target game not found.');
    if ((int)$source['game']['id'] === $targetGameId) throw new RuntimeException('The file is already in this game’s unverified queue.');

    $targetDir = uvf_unverified_dir($config, $target, true);
    $destination = uvf_unique_destination($targetDir, (string)$source['queue_name']);
    if (!@rename((string)$source['path'], $destination)) throw new RuntimeException('Could not move the unverified package to the target queue.');
    if (is_file((string)$source['reason_path'])) @rename((string)$source['reason_path'], $destination . '.txt');
    catalog_unverified_update_queue($db, $config, $source, $targetGameId, basename($destination), $destination);

    return ['original_name' => (string)$source['original_name'], 'source_game' => (string)$source['game']['name'], 'target_game' => (string)$target['name']];
}

function catalog_unverified_discard_item(PDO $db, array $config, array $source): array
{
    if (!@unlink((string)$source['path'])) throw new RuntimeException('Could not remove the selected unverified package.');
    if (is_file((string)$source['reason_path'])) @unlink((string)$source['reason_path']);
    catalog_unverified_delete_database_row($db, (int)$source['game']['id'], (string)$source['queue_name']);
    return ['original_name' => (string)$source['original_name'], 'source_game' => (string)$source['game']['name']];
}

/** @return array{status:string,file_id:int,original_name:string,target_game:string,message:string} */
function catalog_unverified_promote_item(PDO $db, array $config, array $source, int $targetGameId, ?int $userId, bool $allowProfileOverride): array
{
    $indexed = catalog_unverified_index_item($db, $config, $source, $userId, false);
    $row = catalog_one($db, 'SELECT * FROM ue_files WHERE id=? AND scan_status="unverified"', [(int)$indexed['file_id']]);
    if (!$row) throw new RuntimeException('The unverified database row is unavailable.');

    $target = catalog_one($db, 'SELECT * FROM ue_games WHERE id=?', [$targetGameId]);
    if (!$target) throw new RuntimeException('Target game not found.');

    $physicalOriginal = (string)$source['original_name'];
    $prepared = catalog_unverified_prepare_path((string)$source['path'], $physicalOriginal);
    try {
        $classification = gp_classify_file($db, $targetGameId, $prepared['path'], $prepared['name']);
        if (!$allowProfileOverride && empty($classification['ok_for_selected_game'])) {
            throw new RuntimeException('Game/profile mismatch. Detected=' . ($classification['detected_engine'] ?? 'unknown') . ', profile=' . ($classification['selected_engine'] ?? 'unknown') . '. ' . implode(' ', (array)$classification['notes']));
        }

        $sourceRelativePath = scanner_normalize_source_relative_path((string)($row['source_relative_path'] ?? ''));
        $detectedEngine = strtoupper((string)($classification['detected_engine'] ?? $row['detected_engine_key'] ?? ''));
        if (in_array($detectedEngine, ['UE4', 'UE5'], true) && $sourceRelativePath === '') {
            throw new RuntimeException('UE4 package identity requires a mounted source-relative path. Requeue this file through folder upload, Local Source Scan or PAK import before verifying it.');
        }

        $packageName = in_array($detectedEngine, ['UE4', 'UE5'], true)
            ? scanner_ue_package_name_from_source_relative($sourceRelativePath)
            : (string)$row['package_name'];
        $md5 = md5_file($prepared['path']);
        $sha1 = sha1_file($prepared['path']);
        if (!is_string($md5) || !is_string($sha1)) throw new RuntimeException('Could not verify queued file hashes.');
        $guid = trim((string)($row['package_guid'] ?? ''));

        if ($guid !== '') {
            $duplicate = catalog_one($db, 'SELECT * FROM ue_files WHERE game_id=? AND scan_status="verified" AND package_guid=? AND md5=? LIMIT 1', [$targetGameId, $guid, strtolower($md5)]);
        } else {
            $duplicate = catalog_one($db, 'SELECT * FROM ue_files WHERE game_id=? AND scan_status="verified" AND md5=? LIMIT 1', [$targetGameId, strtolower($md5)]);
        }
        if ($duplicate) {
            $status = 'duplicate';
            $message = 'Duplicate in selected game';
            if (strcasecmp((string)$duplicate['package_name'], $packageName) !== 0) {
                catalog_package_alias_add($db, (int)$duplicate['id'], $targetGameId, $packageName, (string)$row['original_name'], $guid, strtolower($md5), (int)$row['file_size']);
                $status = 'alias';
                $message = 'Package alias added for existing file identity';
            }
            catalog_unverified_discard_item($db, $config, $source);
            return ['status' => $status, 'file_id' => (int)$duplicate['id'], 'original_name' => (string)$row['original_name'], 'target_game' => (string)$target['name'], 'message' => $message];
        }

        $ext = catalog_clean_unreal_extension((string)pathinfo((string)$row['original_name'], PATHINFO_EXTENSION));
        $dir = rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR) . '/games/' . scanner_slug_text((string)$target['slug']) . '/verified';
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) throw new RuntimeException('Could not create verified storage folder.');
        $storedName = strtolower($md5) . '.' . $ext;
        $dest = $dir . '/' . $storedName;
        $moved = false;
        if (is_file($dest)) {
            if (!@unlink((string)$source['path'])) throw new RuntimeException('Could not discard queued physical duplicate.');
        } elseif ($prepared['temporary']) {
            if (!@copy($prepared['path'], $dest)) throw new RuntimeException('Could not store decompressed package.');
            if (!@unlink((string)$source['path'])) { @unlink($dest); throw new RuntimeException('Could not remove compressed queue wrapper.'); }
            $moved = true;
        } else {
            if (!@rename((string)$source['path'], $dest)) throw new RuntimeException('Could not move queued package into verified storage.');
            $moved = true;
        }

        try {
            $db->beginTransaction();
            $notes = trim((string)$row['scan_notes'] . "\nVerified from unverified queue for " . (string)$target['name'] . '.');
            $db->prepare('UPDATE ue_files SET game_id=?,package_name=?,stored_name=?,relative_path=?,detected_engine_key=?,detected_package_version=?,detected_licensee_version=?,detection_confidence=?,compatibility_status=?,compatibility_label=?,detection_notes=?,file_size=?,md5=?,sha1=?,scan_status="verified",scan_notes=?,uploaded_by=COALESCE(?,uploaded_by),unverified_queue_key=NULL,unverified_queue_game_id=NULL,unverified_queue_name=NULL,unverified_reason=NULL WHERE id=?')->execute([$targetGameId, $packageName, $storedName, 'storage/games/' . scanner_slug_text((string)$target['slug']) . '/verified/' . $storedName, $classification['detected_engine'], $classification['package_version'], $classification['licensee_version'], $classification['confidence'], $classification['compatibility_status'] ?? 'native', $classification['compatibility_label'] ?? null, implode("\n", (array)$classification['notes']), (int)(filesize($dest) ?: 0), strtolower($md5), strtolower($sha1), $notes, $userId, (int)$row['id']]);
            if ($packageName !== (string)$row['package_name']) {
                $exports = catalog_all($db, 'SELECT id,local_path FROM ue_exports WHERE file_id=?', [(int)$row['id']]);
                $update = $db->prepare('UPDATE ue_exports SET full_path=? WHERE id=?');
                foreach ($exports as $export) $update->execute([scanner_join_path_parts([$packageName, (string)$export['local_path']]), (int)$export['id']]);
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            if ($moved && is_file($dest) && !is_file((string)$source['path'])) @rename($dest, (string)$source['path']);
            throw $error;
        }

        if (is_file((string)$source['reason_path'])) @unlink((string)$source['reason_path']);
        scanner_rebuild_dependencies($db, $config, (int)$row['id']);
        scanner_rebuild_affected_dependencies($db, $config, (int)$row['id']);
        return ['status' => 'verified', 'file_id' => (int)$row['id'], 'original_name' => (string)$row['original_name'], 'target_game' => (string)$target['name'], 'message' => 'Promoted existing unverified database row to verified; package tables were reused.'];
    } finally {
        if ($prepared['temporary'] && is_file($prepared['path'])) @unlink($prepared['path']);
    }
}
