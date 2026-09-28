#!/usr/bin/env php
<?php
declare(strict_types=1);

use UnrealDb\Catalog\Domain\Jobs\JobResourcePolicy;
use UnrealDb\Catalog\Domain\Jobs\JobType;

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';
$claimer = (string)file_get_contents($root . '/src/Infrastructure/Persistence/PdoJobClaimer.php');
$fingerprint = (string)file_get_contents($root . '/src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php');
$checks = [];
$failures = [];
$check = static function (string $name, bool $ok, string $detail) use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) $failures[] = $name . ': ' . $detail;
};
$profile = JobResourcePolicy::for(JobType::FULL_SYNC_FILE, ['file_id' => 42]);
$check(
    'full_sync_units_default_to_four_slots',
    $profile->resourceClass === JobResourcePolicy::FULL_SYNC_UNIT && $profile->limit === 4,
    'Full Sync file units should retain the configured four-slot default.'
);
$check(
    'single_full_sync_uses_configured_limit',
    str_contains($claimer, '$configuredLimit = max(1, (int)($candidate[\'resource_limit\'] ?? 1));')
        && str_contains($claimer, '$parentLimit = $hasCompetingParent ? min(2, $configuredLimit) : $configuredLimit;'),
    'A lone Full Sync must be allowed to use its complete configured resource allowance.'
);
$check(
    'competing_full_syncs_share_pool',
    str_contains($claimer, 'parent.id<>?')
        && str_contains($claimer, 'parent.job_type="' . "' . JobType::FULL_SYNC_GAME . '" . '"')
        && str_contains($claimer, 'parent.status IN ("queued","running")'),
    'Another active Full Sync coordinator must restore the two-worker-per-parent fairness cap.'
);
$check(
    'worker_fingerprint_tracks_scheduler',
    str_contains($fingerprint, '/src/Infrastructure/Persistence/PdoJobClaimer.php'),
    'Changing Full Sync admission must force long-lived detached workers to reload.'
);
foreach (['src/Infrastructure/Persistence/PdoJobClaimer.php','src/Domain/Jobs/JobResourcePolicy.php'] as $relative) {
    $path = $root . '/' . $relative;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path), $output, $code);
    $check('syntax:' . basename($relative), $code === 0, implode(' ', $output));
    $output = [];
}
$result = ['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($failures === [] ? 0 : 2);
