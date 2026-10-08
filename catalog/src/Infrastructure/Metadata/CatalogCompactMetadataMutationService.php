<?php
/**
 * Rewrites verified package identity by republishing authoritative UEDB5.
 *
 * Unverified staging stores local export paths and still needs no payload rewrite
 * when only the catalogue package identity changes.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Metadata;

use InvalidArgumentException;
use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedMetadataStore;

final class CatalogCompactMetadataMutationService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
    }

    public function rewritePackageIdentity(int $fileId, string $packageName): int
    {
        $packageName = trim($packageName);
        if ($fileId < 1 || $packageName === '') {
            throw new InvalidArgumentException('A valid file ID and package name are required.');
        }
        if ($this->db->inTransaction()) {
            throw new RuntimeException('UEDB5 package identity rewriting must run outside an existing transaction.');
        }

        $statement = $this->db->prepare(
            'SELECT f.scan_status,f.package_name,v.format_version '
            . 'FROM ue_files f LEFT JOIN ue_uedb5_files v ON v.file_id=f.id AND v.game_id=f.game_id '
            . 'WHERE f.id=? LIMIT 1'
        );
        $statement->execute([$fileId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('File #' . $fileId . ' was not found.');
        }

        $scanStatus = strtolower(trim((string)($row['scan_status'] ?? '')));
        if ($scanStatus === 'unverified') {
            if ((new CatalogUnverifiedMetadataStore($this->db))->has($fileId)) {
                return 0;
            }
            throw new RuntimeException('Unverified file #' . $fileId . ' has no staging metadata.');
        }
        if ($scanStatus !== 'verified'
            || (int)($row['format_version'] ?? 0) !== Uedb5MetadataContainer::FORMAT_VERSION) {
            throw new RuntimeException('File #' . $fileId . ' has no current UEDB5 metadata to rewrite.');
        }

        $oldPackageName = (string)($row['package_name'] ?? '');
        (new Uedb5VerifiedFilePublisher($this->db, $this->config))->publish($fileId);
        return strcasecmp($oldPackageName, $packageName) === 0 ? 0 : 1;
    }

    public static function joinPackagePath(string $packageName, string $localPath): string
    {
        $packageName = trim($packageName);
        $localPath = trim($localPath);
        if ($localPath === '') return $packageName;
        return rtrim($packageName, '.') . '.' . ltrim($localPath, '.');
    }
}
