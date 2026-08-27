<?php
/**
 * Static contract checks for centralized download administration.
 */
declare(strict_types=1);

$root = dirname(__DIR__);

$files = [
    'settings_page' => $root . '/downloads-settings.php',
    'settings_service' => $root . '/src/Infrastructure/Downloads/CatalogDownloadSettingsService.php',
    'settings_store' => $root . '/src/Infrastructure/Security/CatalogPublicAccessSettingsStore.php',
    'public_cache' => $root . '/src/Infrastructure/Cache/CatalogPublicResponseCacheService.php',
    'public_access_page' => $root . '/public-access-settings.php',
    'public_access_service' => $root . '/src/Infrastructure/Downloads/CatalogPublicAccessSettingsService.php',
    'mirror_providers' => $root . '/mirror-providers.php',
    'mirror_service' => $root . '/src/Infrastructure/Maintenance/CatalogExternalMirrorAdminService.php',
    'pak_download' => $root . '/pak-download.php',
    'legacy_rate_limit' => $root . '/lib/CatalogPublicRateLimit.php',
    'navigation' => $root . '/lib/CatalogNavigation.php',
    'download_admin' => $root . '/download-admin.php',
    'download_logs' => $root . '/download-logs.php',
];

$source = [];
foreach ($files as $key => $path) {
    $content = @file_get_contents($path);
    if (!is_string($content)) {
        fwrite(STDERR, "FAIL: could not read {$path}\n");
        exit(1);
    }
    $source[$key] = $content;
}

$failures = [];
$requireContains = static function (string $key, string $needle, string $label) use (&$failures, $source): void {
    if (!str_contains($source[$key], $needle)) {
        $failures[] = $label . ' missing: ' . $needle;
    }
};
$requireAbsent = static function (string $key, string $needle, string $label) use (&$failures, $source): void {
    if (str_contains($source[$key], $needle)) {
        $failures[] = $label . ' still contains legacy/duplicate token: ' . $needle;
    }
};

foreach ([
    'public_download_mode',
    'public_download_max_files',
    'public_download_window_seconds',
    'public_package_max_builds',
    'public_package_window_seconds',
    'public_download_speed_kbps',
    'public_block_crawlers',
    'public_burst_max_requests',
    'public_burst_window_seconds',
    'public_burst_block_seconds',
    'external_mirror_auto_queue',
    'external_mirror_expiry_days',
    'external_mirror_require_admin_approval',
    'external_mirror_max_file_size_mb',
] as $setting) {
    $requireContains('settings_page', $setting, 'Download Settings page');
}

$requireContains(
    'settings_page',
    'CatalogDownloadSettingsService',
    'Download Settings page'
);
$requireContains(
    'settings_service',
    '$publicValues = CatalogPublicAccessSettingsStore::normalize($values);',
    'Download Settings service'
);
$requireContains(
    'settings_service',
    '\\fed_set_setting($this->db, $name, $value);',
    'Download Settings service'
);
foreach ([
    '$this->db->beginTransaction();',
    '$this->publicAccess->saveDatabase($this->db, $publicValues);',
    '$this->db->commit();',
    '$this->db->rollBack();',
    '$this->publicAccess->publish($publicValues);',
    '\\catalog_public_cache_invalidate($this->config);',
] as $needle) {
    $requireContains('settings_service', $needle, 'Transactional Download Settings save');
}
foreach ([
    'public function saveDatabase(PDO $db, array $settings): array',
    'public function publish(array $settings): array',
] as $needle) {
    $requireContains('settings_store', $needle, 'Public access settings store');
}
foreach ([
    "'.generation'",
    'private static function generationToken(',
    '$generation . "\\n" . $script . "\\n" . $query',
] as $needle) {
    $requireContains('public_cache', $needle, 'Constant-time public cache invalidation');
}

foreach ([
    'name="public_download_max_files"',
    'name="public_download_speed_kbps"',
    'name="public_block_crawlers"',
    'name="public_burst_max_requests"',
] as $needle) {
    $requireAbsent('public_access_page', $needle, 'Public Access & Mail page');
}
$requireContains(
    'public_access_service',
    '$publicValues = $this->publicAccess->settings($this->db);',
    'Public Access & Mail service preservation'
);

foreach ([
    'name="public_download_mode"',
    'name="external_mirror_auto_queue"',
    'name="external_mirror_expiry_days"',
    'name="external_mirror_require_admin_approval"',
    'name="external_mirror_max_file_size_mb"',
] as $needle) {
    $requireAbsent('mirror_providers', $needle, 'Mirror Providers page');
}
$requireAbsent('mirror_service', "'save_settings'", 'Mirror admin service');

foreach ([
    "require_once __DIR__ . '/lib/CatalogPublicAccess.php';",
    'catalog_public_download_limit($db);',
    'catalog_public_download_speed_bytes($db);',
    'catalog_public_stream_file($path, $speedBytes);',
] as $needle) {
    $requireContains('pak_download', $needle, 'Original PAK download');
}
foreach ([
    'CatalogPublicRateLimit.php',
    'catalog_public_download_rate_limit(',
    'readfile($path)',
] as $needle) {
    $requireAbsent('pak_download', $needle, 'Original PAK download');
}
foreach ([
    'function catalog_public_download_rate_limit',
    'UNREALDB_PUBLIC_DOWNLOAD_MAX_REQUESTS',
    'UNREALDB_PUBLIC_DOWNLOAD_WINDOW_SECONDS',
] as $needle) {
    $requireAbsent('legacy_rate_limit', $needle, 'Legacy public rate-limit library');
}

$requireContains(
    'navigation',
    "'Download Settings' => \$root . 'downloads-settings.php'",
    'Download navigation'
);
$requireContains(
    'navigation',
    "'Download' => [",
    'Download navigation group'
);
$requireContains('download_admin', "'Download Settings' => 'downloads-settings.php'", 'Download dashboard');
$requireContains('download_logs', "'Download Settings' => 'downloads-settings.php'", 'Download logs');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo "PASS: download settings are centralized and original PAK downloads use the main public limiter/throttle.\n";
