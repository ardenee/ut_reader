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
        'bool $requirePreferredRoot = false',
    ],
    'src/Infrastructure/Persistence/PdoJobClaimer.php' => [
        'one very short queue-level mutex',
        '$candidate = $this->lockNextValidCandidate($queue, $preferredRootJobId);',
        '$candidate = $this->lockNextValidCandidate($queue, null);',
        'status="queued"',
        'available_at<=?',
        'status="running"',
        'GREATEST(1,j.resource_limit)',
        'queue-claim:',
    ],
    'src/Application/Jobs/JobDeferred.php' => [
        '$retainWorkerAffinity',
        '$children[\'failed\']',
        '$children[\'dead_letter\']',
        '$children[\'cancelled\']',
    ],
    'src/Application/Jobs/JobWorker.php' => [
        'private ?int $preferredRootJobId = null',
        '$job->rootJobId()',
        '$this->retainAffinity($rootJobId)',
        '$this->releaseAffinity()',
    ],
];

$forbidden = [
    'src/Infrastructure/Persistence/PdoJobClaimer.php' => [
        'workflowOpen(',
        'workflowHasReadyWork(',
        "coordinationLockName('resource'",
        "coordinationLockName('key'",
        '$blockedClasses',
        '$blockedKeys',
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
