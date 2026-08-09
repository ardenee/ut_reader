<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns federation peer shared-secret preparation, decryption and plaintext-to-encrypted migration.
 * Why: Secret storage policy and migration are security/persistence concerns and should not live in the legacy federation auth facade.
 * Role: Infrastructure security service preserving existing encryption, strict-mode, migration and audit-log contracts.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Security;

use InvalidArgumentException;
use PDO;
use RuntimeException;

final class CatalogFederationPeerSecretService
{
    public function __construct(private readonly PDO $db)
    {
    }

    public static function store(): FederationSecretStore
    {
        static $store = null;
        if (!$store instanceof FederationSecretStore) {
            $store = FederationSecretStore::fromEnvironment();
        }
        return $store;
    }

    public static function requireEncryptedSecrets(): bool
    {
        return in_array(
            strtolower(trim((string)(getenv('UNREALDB_REQUIRE_ENCRYPTED_FEDERATION_SECRETS') ?: '0'))),
            ['1', 'true', 'yes', 'on'],
            true
        );
    }

    /** @return array{hash:string,stored:string} */
    public static function prepare(string $secret): array
    {
        if ($secret === '' || strlen($secret) > 64) {
            throw new InvalidArgumentException('Federation shared secrets must contain between 1 and 64 bytes.');
        }

        $store = self::store();
        if ($store->hasMasterKey()) {
            $stored = $store->encrypt($secret);
        } elseif (self::requireEncryptedSecrets()) {
            throw new RuntimeException(
                'Federation secret encryption is required, but UNREALDB_FEDERATION_MASTER_KEY is not configured.'
            );
        } else {
            static $warned = false;
            if (!$warned) {
                error_log(
                    '[UnrealDB federation] Peer secrets are using plaintext compatibility mode. '
                    . 'Configure UNREALDB_FEDERATION_MASTER_KEY and run encrypt-federation-secrets.php.'
                );
                $warned = true;
            }
            $stored = $secret;
        }

        return ['hash' => password_hash($secret, PASSWORD_DEFAULT), 'stored' => $stored];
    }

    public static function forCrypto(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        $store = self::store();
        if ($store->isEncrypted($stored)) {
            return $store->decrypt($stored);
        }
        if (self::requireEncryptedSecrets()) {
            throw new RuntimeException(
                'A plaintext federation peer secret remains. Run catalog/bin/encrypt-federation-secrets.php '
                . 'before enabling strict secret policy.'
            );
        }
        return $stored;
    }

    /** @param array<string,mixed> $peer */
    public function peerSecret(array $peer, bool $migratePlaintext = true): string
    {
        $stored = (string)($peer['shared_secret_plain'] ?? '');
        if ($stored === '') {
            return '';
        }

        $store = self::store();
        if ($store->isEncrypted($stored)) {
            return $store->decrypt($stored);
        }
        if ($store->hasMasterKey() && $migratePlaintext && (int)($peer['id'] ?? 0) > 0) {
            $encrypted = $store->encrypt($stored);
            $statement = $this->db->prepare(
                'UPDATE ue_federation_peers SET shared_secret_plain=? WHERE id=? AND shared_secret_plain=?'
            );
            $statement->execute([$encrypted, (int)$peer['id'], $stored]);
            $this->log(
                (int)$peer['id'],
                'INFO',
                'PEER_SECRET_ENCRYPTED',
                'Legacy plaintext peer secret encrypted at first authenticated use.'
            );
            return $stored;
        }
        if (self::requireEncryptedSecrets()) {
            throw new RuntimeException(
                'A plaintext federation peer secret remains. Run catalog/bin/encrypt-federation-secrets.php '
                . 'before enabling strict secret policy.'
            );
        }
        return $stored;
    }

    /** @return array{migrated:int,encrypted:int,missing:int} */
    public function migrateAll(): array
    {
        $store = self::store();
        if (!$store->hasMasterKey()) {
            throw new RuntimeException(
                'UNREALDB_FEDERATION_MASTER_KEY must be configured before migrating peer secrets.'
            );
        }

        $counts = ['migrated' => 0, 'encrypted' => 0, 'missing' => 0];
        $statement = $this->db->query(
            'SELECT id, shared_secret_plain FROM ue_federation_peers ORDER BY id'
        );
        $update = $this->db->prepare(
            'UPDATE ue_federation_peers SET shared_secret_plain=? WHERE id=? AND shared_secret_plain=?'
        );

        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $stored = (string)($row['shared_secret_plain'] ?? '');
            if ($stored === '') {
                $counts['missing']++;
                continue;
            }
            if ($store->isEncrypted($stored)) {
                $store->decrypt($stored);
                $counts['encrypted']++;
                continue;
            }
            $encrypted = $store->encrypt($stored);
            $update->execute([$encrypted, (int)$row['id'], $stored]);
            if ($update->rowCount() === 1) {
                $counts['migrated']++;
            }
        }
        return $counts;
    }

    private function log(int $peerId, string $level, string $event, string $details): void
    {
        $statement = $this->db->prepare(
            'INSERT INTO ue_federation_transfer_logs(peer_id, transfer_job_id, level, event, details) '
            . 'VALUES(?,NULL,?,?,?)'
        );
        $statement->execute([$peerId, $level, $event, $details]);
    }
}
