<?php
/**
 * Administrator-managed full-site IP blocklist.
 *
 * Database rows are the administrative source of truth. Small filesystem marker
 * files mirror active blocks so the public request guard can reject a blocked IP
 * before opening MySQL or serving a cached page.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Security;

use PDO;
use RuntimeException;

final class CatalogSiteBlocklist
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly ?PDO $db,
        private readonly array $config
    ) {
    }

    /** @param array<string,mixed> $config */
    public static function isBlockedCached(array $config, string $ip): bool
    {
        $packed = @inet_pton(trim($ip));
        if (!is_string($packed)) {
            return false;
        }
        return is_file(self::markerPath($config, $packed));
    }

    public function isBlocked(string $ip): bool
    {
        $packed = $this->packed($ip);
        if ($packed === null) {
            return false;
        }
        if (is_file(self::markerPath($this->config, $packed))) {
            return true;
        }
        if (!$this->db instanceof PDO) {
            return false;
        }
        try {
            $statement = $this->db->prepare(
                'SELECT 1 FROM ue_site_blocked_ips WHERE ip_address=? LIMIT 1'
            );
            $statement->execute([$packed]);
            $blocked = $statement->fetchColumn() !== false;
            if ($blocked) {
                $this->writeMarker($packed, $ip, '');
            }
            return $blocked;
        } catch (\PDOException $error) {
            if ($this->missingTable($error)) {
                return false;
            }
            throw $error;
        }
    }

    public function block(string $ip, ?int $userId = null, string $note = ''): void
    {
        if (!$this->db instanceof PDO) {
            throw new RuntimeException('Database access is required to manage the site blocklist.');
        }
        $packed = $this->packed($ip);
        if ($packed === null) {
            throw new \InvalidArgumentException('Enter a valid IPv4 or IPv6 address.');
        }
        $note = mb_substr(trim($note), 0, 500, 'UTF-8');
        $statement = $this->db->prepare(
            'INSERT INTO ue_site_blocked_ips(ip_address,note,created_by,created_at,updated_at) '
            . 'VALUES(?,?,?,CURRENT_TIMESTAMP(6),CURRENT_TIMESTAMP(6)) '
            . 'ON DUPLICATE KEY UPDATE note=VALUES(note),created_by=VALUES(created_by),updated_at=CURRENT_TIMESTAMP(6)'
        );
        $statement->execute([$packed, $note, $userId !== null && $userId > 0 ? $userId : null]);
        $this->writeMarker($packed, trim($ip), $note);
    }

    public function unblock(string $ip): int
    {
        if (!$this->db instanceof PDO) {
            throw new RuntimeException('Database access is required to manage the site blocklist.');
        }
        $packed = $this->packed($ip);
        if ($packed === null) {
            throw new \InvalidArgumentException('Enter a valid IPv4 or IPv6 address.');
        }
        $statement = $this->db->prepare('DELETE FROM ue_site_blocked_ips WHERE ip_address=?');
        $statement->execute([$packed]);
        @unlink(self::markerPath($this->config, $packed));
        return max(0, $statement->rowCount());
    }

    /** @return list<array{ip:string,note:string,created_by:int,created_at:string,updated_at:string}> */
    public function all(): array
    {
        if (!$this->db instanceof PDO) {
            return [];
        }
        try {
            $statement = $this->db->query(
                'SELECT INET6_NTOA(ip_address) ip,note,COALESCE(created_by,0) created_by,created_at,updated_at '
                . 'FROM ue_site_blocked_ips ORDER BY updated_at DESC,ip_address'
            );
        } catch (\PDOException $error) {
            if ($this->missingTable($error)) {
                return [];
            }
            throw $error;
        }
        $rows = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $ip = trim((string)($row['ip'] ?? ''));
            if ($ip === '') {
                continue;
            }
            $note = (string)($row['note'] ?? '');
            try {
                $packed = @inet_pton($ip);
                if (is_string($packed)) {
                    $this->writeMarker($packed, $ip, $note);
                }
            } catch (\Throwable $error) {
                error_log('[UnrealDB site blocklist] Could not refresh cached marker for ' . $ip . ': ' . $error->getMessage());
            }
            $rows[] = [
                'ip' => $ip,
                'note' => $note,
                'created_by' => (int)($row['created_by'] ?? 0),
                'created_at' => (string)($row['created_at'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
            ];
        }
        return $rows;
    }

    /** @param array<string,mixed> $config */
    private static function markerPath(array $config, string $packed): string
    {
        $root = rtrim((string)($config['storage_path'] ?? ''), DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'security' . DIRECTORY_SEPARATOR . 'site-blocklist';
        $hash = hash('sha256', $packed);
        return $root . DIRECTORY_SEPARATOR . substr($hash, 0, 2)
            . DIRECTORY_SEPARATOR . $hash . '.json';
    }

    private function writeMarker(string $packed, string $ip, string $note): void
    {
        $path = self::markerPath($this->config, $packed);
        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create site-blocklist cache directory.');
        }
        $json = json_encode([
            'ip' => $ip,
            'note' => $note,
            'updated_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $temporary = $path . '.tmp-' . bin2hex(random_bytes(4));
        if (@file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new RuntimeException('Could not write site-blocklist cache marker.');
        }
        if (PHP_OS_FAMILY === 'Windows' && is_file($path)) {
            @unlink($path);
        }
        if (!@rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Could not publish site-blocklist cache marker.');
        }
        @chmod($path, 0600);
    }

    private function packed(string $ip): ?string
    {
        $packed = @inet_pton(trim($ip));
        return is_string($packed) ? $packed : null;
    }

    private function missingTable(\PDOException $error): bool
    {
        $message = strtolower($error->getMessage());
        return strtoupper((string)$error->getCode()) === '42S02'
            || (str_contains($message, 'ue_site_blocked_ips') && str_contains($message, 'doesn\'t exist'));
    }
}
