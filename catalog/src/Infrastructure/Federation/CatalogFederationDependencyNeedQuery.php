<?php
/**
 * V5-only federation dependency need query.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ParityV5ReadService;

final class CatalogFederationDependencyNeedQuery
{
    public function __construct(private readonly PDO $db)
    {
        require_once dirname(__DIR__, 3) . '/lib/CatalogSupport.php';
    }

    public function requestStillNeeded(string $requiredPackage, string $requiredObjectPath = ''): bool
    {
        $requiredPackage = trim($requiredPackage);
        $requiredObjectPath = trim($requiredObjectPath);
        if ($requiredPackage === '') {
            return false;
        }

        if ($requiredObjectPath === '') {
            $statement = $this->db->prepare(
                'SELECT 1 FROM ue_uedb5_dependency_packages p '
                . 'JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id AND f.scan_status="verified" '
                . 'WHERE p.required_package_name=? AND p.missing_count>0 LIMIT 1'
            );
            $statement->execute([$requiredPackage]);
            return $statement->fetchColumn() !== false;
        }

        $statement = $this->db->prepare(
            'SELECT DISTINCT p.game_id,p.file_id FROM ue_uedb5_dependency_packages p '
            . 'JOIN ue_files f ON f.id=p.file_id AND f.game_id=p.game_id AND f.scan_status="verified" '
            . 'WHERE p.required_package_name=? AND p.missing_count>0 ORDER BY p.file_id'
        );
        $statement->execute([$requiredPackage]);
        $candidates = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if ($candidates === []) {
            return false;
        }

        $config = function_exists('catalog_config') ? \catalog_config() : [];
        if (!is_array($config) || trim((string)($config['storage_path'] ?? '')) === '') {
            throw new RuntimeException('Catalog storage_path is required for UEDB5 federation dependency reads.');
        }
        $reader = new Uedb5ParityV5ReadService($this->db, $config);
        foreach ($candidates as $candidate) {
            foreach ($reader->dependencies((int)$candidate['game_id'], (int)$candidate['file_id']) as $dependency) {
                if ((string)($dependency['outcome'] ?? '') !== 'missing') {
                    continue;
                }
                if (strcasecmp((string)($dependency['required_package'] ?? ''), $requiredPackage) !== 0) {
                    continue;
                }
                if (strcasecmp((string)($dependency['required_object_path'] ?? ''), $requiredObjectPath) === 0) {
                    return true;
                }
            }
        }
        return false;
    }

    /** @param array<string,mixed> $item */
    public function itemAlreadyLocal(array $item): bool
    {
        $guid = strtoupper(trim((string)($item['package_guid'] ?? '')));
        $md5 = strtolower(trim((string)($item['md5'] ?? '')));
        $package = trim((string)($item['required_package'] ?? ''));

        if ($guid !== '' && \catalog_one(
            $this->db,
            'SELECT id FROM ue_files WHERE package_guid=? AND scan_status="verified" LIMIT 1',
            [$guid]
        )) {
            return true;
        }
        if ($md5 !== '' && \catalog_one(
            $this->db,
            'SELECT id FROM ue_files WHERE md5=? AND scan_status="verified" LIMIT 1',
            [$md5]
        )) {
            return true;
        }
        if ($package !== '' && \catalog_one(
            $this->db,
            'SELECT id FROM ue_files WHERE package_name=? AND scan_status="verified" LIMIT 1',
            [$package]
        )) {
            return true;
        }
        return false;
    }
}
