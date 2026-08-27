<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns administrator-facing external mirror jobs, providers and link lifecycle.
 * Why: Mirror admin pages should not independently implement provider persistence and state transitions.
 * Role: Infrastructure maintenance service over the existing ExternalMirrors/FederationAuth compatibility layer.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Maintenance;

use PDO;
use RuntimeException;
use Throwable;

final class CatalogExternalMirrorAdminService
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/ExternalMirrors.php';
    }

    /** @return array<string,mixed> */
    public function job(int $id): array
    {
        $job = \catalog_one(
            $this->db,
            'SELECT j.*, p.provider_name, p.provider_key, p.expiry_days provider_expiry_days, '
            . 'f.package_name, f.original_name, f.file_size, f.md5, f.sha1, f.package_guid, f.relative_path '
            . 'FROM ue_external_mirror_jobs j '
            . 'LEFT JOIN ue_external_download_providers p ON p.id=j.provider_id '
            . 'JOIN ue_files f ON f.id=j.file_id WHERE j.id=?',
            [$id]
        );
        if (!$job) {
            throw new RuntimeException('Mirror job not found.');
        }
        return $job;
    }

    /** @return list<array<string,mixed>> */
    public function recentJobs(int $limit = 500): array
    {
        $limit = max(1, min(5000, $limit));
        return \catalog_all(
            $this->db,
            'SELECT j.*, p.provider_name, p.provider_class, f.package_name, f.original_name, f.file_size, f.md5 '
            . 'FROM ue_external_mirror_jobs j '
            . 'LEFT JOIN ue_external_download_providers p ON p.id=j.provider_id '
            . 'JOIN ue_files f ON f.id=j.file_id '
            . 'ORDER BY FIELD(j.status,"waiting_admin","queued","uploading","failed","active","cancelled","expired"), '
            . 'j.created_at DESC LIMIT ' . $limit
        );
    }

    /** @return array<string,string> */
    public function settings(): array
    {
        return \fed_all_settings($this->db);
    }

    /** @return list<array<string,mixed>> */
    public function providers(bool $activeOnly = false): array
    {
        $where = $activeOnly ? ' WHERE is_active=1' : '';
        return \catalog_all(
            $this->db,
            'SELECT * FROM ue_external_download_providers' . $where . ' ORDER BY priority, provider_name'
        );
    }

    /** @return list<array<string,mixed>> */
    public function links(int $fileId = 0, int $limit = 500): array
    {
        $limit = max(1, min(5000, $limit));
        $where = '';
        $args = [];
        if ($fileId > 0) {
            $where = ' WHERE l.file_id=?';
            $args[] = $fileId;
        }
        return \catalog_all(
            $this->db,
            'SELECT l.*, p.provider_name, p.provider_key, f.package_name, f.original_name, f.md5 '
            . 'FROM ue_external_download_links l '
            . 'JOIN ue_external_download_providers p ON p.id=l.provider_id '
            . 'JOIN ue_files f ON f.id=l.file_id'
            . $where
            . ' ORDER BY l.created_at DESC LIMIT ' . $limit,
            $args
        );
    }

    public function storagePath(array $job): string
    {
        $path = realpath(dirname(__DIR__, 3) . '/' . (string)$job['relative_path']);
        $root = realpath(rtrim((string)$this->config['storage_path'], DIRECTORY_SEPARATOR));
        if (!$path || !$root || !str_starts_with($path, $root) || !is_file($path)) {
            throw new RuntimeException('Stored file missing or outside storage.');
        }
        return $path;
    }

    /** @return array<string,mixed> */
    public function fulfill(int $id, string $url, int $expiryDays, ?int $userId): array
    {
        $job = $this->job($id);
        $url = trim($url);
        if ($url === '' || !preg_match('/^https?:\/\//i', $url)) {
            throw new RuntimeException('A valid http/https external URL is required.');
        }
        if (empty($job['provider_id'])) {
            throw new RuntimeException('Mirror job has no provider.');
        }
        if (!in_array((string)$job['status'], ['queued', 'waiting_admin', 'failed', 'uploading'], true)) {
            throw new RuntimeException('This job cannot be fulfilled from status: ' . (string)$job['status']);
        }

        $days = $expiryDays > 0
            ? $expiryDays
            : (int)($job['provider_expiry_days'] ?: \fed_setting($this->db, 'external_mirror_expiry_days', '7'));

        $this->db->beginTransaction();
        try {
            $linkId = \external_create_manual_link(
                $this->db,
                (int)$job['file_id'],
                (int)$job['provider_id'],
                $url,
                $userId,
                $days
            );
            $this->db->prepare(
                'UPDATE ue_external_mirror_jobs '
                . 'SET status="active", link_id=?, finished_at=NOW(), last_error=? WHERE id=?'
            )->execute([
                $linkId,
                'Fulfilled manually with external mirror link.',
                (int)$job['id'],
            ]);
            $this->db->commit();
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }

        return $job;
    }

    public function defaultExpiryDays(array $job): int
    {
        return (int)($job['provider_expiry_days'] ?: \fed_setting($this->db, 'external_mirror_expiry_days', '7'));
    }

    public function handleQueueAction(string $action, int $id): string
    {
        if ($action === 'approve') {
            $this->db->prepare(
                'UPDATE ue_external_mirror_jobs SET status="queued" WHERE id=? AND status="waiting_admin"'
            )->execute([$id]);
            return 'Mirror job approved/queued.';
        }
        if ($action === 'cancel') {
            $this->db->prepare(
                'UPDATE ue_external_mirror_jobs '
                . 'SET status="cancelled", finished_at=NOW(), last_error="Cancelled by admin." '
                . 'WHERE id=? AND status IN ("queued","waiting_admin","failed")'
            )->execute([$id]);
            return 'Mirror job cancelled.';
        }
        if ($action === 'retry') {
            $this->db->prepare(
                'UPDATE ue_external_mirror_jobs '
                . 'SET status="queued", attempts=0, started_at=NULL, finished_at=NULL, last_error=NULL '
                . 'WHERE id=? AND status IN ("failed","cancelled")'
            )->execute([$id]);
            return 'Mirror job retried.';
        }
        if ($action === 'expire_old') {
            return 'Expired ' . \external_expire_old_links($this->db) . ' old active mirror link(s).';
        }
        return '';
    }

    /** @param array<string,mixed> $input */
    public function handleProviderAction(string $action, array $input): string
    {
        if ($action === 'add_provider') {
            $key = strtolower(trim((string)($input['provider_key'] ?? '')));
            $name = trim((string)($input['provider_name'] ?? ''));
            $class = trim((string)($input['provider_class'] ?? 'ManualProvider')) ?: 'ManualProvider';
            if (!preg_match('/^[a-z0-9_-]+$/', $key)) {
                throw new RuntimeException('Provider key may only use lowercase letters, numbers, underscore and dash.');
            }
            if ($name === '') {
                throw new RuntimeException('Provider name required.');
            }
            $stmt = $this->db->prepare(
                'INSERT INTO ue_external_download_providers('
                . 'provider_key,provider_name,provider_class,is_active,config_json,max_file_size_mb,expiry_days,priority,notes'
                . ') VALUES(?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $key,
                $name,
                $class,
                (int)($input['is_active'] ?? 1),
                trim((string)($input['config_json'] ?? '{}')) ?: '{}',
                (int)($input['max_file_size_mb'] ?? 1024),
                (int)($input['expiry_days'] ?? 7),
                (int)($input['priority'] ?? 100),
                trim((string)($input['notes'] ?? '')) ?: null,
            ]);
            return 'Provider added.';
        }

        if ($action === 'toggle_provider') {
            $id = (int)($input['id'] ?? 0);
            $row = \catalog_one($this->db, 'SELECT * FROM ue_external_download_providers WHERE id=?', [$id]);
            if (!$row) {
                throw new RuntimeException('Provider not found.');
            }
            $this->db->prepare('UPDATE ue_external_download_providers SET is_active=? WHERE id=?')
                ->execute([(int)$row['is_active'] ? 0 : 1, $id]);
            return 'Provider toggled.';
        }

        return '';
    }

    /** @param array<string,mixed> $input */
    public function handleLinkAction(string $action, array $input, ?int $userId): string
    {
        if ($action === 'add_manual') {
            $fileId = (int)($input['file_id'] ?? 0);
            $providerId = (int)($input['provider_id'] ?? 0);
            $url = trim((string)($input['external_url'] ?? ''));
            $days = (int)($input['expiry_days'] ?? 7);
            if ($fileId <= 0 || $providerId <= 0 || $url === '') {
                throw new RuntimeException('File, provider and URL are required.');
            }
            \external_create_manual_link($this->db, $fileId, $providerId, $url, $userId, $days);
            return 'Manual external mirror link added.';
        }
        if ($action === 'expire') {
            $this->db->prepare('UPDATE ue_external_download_links SET status="expired" WHERE id=?')
                ->execute([(int)($input['id'] ?? 0)]);
            return 'Mirror link expired.';
        }
        if ($action === 'mark_broken') {
            $this->db->prepare(
                'UPDATE ue_external_download_links '
                . 'SET status="broken", error_message="Marked broken by admin." WHERE id=?'
            )->execute([(int)($input['id'] ?? 0)]);
            return 'Mirror link marked broken.';
        }
        return '';
    }
}
