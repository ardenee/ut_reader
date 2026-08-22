#!/usr/bin/env php
<?php
/**
 * Read-only source/provenance lookup for a background job.
 *
 * Accepts either a workflow parent or child id. Archive-derived children include
 * the originating archive job and the resolved server-side archive path when the
 * retained staged source still exists.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$jobId = 0;
foreach (array_slice($argv, 1) as $argument) {
    if (preg_match('/^--job=(\d+)$/', (string)$argument, $match) === 1) {
        $jobId = (int)$match[1];
        break;
    }
    if (ctype_digit((string)$argument)) {
        $jobId = (int)$argument;
        break;
    }
}
if ($jobId < 1) {
    fwrite(STDERR, "Usage: php catalog/bin/locate-job-source.php --job=<background-job-id>\n");
    exit(2);
}

try {
    require_once dirname(__DIR__) . '/bootstrap/operational.php';
    $application = catalog_operational_application();
    $resolver = new \UnrealDb\Catalog\Infrastructure\Jobs\CatalogJobSourceContextResolver(
        $application->db,
        $application->config
    );
    $context = $resolver->forJobId($jobId);
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'source' => $context,
        'read_only' => true,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(0);
} catch (Throwable $error) {
    fwrite(STDERR, json_encode([
        'ok' => false,
        'error' => get_class($error) . ': ' . $error->getMessage(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(2);
}
