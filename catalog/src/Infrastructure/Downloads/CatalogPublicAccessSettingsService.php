<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Reads, validates and persists Public Access & Mail settings.
 * Why: Development notice, feedback and SMTP configuration belong together while download controls live separately.
 * Role: Infrastructure/application service over public-access settings storage, federation settings and SMTP contracts.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Downloads;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Security\CatalogPublicAccessSettingsStore;

final class CatalogPublicAccessSettingsService
{
    private readonly CatalogPublicAccessSettingsStore $publicAccess;

    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly PDO $db,
        private readonly array $config
    ) {
        $root = dirname(__DIR__, 3);
        require_once $root . '/lib/FederationAuth.php';
        require_once $root . '/lib/CatalogPublicResponseCache.php';
        require_once $root . '/lib/CatalogSmtpMailer.php';
        $this->publicAccess = new CatalogPublicAccessSettingsStore($config);
    }

    /** @return array{public:array<string,mixed>,smtp:array<string,mixed>} */
    public function current(): array
    {
        return [
            'public' => $this->publicAccess->settings($this->db),
            'smtp' => \catalog_smtp_settings($this->db),
        ];
    }

    /** @param array<string,mixed> $input */
    public function save(array $input, string $action, string $requestId): string
    {
        // Preserve download/access controls now owned by downloads-settings.php.
        // Saving mail or feedback settings must never reset download limits.
        $publicValues = $this->publicAccess->settings($this->db);
        $publicValues['site_development_mode'] = isset($input['site_development_mode']) ? '1' : '0';
        $publicValues['site_development_title'] = (string)($input['site_development_title'] ?? '');
        $publicValues['site_development_message'] = (string)($input['site_development_message'] ?? '');
        $publicValues['feedback_enabled'] = isset($input['feedback_enabled']) ? '1' : '0';
        $publicValues['feedback_recipient'] = (string)($input['feedback_recipient'] ?? 'info@unrealdb.com');
        $publicValues['feedback_max_requests'] = (string)($input['feedback_max_requests'] ?? '5');
        $publicValues['feedback_window_seconds'] = (string)($input['feedback_window_seconds'] ?? '3600');
        $publicSettings = CatalogPublicAccessSettingsStore::normalize($publicValues);

        $smtpEnabled = isset($input['smtp_enabled']) ? '1' : '0';
        $smtpHost = substr(trim((string)($input['smtp_host'] ?? '')), 0, 255);
        $smtpPort = (string)CatalogPublicAccessSettingsStore::intValue(
            $input['smtp_port'] ?? null,
            587,
            1,
            65535
        );
        $smtpEncryption = strtolower(trim((string)($input['smtp_encryption'] ?? 'starttls')));
        if (!in_array($smtpEncryption, ['none', 'starttls', 'ssl'], true)) {
            $smtpEncryption = 'starttls';
        }
        $smtpUsername = substr(trim((string)($input['smtp_username'] ?? '')), 0, 255);
        $smtpFromEmail = substr(trim((string)($input['smtp_from_email'] ?? 'info@unrealdb.com')), 0, 254);
        $smtpFromName = substr(trim((string)($input['smtp_from_name'] ?? 'UnrealDB')), 0, 180);
        $smtpTimeout = (string)CatalogPublicAccessSettingsStore::intValue(
            $input['smtp_timeout_seconds'] ?? null,
            20,
            3,
            120
        );

        if ($publicSettings['feedback_enabled'] && $smtpEnabled !== '1') {
            throw new RuntimeException('Enable SMTP delivery before enabling the public feedback form.');
        }
        if ($smtpEnabled === '1') {
            if ($smtpHost === '') {
                throw new RuntimeException('SMTP host is required when SMTP is enabled.');
            }
            \catalog_smtp_address($smtpFromEmail, 'SMTP From address');
            \catalog_smtp_address((string)$publicSettings['feedback_recipient'], 'Feedback recipient');
        }

        $publicSettings = $this->publicAccess->save($this->db, $publicSettings);

        foreach ([
            'smtp_enabled' => $smtpEnabled,
            'smtp_host' => $smtpHost,
            'smtp_port' => $smtpPort,
            'smtp_encryption' => $smtpEncryption,
            'smtp_username' => $smtpUsername,
            'smtp_from_email' => $smtpFromEmail,
            'smtp_from_name' => $smtpFromName,
            'smtp_timeout_seconds' => $smtpTimeout,
        ] as $name => $value) {
            \fed_set_setting($this->db, $name, $value);
        }

        if (isset($input['smtp_password_clear'])) {
            \fed_set_setting($this->db, 'smtp_password', '');
        } else {
            $smtpPassword = (string)($input['smtp_password'] ?? '');
            if ($smtpPassword !== '') {
                $storedPassword = $smtpPassword;
                $secretStore = \fed_secret_store();
                if ($secretStore->hasMasterKey()) {
                    $storedPassword = $secretStore->encrypt($smtpPassword);
                }
                \fed_set_setting($this->db, 'smtp_password', $storedPassword);
            }
        }

        \catalog_public_cache_invalidate($this->config);

        if ($action === 'test_mail') {
            \catalog_smtp_send(
                $this->db,
                (string)$publicSettings['feedback_recipient'],
                'UnrealDB SMTP test',
                "This is a test message from UnrealDB.\n\nThe saved SMTP and feedback recipient settings are working.\nRequest reference: " . $requestId
            );
            return 'Public Access & Mail settings saved and the SMTP test message was accepted by the mail server.';
        }

        return 'Public Access & Mail settings saved. Public pages were refreshed.';
    }
}
