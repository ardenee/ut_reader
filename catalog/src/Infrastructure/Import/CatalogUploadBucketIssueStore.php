<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Import;

use PDO;

final class CatalogUploadBucketIssueStore
{
    private ?bool $available = null;

    public function __construct(private readonly PDO $db)
    {
    }

    public function available(): bool
    {
        if ($this->available !== null) {
            return $this->available;
        }
        $statement = $this->db->query(
            'SELECT COUNT(*) FROM information_schema.TABLES '
            . 'WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME="ue_upload_bucket_issues"'
        );
        $this->available = (int)$statement->fetchColumn() === 1;
        return $this->available;
    }

    /**
     * @param array<string,mixed> $issue
     */
    public function record(array $issue, int $userId): int
    {
        if (!$this->available()) {
            throw new \RuntimeException(
                'The Upload Issues schema is not migrated. Run php catalog/bin/migrate.php migrate followed by verify.'
            );
        }

        $relativePath = self::cleanPath((string)($issue['relative_path'] ?? ''));
        $originalName = self::cleanText((string)($issue['original_name'] ?? basename($relativePath)), 255);
        if ($relativePath === '') {
            $relativePath = $originalName !== '' ? $originalName : 'Upload batch';
        }
        if ($originalName === '') {
            $originalName = basename(str_replace('\\', '/', $relativePath)) ?: 'Upload batch';
        }

        $sizeText = self::cleanText((string)($issue['file_size_text'] ?? ''), 32);
        $stage = self::cleanIdentifier((string)($issue['stage'] ?? 'unknown'), 64, 'unknown');
        $message = self::cleanMessage((string)($issue['error_message'] ?? 'Unknown upload failure.'));
        $sourceKind = self::cleanIdentifier((string)($issue['source_kind'] ?? 'upload_bucket_v2'), 32, 'upload_bucket_v2');
        $sessionId = self::cleanText((string)($issue['upload_session_id'] ?? ''), 64);
        $issueKey = hash('sha256', mb_strtolower($relativePath, 'UTF-8') . "\n" . mb_strtolower($sizeText, 'UTF-8'));
        $now = gmdate('Y-m-d H:i:s');

        $statement = $this->db->prepare(
            'INSERT INTO ue_upload_bucket_issues '
            . '(issue_key,source_kind,upload_session_id,relative_path,original_name,file_size_text,stage,error_message,status,'
            . 'occurrence_count,first_seen_at,last_seen_at,resolved_at,resolved_by,resolution_note,created_by) '
            . 'VALUES (?,?,?,?,?,?,?,?,"open",1,?,?,NULL,NULL,NULL,?) '
            . 'ON DUPLICATE KEY UPDATE '
            . 'source_kind=VALUES(source_kind),upload_session_id=VALUES(upload_session_id),relative_path=VALUES(relative_path),'
            . 'original_name=VALUES(original_name),file_size_text=VALUES(file_size_text),stage=VALUES(stage),'
            . 'error_message=VALUES(error_message),status="open",occurrence_count=occurrence_count+1,last_seen_at=VALUES(last_seen_at),'
            . 'resolved_at=NULL,resolved_by=NULL,resolution_note=NULL,id=LAST_INSERT_ID(id)'
        );
        $statement->execute([
            $issueKey,
            $sourceKind,
            $sessionId,
            $relativePath,
            $originalName,
            $sizeText,
            $stage,
            $message,
            $now,
            $now,
            $userId > 0 ? $userId : null,
        ]);
        return (int)$this->db->lastInsertId();
    }

    /** @param list<int> $ids */
    public function setStatus(array $ids, string $status, int $userId, string $note = ''): int
    {
        if (!$this->available()) {
            throw new \RuntimeException('The Upload Issues schema is not migrated.');
        }
        $status = strtolower(trim($status));
        if (!in_array($status, ['open', 'resolved', 'ignored'], true)) {
            throw new \InvalidArgumentException('Invalid Upload Issue status.');
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
        if ($ids === []) {
            return 0;
        }
        if (count($ids) > 1000) {
            throw new \InvalidArgumentException('Update no more than 1,000 Upload Issues at once.');
        }
        $note = self::cleanText($note, 500);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $now = gmdate('Y-m-d H:i:s');
        if ($status === 'open') {
            $sql = 'UPDATE ue_upload_bucket_issues SET status="open",resolved_at=NULL,resolved_by=NULL,resolution_note=NULL '
                . 'WHERE id IN (' . $placeholders . ')';
            $args = $ids;
        } else {
            $sql = 'UPDATE ue_upload_bucket_issues SET status=?,resolved_at=?,resolved_by=?,resolution_note=? '
                . 'WHERE id IN (' . $placeholders . ')';
            $args = [$status, $now, $userId > 0 ? $userId : null, $note];
            array_push($args, ...$ids);
        }
        $statement = $this->db->prepare($sql);
        $statement->execute($args);
        return $statement->rowCount();
    }

    private static function cleanPath(string $value): string
    {
        $value = str_replace('\\', '/', trim($value));
        $value = preg_replace('/[\x00-\x1F\x7F]+/u', '', $value) ?? $value;
        $value = preg_replace('#/+#', '/', $value) ?? $value;
        if (mb_strlen($value, 'UTF-8') > 4096) {
            $value = mb_substr($value, 0, 4096, 'UTF-8');
        }
        return trim($value, '/ ');
    }

    private static function cleanIdentifier(string $value, int $max, string $fallback): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9._:-]+/', '_', $value) ?? '';
        $value = trim($value, '._:-');
        if ($value === '') {
            $value = $fallback;
        }
        return substr($value, 0, $max);
    }

    private static function cleanMessage(string $value): string
    {
        $value = trim((string)(preg_replace('/\s+/u', ' ', $value) ?? $value));
        if ($value === '') {
            return 'Unknown upload failure.';
        }
        return mb_strlen($value, 'UTF-8') > 4000
            ? mb_substr($value, 0, 4000, 'UTF-8') . '…'
            : $value;
    }

    private static function cleanText(string $value, int $max): string
    {
        $value = trim((string)(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? $value));
        return mb_strlen($value, 'UTF-8') > $max ? mb_substr($value, 0, $max, 'UTF-8') : $value;
    }
}
