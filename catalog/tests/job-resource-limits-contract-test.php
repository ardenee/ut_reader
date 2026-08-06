<?php
declare(strict_types=1);

function job_limit_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

require_once __DIR__ . '/../src/Domain/Jobs/JobType.php';
require_once __DIR__ . '/../src/Domain/Jobs/JobResourceProfile.php';
require_once __DIR__ . '/../src/Domain/Jobs/JobResourcePolicy.php';

use UnrealDb\Catalog\Domain\Jobs\JobResourcePolicy;
use UnrealDb\Catalog\Domain\Jobs\JobType;

JobResourcePolicy::setLimitResolver(
    static fn(string $resourceClass, int $fallback): int => $resourceClass === JobResourcePolicy::DEPENDENCY_HEAVY
        ? 6
        : $fallback
);
$dependency = JobResourcePolicy::for(JobType::REBUILD_FILE_DEPENDENCIES, ['file_id' => 42]);
job_limit_expect($dependency->limit === 6, 'The saved limit resolver does not control newly queued dependency jobs.');
JobResourcePolicy::setLimitResolver(null);

$limitFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-job-limits-' . bin2hex(random_bytes(5)) . '.json';
file_put_contents($limitFile, json_encode(['version' => 1, 'limits' => ['dependency-heavy' => 9]], JSON_THROW_ON_ERROR));
JobResourcePolicy::setLimitFile($limitFile);
$fileDependency = JobResourcePolicy::for(JobType::REBUILD_FILE_DEPENDENCIES, ['file_id' => 43]);
job_limit_expect($fileDependency->limit === 9, 'Direct queue creation does not use the saved limit projection.');
JobResourcePolicy::setLimitFile(null);
@unlink($limitFile);

$archive = JobResourcePolicy::for(JobType::IMPORT_STAGED_PAK, ['game_id' => 7]);
job_limit_expect(
    $archive->resourceClass === JobResourcePolicy::ARCHIVE_IMPORT_HEAVY && $archive->limit === 1,
    'Full archive imports are not isolated from normal staged package imports.'
);

$store = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogJobResourceLimitStore.php');
$application = file_get_contents(__DIR__ . '/../src/Presentation/Http/CatalogApplication.php');
$support = file_get_contents(__DIR__ . '/../lib/CatalogSupport.php');
$page = file_get_contents(__DIR__ . '/../job-resource-limits.php');
$navigation = file_get_contents(__DIR__ . '/../lib/CatalogNavigation.php');
$migration = file_get_contents(__DIR__ . '/../migrations/202608060001_job_resource_limits.php');

foreach (compact('store', 'application', 'support', 'page', 'navigation', 'migration') as $name => $source) {
    job_limit_expect(is_string($source) && $source !== '', $name . ' source is missing.');
}

job_limit_expect(
    str_contains($store, 'ue_job_resource_limits')
        && str_contains($store, 'status IN ("queued","running")')
        && str_contains($store, 'resource_limit=?')
        && str_contains($store, 'resource-limits.json') === false
        && str_contains($store, 'writeSettingsFile')
        && str_contains($store, 'class_blocked'),
    'Saved limits do not update the current queue, publish the enqueue projection or expose limiting pressure.'
);
job_limit_expect(
    str_contains($application, 'JobResourcePolicy::setLimitResolver')
        && str_contains($application, 'JobResourcePolicy::setLimitFile')
        && str_contains($application, 'CatalogJobResourceLimitStore'),
    'Application boot does not apply saved limits to future jobs.'
);
job_limit_expect(
    str_contains($support, 'JobResourcePolicy::setLimitFile')
        && str_contains($support, 'resource-limits.json'),
    'Legacy administrator pages that construct queues directly do not load the saved limits.'
);
job_limit_expect(
    str_contains($page, 'Save limits and update current jobs')
        && str_contains($page, 'CatalogDetachedWorker')
        && str_contains($page, 'resource-limits.json')
        && str_contains($page, 'class_blocked'),
    'The administrator page cannot save limits, update current work and refill the worker pool.'
);
job_limit_expect(
    str_contains($navigation, "'Job Resource Limits' => \$root . 'job-resource-limits.php'"),
    'Job Resource Limits is missing from administrator navigation.'
);
job_limit_expect(
    str_contains($migration, 'CREATE TABLE ue_job_resource_limits')
        && str_contains($migration, 'archive-import-heavy')
        && str_contains($migration, 'JOIN ue_job_resource_limits')
        && str_contains($migration, 'status IN ("queued","running")'),
    'The migration does not create settings or update the existing active queue.'
);

echo "Job resource limits contract tests passed.\n";
