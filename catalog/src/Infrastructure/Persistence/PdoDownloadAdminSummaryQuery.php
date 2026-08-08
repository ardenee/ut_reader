<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Reads the Downloads administration dashboard summary.
 * Why: Download/mirror counters and settings aggregation should have one read-model owner rather than page-local SQL.
 * Role: Infrastructure read model.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Infrastructure\Downloads\CatalogPackageExportSettingsService;

final class PdoDownloadAdminSummaryQuery
{
    private readonly CatalogPackageExportSettingsService $packageSettings;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/FederationAuth.php';
        require_once $root . '/lib/CatalogPublicAccess.php';
        $this->packageSettings = new CatalogPackageExportSettingsService($db);
    }

    /**
     * @return array{
     *   settings:array<string,string>,public:array<string,mixed>,package:array<string,mixed>,
     *   active_links:int,expired_links:int,waiting_jobs:int,failed_jobs:int,providers:int
     * }
     */
    public function summary(): array
    {
        return [
            'settings' => \fed_all_settings($this->db),
            'public' => \catalog_public_access_settings($this->db, $this->config),
            'package' => $this->packageSettings->settings(),
            'active_links' => \catalog_count($this->db, 'SELECT COUNT(*) c FROM ue_external_download_links WHERE status="active"'),
            'expired_links' => \catalog_count($this->db, 'SELECT COUNT(*) c FROM ue_external_download_links WHERE status="expired"'),
            'waiting_jobs' => \catalog_count($this->db, 'SELECT COUNT(*) c FROM ue_external_mirror_jobs WHERE status IN ("queued","waiting_admin","uploading")'),
            'failed_jobs' => \catalog_count($this->db, 'SELECT COUNT(*) c FROM ue_external_mirror_jobs WHERE status="failed"'),
            'providers' => \catalog_count($this->db, 'SELECT COUNT(*) c FROM ue_external_download_providers WHERE is_active=1'),
        ];
    }
}
