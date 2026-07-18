<?php
declare(strict_types=1);

require_once __DIR__ . '/../bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Security\FileLoginRateLimiter;
use UnrealDb\Catalog\Infrastructure\Storage\LocalStoragePathGuard;

function security_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function security_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child)) {
            security_remove_tree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}

$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb_security_' . bin2hex(random_bytes(8));
$applicationRoot = $temporaryRoot . DIRECTORY_SEPARATOR . 'catalog';
$storageRoot = $applicationRoot . DIRECTORY_SEPARATOR . 'storage';
$siblingRoot = $applicationRoot . DIRECTORY_SEPARATOR . 'storage-copy';
mkdir($storageRoot, 0700, true);
mkdir($siblingRoot, 0700, true);
file_put_contents($storageRoot . DIRECTORY_SEPARATOR . 'allowed.bin', 'allowed');
file_put_contents($siblingRoot . DIRECTORY_SEPARATOR . 'blocked.bin', 'blocked');

try {
    $allowed = LocalStoragePathGuard::resolveFile($storageRoot, $applicationRoot, 'storage/allowed.bin');
    security_expect($allowed === realpath($storageRoot . DIRECTORY_SEPARATOR . 'allowed.bin'), 'Valid storage file was rejected.');

    $blocked = false;
    try {
        LocalStoragePathGuard::resolveFile($storageRoot, $applicationRoot, 'storage-copy/blocked.bin');
    } catch (RuntimeException) {
        $blocked = true;
    }
    security_expect($blocked, 'Sibling path with the same prefix escaped the storage root.');

    $now = 1000;
    $limiter = new FileLoginRateLimiter(
        $temporaryRoot . DIRECTORY_SEPARATOR . 'rate-limit',
        2,
        60,
        120,
        static function () use (&$now): int {
            return $now;
        }
    );
    security_expect($limiter->retryAfterSeconds('admin', '127.0.0.1') === 0, 'Fresh login identity was blocked.');
    security_expect($limiter->recordFailure('admin', '127.0.0.1') === 0, 'Login was blocked before reaching the configured threshold.');
    security_expect($limiter->recordFailure('admin', '127.0.0.1') === 120, 'Login threshold did not create the configured block.');
    $now += 121;
    security_expect($limiter->retryAfterSeconds('admin', '127.0.0.1') === 0, 'Expired login block was not released.');
    $limiter->clear('admin', '127.0.0.1');

    $index = file_get_contents(__DIR__ . '/../index.php');
    security_expect(is_string($index), 'catalog/index.php could not be read.');
    security_expect(str_contains($index, "redirect_to('download.php?id='"), 'Legacy download route no longer delegates to the canonical endpoint.');
    security_expect(!str_contains($index, 'readfile($path)'), 'Legacy index route regained direct file-serving behavior.');
    security_expect(str_contains($index, 'FileLoginRateLimiter'), 'Administrator login is not routed through the shared rate limiter.');

    $application = file_get_contents(__DIR__ . '/../src/Presentation/Http/CatalogApplication.php');
    security_expect(is_string($application), 'CatalogApplication.php could not be read.');
    security_expect(str_contains($application, 'catalog_start_session'), 'Application bootstrap bypasses the shared session policy.');
    security_expect(!str_contains($application, "display_errors', '1"), 'Application bootstrap re-enabled displayed errors.');

    $security = file_get_contents(__DIR__ . '/../lib/CatalogSecurity.php');
    security_expect(is_string($security), 'CatalogSecurity.php could not be read.');
    security_expect(str_contains($security, 'catalog_session_idle_timeout_seconds'), 'Idle session timeout is missing.');
    security_expect(str_contains($security, 'catalog_session_absolute_timeout_seconds'), 'Absolute session timeout is missing.');

    $federationAuth = file_get_contents(__DIR__ . '/../lib/FederationAuth.php');
    security_expect(is_string($federationAuth), 'FederationAuth.php could not be read.');
    security_expect(str_contains($federationAuth, 'fed_read_request_body'), 'Federation JSON bodies are not read through the bounded reader.');
    security_expect(str_contains($federationAuth, '$limit + 1'), 'Federation body reader does not detect over-limit streams.');

    $inventory = file_get_contents(__DIR__ . '/../api/federation/inventory-push.php');
    security_expect(is_string($inventory), 'inventory-push.php could not be read.');
    security_expect(str_contains($inventory, 'max_inventory_rows_per_push'), 'Federation inventory row limit is missing.');
    security_expect(str_contains($inventory, 'array_chunk($normalized, 500)'), 'Federation inventory is not committed in bounded chunks.');

    foreach (['cron-worker.php', 'cron-worker-streaming.php'] as $cronFile) {
        $cron = file_get_contents(__DIR__ . '/../federation/' . $cronFile);
        security_expect(is_string($cron), $cronFile . ' could not be read.');
        security_expect(str_contains($cron, 'HTTP_X_FEDERATION_CRON_TOKEN'), $cronFile . ' does not require the token header.');
        security_expect(str_contains($cron, "method !== 'POST'"), $cronFile . ' does not enforce POST.');
        security_expect(str_contains($cron, 'UNREALDB_ALLOW_LEGACY_QUERY_TOKENS'), $cronFile . ' lacks an explicit legacy-token compatibility gate.');
    }
} finally {
    security_remove_tree($temporaryRoot);
}

echo "Security boundary tests passed.\n";
