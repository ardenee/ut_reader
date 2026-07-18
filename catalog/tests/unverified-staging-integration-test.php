<?php
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Legacy\LegacyUnverifiedFileStager;

require_once __DIR__ . '/../lib/CatalogScanner.php';

function staging_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function staging_remove_tree(string $path): void
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
            staging_remove_tree($child);
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

$db = new PDO($dsn, $user, $password, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-staging-' . bin2hex(random_bytes(6));
if (!mkdir($storage, 0775, true) && !is_dir($storage)) {
    throw new RuntimeException('Could not create staging test storage.');
}

$config = [
    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'database' => 'unrealdb_test',
        'username' => $user,
        'password' => $password,
        'charset' => 'utf8mb4',
    ],
    'storage_path' => $storage,
    'allowed_extensions' => ['utx'],
    'common_packages' => [],
    'readers' => [],
    'max_upload_bytes' => 1048576,
];
$stager = new LegacyUnverifiedFileStager($db, $config);
$fileIds = [];
$externalFiles = [];

try {
    $bucketTemp = tempnam(sys_get_temp_dir(), 'unrealdb-bucket-');
    staging_expect(is_string($bucketTemp), 'Could not create a bucket test file.');
    file_put_contents($bucketTemp, str_repeat('bucket-metadata-test', 8));

    $bucket = $stager->stageBucketUpload(
        $bucketTemp,
        'Explicit Bucket.utx',
        'Integration test bucket upload.',
        null,
        'Fixture/Content/Explicit Bucket.utx'
    );
    $fileIds[] = (int)$bucket['file_id'];
    staging_expect((int)$bucket['file_id'] > 0, 'Bucket staging did not return a file identity.');
    staging_expect(is_file((string)$bucket['path']), 'Bucket staging did not retain the physical package.');

    $bucketRow = catalog_one($db, 'SELECT * FROM ue_files WHERE id=?', [(int)$bucket['file_id']]);
    staging_expect(is_array($bucketRow), 'Bucket staging did not create a ue_files row.');
    staging_expect((string)$bucketRow['scan_status'] === 'unverified', 'Bucket row is not unverified.');
    staging_expect($bucketRow['game_id'] === null, 'Bucket row unexpectedly has a game assignment.');
    staging_expect((int)$bucketRow['unverified_queue_game_id'] === 0, 'Bucket queue identity is incorrect.');
    staging_expect((string)$bucketRow['unverified_queue_name'] === (string)$bucket['queue_name'], 'Bucket queue name was not persisted.');
    staging_expect((string)$bucketRow['source_relative_path'] === 'Fixture/Content/Explicit Bucket.utx', 'Bucket source-relative path was not preserved.');

    $game = $db->query('SELECT id,slug FROM ue_games ORDER BY id LIMIT 1')->fetch();
    staging_expect(is_array($game) && (int)$game['id'] > 0, 'A seeded game is required for failed-upload staging.');
    $gameId = (int)$game['id'];

    $failedTemp = tempnam(sys_get_temp_dir(), 'unrealdb-failed-');
    staging_expect(is_string($failedTemp), 'Could not create a failed-upload test file.');
    file_put_contents($failedTemp, pack('V', 0x9E2A83C1) . str_repeat("\0", 96));

    $failed = $stager->stageFailedUpload(
        $gameId,
        $failedTemp,
        'Broken Package.utx',
        'Integration test parser failure.',
        null,
        'System/Broken Package.utx'
    );
    staging_expect(is_array($failed), 'A file with Unreal package magic was not retained.');
    $fileIds[] = (int)$failed['file_id'];
    staging_expect((int)$failed['file_id'] > 0, 'Failed-upload staging did not return a file identity.');
    staging_expect(is_file((string)$failed['path']), 'Failed-upload staging did not retain the physical package.');

    $failedRow = catalog_one($db, 'SELECT * FROM ue_files WHERE id=?', [(int)$failed['file_id']]);
    staging_expect(is_array($failedRow), 'Failed-upload staging did not create a ue_files row.');
    staging_expect((string)$failedRow['scan_status'] === 'unverified', 'Failed-upload row is not unverified.');
    staging_expect($failedRow['game_id'] === null, 'Failed-upload row unexpectedly has a verified game assignment.');
    staging_expect((int)$failedRow['unverified_queue_game_id'] === $gameId, 'Failed-upload queue game was not persisted.');

    $copySource = tempnam(sys_get_temp_dir(), 'unrealdb-source-copy-');
    staging_expect(is_string($copySource), 'Could not create a source-copy test file.');
    $externalFiles[] = $copySource;
    file_put_contents($copySource, pack('V', 0x9E2A83C1) . str_repeat("\0", 104));
    $copied = $stager->stageFailedCopy(
        $gameId,
        $copySource,
        'Source Library Package.utx',
        'Copy-preserving source failure.',
        null,
        'Maps/Source Library Package.utx'
    );
    staging_expect(is_array($copied), 'Copy-preserving staging did not retain a valid package.');
    $fileIds[] = (int)$copied['file_id'];
    staging_expect(is_file($copySource), 'Copy-preserving staging removed the configured source file.');
    staging_expect(is_file((string)$copied['path']), 'Copy-preserving staging did not create an independent queue copy.');
    staging_expect(realpath($copySource) !== realpath((string)$copied['path']), 'Copy-preserving staging reused the source path instead of queue storage.');

    $copiedRow = catalog_one($db, 'SELECT * FROM ue_files WHERE id=?', [(int)$copied['file_id']]);
    staging_expect(is_array($copiedRow), 'Copy-preserving staging did not create a database row.');
    staging_expect((string)$copiedRow['source_relative_path'] === 'Maps/Source Library Package.utx', 'Copy-preserving staging lost source-relative context.');

    $scannerTemp = tempnam(sys_get_temp_dir(), 'unrealdb-scanner-failed-');
    staging_expect(is_string($scannerTemp), 'Could not create a scanner failure test file.');
    file_put_contents($scannerTemp, pack('V', 0x9E2A83C1) . str_repeat("\0", 112));
    $scannerReason = 'Scanner primitive integration failure ' . bin2hex(random_bytes(4));
    scanner_store_failed_upload(
        $config,
        $scannerTemp,
        'Folder/Scanner Failure.utx',
        (string)$game['slug'],
        $scannerReason
    );
    staging_expect(!is_file($scannerTemp), 'Scanner staging did not move the failed package out of temporary storage.');

    $scannerRow = catalog_one(
        $db,
        'SELECT * FROM ue_files WHERE scan_status="unverified" AND unverified_reason=? ORDER BY id DESC LIMIT 1',
        [$scannerReason]
    );
    staging_expect(is_array($scannerRow), 'Scanner failure did not create an unverified database row synchronously.');
    $fileIds[] = (int)$scannerRow['id'];
    staging_expect((int)$scannerRow['unverified_queue_game_id'] === $gameId, 'Scanner failure used the wrong physical queue.');
    staging_expect((string)$scannerRow['source_relative_path'] === 'Folder/Scanner Failure.utx', 'Scanner failure did not preserve source-relative context.');

    $unsupportedTemp = tempnam(sys_get_temp_dir(), 'unrealdb-reject-');
    staging_expect(is_string($unsupportedTemp), 'Could not create an unsupported test file.');
    file_put_contents($unsupportedTemp, 'not an Unreal package');
    $unsupported = $stager->stageFailedUpload(
        $gameId,
        $unsupportedTemp,
        'Unsupported.txt',
        'Must not be retained.'
    );
    staging_expect($unsupported === null, 'A non-Unreal failed upload was retained.');
    staging_expect(!is_file($unsupportedTemp), 'A non-Unreal failed upload was not deleted.');

    $unsupportedCopy = tempnam(sys_get_temp_dir(), 'unrealdb-copy-reject-');
    staging_expect(is_string($unsupportedCopy), 'Could not create an unsupported copy test file.');
    $externalFiles[] = $unsupportedCopy;
    file_put_contents($unsupportedCopy, 'not an Unreal package');
    $ignoredCopy = $stager->stageFailedCopy(
        $gameId,
        $unsupportedCopy,
        'Unsupported Source.txt',
        'Must be ignored without deleting the source.'
    );
    staging_expect($ignoredCopy === null, 'Copy-preserving staging retained a non-Unreal source file.');
    staging_expect(is_file($unsupportedCopy), 'Copy-preserving staging deleted a non-Unreal source file.');

    $scannerUnsupported = tempnam(sys_get_temp_dir(), 'unrealdb-scanner-reject-');
    staging_expect(is_string($scannerUnsupported), 'Could not create a scanner unsupported test file.');
    file_put_contents($scannerUnsupported, 'not an Unreal package');
    scanner_store_failed_upload(
        $config,
        $scannerUnsupported,
        'Unsupported Scanner.txt',
        (string)$game['slug'],
        'Must not be retained by scanner helper.'
    );
    staging_expect(!is_file($scannerUnsupported), 'Scanner helper retained a non-Unreal failed upload.');

    echo "Explicit unverified staging integration tests passed.\n";
} finally {
    foreach ($fileIds as $fileId) {
        if ($fileId > 0) {
            $db->prepare('DELETE FROM ue_files WHERE id=?')->execute([$fileId]);
        }
    }
    foreach ($externalFiles as $externalFile) {
        @unlink($externalFile);
    }
    staging_remove_tree($storage);
}
