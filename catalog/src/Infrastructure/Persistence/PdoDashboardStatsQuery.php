<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Loads administrative dashboard counters from MySQL and compact cached aggregates.
 * Why: Dashboard persistence belongs in Infrastructure rather than the Application use case.
 * Role: PDO implementation of the DashboardStatsQuery application port.
 * Audit: Keep queries read-only and bounded; never rebuild projections from an interactive dashboard request.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use PDO;
use UnrealDb\Catalog\Application\Dashboard\DashboardStatsQuery;

final class PdoDashboardStatsQuery implements DashboardStatsQuery
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,int> */
    public function load(): array
    {
        $games = $this->one('SELECT COUNT(*) games FROM ue_games');
        $catalogStats = new PdoGameCatalogStats($this->db);
        if ($catalogStats->available()) {
            /*
             * Never rebuild catalogue projections from an interactive request.
             * Imports and maintenance jobs keep ue_game_catalog_stats current;
             * the dedicated rebuild command is available for explicit repair.
             */
            $global = $catalogStats->global();
            $files = [
                'files' => (int)($global['file_count'] ?? 0),
                'verified' => (int)($global['verified_count'] ?? 0),
                'failed' => (int)($global['failed_count'] ?? 0),
            ];
            $dependencies = [
                'missing' => (int)($global['missing_dependency_count'] ?? 0),
                'resolved' => (int)($global['resolved_dependency_count'] ?? 0),
            ];
        } else {
            $files = $this->one(
                'SELECT COUNT(*) files, '
                . "COALESCE(SUM(scan_status='verified'),0) verified, "
                . "COALESCE(SUM(scan_status='failed'),0) failed "
                . 'FROM ue_files'
            );
            $dependencySource = PdoDependencyReadSource::sql($this->db);
            $dependencies = $this->one(
                'SELECT '
                . "COALESCE(SUM(status='missing'),0) missing, "
                . "COALESCE(SUM(status='resolved'),0) resolved "
                . 'FROM ' . $dependencySource
            );
        }

        $transfers = $this->one(
            'SELECT '
            . "COALESCE(SUM(status='queued'),0) queued, "
            . "COALESCE(SUM(status='downloaded'),0) downloaded, "
            . "COALESCE(SUM(status='failed'),0) failed "
            . 'FROM ue_federation_transfer_jobs'
        );
        $mirrors = $this->one(
            "SELECT COALESCE(SUM(status IN ('queued','waiting_admin','uploading')),0) waiting FROM ue_external_mirror_jobs"
        );
        $links = $this->one(
            "SELECT COALESCE(SUM(status='active'),0) active FROM ue_external_download_links"
        );
        $joins = $this->one(
            "SELECT COALESCE(SUM(status='pending'),0) pending FROM ue_federation_join_requests"
        );

        return [
            'games' => (int)($games['games'] ?? 0),
            'files' => (int)($files['files'] ?? 0),
            'verified' => (int)($files['verified'] ?? 0),
            'failed' => (int)($files['failed'] ?? 0),
            'missing' => (int)($dependencies['missing'] ?? 0),
            'resolved' => (int)($dependencies['resolved'] ?? 0),
            'fedQueued' => (int)($transfers['queued'] ?? 0),
            'fedDownloaded' => (int)($transfers['downloaded'] ?? 0),
            'fedFailed' => (int)($transfers['failed'] ?? 0),
            'mirrorWaiting' => (int)($mirrors['waiting'] ?? 0),
            'mirrorActive' => (int)($links['active'] ?? 0),
            'joinPending' => (int)($joins['pending'] ?? 0),
        ];
    }

    /** @return array<string,mixed> */
    private function one(string $sql): array
    {
        $statement = $this->db->query($sql);
        if ($statement === false) {
            return [];
        }
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }
}
