<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Shared progress, reader and reference helpers used by catalog scanning.
 * Role: Compatibility functions retained for existing scanner callers while stateful responsibilities live under src/.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Import\CatalogFailedUploadRetention;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoCatalogSourcePathStore;

function scanner_source_path_schema_ensure(PDO $db): void
{
    (new PdoCatalogSourcePathStore($db))->ensureSchema();
}

function scanner_record_source_relative_path(PDO $db, int $fileId, string $sourceRelativePath): void
{
    (new PdoCatalogSourcePathStore($db))->recordIfMissing($fileId, $sourceRelativePath);
}

function scanner_file_has_unreal_package_magic(string $path): bool
{
    return CatalogFailedUploadRetention::hasUnrealPackageMagic($path);
}

function scanner_store_failed_upload(
    array $config,
    string $tmp,
    string $originalName,
    string $gameSlug,
    string $reason
): void {
    $uploadedBy = isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
    (new CatalogFailedUploadRetention($config))->preserve(
        $tmp,
        $originalName,
        $gameSlug,
        $reason,
        $uploadedBy
    );
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
    return \UnrealDb\Catalog\Infrastructure\Readers\CatalogReaderResolver::resolve(
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
            $notes[] = str_replace(
                'Package is unversioned; using assumed UE4 version ',
                'Package is unversioned; using assumed UE4 parser version ',
                $text
            );
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
        return $cache[$ref] = scanner_join_path_parts([
            scanner_ref_path($outer, $imports, $exports, $cache, $seen),
            $name,
        ]);
    }

    $row = $exports[$ref - 1] ?? null;
    if (!$row) {
        return '';
    }
    $outer = (int)($row['outerIndex'] ?? $row['packageIndex'] ?? $row['outer'] ?? 0);
    $name = (string)($row['objectNameText'] ?? '');
    return $cache[$ref] = scanner_join_path_parts([
        scanner_ref_path($outer, $imports, $exports, $cache, $seen),
        $name,
    ]);
}
