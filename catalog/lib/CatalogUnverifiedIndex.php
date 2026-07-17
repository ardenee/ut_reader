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

        $guid = trim(catalog_header_guid($header));
        $version = array_key_exists('version', $header) ? (int)$header['version'] : ($summary['ok'] ? (int)($summary['version'] ?? 0) : 0);
        $licensee = array_key_exists('licensee',$MÄ