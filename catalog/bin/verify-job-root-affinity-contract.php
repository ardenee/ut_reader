<?php
declare(strict_types=1);

$catalogRoot = dirname(__DIR__);

$required = [
    'src/Domain/Jobs/ClaimedJob.php' => [
        'public readonly ?int $parentJobId = null',
        'public function rootJobId(): int',
    ],
    'src/Application/Jobs/JobQueue.php' => [
        '?int $preferredRootJobId = null',
        'Preferred root affinity only affects claim ordering',
    ],
    'src/Infrastructure/Persistence/PdoJobClaimer.php' => [
        'FOR UPDATE SKIP LOCKED',
        'PdoJobAdmissionGuard',
        '$preferred = $this->claimFromScope(',
        'return $this->claimFromScope($queue, $workerId, $leaseSeconds, null, $guard);',
        'COALESCE(j.available_at,j.created_at)<=UTC_TIMESTAMP()',
        'Root affinity is preference-only',
    ],
    'src/Application/Jobs/JobDeferred.php' => [
        '$retainWorkerAffinity',
    ],
    'src/Application/Jobs/JobWorker.php' => [
        'private ?int $preferredRootJobId = null',
        '$job->rootJobId()',
        '$this->retainAffinity($rootJobId)',
        '$this->releaseAffinity()',
    ],
];

$forbidden = [
    'src/Application/Jobs/JobQueue.php' => [
        'requirePreferredRoot',
    ],
    'src/Infrastructure/Persistence/PdoJobQueue.php' => [
        'requirePreferredRoot',
    ],
    'src/Infrastructure/Persistence/PdoJobClaimer.php' => [
        'requirePreferredRoot',
        'workflowOpen(',
        'workflowHasReadyWork(',
        '$blockedClasses',
        '$blockedKeys',
    ],
    'src/Application/Jobs/JobWorker.php' => [
        'requirePreferredRoot',
    ],
];

$failures = [];
foreach ($required as $relative => $needles) {
    $path = $catalogRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $source = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($source)) {
        $failures[] = $relative . ': missing/unreadable';
        continue;
    }
    foreach ($needles as $needle) {
        if (!str_contains($source, $needle)) {
            $failures[] = $relative . ': missing contract marker ' . var_export($needle, true);
        }
    }
}

foreach ($forbidden as $relative => $needles) {
    $path = $catalogRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $source = is_file($path) ? file_get_contents($path) : false;
    if (!is_string($source)) {
        $failures[] = $relative . ': missing/unreadable';
        continue;
    }
    foreach ($needles as $needle) {
        if (str_contains($source, $needle)) {
            $failures[] = $relative . ': forbidden legacy scheduler marker still present ' . var_export($needle, true);
        }
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Job root affinity contract FAILED:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "Job root affinity contract passed: affinity is preference-only and valid global work remains claimable.\n");
