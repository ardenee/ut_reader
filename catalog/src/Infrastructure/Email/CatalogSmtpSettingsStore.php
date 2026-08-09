<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Reads and normalizes SMTP delivery settings and decrypts the stored SMTP secret.
 * Why: SMTP configuration/secrets are separate from socket protocol and message delivery mechanics.
 * Role: Infrastructure email settings store preserving the existing administrator settings contract.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Email;

use PDO;
use RuntimeException;
use Throwable;
use UnrealDb\Catalog\Infrastructure\Security\CatalogFederationPeerSecretService;
use UnrealDb\Catalog\Infrastructure\Security\CatalogPublicAccessSettingsStore;

final class CatalogSmtpSettingsStore
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return list<string> */
    public static function settingNames(): array
    {
        return [
            'smtp_enabled',
            'smtp_host',
            'smtp_port',
            'smtp_encryption',
            'smtp_username',
            'smtp_password',
            'smtp_from_email',
            'smtp_from_name',
            'smtp_timeout_seconds',
        ];
    }

    /** @return array<string,mixed> */
    public function settings(): array
    {
        $defaults = [
            'enabled' => false,
            'host' => '',
            'port' => 587,
            'encryption' => 'starttls',
            'username' => '',
            'password' => '',
            'from_email' => 'info@unrealdb.com',
            'from_name' => 'UnrealDB',
            'timeout_seconds' => 20,
        ];
        $names = self::settingNames();
        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $statement = $this->db->prepare(
            'SELECT setting_name,setting_value FROM ue_federation_settings WHERE setting_name IN (' . $placeholders . ')'
        );
        $statement->execute($names);
        $values = [];
        while ($row = $statement->fetch(PDO::FETCH_ASSOC)) {
            $values[(string)$row['setting_name']] = (string)($row['setting_value'] ?? '');
        }

        $storedPassword = (string)($values['smtp_password'] ?? '');
        $password = $storedPassword;
        if ($storedPassword !== '') {
            try {
                $password = CatalogFederationPeerSecretService::forCrypto($storedPassword);
            } catch (Throwable $error) {
                throw new RuntimeException(
                    'The saved SMTP password could not be decrypted: ' . $error->getMessage(),
                    0,
                    $error
                );
            }
        }

        $encryption = strtolower(trim((string)($values['smtp_encryption'] ?? $defaults['encryption'])));
        if (!in_array($encryption, ['none', 'starttls', 'ssl'], true)) {
            $encryption = 'starttls';
        }

        return [
            'enabled' => CatalogPublicAccessSettingsStore::boolValue(
                $values['smtp_enabled'] ?? null,
                false
            ),
            'host' => substr(trim((string)($values['smtp_host'] ?? $defaults['host'])), 0, 255),
            'port' => CatalogPublicAccessSettingsStore::intValue(
                $values['smtp_port'] ?? null,
                587,
                1,
                65535
            ),
            'encryption' => $encryption,
            'username' => substr(trim((string)($values['smtp_username'] ?? $defaults['username'])), 0, 255),
            'password' => $password,
            'password_is_set' => $storedPassword !== '',
            'from_email' => substr(
                trim((string)($values['smtp_from_email'] ?? $defaults['from_email'])),
                0,
                254
            ),
            'from_name' => substr(trim((string)($values['smtp_from_name'] ?? $defaults['from_name'])), 0, 180),
            'timeout_seconds' => CatalogPublicAccessSettingsStore::intValue(
                $values['smtp_timeout_seconds'] ?? null,
                20,
                3,
                120
            ),
        ];
    }
}
