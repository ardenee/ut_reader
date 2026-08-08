<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Owns administrator password/MFA verification and durable recovery-code mutations.
 * Why: Security policy and ue_users mutations should not live in the rendering page.
 * Role: Infrastructure/application service over the existing CatalogMfa and TOTP contracts.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Security;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Application\Security\TotpService;

final class CatalogAdminSecurityService
{
    public function __construct(private readonly PDO $db)
    {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/CatalogSupport.php';
        require_once $root . '/lib/CatalogMfa.php';
    }

    /** @return array<string,mixed> */
    public function administrator(int $userId): array
    {
        $user = \catalog_one($this->db, 'SELECT * FROM ue_users WHERE id=? AND role="admin"', [$userId]);
        if (!$user) {
            throw new RuntimeException('Administrator account is unavailable.');
        }
        return $user;
    }

    /**
     * @return array{flash:string,recovery_codes:list<string>,clear_setup_secret:bool,user:array<string,mixed>}
     */
    public function execute(
        array $user,
        string $action,
        string $password,
        string $code,
        string $setupSecret
    ): array {
        if (!password_verify($password, (string)$user['password_hash'])) {
            usleep(random_int(150000, 300000));
            throw new RuntimeException('Password verification failed.');
        }

        $userId = (int)$user['id'];
        $recoveryCodes = [];
        $clearSetupSecret = false;
        $flash = '';

        if ($action === 'enable') {
            if (\catalog_mfa_enabled($user)) {
                throw new RuntimeException('MFA is already enabled.');
            }
            if (trim($setupSecret) === '') {
                throw new RuntimeException('MFA setup has expired. Reload this page and try again.');
            }
            $recoveryCodes = \catalog_mfa_enable($this->db, $userId, $setupSecret, $code);
            $clearSetupSecret = true;
            \catalog_mark_recent_admin_auth();
            $flash = 'MFA enabled. Save the recovery codes shown below; they will not be displayed again.';
        } elseif ($action === 'disable') {
            if (!\catalog_mfa_enabled($user) || !\catalog_mfa_verify($this->db, $user, $code)) {
                throw new RuntimeException('A valid authenticator or recovery code is required to disable MFA.');
            }
            \catalog_mfa_disable($this->db, $userId);
            $clearSetupSecret = true;
            \catalog_mark_recent_admin_auth();
            $flash = 'MFA disabled.';
        } elseif ($action === 'reauth') {
            if (\catalog_mfa_enabled($user) && !\catalog_mfa_verify($this->db, $user, $code)) {
                throw new RuntimeException('Authenticator or recovery code verification failed.');
            }
            \catalog_mark_recent_admin_auth();
            $flash = 'Administrator reauthentication confirmed for ' . \catalog_recent_admin_auth_seconds() . ' seconds.';
        } elseif ($action === 'regenerate_recovery') {
            if (!\catalog_mfa_enabled($user) || !\catalog_mfa_verify($this->db, $user, $code)) {
                throw new RuntimeException('A valid authenticator or recovery code is required.');
            }
            $recoveryCodes = \catalog_mfa_recovery_codes();
            $this->db->prepare('UPDATE ue_users SET mfa_recovery_codes_json=? WHERE id=?')
                ->execute([\catalog_mfa_recovery_hashes($recoveryCodes), $userId]);
            \catalog_mark_recent_admin_auth();
            $flash = 'New recovery codes created. Previous recovery codes are no longer valid.';
        } else {
            throw new RuntimeException('Unknown security action.');
        }

        return [
            'flash' => $flash,
            'recovery_codes' => $recoveryCodes,
            'clear_setup_secret' => $clearSetupSecret,
            'user' => $this->administrator($userId),
        ];
    }

    /** @return array{secret:string,uri:string} */
    public function setup(string $siteName, string $username, string $existingSecret): array
    {
        $secret = trim($existingSecret);
        if ($secret === '') {
            $secret = TotpService::generateSecret();
        }
        return [
            'secret' => $secret,
            'uri' => TotpService::provisioningUri($siteName, $username, $secret),
        ];
    }
}
