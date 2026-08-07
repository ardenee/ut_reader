<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies security boundaries behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
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
    if (!is_dir($path)) return;
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        is_dir($child) ? security_remove_tree($child) : @unlink($child);
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
    try { LocalStoragePathGuard::resolveFile($storageRoot, $applicationRoot, 'storage-copy/blocked.bin'); } catch (RuntimeException) { $blocked = true; }
    security_expect($blocked, 'Sibling path escaped the storage root.');

    $now = 1000;
    $limiter = new FileLoginRateLimiter($temporaryRoot . DIRECTORY_SEPARATOR . 'rate-limit', 2, 60, 120, static function () use (&$now): int { return $now; });
    security_expect($limiter->recordFailure('admin', '127.0.0.1') === 0, 'Login was blocked too early.');
    security_expect($limiter->recordFailure('admin', '127.0.0.1') === 120, 'Login threshold did not create a block.');
    $now += 121;
    security_expect($limiter->retryAfterSeconds('admin', '127.0.0.1') === 0, 'Expired login block was not released.');

    $federationAuth = file_get_contents(__DIR__ . '/../lib/FederationAuth.php');
    security_expect(is_string($federationAuth) && str_contains($federationAuth, 'fed_read_request_body'), 'Federation bodies are not read through the bounded reader.');
    security_expect(str_contains($federationAuth, '$limit + 1'), 'Federation body reader does not detect over-limit streams.');

    $inventory = file_get_contents(__DIR__ . '/../api/federation/inventory-push.php');
    security_expect(is_string($inventory) && str_contains($inventory, 'max_inventory_rows_per_push'), 'Federation inventory row limit is missing.');
    security_expect(str_contains($inventory, 'array_chunk($normalized, 500)'), 'Federation inventory is not committed in bounded chunks.');

    $cron = file_get_contents(__DIR__ . '/../federation/cron-worker-streaming.php');
    security_expect(is_string($cron), 'cron-worker-streaming.php could not be read.');
    security_expect(str_contains($cron, 'HTTP_X_FEDERATION_CRON_TOKEN'), 'Streaming cron worker does not require the token header.');
    security_expect(str_contains($cron, "method !== 'POST'"), 'Streaming cron worker does not enforce POST.');
    security_expect(str_contains($cron, 'UNREALDB_ALLOW_LEGACY_QUERY_TOKENS'), 'Streaming cron worker lacks an explicit compatibility gate.');
    security_expect(!is_file(__DIR__ . '/../federation/cron-worker.php'), 'Legacy non-streaming cron worker still exists.');
} finally {
    security_remove_tree($temporaryRoot);
}

echo "Security boundary tests passed.\n";
