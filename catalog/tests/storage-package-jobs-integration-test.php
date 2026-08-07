<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies storage package jobs integration behavior as an automated regression/contract test.
 * Why: It exists to catch regressions in this behavior without exposing a production route.
 * Role: Test-only verification code; not part of normal web, API, or worker execution.
 * Audit: Retain while the covered behavior exists; remove or rewrite only with the corresponding production behavior.
 */
declare(strict_types=1);

use UnrealDb\Catalog\Application\Jobs\JobExecutionContext;
use UnrealDb\Catalog\Domain\Jobs\JobType;
use UnrealDb\Catalog\Infrastructure\Jobs\GeneratedPackageJobHandler;
use UnrealDb\Catalog\Infrastructure\Jobs\UnverifiedDuplicateCleanupJobHandler;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoJobQueue;
use UnrealDb\Catalog\Infrastructure\Storage\GeneratedPackageStore;

require_once __DIR__ . '/../lib/CatalogSupportCore.php';
require_once __DIR__ . '/../bootstrap/autoload.php';

function storage_job_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function storage_job_remove_tree(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child) && !is_link($child)) {
            storage_job_remove_tree($child);
        } else {
            @unlink($child);
        }
    }
    @rmdir($path);
}

$dsn = (string)(getenv('UNREALDB_TEST_DSN') ?: '');
$user = (string)(getenv('UNREALDB_TEST_DB_USER') ?: '');
$password = (string)(getenv('UNREALDB_TEST_DB_PASSWORD') ?: '');
if ($dsn === '') {
    throw new RuntimeException('UNREALDB_TEST_DSN is required.');
}
if (!class_exists('ZipArchive')) {
    throw new RuntimeException('ZipArchive is required for package-job integration testing.');
}

$db = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$queueName = 'storage_package_' . bin2hex(random_bytes(5));
$storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-storage-package-' . bin2hex(random_bytes(6));
$bucket = $storage . DIRECTORY_SEPARATOR . 'upload-bucket';
$filesDirectory = $storage . DIRECTORY_SEPARATOR . 'files';
mkdir($bucket, 0775, true);
mkdir($filesDirectory, 0775, true);
$config = [
    'storage_path' => $storage,
    'common_packages' => [],
    'allowed_extensions' => ['uasset'],
    'readers' => [],
    'queue' => ['name' => $queueName, 'lease_seconds' => 60],
];
$fileId = 0;
$artifactPath = null;

try {
    $content = str_repeat('duplicate-job-fixture', 64);
    foreach (['Duplicate-A.uasset', 'Duplicate-B.uasset', 'Duplicate-C.uasset'] as $index => $name) {
        $path = $bucket . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $content);
        file_put_contents($path . '.txt', 'Duplicate cleanup integration fixture.');
        touch($path, time() - (30 - $index));
    }

    $queue = new PdoJobQueue($db);
    $duplicateJobId = $queue->enqueue(
        $queueName,
        JobType::CLEAN_UNVERIFIED_DUPLICATES,
        [],
        10,
        null,
        'duplicate-cleanup-fixture',
        null,
        1
    );
    $duplicateJob = $queue->claim($queueName, 'storage-cleanup-worker', 60);
    storage_job_expect($duplicateJob !== null && $duplicateJob->id === $duplicateJobId, 'Duplicate cleanup job was not claimed.');
    storage_job_expect($duplicateJob->resourceClass === 'storage-heavy', 'Duplicate cleanup job lost its storage-heavy class.');
    $duplicateResult = (new UnverifiedDuplicateCleanupJobHandler($db, $config))->handle(
        $duplicateJob,
        new JobExecutionContext($queue, $duplicateJob, 60)
    );
    storage_job_expect((int)$duplicateResult['duplicate_groups'] === 1, 'Duplicate cleanup did not find the exact group.');
    storage_job_expect((int)$duplicateResult['deleted_files'] === 2, 'Duplicate cleanup did not delete exactly two copies.');
    storage_job_expect($queue->complete($duplicateJob, $duplicateResult) === 'completed', 'Duplicate cleanup job did not complete.');

    $remaining = array_values(array_filter(scandir($bucket) ?: [], static function (string $name): bool {
        return $name !== '.' && $name !== '..' && !str_ends_with(strtolower($name), '.txt');
    }));
    storage_job_expect(count($remaining) === 1, 'Duplicate cleanup did not leave exactly one physical package copy.');

    $gameId = (int)($db->query(
        'SELECT g.id FROM ue_games g JOIN ue_game_profiles p ON p.id=g.profile_id '
        . 'WHERE UPPER(p.engine_key)="UE4" ORDER BY g.id LIMIT 1'
    )->fetchColumn() ?: 0);
    storage_job_expect($gameId > 0, 'A seeded UE4 game is required for package generation testing.');

    $suffix = bin2hex(random_bytes(6));
    $originalName = 'PackageJob_' . $suffix . '.uasset';
    $storedName = $suffix . '.uasset';
    $physicalPath = $filesDirectory . DIRECTORY_SEPARATOR . $storedName;
    $packageBytes = str_repeat('generated-package-job-fixture-' . $suffix, 32);
    file_put_contents($physicalPath, $packageBytes);

    $insert = $db->prepare(
        'INSERT INTO ue_files(game_id,package_name,original_name,stored_name,relative_path,extension,detected_engine_key,'
        . 'file_size,md5,sha1,package_guid,scan_status) VALUES(?,?,?,?,?,?,?,?,?,?,?,"verified")'
    );
    $insert->execute([
        $gameId,
        '/Game/PackageJobs/PackageJob_' . $suffix,
        $originalName,
        $storedName,
        'storage/files/' . $storedName,
        'uasset',
        'UE4',
        strlen($packageBytes),
        md5($packageBytes),
        sha1($packageBytes),
        strtoupper(substr(hash('sha256', $suffix), 0, 32)),
    ]);
    $fileId = (int)$db->lastInsertId();

    $accessToken = bin2hex(random_bytes(16));
    $packageJobId = $queue->enqueue(
        $queueName,
        JobType::GENERATE_MOD_PACKAGE,
        [
            'file_id' => $fileId,
            'format' => 'dependency_zip',
            'include_dependencies' => false,
            'allow_incomplete' => false,
            'options' => ['name' => 'Package Job ' . $suffix, 'version' => '1.0', 'author' => 'CI'],
            'access_token_hash' => hash('sha256', $accessToken),
        ],
        20,
        null,
        null,
        null,
        1
    );
    $packageJob = $queue->claim($queueName, 'package-generation-worker', 60);
    storage_job_expect($packageJob !== null && $packageJob->id === $packageJobId, 'Package generation job was not claimed.');
    storage_job_expect($packageJob->resourceClass === 'package-heavy', 'Package generation job lost its package-heavy class.');
    storage_job_expect($packageJob->concurrencyKey === 'package:file:' . $fileId, 'Package generation target key is incorrect.');

    $packageResult = (new GeneratedPackageJobHandler($db, $config))->handle(
        $packageJob,
        new JobExecutionContext($queue, $packageJob, 60)
    );
    storage_job_expect((string)$packageResult['format'] === 'dependency_zip', 'Package job returned the wrong format.');
    storage_job_expect((int)$packageResult['file_count'] === 1, 'Package job did not include exactly the selected file.');
    storage_job_expect((int)$packageResult['artifact_size'] > 0, 'Package job produced an empty artifact.');
    storage_job_expect($queue->complete($packageJob, $packageResult) === 'completed', 'Package generation job did not complete.');

    $store = new GeneratedPackageStore($storage);
    $artifactPath = $store->resolve((string)$packageResult['artifact_name']);
    storage_job_expect($artifactPath !== null && is_file($artifactPath), 'Published package artifact is missing.');
    storage_job_expect(hash_file('sha256', $artifactPath) === (string)$packageResult['artifact_sha256'], 'Published package hash differs from the durable result.');

    $zip = new ZipArchive();
    storage_job_expect($zip->open($artifactPath) === true, 'Generated dependency ZIP could not be opened.');
    try {
        storage_job_expect($zip->locateName('UnrealDB-Mod.json') !== false, 'Generated ZIP is missing its manifest.');
        storage_job_expect($zip->numFiles >= 3, 'Generated ZIP is missing expected package/readme entries.');
    } finally {
        $zip->close();
    }

    $stored = $db->query('SELECT status,result_json FROM ue_background_jobs WHERE id=' . $packageJobId)->fetch();
    storage_job_expect(is_array($stored) && (string)$stored['status'] === 'completed', 'Completed package job was not persisted.');
    $storedResult = json_decode((string)$stored['result_json'], true);
    storage_job_expect(is_array($storedResult) && (string)($storedResult['artifact_name'] ?? '') === (string)$packageResult['artifact_name'], 'Durable package result lost its artifact identity.');

    fwrite(STDOUT, "Storage cleanup and generated package integration tests passed.\n");
} finally {
    $db->prepare('DELETE FROM ue_background_jobs WHERE queue_name=?')->execute([$queueName]);
    if ($fileId > 0) {
        $db->prepare('DELETE FROM ue_files WHERE id=?')->execute([$fileId]);
    }
    if ($artifactPath !== null && is_file($artifactPath)) {
        @unlink($artifactPath);
    }
    storage_job_remove_tree($storage);
}
