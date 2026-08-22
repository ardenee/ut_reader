#!/usr/bin/env php
<?php
/**
 * Read-only diagnostic for Epic UE3 package summary/compression metadata.
 *
 * This intentionally uses the production reader so the values shown are exactly
 * the values the worker acted on. It never mutates the package or database.
 * A package can be supplied directly, or a retained archive-member child can be
 * resolved from its durable prepared-job workspace with --job=<id>.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$args = array_values(array_slice($argv, 1));
$path = '';
$jobId = 0;
if (isset($args[0]) && preg_match('/^--job=(\d+)$/', (string)$args[0], $match) === 1) {
    $jobId = (int)$match[1];
} elseif (($args[0] ?? '') === '--job' && isset($args[1]) && ctype_digit((string)$args[1])) {
    $jobId = (int)$args[1];
} else {
    $path = trim((string)($args[0] ?? ''));
}

if ($jobId > 0) {
    require_once dirname(__DIR__) . '/bootstrap/operational.php';
    $application = catalog_operational_application();
    $store = new \UnrealDb\Catalog\Infrastructure\Jobs\CatalogPreparedJobFileStore(
        $application->config,
        $jobId,
        'bucket-archive-member'
    );
    $prepared = $store->load();
    if (!is_array($prepared) || trim((string)($prepared['path'] ?? '')) === '') {
        fwrite(STDERR, 'No retained bucket archive-member prepared file exists for job #' . $jobId . '. ');
        fwrite(STDERR, 'Expected workspace: ' . $store->directory() . PHP_EOL);
        exit(2);
    }
    $path = (string)$prepared['path'];
    fwrite(STDOUT, 'Resolved from background job #' . $jobId . ': '
        . (string)($prepared['logical_name'] ?? basename($path)) . PHP_EOL);
}

if ($path === '' || !is_file($path)) {
    fwrite(STDERR, "Usage:\n");
    fwrite(STDERR, "  php catalog/bin/diagnose-ue3-package.php <package.upk|package.ut3>\n");
    fwrite(STDERR, "  php catalog/bin/diagnose-ue3-package.php --job=<background-job-id>\n");
    exit(2);
}

require_once dirname(__DIR__) . '/parsers/EpicUE3PackageReader.php';

$size = filesize($path);
if ($size === false) {
    fwrite(STDERR, "Could not determine package size.\n");
    exit(2);
}

fwrite(STDOUT, 'Package: ' . $path . PHP_EOL);
fwrite(STDOUT, 'Size: ' . number_format($size) . ' bytes' . PHP_EOL);
fwrite(STDOUT, 'SHA1: ' . sha1_file($path) . PHP_EOL);
fwrite(STDOUT, 'SHA256: ' . hash_file('sha256', $path) . PHP_EOL);

$reader = new CatalogUE3PackageReader($path);
$header = $reader->getHeader();
$issues = $reader->getIssues();

$fields = [
    'sourceTag', 'packedVersion', 'version', 'licensee', 'totalHeaderSize',
    'folderName', 'packageFlags', 'nameCount', 'nameOffset', 'exportCount',
    'exportOffset', 'importCount', 'importOffset', 'dependsOffset',
    'importExportGuidsOffset', 'importGuidsCount', 'exportGuidsCount',
    'thumbnailTableOffset', 'guid', 'genCount', 'engineVersion',
    'cookedContentVersion', 'compressionFlags', 'packageSource',
    'compressed', 'logicalDecompressed', 'logicalSize',
];

fwrite(STDOUT, PHP_EOL . "Summary fields:\n");
foreach ($fields as $field) {
    if (!array_key_exists($field, $header)) {
        continue;
    }
    $value = $header[$field];
    if (is_bool($value)) {
        $value = $value ? 'true' : 'false';
    } elseif (is_int($value) && in_array($field, ['sourceTag', 'packageFlags', 'compressionFlags', 'packageSource'], true)) {
        $value = sprintf('%u (0x%08X)', $value, $value);
    } elseif (is_array($value)) {
        $value = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
    fwrite(STDOUT, '  ' . $field . '=' . (string)$value . PHP_EOL);
}

$chunks = is_array($header['chunks'] ?? null) ? array_values($header['chunks']) : [];
fwrite(STDOUT, PHP_EOL . 'Compressed chunks: ' . count($chunks) . PHP_EOL);
fwrite(STDOUT, str_repeat('-', 112) . PHP_EOL);

$handle = fopen($path, 'rb');
foreach ($chunks as $index => $chunk) {
    $uOff = (int)($chunk['uOff'] ?? 0);
    $uLen = (int)($chunk['uLen'] ?? 0);
    $cOff = (int)($chunk['cOff'] ?? 0);
    $cLen = (int)($chunk['cLen'] ?? 0);
    $uEnd = $uOff + $uLen;
    $cEnd = $cOff + $cLen;
    $fits = $cOff >= 0 && $cLen >= 0 && $cEnd <= $size;
    $prefix = '';
    if (is_resource($handle) && $cOff >= 0 && $cOff < $size && fseek($handle, $cOff, SEEK_SET) === 0) {
        $raw = fread($handle, (int)min(16, $size - $cOff));
        if (is_string($raw)) {
            $prefix = strtoupper(bin2hex($raw));
        }
    }
    fwrite(STDOUT, sprintf(
        '[%d] uOff=%d uLen=%d uEnd=%d cOff=%d cLen=%d cEnd=%d physicalFit=%s remainingFromOffset=%d prefix=%s%s',
        $index,
        $uOff,
        $uLen,
        $uEnd,
        $cOff,
        $cLen,
        $cEnd,
        $fits ? 'yes' : 'NO',
        $cOff >= 0 && $cOff <= $size ? $size - $cOff : -1,
        $prefix !== '' ? $prefix : '-',
        PHP_EOL
    ));
}
if (is_resource($handle)) {
    fclose($handle);
}

fwrite(STDOUT, str_repeat('-', 112) . PHP_EOL);
fwrite(STDOUT, 'Reader issues: ' . count($issues) . PHP_EOL);
foreach ($issues as $issue) {
    fwrite(STDOUT, '  ' . $issue . PHP_EOL);
}

exit($issues === [] ? 0 : 1);
