<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns administrator TOTP secrets, recovery codes and replay-safe MFA verification.
 * Why: MFA persistence and cryptographic handling should have one security owner rather than procedural page helpers.
 * Role: Infrastructure security service preserving the existing encrypted TOTP/recovery-code contract.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Security;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Application\Security\TotpService;

final class CatalogMfaService
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @param array<string,mixed> $user */
    public static function enabled(array $user): bool
    {
        return !empty($user['mfa_enabled_at'])
            && trim((string)($user['mfa_totp_secret'] ?? '')) !== '';
    }

    /** @param array<string,mixed> $user */
    public static function secret(array $user): string
    {
        $stored = trim((string)($user['mfa_totp_secret'] ?? ''));
        if ($stored === '') {
            return '';
        }
        return ApplicationSecretStore::fromEnvironment()->decrypt($stored);
    }

    /** @return list<string> */
    public static function recoveryCodes(int $count = 10): array
    {
        $codes = [];
        for ($index = 0; $index < max(5, min($count, 20)); $index++) {
            $codes[] = strtoupper(
                substr(bin2hex(random_bytes(6)), 0, 6)
                . '-'
                . substr(bin2hex(random_bytes(6)), 0, 6)
            );
        }
        return $codes;
    }

    /** @param list<string> $codes */
    public static function recoveryHashes(array $codes): string
    {
        $hashes = [];
        foreach ($codes as $code) {
            $normalized = strtoupper(preg_replace('/[^A-Z0-9]+/', '', $code) ?? '');
            if ($normalized !== '') {
                $hashes[] = password_hash($normalized, PASSWORD_DEFAULT);
            }
        }
        return json_encode($hashes, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string,mixed> $user */
    public function verify(array $user, string $code): bool
    {
        if (!self::enabled($user)) {
            return true;
        }

        $normalizedRecovery = strtoupper(preg_replace('/[^A-Z0-9]+/', '', $code) ?? '');
        $recoveryHashes = json_decode((string)($user['mfa_recovery_codes_json'] ?? '[]'), true);
        if (is_array($recoveryHashes) && strlen($normalizedRecovery) >= 10) {
            foreach ($recoveryHashes as $index => $hash) {
                if (is_string($hash) && password_verify($normalizedRecovery, $hash)) {
                    unset($recoveryHashes[$index]);
                    $this->db->prepare('UPDATE ue_users SET mfa_recovery_codes_json=? WHERE id=?')
                        ->execute([
                            json_encode(array_values($recoveryHashes), JSON_THROW_ON_ERROR),
                            (int)$user['id'],
                        ]);
                    return true;
                }
            }
        }

        $digits = preg_replace('/\D+/', '', $code) ?? '';
        if (!TotpService::verify(self::secret($user), $digits, null, 1)) {
            return false;
        }
        $step = intdiv(time(), 30);
        $last = (int)($user['mfa_last_used_step'] ?? 0);
        if ($last >= $step) {
            return false;
        }
        $statement = $this->db->prepare(
            'UPDATE ue_users SET mfa_last_used_step=? WHERE id=? '
            . 'AND (mfa_last_used_step IS NULL OR mfa_last_used_step<?)'
        );
        $statement->execute([$step, (int)$user['id'], $step]);
        return $statement->rowCount() === 1;
    }

    /** @return list<string> */
    public function enable(int $userId, string $secret, string $code): array
    {
        if (!TotpService::verify($secret, $code, null, 1)) {
            throw new RuntimeException('The authenticator code is invalid.');
        }
        $recovery = self::recoveryCodes();
        $encrypted = ApplicationSecretStore::fromEnvironment()->encrypt($secret);
        $this->db->prepare(
            'UPDATE ue_users SET mfa_totp_secret=?,mfa_recovery_codes_json=?,mfa_enabled_at=NOW(),'
            . 'mfa_last_used_step=? WHERE id=?'
        )->execute([
            $encrypted,
            self::recoveryHashes($recovery),
            intdiv(time(), 30),
            $userId,
        ]);
        return $recovery;
    }

    public function disable(int $userId): void
    {
        $this->db->prepare(
            'UPDATE ue_users SET mfa_totp_secret=NULL,mfa_recovery_codes_json=NULL,'
            . 'mfa_enabled_at=NULL,mfa_last_used_step=NULL WHERE id=?'
        )->execute([$userId]);
    }
}
