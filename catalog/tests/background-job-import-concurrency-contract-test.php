<?php
declare(strict_types=1);

function import_concurrency_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

putenv('UNREALDB_JOB_RESOURCE_LIMIT_IMPORT_HEAVY');

require_once __DIR__ . '/../src/Domain/Jobs/JobResourceProfile.php';
require_once __DIR__ . '/../src/Domain/Jobs/JobType.php';
require_once __DIR__ . '/../src/Domain/Jobs/JobResourcePolicy.php';

use UnrealDb\Catalog\Domain\Jobs\JobResourcePolicy;
use UnrealDb\Catalog\Domain\Jobs\JobType;

$first = JobResourcePolicy::for(JobType::IMPORT_STAGED_PACKAGE, [
    'game_id' => 5,
    'staged_path' => 'incoming/first-file.u',
    'original_name' => 'FirstFile.u',
]);
$second = JobResourcePolicy::for(JobType::IMPORT_STAGED_PACKAGE, [
    'game_id' => 5,
    'staged_path' => 'incoming/second-file.u',
    'original_name' => 'SecondFile.u',
]);
$duplicate = JobResourcePolicy::for(JobType::IMPORT_STAGED_PACKAGE, [
    'game_id' => 5,
    'staged_path' => 'incoming/first-file.u',
    'original_name' => 'FirstFile.u',
]);

import_concurrency_expect($first->resourceClass === JobResourcePolicy::IMPORT_HEAVY, 'Staged package imports use the wrong resource class.');
import_concurrency_expect($first->limit === 8, 'Staged package imports are not allowed to use eight workers.');
import_concurrency_expect(str_starts_with((string)$first->concurrencyKey, 'import:file:'), 'Staged package imports do not use per-file concurrency keys.');
import_concurrency_expect($first->concurrencyKey !== $second->concurrencyKey, 'Different staged packages share a concurrency key.');
import_concurrency_expect($first->concurrencyKey === $duplicate->concurrencyKey, 'The same staged package does not receive a stable concurrency key.');

$pak = JobResourcePolicy::for(JobType::IMPORT_STAGED_PAK, ['game_id' => 5, 'staged_path' => 'incoming/archive.pak']);
$backup = JobResourcePolicy::for(JobType::IMPORT_GAME_BACKUP, ['game_id' => 5]);
import_concurrency_expect($pak->limit === 1 && $pak->concurrencyKey === 'import:game:5', 'PAK imports must remain serialized per game.');
import_concurrency_expect($backup->limit === 1 && $backup->concurrencyKey === 'import:game:5', 'Game backup imports must remain serialized per game.');

$detachedWorker = file_get_contents(__DIR__ . '/../src/Infrastructure/Jobs/CatalogDetachedWorker.php');
$migration = file_get_contents(__DIR__ . '/../migrations/202608050001_parallel_staged_package_imports.php');
import_concurrency_expect(is_string($detachedWorker) && $detachedWorker !== '', 'Detached worker source is unavailable.');
import_concurrency_expect(is_string($migration) && $migration !== '', 'Parallel import migration source is unavailable.');
import_concurrency_expect(
    str_contains($detachedWorker, "(int)(\$before['desired_count'] ?? \$this->configuredWorkerCount())"),
    'Implicit queue starts do not preserve the selected worker-pool size.'
);
import_concurrency_expect(
    str_contains($migration, "'version' => '202608050001'")
        && str_contains($migration, 'resource_limit=8')
        && str_contains($migration, 'job_type="catalog.import_staged_package"')
        && str_contains($migration, 'CONCAT("import:file:"'),
    'The migration does not backfill queued staged package imports for per-file concurrency.'
);

echo "Background job import-concurrency contract tests passed.\n";
