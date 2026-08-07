<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies dependency refresh job integration behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\CatalogMaintenanceJobHandler;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;

require_once __DIR__ . '/../bootstrap/autoload.php';

function dependency_job_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$dsn = (string)(getenv('UNREALDB_TEST_DSN') ?: 'mysql:host=127.0.0.1;port=3306;dbname=unrealdb_test;charset=utf8mb4');
$user = (string)(getenv('UNREALDB_TEST_DB_USER') ?: 'root');
$password = (string)(getenv('UNREALDB_TEST_DB_PASSWORD') ?: 'root');
$db = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$queueName = 'dependency_job_' . bin2hex(random_bytes(5));
$fileIds = [];

try {
    $gameId = (int)$db->query('SELECT id FROM ue_games ORDER BY id LIMIT 1')->fetchColumn();
    dependency_job_expect($gameId > 0, 'The canonical test game is missing.');

    $suffix = bin2hex(random_bytes(6));
    $insertFile = $db->prepare(
        'INSERT INTO ue_files(game_id,package_name,original_name,stored_name,relative_path,extension,file_size,md5,sha1,scan_status) '
        . 'VALUES(?,?,?,?,?,?,?,?,?,"verified")'
    );
    $insertFile->execute([
        $gameId,
        'ExactRefresh_' . $suffix,
        'ExactRefresh_' . $suffix . '.u',
        $suffix . '_exact.u',
        'storage/tests/' . $suffix . '_exact.u',
        'u',
        1,
        md5('exact-' . $suffix),
        sha1('exact-' . $suffix),
    ]);
    $exactFileId = (int)$db->lastInsertId();
    $fileIds[] = $exactFileId;

    $insertFile->execute([
        $gameId,
        'Dependent_' . $suffix,
        'Dependent_' . $suffix . '.u',
        $suffix . '_dependent.u',
        'storage/tests/' . $suffix . '_dependent.u',
        'u',
        1,
        md5('dependent-' . $suffix),
        sha1('dependent-' . $suffix),
    ]);
    $dependentFileId = (int)$db->lastInsertId();
    $fileIds[] = $dependentFileId;

    $insertImport = $db->prepare(
        'INSERT INTO ue_imports(file_id,import_index,class_package,class_name,object_name,outer_index,full_path,root_package,relative_object_path,is_common) '
        . 'VALUES(?,0,"Core","Class",?,0,?,?,?,0)'
    );
    $insertImport->execute([
        $exactFileId,
        'MissingObject',
        'MissingPackage_' . $suffix . '.MissingObject',
        'MissingPackage_' . $suffix,
        'MissingObject',
    ]);
    $exactImportId = (int)$db->lastInsertId();

    $exactPackageName = 'ExactRefresh_' . $suffix;
    $insertImport->execute([
        $dependentFileId,
        'ReferencedObject',
        $exactPackageName . '.ReferencedObject',
        $exactPackageName,
        'ReferencedObject',
    ]);
    $dependentImportId = (int)$db->lastInsertId();

    $insertDependency = $db->prepare(
        'INSERT INTO ue_dependencies(file_id,import_id,required_package,required_object_path,status,resolution_source,resolution_confidence) '
        . 'VALUES(?,?,?, ?,"missing",?,?)'
    );
    $insertDependency->execute([
        $exactFileId,
        $exactImportId,
        'OldPackage',
        'OldPackage.OldObject',
        'before_exact_refresh',
        'before',
    ]);
    $insertDependency->execute([
        $dependentFileId,
        $dependentImportId,
        $exactPackageName,
        $exactPackageName . '.ReferencedObject',
        'fixture_unchanged',
        'fixture',
    ]);

    $queue = new PdoJobQueue($db);
    $jobId = $queue->enqueue(
        $queueName,
        JobType::REBUILD_FILE_DEPENDENCIES,
        ['file_id' => $exactFileId],
        20,
        null,
        'exact-file:' . $exactFileId,
        null,
        1
    );
    $claimed = $queue->claim($queueName, 'dependency-job-test-worker', 60);
    dependency_job_expect($claimed !== null && $claimed->id === $jobId, 'The exact dependency refresh job was not claimed.');
    dependency_job_expect($claimed->type === JobType::REBUILD_FILE_DEPENDENCIES, 'The exact dependency job type changed.');
    dependency_job_expect($claimed->concurrencyKey === 'dependency:file:' . $exactFileId, 'The exact dependency target key was not assigned.');

    $context = new JobExecutionContext($queue, $claimed, 60);
    $result = (new CatalogMaintenanceJobHandler($db, []))->handle($claimed, $context);
    dependency_job_expect((int)($result['file_id'] ?? 0) === $exactFileId, 'The handler returned the wrong file identity.');
    dependency_job_expect((int)($result['stats']['total'] ?? 0) === 1, 'The handler did not report the rebuilt dependency count.');
    dependency_job_expect((int)($result['stats']['missing'] ?? 0) === 1, 'The rebuilt dependency did not resolve to the expected missing state.');
    dependency_job_expect($queue->complete($claimed, $result) === 'completed', 'The exact dependency job did not complete.');

    $exactDependency = $db->query('SELECT status,resolution_source,resolution_confidence FROM ue_dependencies WHERE file_id=' . $exactFileId)->fetch();
    dependency_job_expect(is_array($exactDependency), 'The exact file dependency row was not recreated.');
    dependency_job_expect((string)$exactDependency['status'] === 'missing', 'The exact file dependency status was not rebuilt.');
    dependency_job_expect((string)$exactDependency['resolution_source'] === 'none', 'The exact file dependency retained stale resolution metadata.');

    $dependentDependency = $db->query('SELECT resolution_source FROM ue_dependencies WHERE file_id=' . $dependentFileId)->fetch();
    dependency_job_expect(is_array($dependentDependency), 'The dependent fixture row disappeared.');
    dependency_job_expect((string)$dependentDependency['resolution_source'] === 'fixture_unchanged', 'The exact file job incorrectly rebuilt dependant files.');

    $storedJob = $db->query('SELECT status,result_json FROM ue_background_jobs WHERE id=' . $jobId)->fetch();
    dependency_job_expect(is_array($storedJob) && (string)$storedJob['status'] === 'completed', 'The completed job state was not persisted.');
    $storedResult = json_decode((string)$storedJob['result_json'], true);
    dependency_job_expect(is_array($storedResult) && (int)($storedResult['file_id'] ?? 0) === $exactFileId, 'The durable job result is missing the file identity.');

    fwrite(STDOUT, "Dependency refresh job integration tests passed.\n");
} finally {
    $db->prepare('DELETE FROM ue_background_jobs WHERE queue_name=?')->execute([$queueName]);
    if ($fileIds !== []) {
        $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
        $db->prepare('DELETE FROM ue_files WHERE id IN (' . $placeholders . ')')->execute($fileIds);
    }
}
