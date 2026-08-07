<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Defines the application class `CatalogDashboardStats` for catalog dashboard stats.
 * Why: It keeps this responsibility in the namespaced architecture instead of repeating it in page, API, or worker
 *      entry points.
 * Role: Application-layer orchestration shared by pages, APIs, jobs, and infrastructure adapters.
 * Audit: Primary namespaced implementation; prefer reusing this layer over creating parallel page-local copies of the
 *        same behavior.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Dashboard;

use PDO;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoGameCatalogStats;

/** Loads administrative dashboard counters from compact cached aggregates. */
final class CatalogDashboardStats
{
    /** @return array<string, int> */
    public static function load(PDO $db): array
    {
        $games = \catalog_one($db, 'SELECT COUNT(*) games FROM ue_games') ?? [];
        $catalogStats = new PdoGameCatalogStats($db);
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
            $files = \catalog_one(
                $db,
                'SELECT COUNT(*) files, '
                . "COALESCE(SUM(scan_status='verified'),0) verified, "
                . "COALESCE(SUM(scan_status='failed'),0) failed "
                . 'FROM ue_files'
            ) ?? [];
            $dependencies = \catalog_one(
                $db,
                'SELECT '
                . "COALESCE(SUM(status='missing'),0) missing, "
                . "COALESCE(SUM(status='resolved'),0) resolved "
                . 'FROM ue_dependencies'
            ) ?? [];
        }

        $transfers = \catalog_one(
            $db,
            'SELECT '
            . "COALESCE(SUM(status='queued'),0) queued, "
            . "COALESCE(SUM(status='downloaded'),0) downloaded, "
            . "COALESCE(SUM(status='failed'),0) failed "
            . 'FROM ue_federation_transfer_jobs'
        ) ?? [];
        $mirrors = \catalog_one(
            $db,
            "SELECT COALESCE(SUM(status IN ('queued','waiting_admin','uploading')),0) waiting FROM ue_external_mirror_jobs"
        ) ?? [];
        $links = \catalog_one(
            $db,
            "SELECT COALESCE(SUM(status='active'),0) active FROM ue_external_download_links"
        ) ?? [];
        $joins = \catalog_one(
            $db,
            "SELECT COALESCE(SUM(status='pending'),0) pending FROM ue_federation_join_requests"
        ) ?? [];

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
}
