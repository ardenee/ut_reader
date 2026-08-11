#!/usr/bin/env php
<?php
/**
 * Static contract for retrying atomic format-2 metadata publication after MySQL contention.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
$checks = [
    'generic contention helper exists' => [
        'path' => $root . '/src/Infrastructure/Persistence/PdoContention.php',
        'needle' => 'final class PdoContention',
        'present' => true,
    ],
    'contention helper recognizes serialization failure' => [
        'path' => $root . '/src/Infrastructure/Persistence/PdoContention.php',
        'needle' => "\$sqlState === '40001'",
        'present' => true,
    ],
    'contention helper recognizes mysql deadlock' => [
        'path' => $root . '/src/Infrastructure/Persistence/PdoContention.php',
        'needle' => '[1205, 1213]',
        'present' => true,
    ],
    'finalizer retries whole snapshot publication' => [
        'path' => $root . '/src/Infrastructure/Metadata/VerifiedFileCompactMetadataFinalizer.php',
        'needle' => 'publishWithContentionRetry(',
        'present' => true,
    ],
    'finalizer uses generic contention classifier' => [
        'path' => $root . '/src/Infrastructure/Metadata/VerifiedFileCompactMetadataFinalizer.php',
        'needle' => 'PdoContention::retryable($error)',
        'present' => true,
    ],
    'finalizer bounds retry attempts' => [
        'path' => $root . '/src/Infrastructure/Metadata/VerifiedFileCompactMetadataFinalizer.php',
        'needle' => 'PUBLICATION_CONTENTION_ATTEMPTS = 5',
        'present' => true,
    ],
    'snapshot writer owns rollback' => [
        'path' => $root . '/src/Infrastructure/Metadata/BlockedCompressedMetadataSnapshotWriter.php',
        'needle' => '$this->db->rollBack()',
        'present' => true,
    ],
    'snapshot writer restores prior container' => [
        'path' => $root . '/src/Infrastructure/Metadata/BlockedCompressedMetadataSnapshotWriter.php',
        'needle' => '@rename($backupPath, $path)',
        'present' => true,
    ],
];

$failed = [];
foreach ($checks as $label => $check) {
    $content = @file_get_contents((string)$check['path']);
    $present = is_string($content) && str_contains($content, (string)$check['needle']);
    if (!is_string($content) || $present !== (bool)$check['present']) {
        $failed[] = $label;
    }
}

if ($failed !== []) {
    fwrite(STDERR, "Compact metadata contention retry contract FAILED:\n - " . implode("\n - ", $failed) . "\n");
    exit(1);
}

echo 'Compact metadata contention retry contract passed (' . count($checks) . " checks).\n";
