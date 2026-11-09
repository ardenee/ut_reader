<?php
/**
 * Bounded, read-only dashboard counter drill-down.
 *
 * Every predicate mirrors one exact PdoDashboardStatsQuery counter. The
 * dashboard's source-published dependency aggregates remain in missing.php.
 * No user-provided SQL, expensive table reconstruction or hidden writes.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Persistence;

use InvalidArgumentException;
use PDO;

final class PdoDashboardDetailPageQuery
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<array<string,mixed>> */
    public function page(string $metric, int $page, int $pageSize = 50): array
    {
        if ($page < 1 || $pageSize < 1 || $pageSize > 100) {
            throw new InvalidArgumentException('Invalid dashboard detail pagination');
        }
        $offset = ($page - 1) * $pageSize;
        if ($offset < 0 || $offset > 5000000) {
            throw new InvalidArgumentException('Dashboard detail offset out of range');
        }
        $sql = match ($metric) {
            'files', 'verified', 'failed' =>
                'SELECT f.id, f.game_id, f.package_name, f.original_name, '
                . 'f.scan_status, f.extension, f.metadata_error, f.scan_notes, '
                . 'g.name game_name '
                . 'FROM ue_files f JOIN ue_games g ON g.id=f.game_id '
                . ($metric === 'verified'
                    ? "WHERE f.scan_status='verified' "
                    : ($metric === 'failed' ? "WHERE f.scan_status='failed' " : ''))
                . 'ORDER BY f.id DESC',
            'fedQueued', 'fedDownloaded', 'fedFailed' =>
                'SELECT j.id, j.status, j.peer_id, j.direction, j.local_file_id, '
                . 'j.remote_file_id, j.attempts, j.last_error, j.created_at, '
                . 'p.site_name peer_name '
                . 'FROM ue_federation_transfer_jobs j '
                . 'LEFT JOIN ue_federation_peers p ON p.id=j.peer_id '
                . "WHERE j.status='" . match ($metric) {
                    'fedQueued' => 'queued',
                    'fedDownloaded' => 'downloaded',
                    'fedFailed' => 'failed',
                } . "' ORDER BY j.id DESC",
            'mirrorWaiting' =>
                'SELECT m.id, m.status, m.file_id, m.provider_id, m.attempts, '
                . 'm.last_error, m.created_at, f.original_name, f.package_name, '
                . 'g.name game_name '
                . 'FROM ue_external_mirror_jobs m '
                . 'LEFT JOIN ue_files f ON f.id=m.file_id '
                . 'LEFT JOIN ue_games g ON g.id=f.game_id '
                . "WHERE m.status IN ('queued','waiting_admin','uploading') "
                . 'ORDER BY m.id DESC',
            'mirrorActive' =>
                'SELECT l.id, l.file_id, l.provider_id, l.status, '
                . 'l.expires_at, l.requested_count, l.updated_at, '
                . 'f.package_name, f.original_name '
                . 'FROM ue_external_download_links l '
                . 'LEFT JOIN ue_files f ON f.id=l.file_id '
                . "WHERE l.status='active' ORDER BY l.id DESC",
            'joinPending' =>
                'SELECT id, status, site_name, site_url, contact_name, '
                . "contact_email, created_at FROM ue_federation_join_requests "
                . "WHERE status='pending' ORDER BY id DESC",
            default => throw new InvalidArgumentException('Unknown dashboard metric'),
        };
        // All pagination parameters are bounded integers. All query choices
        // are hard-coded and not constructed from untrusted user strings.
        $stmt = $this->db->query($sql . ' LIMIT ' . $pageSize . ' OFFSET ' . $offset);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
