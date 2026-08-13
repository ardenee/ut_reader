<?php
/**
 * Regression contract for browser upload policy and post-upload queue isolation.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [
    'profiled batch API' => [
        $root . '/api/v1/profiled-upload-batch.php',
        [
            'CatalogProgramSettingsStore',
            'allowed_extensions_json',
            "JsonResponse::error('invalid_extension'",
            'normal_upload_limit_bytes',
            'container_upload_limit_bytes',
        ],
        [],
    ],
    'profiled chunk API' => [
        $root . '/api/v1/profiled-upload-chunk.php',
        [
            'session_write_close();',
            'profiled_chunk_file_policy',
            'CatalogProgramSettingsStore',
        ],
        ['pruneStale'],
    ],
    'profiled browser client' => [
        $root . '/assets/profiled-upload-jobs.js',
        [
            'function clientPolicy(file)',
            'unsupported/oversized file(s) skipped',
            'Starting continuous upload staging.',
        ],
        [],
    ],
    'bucket chunk API' => [
        $root . '/api/v1/upload-bucket-chunk.php',
        ['session_write_close();'],
        [],
    ],
    'bucket batch API' => [
        $root . '/api/v1/upload-bucket-batch.php',
        ['session_write_close();'],
        [],
    ],
    'bucket browser client' => [
        $root . '/assets/upload-bucket-v2-coordinator.js',
        [
            'Phase 1: inspect/hash/duplicate-check every selected file before the',
            'Phase 2: transfer only READY files.',
            'Phase 3: only now touch the background queue.',
            'const FINALIZE_BATCH_SIZE = 100;',
        ],
        ['async function finalizeOne('],
    ],
    'program settings' => [
        $root . '/src/Infrastructure/Settings/CatalogProgramSettingsStore.php',
        ['normal_upload_limit_bytes', 'container_upload_limit_bytes'],
        [],
    ],
];

$failures = [];
foreach ($checks as $label => [$path, $required, $forbidden]) {
    $source = @file_get_contents($path);
    if (!is_string($source)) {
        $failures[] = $label . ': could not read ' . $path;
        continue;
    }
    foreach ($required as $needle) {
        if (!str_contains($source, $needle)) {
            $failures[] = $label . ': missing contract fragment: ' . $needle;
        }
    }
    foreach ($forbidden as $needle) {
        if (str_contains($source, $needle)) {
            $failures[] = $label . ': forbidden hot-path fragment present: ' . $needle;
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Upload ingress policy contract FAILED\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Upload ingress policy contract passed.\n");
