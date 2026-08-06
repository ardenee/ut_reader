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

$archive = JobResourcePolicy::for(JobType::IMPORT_STAGED_PAK, ['game_id' => 7]);
job_limit_expect(
    $archive->resourceClass === JobResourcePolicy::ARCHIVE_IMPORT_HEAVY && $archive->limit === 1,
    'Full archive imports are not isolated from normal staged package imports.'
);
JobResourcePolicy::setLimitResolver(null);

$store = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogJobResourceLimitStore.php');
$application = file_get_contents(__DIR__ . '/../src/Presentation/Http/CatalogApplication.php');
$page = file_get_contents(__DIR__ . '/../job-resource-limits.php');
$navigation = file_get_contents(__DIR__ . '/../lib/CatalogNavigation.php');
$migration = file_get_contents(__DIR__ . '/../migrations/202608060001_job_resource_limits.php');

foreach (compact('store', 'application', 'page', 'navigation', 'migration') as $name => $source) {
    job_limit_expect(is_string($source) && $source !== '', $name . ' source is missing.');
}

job_limit_expect(
    str_contains($store, 'ue_job_resource_limits')
        && str_contains($store, 'status IN ("queued","running")')
        && str_contains($store, 'resource_limit=?')
        && str_contains($store, 'class_blocked'),
    'Saved limits do not update the current durable queue or expose limiting pressure.'
);
job_limit_expect(
    str_contains($application, 'JobResourcePolicy::setLimitResolver')
        && str_contains($application, 'CatalogJobResourceLimitStore'),
    'Application boot does not apply saved limits to future jobs.'
);
job_limit_expect(
    str_contains($page, 'Save limits and update current jobs')
        && str_contains($page, 'CatalogDetachedWorker')
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
