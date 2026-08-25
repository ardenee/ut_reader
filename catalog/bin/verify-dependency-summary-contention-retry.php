#!/usr/bin/env php
<?php
/** Read-only contract verifier for dependency-summary contention handling. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$path = $root . '/src/Infrastructure/Persistence/PdoDependencyPackageSummary.php';
$source = (string)@file_get_contents($path);
$checks = [
    'deterministic_file_lock_order' => str_contains($source, 'sort($fileIds, SORT_NUMERIC);'),
    'bounded_local_contention_attempts' => str_contains($source, 'private const CONTENTION_ATTEMPTS = 5;'),
    'uses_shared_contention_classifier' => str_contains($source, 'PdoContention::retryable($error)'),
    'uses_jittered_backoff' => str_contains($source, 'PdoContention::backoffMicros($attempt, 25000)'),
    'retries_only_owned_transactions' => str_contains($source, '$maxAttempts = $ownsTransaction ? self::CONTENTION_ATTEMPTS : 1;'),
    'rolls_back_failed_chunk_before_retry' => str_contains($source, '$this->db->rollBack();'),
];

$failures = [];
foreach ($checks as $name => $ok) {
    if (!$ok) {
        $failures[] = $name;
    }
}

$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
