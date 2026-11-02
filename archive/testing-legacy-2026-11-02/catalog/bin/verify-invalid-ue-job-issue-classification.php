#!/usr/bin/env php
<?php
/**
 * Regression gate: completed invalid Unreal package jobs are operator issues,
 * not ordinary completed successes.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$queryPath = $root . '/src/Infrastructure/Persistence/PdoBackgroundJobFileTreeQuery.php';
$projectorPath = $root . '/src/Infrastructure/Jobs/CatalogBackgroundJobFileTreeProjector.php';
$displayPath = $root . '/src/Infrastructure/Jobs/CatalogJobDisplayStatus.php';
$clientPath = $root . '/assets/background-jobs-files.js';

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};

$lint = static function (string $path): array {
    $pipes = [];
    $process = @proc_open([PHP_BINARY, '-l', $path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        return [false, 'Could not run php -l for ' . $path];
    }
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    return [$exit === 0, trim((string)$stderr . ' ' . (string)$stdout)];
};

[$queryOk, $queryLint] = $lint($queryPath);
[$projectorOk, $projectorLint] = $lint($projectorPath);
[$displayOk, $displayLint] = $lint($displayPath);
$record(
    'php_syntax',
    $queryOk && $projectorOk && $displayOk,
    trim($queryLint . ' | ' . $projectorLint . ' | ' . $displayLint)
);

$query = (string)@file_get_contents($queryPath);
$projector = (string)@file_get_contents($projectorPath);
$display = (string)@file_get_contents($displayPath);
$client = (string)@file_get_contents($clientPath);

$record(
    'query_counts_invalid_ue_as_issue',
    str_contains($query, '"invalid_ue_package"'),
    'Background Jobs Issues tab must include completed invalid UE package outcomes.'
);

$record(
    'projector_renders_invalid_ue_as_issue',
    str_contains($projector, "'invalid_ue_package'")
        && str_contains($projector, "'invalid_ue_package' => 'Invalid UE file · logged in System Errors'"),
    'The visible file row must agree with the Issues-tab query classification.'
);

$record(
    'central_status_policy_counts_invalid_ue_as_issue',
    str_contains($display, "'failed', 'rejected', 'unverified', 'invalid_ue_package'")
        && str_contains($display, 'display_status IN ("failed","rejected","unverified","invalid_ue_package")'),
    'Shared filters/exports must agree with the file-tree Issues classification.'
);

$record(
    'retained_invalid_ue_exposes_current_code_revalidation',
    str_contains($projector, '$row[\'can_revalidate\']')
        && str_contains($projector, '!empty($result[\'source_retained\'])')
        && str_contains($client, "action: 'revalidate'")
        && str_contains($client, 'Revalidate with current code'),
    'A retained invalid-package issue must expose the explicit current-code revalidation path instead of requiring another upload.'
);

echo json_encode([
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($failures === [] ? 0 : 1);
