<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Reads, validates and persists site-wide public download controls.
 * Why: Download limits, transfer speed, abuse protection and mirror behavior belong to one administrator workflow.
 * Role: Infrastructure/application service over public-access and federation settings storage.
 */
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Downloads;

use PDO;
use RuntimeException;
use UnrealDb\Catalog\Infrastructure\Security\CatalogPublicAccessSettingsStore;

final class CatalogDownloadSettingsService
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
        $this->publicAccess = new CatalogPublicAccessSettingsStore($config);
    }

    /** @return array{public:array<string,mixed>,mirror:array<string,string>} */
    public function current(): array
    {
        return [
            'public' => $this->publicAccess->settings($this->db),
            'mirror' => \fed_all_settings($this->db),
        ];
    }

    /** @param array<string,mixed> $input */
    public function save(array $input): string
    {
        $values = $this->publicAccess->settings($this->db);
        foreach ([
            'public_download_max_files',
            'public_download_window_seconds',
            'public_package_max_builds',
            'public_package_window_seconds',
            'public_download_speed_kbps',
            'public_burst_max_requests',
            'public_burst_window_seconds',
            'public_burst_block_seconds',
        ] as $key) {
            if (array_key_exists($key, $input)) {
                $values[$key] = (string)$input[$key];
            }
        }
        $values['public_block_crawlers'] = isset($input['public_block_crawlers']) ? '1' : '0';
        $publicValues = CatalogPublicAccessSettingsStore::normalize($values);

        $mode = strtolower(trim((string)($input['public_download_mode'] ?? 'local_direct')));
        if (!in_array($mode, ['local_direct', 'external_mirror', 'external_mirror_preferred', 'disabled'], true)) {
            throw new RuntimeException('Invalid public download mode.');
        }

        $mirrorValues = [
            'public_download_mode' => $mode,
            'external_mirror_auto_queue' => isset($input['external_mirror_auto_queue']) ? '1' : '0',
            'external_mirror_expiry_days' => (string)CatalogPublicAccessSettingsStore::intValue(
                $input['external_mirror_expiry_days'] ?? null,
                7,
                1,
                3650
            ),
            'external_mirror_require_admin_approval' => isset($input['external_mirror_require_admin_approval']) ? '1' : '0',
            'external_mirror_max_file_size_mb' => (string)CatalogPublicAccessSettingsStore::intValue(
                $input['external_mirror_max_file_size_mb'] ?? null,
                1024,
                1,
                1048576
            ),
        ];
        // Persist only after the complete form has been normalized and validated.
        $this->publicAccess->save($this->db, $publicValues);
        foreach ($mirrorValues as $name => $value) {
            \fed_set_setting($this->db, $name, $value);
        }

        \catalog_public_cache_invalidate($this->config);
        return 'Download settings saved. Public download controls were refreshed.';
    }
}
