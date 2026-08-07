<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies source identity job integration behavior as an automated regression/contract test.
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

function source_identity_integration_expect(bool $condition, string $message): void
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

$queueName = 'source_identity_job_' . bin2hex(random_bytes(5));
$fileIds = [];
$sourceId = 0;

try {
    $gameId = (int)($db->query(
        'SELECT g.id FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id '
        . 'WHERE UPPER(p.engine_key)="UE4" ORDER BY g.id LIMIT 1'
    )->fetchColumn() ?: 0);
    source_identity_integration_expect($gameId > 0, 'The canonical test database has no UE4 game/profile assignment.');

    $suffix = bin2hex(random_bytes(6));
    $filename = 'Fixture_' . $suffix . '.uasset';
    $oldPackage = '/Game/Old/Fixture_' . $suffix;
    $canonicalPackage = '/Game/Canonical/Fixture_' . $suffix;
    $aliasPackage = '/FixturePlugin/Alias/Fixture_' . $suffix;
    $primarySourcePath = 'Project/Content/Canonical/' . $filename;
    $aliasSourcePath = 'Project/Plugins/FixturePlugin/Content/Alias/' . $filename;

    $insertSource = $db->prepare(
        'INSERT INTO ue_sources(game_id,name,source_type,base_path) VALUES(?,? ,"local_path",?)'
    );
    $insertSource->execute([$gameId, 'Source identity fixture ' . $suffix, '/fixtures/' . $suffix]);
    $sourceId = (int)$db->lastInsertId();

    $insertFile = $db->prepare(
        'INSERT INTO ue_files(game_id,package_name,original_name,source_relative_path,stored_name,relative_path,extension,'
        . 'detected_engine_key,file_size,md5,sha1,scan_status) VALUES(?,?,?,?,?,?,?,?,?,?,?,"verified")'
    );
    $insertFile->execute([
        $gameId,
        $oldPackage,
        'Wrong_' . $filename,
        $primarySourcePath,
        $suffix . '_target.uasset',
        'storage/tests/' . $suffix . '_target.uasset',
        'uasset',
        'UE4',
        1,
        md5('source-identity-target-' . $suffix),
        sha1('source-identity-target-' . $suffix),
    ]);
    $targetFileId = (int)$db->lastInsertId();
    $fileIds[] = $targetFileId;

    $insertFile->execute([
        $gameId,
        '/Game/Dependent/Dependent_' . $suffix,
        'Dependent_' . $suffix . '.uasset',
        'Project/Content/Dependent/Dependent_' . $suffix . '.uasset',
        $suffix . '_dependent.uasset',
        'storage/tests/' . $suffix . '_dependent.uasset',
        'uasset',
        'UE4',
        1,
        md5('source-identity-dependent-' . $suffix),
        sha1('source-identity-dependent-' . $suffix),
    ]);
    $dependentFileId = (int)$db->lastInsertId();
    $fileIds[] = $dependentFileId;

    $db->prepare(
        'INSERT INTO ue_file_locations(file_id,source_id,source_relative_path,exists_in_source,last_seen_at) '
        . 'VALUES(?,?,?,1,NOW())'
    )->execute([$targetFileId, $sourceId, $aliasSourcePath]);

    $db->prepare(
        'INSERT INTO ue_exports(file_id,export_index,class_name,object_name,outer_index,local_path,full_path) '
        . 'VALUES(?,0,"Class","FixtureObject",0,"FixtureObject",?)'
    )->execute([$targetFileId, $oldPackage . '.FixtureObject']);

    $insertImport = $db->prepare(
        'INSERT INTO ue_imports(file_id,import_index,class_package,class_name,object_name,outer_index,full_path,root_package,relative_object_path,is_common) '
        . 'VALUES(?,0,"Core","Class",?,0,?,?,?,?)'
    );
    $insertImport->execute([
        $targetFileId,
        'Object',
        '/Script/CoreUObject.Object',
        '/Script/CoreUObject',
        'Object',
        1,
    ]);
    $targetImportId = (int)$db->lastInsertId();

    $insertImport->execute([
        $dependentFileId,
        'FixtureObject',
        $oldPackage . '.FixtureObject',
        $oldPackage,
        'FixtureObject',
        0,
    ]);
    $dependentImportId = (int)$db->lastInsertId();

    $insertDependency = $db->prepare(
        'INSERT INTO ue_dependencies(file_id,import_id,required_package,required_object_path,status,resolution_source,resolution_confidence) '
        . 'VALUES(?,?,?, ?,"missing",?,?)'
    );
    $insertDependency->execute([
        $targetFileId,
        $targetImportId,
        '/Script/CoreUObject',
        '/Script/CoreUObject.Object',
        'fixture_before',
        'before',
    ]);
    $insertDependency->execute([
        $dependentFileId,
        $dependentImportId,
        $oldPackage,
        $oldPackage . '.FixtureObject',
        'fixture_before',
        'before',
    ]);

    $queue = new PdoJobQueue($db);
    $jobId = $queue->enqueue(
        $queueName,
        JobType::REPAIR_SOURCE_IDENTITY_FILE,
        ['file_id' => $targetFileId],
        10,
        null,
        'source-identity-file:' . $targetFileId,
        null,
        1
    );
    $claimed = $queue->claim($queueName, 'source-identity-test-worker', 60);
    source_identity_integration_expect($claimed !== null && $claimed->id === $jobId, 'The source identity repair job was not claimed.');
    source_identity_integration_expect($claimed->type === JobType::REPAIR_SOURCE_IDENTITY_FILE, 'The source identity job type changed.');
    source_identity_integration_expect($claimed->resourceClass === 'dependency-heavy', 'Source identity repair did not use the exclusive heavy class.');
    source_identity_integration_expect($claimed->concurrencyKey === 'source-identity:file:' . $targetFileId, 'Source identity repair lost its target concurrency key.');

    $context = new JobExecutionContext($queue, $claimed, 60);
    $result = (new CatalogMaintenanceJobHandler($db, ['common_packages' => []]))->handle($claimed, $context);
    source_identity_integration_expect(!empty($result['changed']), 'The worker did not report the canonical identity change.');
    source_identity_integration_expect((string)$result['old_package_name'] === $oldPackage, 'The worker returned the wrong previous package name.');
    source_identity_integration_expect((string)$result['new_package_name'] === $canonicalPackage, 'The worker returned the wrong canonical package name.');
    source_identity_integration_expect((int)$result['alias_count'] === 1, 'The mounted plugin alias was not retained.');
    source_identity_integration_expect((int)$result['dependency_files_refreshed'] === 2, 'The target and referring dependency rows were not both refreshed.');
    source_identity_integration_expect($queue->complete($claimed, $result) === 'completed', 'The source identity job did not complete.');

    $target = $db->query(
        'SELECT package_name,original_name,source_relative_path FROM ue_files WHERE id=' . $targetFileId
    )->fetch();
    source_identity_integration_expect(is_array($target), 'The repaired file disappeared.');
    source_identity_integration_expect((string)$target['package_name'] === $canonicalPackage, 'The canonical package name was not persisted.');
    source_identity_integration_expect((string)$target['original_name'] === $filename, 'The source filename was not promoted to the primary original name.');
    source_identity_integration_expect((string)$target['source_relative_path'] === $primarySourcePath, 'The canonical source-relative path changed unexpectedly.');

    $exportPath = (string)$db->query('SELECT full_path FROM ue_exports WHERE file_id=' . $targetFileId)->fetchColumn();
    source_identity_integration_expect($exportPath === $canonicalPackage . '.FixtureObject', 'Export full paths were not rewritten to the canonical package identity.');

    $alias = $db->query(
        'SELECT package_name,original_name FROM ue_file_package_aliases WHERE file_id=' . $targetFileId
    )->fetch();
    source_identity_integration_expect(is_array($alias), 'The source-derived alias row was not created.');
    source_identity_integration_expect((string)$alias['package_name'] === $aliasPackage, 'The mounted plugin alias package name is incorrect.');
    source_identity_integration_expect((string)$alias['original_name'] === $filename, 'The alias original filename is incorrect.');

    $targetDependency = $db->query(
        'SELECT status,resolution_source FROM ue_dependencies WHERE file_id=' . $targetFileId
    )->fetch();
    source_identity_integration_expect(is_array($targetDependency), 'The repaired file dependency row was not recreated.');
    source_identity_integration_expect((string)$targetDependency['status'] === 'common', 'The repaired file dependency did not use common-script resolution.');

    $dependentDependency = $db->query(
        'SELECT status,resolution_source FROM ue_dependencies WHERE file_id=' . $dependentFileId
    )->fetch();
    source_identity_integration_expect(is_array($dependentDependency), 'The referring dependency row was not recreated.');
    source_identity_integration_expect((string)$dependentDependency['resolution_source'] !== 'fixture_before', 'The referring file retained stale dependency metadata.');

    $storedJob = $db->query('SELECT status,result_json FROM ue_background_jobs WHERE id=' . $jobId)->fetch();
    source_identity_integration_expect(is_array($storedJob) && (string)$storedJob['status'] === 'completed', 'The completed source identity job was not persisted.');
    $storedResult = json_decode((string)$storedJob['result_json'], true);
    source_identity_integration_expect(is_array($storedResult) && (string)($storedResult['new_package_name'] ?? '') === $canonicalPackage, 'The durable result is missing the canonical package identity.');

    fwrite(STDOUT, "Source identity repair job integration tests passed.\n");
} finally {
    $db->prepare('DELETE FROM ue_background_jobs WHERE queue_name=?')->execute([$queueName]);
    if ($fileIds !== []) {
        $placeholders = implode(',', array_fill(0, count($fileIds), '?'));
        $db->prepare('DELETE FROM ue_files WHERE id IN (' . $placeholders . ')')->execute($fileIds);
    }
    if ($sourceId > 0) {
        $db->prepare('DELETE FROM ue_sources WHERE id=?')->execute([$sourceId]);
    }
}
