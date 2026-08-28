<?php
/**
 * Persistent administrator-managed IP blocklist for transfer actions only.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Security;

use PDO;

final class CatalogTransferBlocklist
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function isBlocked(string $ip): bool
    {
        $packed = $this->packed($ip);
        if ($packed === null) {
            return false;
        }
        $statement = $this->db->prepare(
            'SELECT 1 FROM ue_transfer_blocked_ips WHERE ip_address=? LIMIT 1'
        );
        $statement->execute([$packed]);
        return $statement->fetchColumn() !== false;
    }

    public function block(string $ip, ?int $userId = null, string $note = ''): void
    {
        $packed = $this->packed($ip);
        if ($packed === null) {
            throw new \InvalidArgumentException('Enter a valid IPv4 or IPv6 address.');
        }
        $note = trim($note);
        if (mb_strlen($note, 'UTF-8') > 500) {
            $note = mb_substr($note, 0, 500, 'UTF-8');
        }
        $statement = $this->db->prepare(
            'INSERT INTO ue_transfer_blocked_ips(ip_address,note,created_by,created_at,updated_at) '
            . 'VALUES(?,?,?,CURRENT_TIMESTAMP(6),CURRENT_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE note=VALUES(note),created_by=VALUES(created_by),updated_at=CURRENT_TIMESTAMP(6)'
        );
        $statement->execute([$packed, $note, $userId !== null && $userId > 0 ? $userId : null]);
    }

    public function unblock(string $ip): int
    {
        $packed = $this->packed($ip);
        if ($packed === null) {
            throw new \InvalidArgumentException('Enter a valid IPv4 or IPv6 address.');
        }
        $statement = $this->db->prepare('DELETE FROM ue_transfer_blocked_ips WHERE ip_address=?');
        $statement->execute([$packed]);
        return max(0, $statement->rowCount());
    }

    /** @return list<array{ip:string,note:string,created_by:int,created_at:string,updated_at:string}> */
    public function all(): array
    {
        $statement = $this->db->query(
            'SELECT INET6_NTOA(ip_address) ip,note,COALESCE(created_by,0) created_by,created_at,updated_at '
            . 'FROM ue_transfer_blocked_ips ORDER BY updated_at DESC,ip_address'
        );
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $ip = trim((string)($row['ip'] ?? ''));
            if ($ip === '') {
                continue;
            }
            $rows[] = [
                'ip' => $ip,
                'note' => (string)($row['note'] ?? ''),
                'created_by' => (int)($row['created_by'] ?? 0),
                'created_at' => (string)($row['created_at'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
            ];
        }
        return $rows;
    }

    private function packed(string $ip): ?string
    {
        $ip = trim($ip);
        if ($ip === '') {
            return null;
        }
        $packed = @inet_pton($ip);
        return is_string($packed) ? $packed : null;
    }
}
