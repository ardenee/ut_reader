#!/usr/bin/env php
<?php
/** Read-only contract for retained-archive retryability and operator controls. */
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

$uiPath = $root . '/assets/background-jobs-archive-errors.js';
$bulkPath = $root . '/src/Infrastructure/Persistence/PdoBackgroundJobBulkAction.php';
$ui = $read('assets/background-jobs-archive-errors.js');
$bulk = $read('src/Infrastructure/Persistence/PdoBackgroundJobBulkAction.php');
$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) $failures[] = $name . ': ' . $detail;
};

$record(
    'ui_distinguishes_blocked_retained_archives',
    str_contains($ui, 'const blockedArchiveIds = new Set()')
        && str_contains($ui, 'const isBlockedRetainedArchive = (job) =>')
        && str_contains($ui, "setText(button, 'Recovery blocked')")
        && str_contains($ui, "button.dataset.action = ''")
        && str_contains($ui, 'button.disabled = true'),
    'Deterministic retained failures must not continue to present an active Retry archive control.'
);
$record(
    'known_decoder_capability_failures_are_blocked',
    str_contains($ui, "text.includes('unsupported zip compression method')")
        && str_contains($ui, "text.includes('rarentry::extract() returned failure')"),
    'Known decoder-capability failures must remain blocked when replaying identical bytes cannot change the result.'
);
$record(
    'zip_stream_and_size_failures_remain_retryable',
    !str_contains($ui, "text.includes('could not read zip member stream')")
        && !str_contains($ui, "text.includes('output size does not match its declared size')"),
    'ZIP stream/size failures must remain operator-retryable because exact local-header recovery can now decode and CRC-verify those members.'
);
$record(
    'bulk_retry_excludes_decoder_blocked_archives',
    str_contains($bulk, 'decoderBlockedArchiveSql(')
        && str_contains($bulk, 'AND NOT ')
        && str_contains($bulk, 'installed php archive decoder cannot decode this archive/member encoding')
        && str_contains($bulk, 'unsupported zip compression method')
        && str_contains($bulk, 'rarentry::extract() returned failure'),
    'The server must refuse futile replay of known decoder-capability failures even if an older browser posts restart.'
);
$record(
    'retry_all_is_explicitly_retryable_only',
    str_contains($ui, "setText(retryMatchingButton, 'Retry retryable archives')")
        && str_contains($ui, 'Decoder-blocked archives are deliberately excluded.')
        && str_contains($ui, 'visibleRetryableCount() < 1'),
    'Bulk recovery controls must distinguish retained source count from currently retryable archive count.'
);

$syntaxFailures = [];
foreach ([$bulkPath] as $path) {
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        $syntaxFailures[] = basename($path) . ' could not be linted';
        continue;
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0) {
        $syntaxFailures[] = basename($path) . ': ' . trim((string)$stderr . ' ' . (string)$stdout);
    }
}
$record('php_syntax', $syntaxFailures === [], implode(' | ', $syntaxFailures));

$node = trim((string)@shell_exec('node --version 2>NUL'));
if ($node !== '') {
    $output = [];
    $status = 0;
    exec('node --check ' . escapeshellarg($uiPath) . ' 2>&1', $output, $status);
    $record('javascript_syntax', $status === 0, implode(' ', $output));
} else {
    $record('javascript_syntax', true, 'Node is unavailable; static JavaScript contract checks still apply.');
}

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
