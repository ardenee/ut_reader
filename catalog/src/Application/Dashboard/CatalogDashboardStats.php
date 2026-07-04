<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Application\Dashboard;

use PDO;

/**
 * Loads the live administrative dashboard counters.
 *
 * The application layer groups each table's related metrics, while existing
 * presentation code continues to decide labels, links, and display states.
 */
final class CatalogDashboardStats
{
    /**
     * @return array<string, int>
     */
    public static function load(PDO $db): array
    {
        $games = \catalog_one($db, 'SELECT COUNT(*) games FROM ue_games') ?? [];
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
