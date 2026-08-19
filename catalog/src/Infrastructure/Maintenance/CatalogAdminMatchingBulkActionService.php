<?php
/**
 * Executes explicit administrator actions against all rows matching the current
 * System Errors or Upload Issues filters without materialising thousands of IDs.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Maintenance;

use PDO;

final class CatalogAdminMatchingBulkActionService
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{matched:int,affected:int,action:string}
     */
    public function systemErrors(
        string $action,
        array $filters,
        ?int $userId,
        string $note = ''
    ): array {
        $action = $this->action($action);
        [$whereSql, $args] = $this->systemErrorWhere($filters);
        $matched = $this->count('ue_system_errors', $whereSql, $args);
        if ($matched < 1) {
            return ['matched' => 0, 'affected' => 0, 'action' => $action];
        }

        if ($action === 'delete') {
            $statement = $this->db->prepare('DELETE FROM ue_system_errors' . $whereSql);
            $statement->execute($args);
            return ['matched' => $matched, 'affected' => $statement->rowCount(), 'action' => $action];
        }

        $targetStatus = $action === 'reopen' ? 'open' : ($action === 'resolve' ? 'resolved' : 'ignored');
        if ($targetStatus === 'open') {
            $sql = 'UPDATE ue_system_errors SET status="open",resolved_at=NULL,resolved_by=NULL,resolution_note=NULL'
                . $whereSql;
            $mutationArgs = $args;
        } else {
            $sql = 'UPDATE ue_system_errors SET status=?,resolved_at=?,resolved_by=?,resolution_note=?' . $whereSql;
            $mutationArgs = [
                $targetStatus,
                gmdate('Y-m-d H:i:s'),
                $userId !== null && $userId > 0 ? $userId : null,
                $this->text($note, 500),
                ...$args,
            ];
        }
        $statement = $this->db->prepare($sql);
        $statement->execute($mutationArgs);
        return ['matched' => $matched, 'affected' => $statement->rowCount(), 'action' => $action];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{matched:int,affected:int,action:string}
     */
    public function uploadIssues(
        string $action,
        array $filters,
        ?int $userId,
        string $note = ''
    ): array {
        $action = $this->action($action);
        [$whereSql, $args] = $this->uploadIssueWhere($filters);
        $matched = $this->count('ue_upload_bucket_issues', $whereSql, $args);
        if ($matched < 1) {
            return ['matched' => 0, 'affected' => 0, 'action' => $action];
        }

        if ($action === 'delete') {
            $statement = $this->db->prepare('DELETE FROM ue_upload_bucket_issues' . $whereSql);
            $statement->execute($args);
            return ['matched' => $matched, 'affected' => $statement->rowCount(), 'action' => $action];
        }

        $targetStatus = $action === 'reopen' ? 'open' : ($action === 'resolve' ? 'resolved' : 'ignored');
        if ($targetStatus === 'open') {
            $sql = 'UPDATE ue_upload_bucket_issues '
                . 'SET status="open",resolved_at=NULL,resolved_by=NULL,resolution_note=NULL' . $whereSql;
            $mutationArgs = $args;
        } else {
            $sql = 'UPDATE ue_upload_bucket_issues SET status=?,resolved_at=?,resolved_by=?,resolution_note=?'
                . $whereSql;
            $mutationArgs = [
                $targetStatus,
                gmdate('Y-m-d H:i:s'),
                $userId !== null && $userId > 0 ? $userId : null,
                $this->text($note, 500),
                ...$args,
            ];
        }
        $statement = $this->db->prepare($sql);
        $statement->execute($mutationArgs);
        return ['matched' => $matched, 'affected' => $statement->rowCount(), 'action' => $action];
    }

    /** @param array<string,mixed> $filters @return array{0:string,1:list<mixed>} */
    private function systemErrorWhere(array $filters): array
    {
        $status = strtolower(trim((string)($filters['status'] ?? 'open')));
        if (!in_array($status, ['open', 'resolved', 'ignored', 'all'], true)) {
            $status = 'open';
        }
        $severity = strtolower(trim((string)($filters['severity'] ?? 'all')));
        if (!in_array($severity, ['debug', 'info', 'warning', 'error', 'critical', 'all'], true)) {
            $severity = 'all';
        }
        $source = preg_replace(
            '/[^a-z0-9._:-]+/',
            '',
            strtolower(trim((string)($filters['source'] ?? 'all')))
        ) ?: 'all';
        $search = $this->text((string)($filters['q'] ?? ''), 200);

        $where = [];
        $args = [];
        if ($status !== 'all') {
            $where[] = 'status=?';
            $args[] = $status;
        }
        if ($severity !== 'all') {
            $where[] = 'severity=?';
            $args[] = $severity;
        }
        if ($source !== 'all') {
            $where[] = 'source_kind=?';
            $args[] = $source;
        }
        if ($search !== '') {
            $where[] = '(message LIKE ? OR error_type LIKE ? OR route LIKE ? OR source_file LIKE ? OR request_id LIKE ?)';
            $like = '%' . $this->escapeLike($search) . '%';
            array_push($args, $like, $like, $like, $like, $like);
        }
        return [$where !== [] ? ' WHERE ' . implode(' AND ', $where) : '', $args];
    }

    /** @param array<string,mixed> $filters @return array{0:string,1:list<mixed>} */
    private function uploadIssueWhere(array $filters): array
    {
        $status = strtolower(trim((string)($filters['status'] ?? 'open')));
        if (!in_array($status, ['open', 'resolved', 'ignored', 'all'], true)) {
            $status = 'open';
        }
        $search = $this->text((string)($filters['q'] ?? ''), 200);

        $where = [];
        $args = [];
        if ($status !== 'all') {
            $where[] = 'status=?';
            $args[] = $status;
        }
        if ($search !== '') {
            $where[] = '(relative_path LIKE ? OR original_name LIKE ? OR stage LIKE ? OR error_message LIKE ?)';
            $like = '%' . $this->escapeLike($search) . '%';
            array_push($args, $like, $like, $like, $like);
        }
        return [$where !== [] ? ' WHERE ' . implode(' AND ', $where) : '', $args];
    }

    /** @param list<mixed> $args */
    private function count(string $table, string $whereSql, array $args): int
    {
        $statement = $this->db->prepare('SELECT COUNT(*) FROM ' . $table . $whereSql);
        $statement->execute($args);
        return max(0, (int)$statement->fetchColumn());
    }

    private function action(string $action): string
    {
        $action = strtolower(trim($action));
        if (!in_array($action, ['resolve', 'ignore', 'reopen', 'delete'], true)) {
            throw new \InvalidArgumentException('Choose Resolve, Ignore, Reopen or Delete.');
        }
        return $action;
    }

    private function text(string $value, int $max): string
    {
        $value = trim((string)(preg_replace('/\s+/u', ' ', $value) ?? $value));
        return mb_strlen($value, 'UTF-8') > $max ? mb_substr($value, 0, $max, 'UTF-8') : $value;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
