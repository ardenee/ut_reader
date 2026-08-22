#!/usr/bin/env php
<?php
/** Read-only contract verifier for Upload Bucket pause/finalisation worker-state parity. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$read = static function (string $relative) use ($root): string {
    $value = @file_get_contents($root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
    return is_string($value) ? $value : '';
};

$statePath = $root . '/src/Infrastructure/Import/CatalogBucketProcessingStateService.php';
$finalizerPath = $root . '/src/Infrastructure/Import/CatalogBucketBatchFinalizer.php';
$state = $read('src/Infrastructure/Import/CatalogBucketProcessingStateService.php');
$finalizer = $read('src/Infrastructure/Import/CatalogBucketBatchFinalizer.php');

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ': ' . $detail;
    }
};

$record(
    'pause_state_counts_launching_workers_as_busy',
    str_contains($state, "(int)(\$status['launching_count'] ?? 0) > 0")
        && str_contains($state, 'if ($requestPause && $busy)')
        && str_contains($state, 'if ($busy)')
        && str_contains($state, "'launching' => \$launching")
        && str_contains($state, "'busy' => \$busy"),
    'A launching detached worker is queue activity and must keep Upload Bucket finalisation in the waiting state.'
);

$record(
    'finalizer_uses_same_busy_definition',
    str_contains($finalizer, "(int)(\$workerStatus['launching_count'] ?? 0) > 0")
        && str_contains($finalizer, 'if ($prepareQueue && !$busy)')
        && str_contains($finalizer, 'if ($busy)')
        && str_contains($finalizer, 'throw new CatalogBucketProcessingActive($activeQueues)'),
    'The final server-side gate must use the same active-or-launching definition as browser pause polling.'
);

$syntaxFailures = [];
if (function_exists('proc_open')) {
    foreach ([$statePath, $finalizerPath] as $path) {
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
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit($failures === [] ? 0 : 2);
