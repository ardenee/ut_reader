<?php
declare(strict_types=1);

$catalogRoot = dirname(__DIR__);

$required = [
    'src/Domain/Jobs/ClaimedJob.php' => [
        'public readonly ?int $parentJobId = null',
        'public readonly ?int $resolvedRootJobId = null',
        'public function rootJobId(): int',
        '$this->resolvedRootJobId',
    ],
    'src/Application/Jobs/JobQueue.php' => [
        '?int $preferredRootJobId = null',
        'preferred root is strict worker affinity',
        'unrelated roots are not eligible for this worker',
    ],
    'src/Infrastructure/Persistence/PdoJobClaimer.php' => [
        'FOR UPDATE SKIP LOCKED',
        'PdoJobAdmissionGuard',
        'WITH RECURSIVE root_scope AS',
        'EXISTS (SELECT 1 FROM root_scope scope WHERE scope.id=j.id)',
        'j.parent_job_id IS NULL',
        'JobType::PROFILED_UPLOAD_BATCH',
        'execution_parent.id=j.parent_job_id',
        'execution_parent.job_type=',
        'workflowOpen(',
        'ensureRootAffinity(',
        'SELECT GET_LOCK(?,0)',
        'SELECT RELEASE_LOCK(?)',
        'Strict root affinity',
        'COALESCE(j.available_at,j.created_at)<=UTC_TIMESTAMP()',
    ],
    'src/Infrastructure/Jobs/CatalogProfiledUploadBatchJobHandler.php' => [
        '$context->defer(1, $progress, false);',
        'independent source/file execution roots',
    ],
    'src/Application/Jobs/JobDeferred.php' => [
        '$retainWorkerAffinity',
    ],
    'src/Application/Jobs/JobWorker.php' => [
        'private ?int $preferredRootJobId = null',
        '$job->rootJobId()',
        '$this->retainAffinity($rootJobId)',
        '$failureStatus === \'retry_queued\'',
    ],
    'src/Infrastructure/Jobs/CatalogWorkerCodeVersion.php' => [
        '/src/Domain/Jobs/ClaimedJob.php',
        '/src/Infrastructure/Persistence/PdoJobClaimer.php',
    ],
];

$forbidden = [
    'src/Application/Jobs/JobQueue.php' => [
        'Preferred root affinity only affects claim ordering',
    ],
    'src/Infrastructure/Persistence/PdoJobClaimer.php' => [
        'Root affinity is preference-only',
        '(j.id=? OR j.parent_job_id=?)',
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

fwrite(STDOUT, "Job root affinity contract passed: each worker owns one source execution root and drains its full descendant workflow before moving on.\n");
