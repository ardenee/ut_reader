<?php
declare(strict_types=1);
/** Runs actual V5 verified publisher identity gates against isolated SQLite/storage. */
if (PHP_SAPI !== 'cli') { exit(1); }
require_once dirname(__DIR__) . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5VerifiedFilePublisher;

$storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uedb5-publisher-gates-' . bin2hex(random_bytes(8));
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec('CREATE TABLE ue_files (
    id INTEGER PRIMARY KEY, game_id INTEGER, package_name TEXT, original_name TEXT,
    stored_name TEXT, relative_path TEXT, file_size INTEGER, md5 TEXT, sha1 TEXT,
    package_version INTEGER, licensee_version INTEGER, scan_status TEXT
)');
$sourceDir = $storage . DIRECTORY_SEPARATOR . 'games' . DIRECTORY_SEPARATOR . 'ut4'
    . DIRECTORY_SEPARATOR . 'verified';
$checks = [];
try {
    if (!mkdir($sourceDir, 0775, true) && !is_dir($sourceDir)) {
        throw new RuntimeException('Isolated verified fixture directory unavailable.');
    }
    $source = $sourceDir . DIRECTORY_SEPARATOR . 'fixture.uasset';
    $bytes = 'UEDB5 verified identity regression fixture; not a real Unreal package';
    file_put_contents($source, $bytes);
    $writer = $db->prepare('INSERT INTO ue_files VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    $writer->execute([91,7,'Fixture','fixture.uasset','fixture.uasset','',strlen($bytes),
        md5($bytes),sha1($bytes),0,0,'verified']);
    $publisher = new Uedb5VerifiedFilePublisher($db, ['storage_path'=>$storage]);
    $case = static function (string $name, int $fileId, string $error) use ($publisher,&$checks): void {
        try {
            $publisher->publish($fileId);
            $checks[$name] = false;
        } catch (RuntimeException $e) {
            $checks[$name] = str_contains($e->getMessage(), $error);
        }
    };
    $case('nonpositive_file_id',0,'positive verified file ID');
    $case('unknown_file_id',9999,'active verified catalogue file');
    $db->exec("UPDATE ue_files SET scan_status='unverified' WHERE id=91");
    $case('unverified_file_rejected',91,'active verified catalogue file');
    $db->exec("UPDATE ue_files SET scan_status='verified',game_id=0 WHERE id=91");
    $case('invalid_game_rejected',91,'no game identity');
    $db->exec("UPDATE ue_files SET game_id=7,stored_name='../fixture.uasset' WHERE id=91");
    $case('unsafe_stored_name_rejected',91,'Invalid verified stored_name');
    $db->exec("UPDATE ue_files SET stored_name='absent.uasset' WHERE id=91");
    $case('missing_stored_file_rejected',91,'Verified source bytes are missing');
    $db->exec("UPDATE ue_files SET stored_name='fixture.uasset',file_size=1 WHERE id=91");
    $case('file_size_mismatch_rejected',91,'source byte size does not match');
    $db->exec("UPDATE ue_files SET file_size=" . strlen($bytes) . ",md5='invalid' WHERE id=91");
    $case('md5_mismatch_rejected',91,'source MD5 does not match');
    $db->prepare('UPDATE ue_files SET md5=? ,sha1=? WHERE id=91')
        ->execute([md5($bytes),'invalid']);
    $case('sha1_mismatch_rejected',91,'source SHA1 does not match');
} finally {
    if (is_dir($storage)) {
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($storage, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($walk as $entry) {
            if ($entry->isDir()) { rmdir($entry->getPathname()); }
            else { unlink($entry->getPathname()); }
        }
        rmdir($storage);
    }
}
$failures = array_keys(array_filter($checks, static fn(bool $value):bool => !$value));
echo json_encode(['ok'=>$failures===[],'isolated'=>true,'checks'=>$checks,'failures'=>$failures],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures===[] ? 0 : 2);
