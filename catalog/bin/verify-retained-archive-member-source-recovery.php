#!/usr/bin/env php
<?php
/** Regression verifier for archive-member source recovery across retries/deploys. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$routerPath = $root . '/src/Infrastructure/Jobs/CatalogArchiveMemberContentRoutingJobHandler.php';
$restorerPath = $root . '/src/Infrastructure/Jobs/CatalogRetainedArchiveMemberSourceRestorer.php';
$resolverPath = $root . '/src/Infrastructure/Jobs/CatalogJobSourceContextResolver.php';
$retryPath = $root . '/src/Application/Jobs/JobFailureRetryPolicy.php';
$versionPath = $root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php';
$files = [$routerPath, $restorerPath, $resolverPath, $retryPath, $versionPath, __FILE__];

$source = [];
foreach ($files as $path) {
    $source[$path] = (string)@file_get_contents($path);
}

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$router = $source[$routerPath];
$restorer = $source[$restorerPath];
$resolver = $source[$resolverPath];
$retry = $source[$retryPath];
$version = $source[$versionPath];

$preparedLookup = strpos($router, "CatalogPreparedJobFileStore(\$this->config, \$job->id, 'bucket-archive-member')");
$incomingResolve = strpos($router, '$incoming->resolve($stagedPath)');
$record(
    'router_prefers_durable_child_prepared_source',
    $preparedLookup !== false && $incomingResolve !== false && $preparedLookup < $incomingResolve
        && str_contains($router, '$sourceFromPrepared = is_array($prepared);'),
    'A retry must classify the durable bucket-archive-member prepared file before touching its shorter-lived jobs/incoming path.'
);

$record(
    'missing_child_staging_recovers_from_parent_archive',
    str_contains($router, 'CatalogRetainedArchiveMemberSourceRestorer')
        && str_contains($router, 'archive_member_source_restored')
        && str_contains($restorer, 'CatalogJobSourceContextResolver')
        && str_contains($restorer, "archive_prepared_path")
        && str_contains($restorer, 'hash_equals($recordedEntry, $candidatePath)')
        && str_contains($restorer, 'stageLocalFile($temporary, $originalName)'),
    'If both current incoming staging and the child prepared file are absent, the exact recorded member must be reconstructed from the retained parent archive.'
);

$record(
    'parent_recovery_uses_authoritative_job_owned_source',
    str_contains($resolver, 'applyPreparedArchiveSource($context, $parentJobId)')
        && str_contains($resolver, "archive_source_storage'] = 'job-prepared'")
        && str_contains($restorer, "archive_full_path"),
    'Recovery must prefer the parent job-owned prepared archive rather than relying on the released chunk-upload ingress path.'
);

$record(
    'special_routes_restaged_before_prepared_source_is_cleared',
    substr_count($router, 'stageLocalFile($sourcePath, $originalName)') >= 2
        && substr_count($router, '$preparedStore->clear();') >= 3
        && str_contains($router, '$this->withStagedFile($effectiveJob, $restaged)'),
    'Historical prepared members that are newly recognized as redirects or nested archives must be copied back to controlled staging before their old prepared slot is cleared.'
);

$record(
    'unrecoverable_missing_member_source_is_non_retryable',
    str_contains($retry, "'archive member staged source is unavailable and retained-parent reconstruction failed:'")
        && str_contains($retry, "'retained parent archive source is unavailable for member reconstruction'")
        && str_contains($retry, "'retained parent archive no longer contains the exact recorded member'"),
    'Once child prepared storage, incoming staging and retained-parent reconstruction all fail, replaying the same immutable job must not create three identical System Errors.'
);

$record(
    'worker_fingerprint_tracks_source_restorer',
    str_contains($version, '/CatalogRetainedArchiveMemberSourceRestorer.php')
        && str_contains($version, '/CatalogArchiveMemberContentRoutingJobHandler.php'),
    'Detached workers must reconcile when the retry source-selection/reconstruction code changes.'
);

$record(
    'recovery_stays_in_process_php',
    preg_match('/\b(?:exec|shell_exec|system|passthru|popen|proc_open)\s*\(/i', $router . "\n" . $restorer) !== 1,
    'Archive-member recovery must not invoke external archive applications or shell processes.'
);

$syntaxFailures = [];
if (function_exists('proc_open')) {
    foreach ($files as $path) {
        $pipes = [];
        $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            $syntaxFailures[] = basename($path) . ': could not run php -l';
            continue;
        }
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        if ($exit !== 0) {
            $syntaxFailures[] = basename($path) . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
        }
    }
}
$record('php_syntax', $syntaxFailures === [], $syntaxFailures === [] ? '' : implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
