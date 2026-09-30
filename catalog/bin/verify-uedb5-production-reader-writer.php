<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { exit(1); }
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataSnapshotWriter;

$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-uedb5-production-' . bin2hex(random_bytes(5));
$fileId = 24680;
$gameId = 7;
$failures = [];
$checks = [];
$check = static function (bool $ok, string $name) use (&$failures, &$checks): void {
    $checks[$name] = $ok;
    if (!$ok) { $failures[] = $name; }
};

$snapshot = [
    'file' => ['id' => $fileId, 'game_id' => $gameId, 'package_name' => 'V5ReaderWriter', 'original_name' => 'V5ReaderWriter.uasset'],
    'package_family' => 'classic-linkerload',
    'source_policy' => 'contract-test-v1',
    'section_schemas' => ['imports' => 'test.import.v1', 'exports' => 'test.export.v1'],
    'sections' => [
        'imports' => [
            ['index' => 0, 'name' => 'ProviderA', 'identity' => 'FEDCBA9876543210'],
            ['index' => 1, 'name' => 'ProviderB', 'identity' => '0123456789ABCDEF'],
        ],
        'exports' => [
            ['index' => 0, 'name' => 'ObjectA', 'public_hash' => '1122334455667788'],
        ],
    ],
];

try {
    $v4Path = BlockedCompressedMetadataContainer::path($tempRoot, $gameId, $fileId);
    $v4Dir = dirname($v4Path);
    if (!is_dir($v4Dir)) { mkdir($v4Dir, 0775, true); }
    file_put_contents($v4Path, 'UEDB4-SENTINEL');
    $v4HashBefore = hash_file('sha256', $v4Path);

    $writer = new Uedb5MetadataSnapshotWriter($tempRoot);
    $reader = new Uedb5MetadataReader($tempRoot);
    $written = $writer->write($snapshot, 1);
    $path = $writer->path($gameId, $fileId);

    $check(str_ends_with($path, '.uedb5') && is_file($path), 'v5_writer_uses_isolated_extension');
    $check((int)$written['format_version'] === 5 && strlen((string)$written['payload_sha256']) === 32, 'v5_writer_returns_verified_registration_material');
    $check(hash_file('sha256', $v4Path) === $v4HashBefore, 'v5_writer_does_not_touch_v4_container');

    $manifest = $reader->manifest($gameId, $fileId);
    $check((string)$manifest['package_family'] === 'classic-linkerload' && (string)$manifest['source_policy'] === 'contract-test-v1', 'v5_reader_routes_manifest_identity');
    $check($reader->count($gameId, $fileId, 'imports') === 2, 'v5_reader_reports_section_counts');
    $page = $reader->page($gameId, $fileId, 'imports', 1, 1);
    $check(count($page) === 1 && ($page[0]['name'] ?? null) === 'ProviderB', 'v5_reader_pages_source_rows');
    $sparse = $reader->rowsByPositions($gameId, $fileId, 'imports', [1, 0]);
    $check(array_keys($sparse) === [0, 1] && ($sparse[1]['identity'] ?? null) === '0123456789ABCDEF', 'v5_reader_sparse_positions_are_source_ordered');

    $roundTrip = $reader->snapshot($gameId, $fileId);
    $check($roundTrip === $snapshot, 'v5_reader_reconstructs_canonical_snapshot');
    $verified = $reader->verify($gameId, $fileId, (string)$written['payload_sha256']);
    $check((int)$verified['block_count'] === 3, 'v5_reader_verifies_registered_hash_material');

    $wrongGameRejected = false;
    try { $reader->manifest($gameId + 1, $fileId); } catch (RuntimeException) { $wrongGameRejected = true; }
    $check($wrongGameRejected, 'v5_reader_rejects_wrong_game_identity');

    $snapshot['sections']['imports'][1]['name'] = 'ProviderB2';
    $rewritten = $writer->write($snapshot, 1);
    $reader->clearCache($gameId, $fileId);
    $check(($reader->page($gameId, $fileId, 'imports', 1, 1)[0]['name'] ?? null) === 'ProviderB2', 'v5_writer_atomically_replaces_existing_v5_file');
    $check(!hash_equals((string)$written['payload_sha256'], (string)$rewritten['payload_sha256']), 'v5_replacement_changes_content_hash');

    $readerSource = file_get_contents($root . '/src/Infrastructure/Metadata/Uedb5MetadataReader.php');
    $writerSource = file_get_contents($root . '/src/Infrastructure/Metadata/Uedb5MetadataSnapshotWriter.php');
    $isolated = is_string($readerSource) && is_string($writerSource)
        && !str_contains($readerSource, 'use PDO')
        && !str_contains($writerSource, 'use PDO')
        && !str_contains($readerSource, 'BlockedCompressedMetadataReader::')
        && !str_contains($writerSource, 'BlockedCompressedMetadataSnapshotWriter::');
    $check($isolated, 'v5_reader_writer_have_no_v4_or_registration_fallback');

    $v4StillRejected = false;
    try { Uedb5MetadataContainer::verifyFile($v4Path, $fileId); } catch (RuntimeException) { $v4StillRejected = true; }
    $check($v4StillRejected, 'v5_reader_never_interprets_v4_container');
} finally {
    if (is_dir($tempRoot)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($tempRoot);
    }
}

echo json_encode(['ok' => $failures === [], 'checks' => $checks, 'failures' => $failures], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 1);
