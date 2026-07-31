<?php
declare(strict_types=1);

function public_access_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$root = dirname(__DIR__);
$files = [
    'access' => $root . '/lib/CatalogPublicAccess.php',
    'smtp' => $root . '/lib/CatalogSmtpMailer.php',
    'support' => $root . '/lib/CatalogSupport.php',
    'support_core' => $root . '/lib/CatalogSupportCore.php',
    'public_cache' => $root . '/lib/CatalogPublicResponseCache.php',
    'landing' => $root . '/index.php',
    'feedback' => $root . '/feedback.php',
    'admin' => $root . '/public-access-settings.php',
    'download' => $root . '/download.php',
    'package_job' => $root . '/generated-package-job.php',
    'package_download' => $root . '/generated-package-download.php',
    'navigation' => $root . '/lib/CatalogNavigation.php',
    'download_admin' => $root . '/download-admin.php',
    'migration' => $root . '/migrations/202607310004_public_access_feedback.php',
    'safety_migration' => $root . '/migrations/202607310005_feedback_smtp_safety.php',
];
$sources = [];
foreach ($files as $name => $path) {
    $source = file_get_contents($path);
    public_access_expect(is_string($source) && $source !== '', $name . ' source is missing.');
    $sources[$name] = $source;
}

public_access_expect(
    str_contains($sources['landing'], "site_development_title")
        && str_contains($sources['landing'], 'Public access restrictions')
        && str_contains($sources['landing'], 'feedback.php'),
    'The public landing page does not explain the development state, restrictions and feedback route.'
);

public_access_expect(
    str_contains($sources['feedback'], 'catalog_smtp_send(')
        && str_contains($sources['feedback'], 'catalog_public_feedback_limit($db)')
        && str_contains($sources['feedback'], 'name="return_to"')
        && str_contains($sources['feedback'], "header('Location: ' . \$returnTo, true, 303)")
        && str_contains($sources['feedback'], "\$_SERVER['HTTP_REFERER']")
        && !str_contains($sources['feedback'], 'smtp_host')
        && !str_contains($sources['feedback'], 'smtp_password'),
    'The feedback form exposes SMTP settings, omits rate controls, or does not return to the previous safe page.'
);

foreach (['smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'smtp_from_email'] as $field) {
    public_access_expect(str_contains($sources['admin'], $field), 'Admin mail settings are missing ' . $field . '.');
}
public_access_expect(
    str_contains($sources['admin'], 'Save and send SMTP test')
        && str_contains($sources['admin'], 'Enable SMTP delivery before enabling the public feedback form.')
        && str_contains($sources['admin'], 'public_download_max_files')
        && str_contains($sources['admin'], 'public_burst_block_seconds')
        && str_contains($sources['admin'], 'catalog_public_cache_invalidate($config)'),
    'The admin page does not control/test mail, public restrictions, or refresh public pages after saving.'
);

$guardPosition = strpos($sources['support'], 'catalog_public_access_guard_request();');
$cachePosition = strpos($sources['support'], 'catalog_public_cache_bootstrap');
public_access_expect(
    $guardPosition !== false && $cachePosition !== false && $guardPosition < $cachePosition,
    'The crawler/burst guard does not run before public response-cache lookup.'
);

public_access_expect(
    str_contains($sources['support_core'], "catalog_nav_link('Search'")
        && str_contains($sources['support_core'], "'Feedback',")
        && str_contains($sources['support_core'], 'catalog_public_feedback_enabled()')
        && str_contains($sources['support_core'], 'catalog_public_current_return_path()')
        && str_contains($sources['support_core'], 'catalog_public_safe_return_path')
        && str_contains($sources['support_core'], "\$_SESSION['catalog_global_flash']"),
    'The public header does not place an enabled Feedback link beside Search or preserve a safe return target.'
);

public_access_expect(
    str_contains($sources['public_cache'], 'function catalog_public_cache_invalidate')
        && str_contains($sources['public_cache'], "if (\$page === '' || \$page === 'home')")
        && str_contains($sources['public_cache'], 'return 0;'),
    'The landing page can remain stale after the development notice or feedback settings change.'
);

public_access_expect(
    str_contains($sources['access'], "'public_download_max_files' => 10")
        && str_contains($sources['access'], "'public_package_max_builds' => 10")
        && str_contains($sources['access'], "'public_burst_block_seconds' => 600")
        && str_contains($sources['access'], 'catalog_public_access_known_crawler')
        && str_contains($sources['access'], 'catalog_public_stream_file')
        && str_contains($sources['access'], 'while (!connection_aborted())')
        && !str_contains($sources['access'], "if (!empty(\$_COOKIE['UNREALDB_REMEMBER'])) {\n        return true;")
        && str_contains($sources['access'], 'return catalog_support_is_admin();'),
    'The default 10-per-hour limits, ten-minute block, crawler detection or speed-controlled stream are missing.'
);

public_access_expect(
    str_contains($sources['download'], 'catalog_public_download_limit($db)')
        && str_contains($sources['download'], 'catalog_public_stream_file($path, $speedBytes)'),
    'Individual downloads do not apply the configured IP limit and speed control.'
);

public_access_expect(
    str_contains($sources['package_job'], 'catalog_public_package_limit($db)')
        && !str_contains($sources['package_job'], 'UNREALDB_PACKAGE_GENERATION_MAX_REQUESTS'),
    'Generated-package enqueue does not use the administrator-controlled per-IP limit.'
);

public_access_expect(
    str_contains($sources['package_download'], 'catalog_public_download_speed_bytes($db)')
        && str_contains($sources['package_download'], 'catalog_public_stream_file($path, $speedBytes)'),
    'Generated package downloads do not use the configured speed control.'
);

public_access_expect(
    substr_count($sources['navigation'], "'Public Access & Mail'") >= 2
        && str_contains($sources['download_admin'], 'public-access-settings.php'),
    'Public access controls are not linked from the administrator navigation and Downloads page.'
);

public_access_expect(
    str_contains($sources['migration'], "'version' => '202607310004'")
        && str_contains($sources['migration'], "'feedback_recipient' => 'info@unrealdb.com'")
        && str_contains($sources['migration'], "'public_download_max_files' => '10'")
        && str_contains($sources['migration'], "'public_package_max_builds' => '10'")
        && str_contains($sources['migration'], "'feedback_enabled' => '0'")
        && str_contains($sources['safety_migration'], "'version' => '202607310005'")
        && str_contains($sources['safety_migration'], 'smtp_enabled'),
    'The migration does not seed the public feedback and access defaults.'
);

public_access_expect(
    str_contains($sources['smtp'], 'STARTTLS')
        && str_contains($sources['smtp'], 'AUTH LOGIN')
        && str_contains($sources['smtp'], 'stream_socket_client'),
    'The SMTP client does not support configured authenticated TLS delivery.'
);

echo "Public access controls contract tests passed.\n";
