<?php
declare(strict_types=1);
/**
 * Reparse exactly one existing verified source package and round-trip a new V5
 * snapshot in disposable storage. Never registers or writes SQL / source bytes.
 */
if (PHP_SAPI !== 'cli') { exit(1); }
require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/GameProfiles.php';
require_once dirname(__DIR__) . '/lib/CatalogUE4ParserProfile.php';
require_once dirname(__DIR__) . '/lib/CatalogUE5ParserProfile.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameSourceRegistry;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SourceSnapshotFactory;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;

$options = getopt('', ['file-id::']);
$fileId = max(1, (int)($options['file-id'] ?? 110902));
$temp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uedb5-source-roundtrip-' . bin2hex(random_bytes(8));
$report = ['ok'=>false,'file_id'=>$fileId,'scope'=>'one verified source file; DB and stored source read-only; temporary V5 only'];
try {
    $app = catalog_bootstrap(false);
    $config = catalog_config();
    $statement = $app->db->prepare('SELECT * FROM ue_files WHERE id=? AND scan_status="verified" LIMIT 1');
    $statement->execute([$fileId]);
    $file = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($file)) { throw new RuntimeException('Selected verified source does not exist'); }
    $gameId = (int)$file['game_id'];
    $stored = (string)($file['stored_name'] ?? '');
    if ($stored === '' || basename($stored) !== $stored) {
        throw new RuntimeException('Unsafe stored source name');
    }
    $source = rtrim((string)$config['storage_path'], '\\/') . DIRECTORY_SEPARATOR
        . 'games' . DIRECTORY_SEPARATOR . Uedb5GameSourceRegistry::storageKey($gameId)
        . DIRECTORY_SEPARATOR . 'verified' . DIRECTORY_SEPARATOR . $stored;
    if (!is_file($source)) { throw new RuntimeException('Original source missing'); }
    clearstatcache(true, $source);
    if ((int)filesize($source) !== (int)$file['file_size']
        || !hash_equals(strtolower((string)$file['md5']), (string)md5_file($source))
        || !hash_equals(strtolower((string)$file['sha1']), (string)sha1_file($source))) {
        throw new RuntimeException('Original source identity differs from registered file');
    }
    $snapshot = (new Uedb5SourceSnapshotFactory($app->db, $config))
        ->buildForGameId($gameId, $source, $file);
    $written = (new Uedb5MetadataSnapshotWriter($temp))->write($snapshot);
    $reader = new Uedb5MetadataReader($temp);
    $decoded = $reader->snapshot($gameId, $fileId);
    $verified = $reader->verify($gameId, $fileId, (string)$written['payload_sha256']);
    $report += ['game_id'=>$gameId,'source_bytes'=>(int)$file['file_size'],
        'v5_bytes'=>(int)$written['compressed_size'],
        'v5_blocks'=>(int)$verified['block_count'],
        'snapshot_round_trip_exact'=>$decoded === $snapshot,
        'registered_source_identity_matches'=>true];
    $report['ok'] = $decoded === $snapshot && (int)$verified['block_count'] > 0;
    if (!$report['ok']) { $report['error'] = 'Source/V5 round trip did not match'; }
} catch (Throwable $e) {
    $report['error']=$e->getMessage();
} finally {
    if (is_dir($temp)) {
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($temp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($walk as $entry) {
            if ($entry->isDir()) { @rmdir($entry->getPathname()); }
            else { @unlink($entry->getPathname()); }
        }
        @rmdir($temp);
    }
}
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
exit($report['ok'] ? 0 : 2);
