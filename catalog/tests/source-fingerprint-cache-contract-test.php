<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies source fingerprint cache behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Persistence\PdoSourceFileFingerprintCache;

require_once __DIR__ . '/../bootstrap/autoload.php';

function source_fingerprint_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$migration = file_get_contents(__DIR__ . '/../migrations/202607270008_source_file_fingerprints.php');
source_fingerprint_expect(is_string($migration), 'Source fingerprint migration could not be read.');
source_fingerprint_expect(
    str_contains($migration, "'version' => '202607270008'")
        && str_contains($migration, 'ue_source_file_fingerprints')
        && str_contains($migration, 'quick_fingerprint')
        && str_contains($migration, 'matched_file_id'),
    'Source fingerprint migration does not create the required projection.'
);

$classSource = file_get_contents(__DIR__ . '/../src/Infrastructure/Persistence/PdoSourceFileFingerprintCache.php');
source_fingerprint_expect(is_string($classSource), 'Source fingerprint cache class could not be read.');
source_fingerprint_expect(
    str_contains($classSource, 'ue-source-fingerprint-v1')
        && str_contains($classSource, '$sampleSize = 65536;')
        && str_contains($classSource, 'resolveVerifiedFile(')
        && str_contains($classSource, 'identityAgrees(')
        && !str_contains($classSource, "hash_file('md5'"),
    'Source fingerprint cache does not use bounded sampled probes and verified identity checks.'
);

$reflection = new ReflectionClass(PdoSourceFileFingerprintCache::class);
/** @var PdoSourceFileFingerprintCache $cache */
$cache = $reflection->newInstanceWithoutConstructor();
$temporary = tempnam(sys_get_temp_dir(), 'ue_fingerprint_test_');
source_fingerprint_expect(is_string($temporary) && $temporary !== '', 'Could not create fingerprint test file.');
try {
    file_put_contents($temporary, str_repeat('A', 400000));
    $mtime = filemtime($temporary);
    $first = $cache->probe($temporary);
    $second = $cache->probe($temporary);
    source_fingerprint_expect($first === $second, 'Unchanged file did not produce a stable source fingerprint.');

    $handle = fopen($temporary, 'r+b');
    source_fingerprint_expect($handle !== false, 'Could not reopen fingerprint test file.');
    fseek($handle, 200000, SEEK_SET);
    fwrite($handle, str_repeat('B', 4096));
    fclose($handle);
    if ($mtime !== false) {
        touch($temporary, $mtime);
    }
    clearstatcache(true, $temporary);
    $changed = $cache->probe($temporary);
    source_fingerprint_expect(
        $changed['file_size'] === $first['file_size']
            && $changed['modified_at'] === $first['modified_at']
            && $changed['quick_fingerprint'] !== $first['quick_fingerprint'],
        'Sampled fingerprint did not detect changed content with unchanged size and timestamp.'
    );
} finally {
    @unlink($temporary);
}

$scanner = file_get_contents(__DIR__ . '/../lib/CatalogSourceScanNoContainers.php');
source_fingerprint_expect(is_string($scanner), 'Cached source scanner could not be read.');
source_fingerprint_expect(
    str_contains($scanner, 'PdoSourceFileFingerprintCache')
        && str_contains($scanner, "'fingerprint_hits'")
        && str_contains($scanner, "'redirect_cache_hits'")
        && str_contains($scanner, 'resolveVerifiedFile(')
        && str_contains($scanner, 'catalog_source_scan_cached_work('),
    'Durable local source scan is not connected to the fingerprint cache.'
);

$page = file_get_contents(__DIR__ . '/../source-scan.php');
source_fingerprint_expect(is_string($page), 'Source scan page could not be read.');
source_fingerprint_expect(
    str_contains($page, 'catalog_source_scan_run_without_containers(')
        && !str_contains($page, 'md5_file(')
        && !str_contains($page, 'function scan_local_source('),
    'Synchronous source scan still maintains a separate uncached hashing loop.'
);

fwrite(STDOUT, "Source fingerprint cache contract tests passed.\n");
