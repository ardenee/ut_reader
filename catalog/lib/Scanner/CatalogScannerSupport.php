<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Shared storage, progress, reader and reference helpers used by catalog scanning.
 * Role: Compatibility functions retained for existing scanner callers while the scanner monolith is decomposed.
 */
declare(strict_types=1);

function scanner_source_path_schema_ensure(PDO $db): void
{
    static $verified = false;
    if ($verified) {
        return;
    }

    $exists = catalog_one(
        $db,
        'SELECT COUNT(*) c FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_files" AND COLUMN_NAME="source_relative_path"'
    );
    if ((int)($exists['c'] ?? 0) === 0) {
        throw new RuntimeException(
            'The database schema is not migrated. Missing: ue_files.source_relative_path. '
            . 'Run php catalog/bin/migrate.php migrate followed by verify.'
        );
    }

    $verified = true;
}

function scanner_record_source_relative_path(PDO $db, int $fileId, string $sourceRelativePath): void
{
    $sourceRelativePath = scanner_normalize_source_relative_path($sourceRelativePath);
    if ($sourceRelativePath === '') {
        return;
    }
    scanner_source_path_schema_ensure($db);
    $db->prepare('UPDATE ue_files SET source_relative_path=CASE WHEN source_relative_path IS NULL OR source_relative_path="" THEN ? ELSE source_relative_path END WHERE id=?')->execute([$sourceRelativePath, $fileId]);
}

function scanner_file_has_unreal_package_magic(string $path): bool
{
    $bytes = @file_get_contents($path, false, null, 0, 4);
    if (!is_string($bytes) || strlen($bytes) !== 4) {
        return false;
    }
    return (int)(unpack('V', $bytes)[1] ?? 0) === 0x9E2A83C1;
}

function scanner_store_failed_upload(array $config, string $tmp, string $originalName, string $gameSlug, string $reason): void
{
    if (!is_file($tmp)) {
        return;
    }
    if (!scanner_file_has_unreal_package_magic($tmp)) {
        @unlink($tmp);
        return;
    }

    $normalizedSlug = scanner_slug_text($gameSlug);
    try {
        require_once __DIR__ . '/../UnverifiedFileManager.php';
        require_once __DIR__ . '/../CatalogUnverifiedIndex.php';

        $db = catalog_db($config);
        $game = catalog_one($db, 'SELECT id,name,slug,profile_id FROM ue_games WHERE slug=? LIMIT 1', [$gameSlug]);
        if (!$game) {
            foreach (catalog_all($db, 'SELECT id,name,slug,profile_id FROM ue_games') as $candidate) {
                if (scanner_slug_text((string)$candidate['slug']) === $normalizedSlug) {
                    $game = $candidate;
                    break;
                }
            }
        }
        if (!$game) {
            throw new RuntimeException('Target unverified queue game was not found.');
        }

        $uploadedBy = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
        $sourceRelativePath = (str_contains($originalName, '/') || str_contains($originalName, '\\'))
            ? scanner_normalize_source_relative_path($originalName)
            : '';
        $stager = new \UnrealDb\Catalog\Infrastructure\Legacy\LegacyUnverifiedFileStager($db, $config);
        $stager->stageFailedUpload(
            (int)$game['id'],
            $tmp,
            $originalName,
            $reason,
            $uploadedBy,
            $sourceRelativePath
        );
        return;
    } catch (Throwable $error) {
        error_log('[UnrealDB failed upload staging] ' . $originalName . ': ' . $error->getMessage());
        if (!is_file($tmp)) {
            return;
        }
    }

    // Last-resort retention when database staging is unavailable before the
    // file reaches queue storage. The queue reconciliation page can recover it.
    $dir = rtrim((string)$config['storage_path'], DIRECTORY_SEPARATOR) . '/games/' . $normalizedSlug . '/unverified';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $cleanName = scanner_clean_original_filename($originalName);
    $name = date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '_' . preg_replace('/[^A-Za-z0-9._ +\-]+/', '_', basename($cleanName));
    $destination = $dir . '/' . $name;
    if (@rename($tmp, $destination)) {
        @file_put_contents($destination . '.txt', $reason . "\nDatabase staging was unavailable; run unverified queue reconciliation.");
    }
}

function scanner_profile_extensions(array $profile, array $config): array
{
    $profileExts = json_decode((string)($profile['allowed_extensions_json'] ?? '[]'), true);
    if (!is_array($profileExts) || !$profileExts) {
        $profileExts = $config['allowed_extensions'] ?? [];
    }
    $out = [];
    foreach ($profileExts as $ext) {
        $ext = catalog_clean_unreal_extension((string)$ext);
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
    $progress(['stage' => $stage, 'done' => $done, 'total' => $total, 'percent' => (int)round(($done / $total) * 100), 'message' => $message]);
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

/** @param list<string> $columns @param list<list<mixed>> $rows */
function scanner_bulk_insert(PDO $db, string $table, array $columns, array $rows): void
{
    if ($rows === []) {
        return;
    }
    if (preg_match('/^[A-Za-z0-9_]+$/', $table) !== 1 || $columns === []) {
        throw new InvalidArgumentException('Invalid bulk insert target.');
    }
    foreach ($columns as $column) {
        if (preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1) {
            throw new InvalidArgumentException('Invalid bulk insert column.');
        }
    }

    $columnCount = count($columns);
    $tuple = '(' . implode(',', array_fill(0, $columnCount, '?')) . ')';
    $values = [];
    $args = [];
    foreach ($rows as $row) {
        if (count($row) !== $columnCount) {
            throw new InvalidArgumentException('Bulk insert row has the wrong column count.');
        }
        $values[] = $tuple;
        array_push($args, ...$row);
    }

    $statement = $db->prepare(
        'INSERT INTO ' . $table . '(' . implode(',', $columns) . ') VALUES ' . implode(',', $values)
    );
    $statement->execute($args);
}

function scanner_load_reader_class(array $config, string $engineKey): string
{
    return \UnrealDb\Catalog\Infrastructure\Readers\CatalogReaderResolver::resolve($config, $engineKey, 'Reader not found for package engine', 'Reader file loaded for package engine ', ['UE4', 'UE5']);
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
            $notes[] = str_replace('Package is unversioned; using assumed UE4 version ', 'Package is unversioned; using assumed UE4 parser version ', $text);
            continue;
        }
        if (str_starts_with($text, 'Package is unversioned; using assumed UE4 parser version ')) {
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
