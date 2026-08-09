<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Answers whether an approved federation dependency is still missing locally.
 * Why: Dependency-state and local-identity reads are query concerns and should not be mixed with network polling or transfer-job mutation.
 * Role: Infrastructure federation read service preserving the existing dependency/local-file matching rules.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Federation;

use PDO;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoDependencyReadSource;

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

        $dependencySource = PdoDependencyReadSource::sql($this->db);
        if ($requiredObjectPath !== '') {
            $row = \catalog_one(
                $this->db,
                'SELECT d.id FROM ' . $dependencySource . ' d '
                    . 'JOIN ue_files f ON f.id=d.file_id '
                    . 'WHERE d.status="missing" AND f.scan_status="verified" '
                    . 'AND d.required_package=? AND d.required_object_path=? LIMIT 1',
                [$requiredPackage, $requiredObjectPath]
            );
            if ($row) {
                return true;
            }
        }

        return \catalog_one(
            $this->db,
            'SELECT d.id FROM ' . $dependencySource . ' d '
                . 'JOIN ue_files f ON f.id=d.file_id '
                . 'WHERE d.status="missing" AND f.scan_status="verified" '
                . 'AND d.required_package=? LIMIT 1',
            [$requiredPackage]
        ) !== null;
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
