<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogScanner.php';

function catalog_file_maintenance_storage_path(array $config, array $file): ?string
{
    $storageRoot = realpath(rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR));
    if ($storageRoot === false || !is_dir($storageRoot)) {
        throw new RuntimeException('Catalog storage folder is unavailable.');
    }
    $relativePath = ltrim(str_replace('\\', '/', (string)($file['relative_path'] ?? '')), '/');
    if ($relativePath === '') {
        return null;
    }
    $catalogRoot = realpath(__DIR__ . '/..');
    if ($catalogRoot === false) {
        throw new RuntimeException('Catalog application folder is unavailable.');
    }
    $candidate = $catalogRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
    if (!file_exists($candidate)) {
        return null;
    }
    $resolved = realpath($candidate);
    $rootPrefix = rtrim($storageRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if ($resolved === false || !str_starts_with($resolved, $rootPrefix)) {
        throw new RuntimeException('Refusing to use a file outside catalog storage.');
    }
    return $resolved;
}

function catalog_file_maintenance_emit(?callable $progress, string $stage, int $percent, string $message): void
{
    scanner_emit_percent($progress, $stage, $percent, $message);
}

function catalog_file_maintenance_storage_root(array $config): string
{
    $storageRoot = trim((string)($config['storage_path'] ?? ''));
    if ($storageRoot === '') {
        throw new RuntimeException('Catalog storage_path is required for compact file maintenance.');
    }
    return $storageRoot;
}

function catalog_file_maintenance_metadata_path(array $config, int $gameId, int $fileId): string
{
    return \UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer::path(
        catalog_file_maintenance_storage_root($config),
        $gameId,
        $fileId
    );
}

/** @return array<string,mixed> */
function catalog_file_maintenance_snapshot(PDO $db, int $fileId, ?array $config = null): array
{
    $config ??= catalog_config();
    return (new \UnrealDb\Catalog\Infrastructure\Metadata\CompactFileMaintenanceSnapshot(
        $db,
        catalog_file_maintenance_storage_root($config)
    ))->capture($fileId);
}

function catalog_file_maintenance_restore_snapshot(PDO $db, array $snapshot, ?array $config = null): void
{
    $config ??= catalog_config();
    (new \UnrealDb\Catalog\Infrastructure\Metadata\CompactFileMaintenanceSnapshot(
        $db,
        catalog_file_maintenance_storage_root($config)
    ))->restore($snapshot);
}

function catalog_file_maintenance_source_relative_path(array $snapshot): string
{
    $filePath = scanner_normalize_source_relative_path((string)($snapshot['file']['source_relative_path'] ?? ''));
    if ($filePath !== '') {
        return $filePath;
    }
    foreach ((array)($snapshot['locations'] ?? []) as $location) {
        if (!is_array($location)) {
            continue;
        }
        $path = scanner_normalize_source_relative_path((string)($location['source_relative_path'] ?? ''));
        if ($path !== '') {
            return $path;
        }
    }
    return '';
}

/** @return list<int> */
function catalog_file_maintenance_affected_ids(PDO $db, int $gameId, int $removedFileId, string $packageName): array
{
    if ((string)($_POST['operation'] ?? '') === 'sync_reimport') {
        return [];
    }

    $packageName = trim($packageName);
    $rows = catalog_all(
        $db,
        'SELECT DISTINCT l.file_id FROM ue_dependency_links l '
        . 'JOIN ue_terms t ON t.id=l.required_package_term_id '
        . 'JOIN ue_files owner ON owner.id=l.file_id '
        . 'WHERE owner.game_id=? AND l.file_id<>? AND ('
        . 'l.resolved_file_id=? OR (t.value_hash=? AND t.value_length=? AND t.value_prefix=?))',
        [
            $gameId,
            $removedFileId,
            $removedFileId,
            md5($packageName, true),
            strlen($packageName),
            substr($packageName, 0, 200),
        ]
    );

    return array_map(static fn(array $row): int => (int)$row['file_id'], $rows);
}

function catalog_file_maintenance_refresh_ids(PDO $db, array $config, array $fileIds, ?callable $progress, int $startPercent, int $endPercent, string $prefix): void
{
    $fileIds = array_values(array_unique(array_filter(array_map('intval', $fileIds), static fn(int $id): bool => $id > 0)));
    $total = count($fileIds);
    if ($total === 0) {
        catalog_file_maintenance_emit($progress, 'dependencies', $endPercent, $prefix . ': no affected packages');
        return;
    }
    foreach ($fileIds as $index => $fileId) {
        scanner_rebuild_dependencies(
            $db,
            $config,
            $fileId,
            $progress,
            scanner_range_percent($startPercent, $endPercent, $index, $total),
            scanner_range_percent($startPercent, $endPercent, $index + 1, $total),
            $prefix . ' ' . ($index + 1) . '/' . $total
        );
    }
}
