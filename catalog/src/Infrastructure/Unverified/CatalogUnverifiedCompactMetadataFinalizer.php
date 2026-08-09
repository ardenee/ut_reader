<?php
/**
 * Purpose: Publishes a promoted unverified staging snapshot as authoritative format-2 metadata.
 * Why: Promotion must reuse already parsed staging data without depending on legacy SQL metadata tables or reparsing the package.
 * Role: Recovery-safe bridge from temporary unverified staging to current verified metadata.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Unverified;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataSnapshotWriter;

final class CatalogUnverifiedCompactMetadataFinalizer
{
    private readonly CatalogUnverifiedMetadataStore $store;
    private readonly CatalogUnverifiedMetadataSnapshotBuilder $builder;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $this->store = new CatalogUnverifiedMetadataStore($db);
        $this->builder = new CatalogUnverifiedMetadataSnapshotBuilder($db);
    }

    /** @return array<string,mixed> */
    public function finalize(int $fileId): array
    {
        if ($fileId < 1) {
            throw new RuntimeException('A positive promoted file ID is required.');
        }
        $storageRoot = trim((string)($this->config['storage_path'] ?? ''));
        if ($storageRoot === '') {
            throw new RuntimeException('Catalog storage_path is required for compact metadata publication.');
        }

        $statement = $this->db->prepare(
            'SELECT id,game_id,package_name,original_name,scan_status FROM ue_files WHERE id=?'
        );
        $statement->execute([$fileId]);
        $file = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($file) || (string)($file['scan_status'] ?? '') !== 'verified') {
            throw new RuntimeException('The promoted file is not an active verified catalogue row.');
        }
        $gameId = (int)($file['game_id'] ?? 0);
        if ($gameId < 1) {
            throw new RuntimeException('The promoted file has no selected game identity.');
        }

        $registration = $this->db->prepare('SELECT format_version FROM ue_file_metadata WHERE file_id=?');
        $registration->execute([$fileId]);
        if ((int)($registration->fetchColumn() ?: 0) === BlockedCompressedMetadataContainer::FORMAT_VERSION) {
            $verified = (new BlockedCompressedMetadataReader($this->db, $storageRoot))->verify($fileId);
            if ($this->store->has($fileId)) {
                $this->store->delete($fileId);
            }
            $verified['already_compact'] = true;
            return $verified;
        }

        $staging = $this->store->load($fileId);
        $snapshot = $this->builder->forVerified(
            $staging,
            $this->config,
            $fileId,
            $gameId,
            (string)$file['package_name'],
            (string)$file['original_name']
        );
        $result = (new BlockedCompressedMetadataSnapshotWriter($this->db, $storageRoot))->write($snapshot);
        if (
            empty($result['verified'])
            || (int)($result['format_version'] ?? 0) !== BlockedCompressedMetadataContainer::FORMAT_VERSION
        ) {
            throw new RuntimeException('Promoted compact metadata did not verify as format version 2.');
        }

        // Delete temporary staging only after the current metadata container and
        // all current projections have committed and verified successfully.
        $this->store->delete($fileId);
        $result['already_compact'] = false;
        return $result;
    }
}
