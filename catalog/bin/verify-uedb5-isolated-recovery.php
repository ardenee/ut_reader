<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
require_once dirname(__DIR__) . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;

$root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uedb5-recovery-' . bin2hex(random_bytes(8));
$fileId = 934791;
$gameId = 7;
$results = [];
$check = static function (string $name, bool $passed) use (&$results): void {
    $results[$name] = $passed;
};
$snapshot = [
    'file' => ['id' => $fileId, 'game_id' => $gameId, 'package_name' => 'RecoveryFixture'],
    'package_family' => 'classic-linkerload',
    'source_policy' => 'isolated-recovery-test',
    'section_schemas' => ['exports' => 'test.exports.v1'],
    'sections' => ['exports' => [['index' => 0, 'name' => 'Example']]],
];
try {
    $writer = new Uedb5MetadataSnapshotWriter($root);
    $reader = new Uedb5MetadataReader($root);
    $saved = $writer->write($snapshot, 1);
    $path = $writer->path($gameId, $fileId);
    $original = file_get_contents($path);
    if (!is_string($original) || strlen($original) < 20) {
        throw new RuntimeException('Isolated fixture could not be created');
    }

    $check('healthy_fixture', hash_equals(
        (string)$saved['payload_sha256'],
        (string)$reader->verify($gameId, $fileId)['payload_sha256']
    ));

    unlink($path);
    $reader->clearCache($gameId, $fileId);
    $missing = false;
    try { $reader->verify($gameId, $fileId); }
    catch (RuntimeException $e) { $missing = str_contains(strtolower($e->getMessage()), 'missing'); }
    $check('missing_file_rejected', $missing);

    file_put_contents($path, substr($original, 0, 8));
    $reader->clearCache($gameId, $fileId);
    $truncated = false;
    try { $reader->verify($gameId, $fileId); }
    catch (RuntimeException $e) { $truncated = true; }
    $check('truncated_file_rejected', $truncated);

    $damaged = $original;
    $index = strlen($damaged) - 1;
    $damaged[$index] = chr(ord($damaged[$index]) ^ 255);
    file_put_contents($path, $damaged);
    $reader->clearCache($gameId, $fileId);
    $corrupted = false;
    try { $reader->verify($gameId, $fileId); }
    catch (RuntimeException $e) { $corrupted = true; }
    $check('damaged_payload_rejected', $corrupted);

    file_put_contents($path, $original);
    $reader->clearCache($gameId, $fileId);
    $check('restored_file_revalidated', hash_equals(
        (string)$saved['payload_sha256'],
        (string)$reader->verify($gameId, $fileId)['payload_sha256']
    ));
} catch (Throwable $e) {
    $results['fixture_execution'] = false;
    $error = $e->getMessage();
} finally {
    if (is_dir($root)) {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iter as $entry) {
            if ($entry->isDir()) { rmdir($entry->getPathname()); }
            else { unlink($entry->getPathname()); }
        }
        rmdir($root);
    }
}
$failures = array_keys(array_filter($results, static fn(bool $ok): bool => !$ok));
echo json_encode(['ok' => $failures === [], 'checks' => $results, 'failures' => $failures,
    'error' => $error ?? null], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
