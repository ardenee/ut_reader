<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Reads and repairs verified files whose stored package GUID is blank/all-zero.
 * Why: Storage-path validation, package-header fallback parsing, identity mutation and projection reconciliation are
 *      maintenance concerns rather than Presentation responsibilities.
 * Role: Infrastructure maintenance service.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Maintenance;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogProjectionReconciliationQueue;

final class CatalogZeroGuidRepairService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
    }

    /** @return list<array<string,mixed>> */
    public function games(): array
    {
        return \catalog_all($this->db, 'SELECT id,name FROM ue_games ORDER BY name');
    }

    public function normalizeGameId(int $gameId): int
    {
        if ($gameId <= 0) {
            return 0;
        }
        foreach ($this->games() as $game) {
            if ((int)$game['id'] === $gameId) {
                return $gameId;
            }
        }
        return 0;
    }

    /** @return list<array<string,mixed>> */
    public function repairableRows(int $gameId, int $limit): array
    {
        $limit = max(1, $limit);
        $sql = 'SELECT f.id,f.game_id,g.name game_name,f.package_name,f.original_name,f.extension,'
            . 'f.package_guid,f.md5,f.file_size,f.relative_path,f.uploaded_at '
            . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
            . 'WHERE f.scan_status="verified" '
            . 'AND (f.package_guid IS NULL OR f.package_guid="" OR f.package_guid="00000000-00000000-00000000-00000000")';
        $args = [];
        if ($gameId > 0) {
            $sql .= ' AND f.game_id=?';
            $args[] = $gameId;
        }
        $sql .= ' ORDER BY g.name,f.package_name,f.original_name,f.id LIMIT ' . $limit;

        $rows = [];
        foreach (\catalog_all($this->db, $sql, $args) as $row) {
            $candidate = $this->candidateForFile($row);
            if ($candidate !== '') {
                $row['candidate_guid'] = $candidate;
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** @param list<int> $ids @return array{fixed:int,skipped:int} */
    public function repair(array $ids, int $maxRows): array
    {
        $ids = array_values(array_filter(
            array_unique(array_map('intval', $ids)),
            static fn(int $id): bool => $id > 0
        ));
        if ($ids === []) {
            throw new RuntimeException('Select at least one file to repair.');
        }
        if (count($ids) > $maxRows) {
            throw new RuntimeException('Too many files selected. Process at most ' . $maxRows . ' files at once.');
        }

        $fixed = 0;
        $skipped = 0;
        $contexts = [];
        foreach ($ids as $id) {
            $file = \catalog_one(
                $this->db,
                'SELECT id,game_id,package_name,package_guid,relative_path FROM ue_files WHERE id=?',
                [$id]
            );
            if (!$file || !$this->isZero((string)($file['package_guid'] ?? ''))) {
                $skipped++;
                continue;
            }
            $candidate = $this->candidateForFile($file);
            if ($candidate === '') {
                $skipped++;
                continue;
            }

            $this->db->prepare('UPDATE ue_files SET package_guid=? WHERE id=?')->execute([$candidate, $id]);
            $contexts[] = [
                'file_id' => $id,
                'game_id' => (int)$file['game_id'],
                'package_name' => (string)$file['package_name'],
            ];
            $fixed++;
        }

        foreach ($contexts as $context) {
            CatalogProjectionReconciliationQueue::enqueue(
                $this->db,
                (int)$context['file_id'],
                [(int)$context['game_id']],
                [(string)$context['package_name']],
                $this->config
            );
        }

        return ['fixed' => $fixed, 'skipped' => $skipped];
    }

    private function isZero(string $guid): bool
    {
        $guid = strtoupper(trim($guid));
        return $guid === '' || $guid === '00000000-00000000-00000000-00000000';
    }

    private function candidateForFile(array $file): string
    {
        $path = $this->storedPath($file);
        return $path !== null ? $this->candidateFromPath($path) : '';
    }

    private function storedPath(array $file): ?string
    {
        $storageRoot = realpath(rtrim((string)$this->config['storage_path'], DIRECTORY_SEPARATOR));
        $storedPath = realpath(dirname(__DIR__, 3) . '/' . (string)$file['relative_path']);
        if (!$storageRoot || !$storedPath || !str_starts_with($storedPath, $storageRoot) || !is_file($storedPath)) {
            return null;
        }
        return $storedPath;
    }

    private function candidateFromPath(string $path): string
    {
        $bytes = @file_get_contents($path, false, null, 0, 64);
        if (!is_string($bytes) || strlen($bytes) < 52) {
            return '';
        }
        $tag = (int)(unpack('V', substr($bytes, 0, 4))[1] ?? 0);
        if (!\UnrealDb\Catalog\Domain\Package\CatalogUnrealPackageTag::isSupportedLittleEndianValue($tag)) {
            return '';
        }
        $parts = [
            (int)(unpack('V', substr($bytes, 36, 4))[1] ?? 0),
            (int)(unpack('V', substr($bytes, 40, 4))[1] ?? 0),
            (int)(unpack('V', substr($bytes, 44, 4))[1] ?? 0),
            (int)(unpack('V', substr($bytes, 48, 4))[1] ?? 0),
        ];
        if ($parts === [0, 0, 0, 0]) {
            return '';
        }
        return sprintf('%08X-%08X-%08X-%08X', $parts[0], $parts[1], $parts[2], $parts[3]);
    }
}
