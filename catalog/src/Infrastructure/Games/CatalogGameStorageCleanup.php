<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Removes staged/unverified and linked PAK storage associated with one game.
 * Why: Game lifecycle orchestration should not own filesystem traversal and storage deletion details.
 * Role: Infrastructure filesystem collaborator for game reset/delete operations.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Games;

use PDO;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedQueueStorage;

final class CatalogGameStorageCleanup
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
    }

    /** @return array{deleted:int,failed:int} */
    public function deleteStagedFiles(array $game): array
    {
        $directory = CatalogUnverifiedQueueStorage::unverifiedDirectory($this->config, $game, false);
        if (!is_dir($directory) || !is_readable($directory)) {
            return ['deleted' => 0, 'failed' => 0];
        }

        $deleted = 0;
        $failed = 0;
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (!is_file($path) || is_link($path)) {
                continue;
            }
            if (@unlink($path)) {
                $deleted++;
            } else {
                $failed++;
            }
        }
        return ['deleted' => $deleted, 'failed' => $failed];
    }

    /** @return array{deleted:int,failed:int} */
    public function deletePakStorage(int $gameId): array
    {
        $columns = \catalog_all(
            $this->db,
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_pak_files"'
        );
        if ($columns === []) {
            return ['deleted' => 0, 'failed' => 0];
        }
        $columnNames = array_fill_keys(
            array_map(static fn(array $row): string => (string)$row['COLUMN_NAME'], $columns),
            true
        );
        $pathColumn = isset($columnNames['stored_path'])
            ? 'stored_path'
            : (isset($columnNames['relative_path']) ? 'relative_path' : null);
        if ($pathColumn === null) {
            return ['deleted' => 0, 'failed' => 0];
        }

        $rows = \catalog_all(
            $this->db,
            'SELECT ' . $pathColumn . ' storage_path FROM ue_pak_files WHERE game_id=?',
            [$gameId]
        );
        $storageRoot = realpath(rtrim((string)$this->config['storage_path'], DIRECTORY_SEPARATOR));
        if ($storageRoot === false || !is_dir($storageRoot)) {
            return ['deleted' => 0, 'failed' => count($rows)];
        }
        $rootPrefix = rtrim(str_replace('\\', '/', $storageRoot), '/') . '/';

        $deleted = 0;
        $failed = 0;
        foreach ($rows as $row) {
            $relative = trim((string)($row['storage_path'] ?? ''));
            if ($relative === '') {
                continue;
            }
            $relative = ltrim(str_replace('\\', '/', $relative), '/');
            if (str_starts_with(strtolower($relative), 'storage/')) {
                $relative = substr($relative, strlen('storage/'));
            }
            if ($relative === '' || str_contains($relative, '../') || str_contains($relative, "\0")) {
                $failed++;
                continue;
            }
            $candidate = $storageRoot . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $real = realpath($candidate);
            if ($real === false || !is_file($real) || is_link($real)) {
                continue;
            }
            $normalized = str_replace('\\', '/', $real);
            $inside = DIRECTORY_SEPARATOR === '\\'
                ? str_starts_with(strtolower($normalized), strtolower($rootPrefix))
                : str_starts_with($normalized, $rootPrefix);
            if (!$inside) {
                $failed++;
                continue;
            }
            if (@unlink($real)) {
                $deleted++;
            } else {
                $failed++;
            }
        }
        return ['deleted' => $deleted, 'failed' => $failed];
    }
}
