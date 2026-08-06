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
$dependency = JobResourcePolicy::for(JobType::REBUILD_FILE_DEPENDENCIES, ['file_id' => 42, 'game_id' => 7]);
job_limit_expect($dependency->limit === 6, 'The saved limit resolver does not control newly queued dependency jobs.');
job_limit_expect(
    $dependency->concurrencyKey === 'dependency:file:42',
    'Exact-file dependency work is no longer protected by its file key.'
);
$affected = JobResourcePolicy::for(JobType::REBUILD_AFFECTED_DEPENDENCIES, ['file_id' => 42, 'game_id' => 7]);
job_limit_expect(
    $affected->concurrencyKey === 'dependency:affected-game:7',
    'Affected dependency refreshes for one game can still overlap and rewrite the same files concurrently.'
);

$archive = JobResourcePolicy::for(JobType::IMPORT_STAGED_PAK, ['game_id' => 7]);
job_limit_expect(
    $archive->resourceClass === JobResourcePolicy::ARCHIVE_IMPORT_HEAVY && $archive->limit === 1,
    'Full archive imports are not isolated from normal staged package imports.'
);
JobResourcePolicy::setLimitResolver(null);

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
        && str_contains($store, 'WHERE queue_name=? AND status="queued" AND resource_class=? AND resource_limit<>?')
        && str_contains($store, 'WHERE queue_name=? AND status IN ("queued","running") GROUP BY resource_class')
        && str_contains($store, '$changed = []')
        && str_contains($store, 'rekeyQueuedAffectedDependencyJobs')
        && str_contains($store, 'JobType::REBUILD_AFFECTED_DEPENDENCIES')
        && str_contains($store, 'JSON_EXTRACT(payload_json,"$.game_id")')
        && str_contains($store, 'dependency:affected-game:')
        && str_contains($store, "'rekeyed_jobs'")
        && str_contains($store, 'class_blocked'),
    'Saved limits do not restrict queue updates or rekey affected dependency work for safe per-game serialization.'
);
job_limit_expect(
    !str_contains($store, 'WHERE resource_class=? AND status IN ("queued","running")'),
    'The resource-limit save still performs an unscoped full-table update across running rows.'
);
job_limit_expect(
    str_contains($application, 'JobResourcePolicy::setLimitResolver')
        && str_contains($application, 'CatalogJobResourceLimitStore'),
    'Application boot does not apply saved limits to future jobs.'
);
job_limit_expect(
    str_contains($support, 'JobResourcePolicy::setLimitResolver')
        && str_contains($support, 'CatalogJobResourceLimitStore')
        && str_contains($support, 'catalog_db($config)'),
    'Legacy administrator pages that construct queues directly do not load the saved database limits.'
);
job_limit_expect(
    str_contains($page, 'Save limits and update queued jobs')
        && str_contains($page, 'new CatalogJobResourceLimitStore($db, $queueName)')
        && str_contains($page, 'header(\'Location: job-resource-limits.php\', true, 303)')
        && !str_contains($page, '->start($queueName')
        && str_contains($page, 'rekeyed_jobs')
        && str_contains($page, 'per-game serialization')
        && str_contains($page, 'class_blocked'),
    'The administrator POST does not return immediately after updating limits and queue safety keys.'
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
    'The original immutable migration does not create settings or initialize the existing active queue.'
);

echo "Job resource limits contract tests passed.\n";
