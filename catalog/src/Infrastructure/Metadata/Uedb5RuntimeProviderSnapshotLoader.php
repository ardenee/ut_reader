<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use PDO;
use RuntimeException;

/**
 * Loads one verified UEDB5 provider and exposes the normalized classic tables
 * consumed by the source-exact VerifyImport resolvers.
 */
final class Uedb5RuntimeProviderSnapshotLoader
{
    private Uedb5MetadataReader $reader;

    public function __construct(
        private readonly PDO $db,
        string $storageRoot
    ) {
        $storageRoot = trim($storageRoot);
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for UEDB5 provider loading.');
        }
        $this->reader = new Uedb5MetadataReader($storageRoot);
    }

    /** @return array<string,mixed> */
    public function load(int $fileId): array
    {
        if ($fileId < 1) {
            throw new RuntimeException('A positive provider file ID is required.');
        }
        $statement = $this->db->prepare(
            'SELECT f.*,v.game_id v5_game_id,v.format_version '
            . 'FROM ue_files f JOIN ue_uedb5_files v ON v.file_id=f.id AND v.game_id=f.game_id '
            . 'WHERE f.id=? AND f.scan_status="verified" AND v.format_version=5 LIMIT 1'
        );
        $statement->execute([$fileId]);
        $file = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($file)) {
            throw new RuntimeException('Verified provider file #' . $fileId . ' has no UEDB5 registration.');
        }
        $gameId = (int)$file['v5_game_id'];
        $snapshot = $this->reader->snapshot($gameId, $fileId);
        try {
            $tables = Uedb5ClassicDependencyResolver::normalizedTables($snapshot);
        } finally {
            $this->reader->clearCache($gameId, $fileId);
        }

        return [
            'file' => array_replace((array)($snapshot['file'] ?? []), $file),
            'summary' => (array)($snapshot['sections']['summary'][0] ?? []),
            'names' => array_values($tables['names']),
            'imports' => array_values($tables['imports']),
            'exports' => array_values($tables['exports']),
            'snapshot' => $snapshot,
        ];
    }
}
