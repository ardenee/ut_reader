<?php
declare(strict_types=1);

require_once __DIR__ . '/CatalogSupport.php';

use UnrealDb\Catalog\Application\Security\TotpService;
use UnrealDb\Catalog\Infrastructure\Security\ApplicationSecretStore;

function catalog_mfa_enabled(array $user): bool
{
    return !empty($user['mfa_enabled_at']) && trim((string)($user['mfa_totp_secret'] ?? '')) !== '';
}

function catalog_mfa_secret(array $user): string
{
    $stored = trim((string)($user['mfa_totp_secret'] ?? ''));
    if ($stored === '') {
        return '';
    }
    return ApplicationSecretStore::fromEnvironment()->decrypt($stored);
}

/** @return list<string> */
function catalog_mfa_recovery_codes(int $count = 10): array
{
    $codes = [];
    for ($index = 0; $index < max(5, min($count, 20)); $index++) {
        $codes[] = strtoupper(substr(bin2hex(random_bytes(6)), 0, 6) . '-' . substr(bin2hex(random_bytes(6)), 0, 6));
    }
    return $codes;
}

/** @param list<string> $codes */
function catalog_mfa_recovery_hashes(array $codes): string
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

function catalog_mfa_verify(PDO $db, array $user, string $code): bool
{
    if (!catalog_mfa_enabled($user)) {
        return true;
    }
    $normalizedRecovery = strtoupper(preg_replace('/[^A-Z0-9]+/', '', $code) ?? '');
    $recoveryHashes = json_decode((string)($user['mfa_recovery_codes_json'] ?? '[]'), true);
    if (is_array($recoveryHashes) && strlen($normalizedRecovery) >= 10) {
        foreach ($recoveryHashes as $index => $hash) {
            if (is_string($hash) && password_verify($normalizedRecovery, $hash)) {
                unset($recoveryHashes[$index]);
                $db->prepare('UPDATE ue_users SET mfa_recovery_codes_json=? WHERE id=?')
                    ->execute([json_encode(array_values($recoveryHashes), JSON_THROW_ON_ERROR), (int)$user['id']]);
                return true;
            }
        }
    }

    $digits = preg_replace('/\D+/', '', $code) ?? '';
    if (!TotpService::verify(catalog_mfa_secret($user), $digits, null, 1)) {
        return false;
    }
    $step = intdiv(time(), 30);
    $last = (int)($user['mfa_last_used_step'] ?? 0);
    if ($last >= $step) {
        return false;
    }
    $statement = $db->prepare('UPDATE ue_users SET mfa_last_used_step=? WHERE id=? AND (mfa_last_used_step IS NULL OR mfa_last_used_step<?)');
    $statement->execute([$step, (int)$user['id'], $step]);
    return $statement->rowCount() === 1;
}

function catalog_mfa_enable(PDO $db, int $userId, string $secret, string $code): array
{
    if (!TotpService::verify($secret, $code, null, 1)) {
        throw new RuntimeException('The authenticator code is invalid.');
    }
    $recovery = catalog_mfa_recovery_codes();
    $encrypted = ApplicationSecretStore::fromEnvironment()->encrypt($secret);
    $db->prepare(
        'UPDATE ue_users SET mfa_totp_secret=?,mfa_recovery_codes_json=?,mfa_enabled_at=NOW(),mfa_last_used_step=? WHERE id=?'
    )->execute([$encrypted, catalog_mfa_recovery_hashes($recovery), intdiv(time(), 30), $userId]);
    return $recovery;
}

function catalog_mfa_disable(PDO $db, int $userId): void
{
    $db->prepare('UPDATE ue_users SET mfa_totp_secret=NULL,mfa_recovery_codes_json=NULL,mfa_enabled_at=NULL,mfa_last_used_step=NULL WHERE id=?')->execute([$userId]);
}

function catalog_mark_recent_admin_auth(): void
{
    catalog_start_session();
    $_SESSION['catalog_auth_verified_at'] = time();
}

function catalog_recent_admin_auth_seconds(): int
{
    return catalog_security_env_seconds('UNREALDB_ADMIN_REAUTH_SECONDS', 600, 60, 86400);
}

function catalog_has_recent_admin_auth(): bool
{
    return catalog_support_is_admin()
        && (time() - (int)($_SESSION['catalog_auth_verified_at'] ?? 0)) <= catalog_recent_admin_auth_seconds();
}

function catalog_require_recent_admin_auth(): void
{
    if (!catalog_has_recent_admin_auth()) {
        throw new RuntimeException('Administrator reauthentication is required. Open admin-security.php and confirm your password and MFA code.');
    }
}
