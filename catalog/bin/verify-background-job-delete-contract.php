<?php
/**
 * Regression contract for safe deletion of queued/deferred Background Jobs rows.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$checks = [
    'bulk persistence' => [
        $root . '/src/Infrastructure/Persistence/PdoBackgroundJobBulkAction.php',
        [
            "'delete' => 'j.status IN (\"queued\",\"completed\",\"failed\",\"dead_letter\",\"cancelled\")'",
            'Cancelled automatically because the job was selected for deletion.',
            'Delete matching non-running jobs',
        ],
    ],
    'background jobs UI' => [
        $root . '/lib/CatalogNavigation.php',
        [
            'background-jobs-delete-nonrunning.js',
        ],
    ],
    'delete UI adapter' => [
        $root . '/assets/background-jobs-delete-nonrunning.js',
        [
            'Delete selected/matching non-running jobs',
            "option.value = 'delete'",
        ],
    ],
];

$failures = [];
foreach ($checks as $label => [$path, $needles]) {
    $source = @file_get_contents($path);
    if (!is_string($source)) {
        $failures[] = $label . ': could not read ' . $path;
        continue;
    }
    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) {
            $failures[] = $label . ': missing contract fragment: ' . $needle;
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Background-job delete contract FAILED\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Background-job delete contract passed.\n");
