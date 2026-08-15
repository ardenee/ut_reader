<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Preserves the stable public-access helper API for existing pages.
 * Why: Settings storage, abuse protection and file streaming now have focused namespaced owners.
 * Role: Thin compatibility facade; do not add public-access implementation logic here.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Security\CatalogPublicAccessGuard;
use UnrealDb\Catalog\Infrastructure\Security\CatalogPublicAccessSettingsStore;
use UnrealDb\Catalog\Infrastructure\Security\CatalogPublicFileStreamer;

/** @return array<string,mixed> */
function catalog_public_access_settings(?PDO $db = null, ?array $config = null): array
{
    $config ??= function_exists('catalog_config') ? catalog_config() : [];
    return (new CatalogPublicAccessSettingsStore($config))->settings($db);
}

function catalog_public_access_client_ip(): string
{
    return (new CatalogPublicAccessGuard())->clientIp();
}

function catalog_public_access_guard_crawler_request(): void
{
    (new CatalogPublicAccessGuard())->guardCrawlerRequest();
}

function catalog_public_access_guard_burst_request(): void
{
    (new CatalogPublicAccessGuard())->guardBurstRequest();
}

function catalog_public_access_guard_request(): void
{
    (new CatalogPublicAccessGuard())->guardRequest();
}

function catalog_public_download_limit(PDO $db): void
{
    (new CatalogPublicAccessGuard(catalog_config()))->downloadLimit($db);
}

function catalog_public_package_limit(PDO $db): void
{
    (new CatalogPublicAccessGuard(catalog_config()))->packageLimit($db);
}

function catalog_public_feedback_limit(PDO $db): void
{
    (new CatalogPublicAccessGuard(catalog_config()))->feedbackLimit($db);
}

function catalog_public_download_speed_bytes(PDO $db): int
{
    return (new CatalogPublicAccessGuard(catalog_config()))->downloadSpeedBytes($db);
}

function catalog_public_stream_file(string $path, int $bytesPerSecond = 0): never
{
    (new CatalogPublicFileStreamer())->stream($path, $bytesPerSecond);
}

function catalog_public_access_window_label(int $seconds): string
{
    return CatalogPublicAccessSettingsStore::windowLabel($seconds);
}
