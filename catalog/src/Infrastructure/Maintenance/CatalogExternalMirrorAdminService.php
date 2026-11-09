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

    /**
     * Paginate the full external mirror job queue; the old recentJobs(500)
     * view hid older pending manual jobs from the administrator.
     *
     * @return array{rows:list<array<string,mixed>>,total:int,page:int,page_count:int}
     */
    public function pageJobs(int $page, int $pageSize, string $status): array
    {
        $where = match ($status) {
            'waiting' => ' WHERE j.status IN ("queued","waiting_admin","uploading")',
            'failed' => ' WHERE j.status="failed"',
            'cancelled' => ' WHERE j.status="cancelled"',
            'active' => ' WHERE j.status="active"',
            'all' => '',
            default => throw new RuntimeException('Invalid mirror job filter.'),
        };
        if (!in_array($pageSize, [50,100], true)) {
            throw new RuntimeException('Invalid mirror job page size.');
        }
        $total = (int)(\catalog_one(
            $this->db,
            'SELECT COUNT(*) c FROM ue_external_mirror_jobs j '
            . 'JOIN ue_files f ON f.id=j.file_id' . $where
        )['c'] ?? 0);
        $pages = max(1, (int)ceil($total / $pageSize));
        $page = max(1, min($pages, $page));
        $offset = ($page - 1) * $pageSize;
        $rows = \catalog_all(
            $this->db,
            'SELECT j.*,p.provider_name,p.provider_class, '
            . 'f.package_name,f.original_name,f.file_size,f.md5 '
            . 'FROM ue_external_mirror_jobs j '
            . 'LEFT JOIN ue_external_download_providers p ON p.id=j.provider_id '
            . 'JOIN ue_files f ON f.id=j.file_id' . $where
            . ' ORDER BY FIELD(j.status,"waiting_admin","queued","uploading","failed","active","cancelled","expired"),'
            . 'j.created_at DESC,j.id DESC LIMIT ' . $pageSize . ' OFFSET ' . $offset
        );
        return ['rows'=>$rows,'total'=>$total,'page'=>$page,'page_count'=>$pages];
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
        if ($action === 'delete_cancelled') {
            if ($id < 1) throw new RuntimeException('A valid mirror job ID is required.');
            $statement = $this->db->prepare(
                'DELETE FROM ue_external_mirror_jobs WHERE id=? AND status="cancelled"'
            );
            $statement->execute([$id]);
            if ($statement->rowCount() !== 1) {
                throw new RuntimeException('Job is missing or no longer cancelled.');
            }
            return 'Permanently removed cancelled mirror job #' . $id
                . '. The original package and external links were not deleted.';
        }
        if ($action === 'expire_old') {
            return 'Expired ' . \external_expire_old_links($this->db) . ' old active mirror link(s).';
        }
        return '';
    }

    /**
     * Permanently remove ONLY already-cancelled mirror job rows. This never
     * removes the associated original package bytes or external links.
     */
    public function purgeCancelledBulk(int $expected): int
    {
        if ($expected < 1 || $expected > 20000) {
            throw new RuntimeException('Invalid mirror purge count.');
        }
        $this->db->beginTransaction();
        try {
            $rows = $this->db->query(
                'SELECT id FROM ue_external_mirror_jobs '
                . 'WHERE status="cancelled" ORDER BY id FOR UPDATE'
            )->fetchAll(PDO::FETCH_COLUMN);
            if (count($rows) !== $expected) {
                throw new RuntimeException('Cancelled mirror jobs changed. Refresh the page.');
            }
            $stmt = $this->db->prepare(
                'DELETE FROM ue_external_mirror_jobs WHERE status="cancelled"'
            );
            $stmt->execute();
            if ($stmt->rowCount() !== $expected) {
                throw new RuntimeException('Mirror purge count changed; rolling back.');
            }
            $this->db->commit();
            return $expected;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    /**
     * Cancel queued/waiting-admin mirror jobs without deleting job history,
     * files, existing external links or in-flight uploads. The count is a
     * deliberate admin-confirmed snapshot to prevent accidental mass changes.
     */
    public function cancelPendingBulk(int $expected): int
    {
        if ($expected < 1 || $expected > 10000) {
            throw new RuntimeException('Refusing an invalid bulk mirror cancellation count.');
        }
        $this->db->beginTransaction();
        try {
            $rows = $this->db->query(
                'SELECT id FROM ue_external_mirror_jobs '
                . 'WHERE status IN ("queued","waiting_admin") ORDER BY id FOR UPDATE'
            )->fetchAll(PDO::FETCH_COLUMN);
            if (count($rows) !== $expected) {
                throw new RuntimeException(
                    'Mirror queue changed since the confirmation page. Refresh before cancelling.'
                );
            }
            $stmt = $this->db->prepare(
                'UPDATE ue_external_mirror_jobs '
                . 'SET status="cancelled", finished_at=NOW(), last_error="Cancelled by admin (dashboard bulk)." '
                . 'WHERE status IN ("queued","waiting_admin")'
            );
            $stmt->execute();
            if ($stmt->rowCount() !== $expected) {
                throw new RuntimeException('Concurrent mirror job change; cancellation was rolled back.');
            }
            $this->db->commit();
            return $expected;
        } catch (Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
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
