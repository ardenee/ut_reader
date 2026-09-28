<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5MetadataStagingReader;

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-uedb5-foundation-' . bin2hex(random_bytes(5));
$fileId = 12345;
$gameId = 7;
$snapshot = [
    'file' => [
        'id' => $fileId,
        'game_id' => $gameId,
        'package_name' => 'TestPackage',
        'original_name' => 'TestPackage.uasset',
    ],
    'package_family' => 'classic',
    'source_policy' => 'ue5-5.8.3',
    'section_schemas' => [
        'imports' => 'ue5.classic.import.test-v1',
        'exports' => 'ue5.classic.export.test-v1',
    ],
    'sections' => [
        'imports' => [
            [
                'import_index' => 0,
                'package_name' => '/Game/Provider',
                'b_import_optional' => false,
                'raw_package_name' => ['index' => 12, 'number' => 0],
            ],
            [
                'import_index' => 1,
                'package_name' => '/Game/OptionalProvider',
                'b_import_optional' => true,
                'raw_package_name' => ['index' => 13, 'number' => 2],
            ],
        ],
        'exports' => [
            [
                'export_index' => 0,
                'object_flags_uint64_hex' => '0000000400000000',
                'public_export_hash_uint64_hex' => 'fedcba9876543210',
                'outer_index' => 0,
            ],
        ],
    ],
];

$checks = [];
$check = static function (string $name, bool $ok, string $detail) use (&$checks): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
};

try {
    $path = Uedb5MetadataContainer::path($tempRoot, $gameId, $fileId);
    $built = Uedb5MetadataContainer::buildToFile($snapshot, $path, 1);
    $reader = new Uedb5MetadataStagingReader($path, $fileId);
    $headerBytes = file_get_contents($path, false, null, 0, Uedb5MetadataContainer::HEADER_LENGTH);
    $header = is_string($headerBytes)
        ? unpack('a8magic/vversion/vcodec/Vmanifest_length/Vreserved', $headerBytes)
        : false;

    $check('v5_magic_and_version', is_array($header)
        && (string)$header['magic'] === Uedb5MetadataContainer::MAGIC
        && (int)$header['version'] === 5,
        'UEDB5 must use its own magic and format version.');
    $check('v5_extension_isolated', str_ends_with($path, '.uedb5'), 'Staged format-5 files must use .uedb5.');
    $check('manifest_routes_source_policy', (string)($reader->manifest()['package_family'] ?? '') === 'classic'
        && (string)($reader->manifest()['source_policy'] ?? '') === 'ue5-5.8.3',
        'Package family and audited source policy must be explicit.');
    $check('section_schema_preserved', (string)($reader->manifest()['section_schemas']['imports'] ?? '')
        === 'ue5.classic.import.test-v1', 'Section schema identity must survive the container round trip.');

    $imports = $reader->page('imports', 0, 10);
    $exports = $reader->page('exports', 0, 10);
    $check('paged_staging_reader_roundtrip', count($imports) === 2
        && !empty($imports[1]['b_import_optional'])
        && (int)($imports[1]['raw_package_name']['number'] ?? -1) === 2,
        'The staging reader must preserve source-shaped nested rows.');
    $check('uint64_hex_survives_without_php_int', (string)($exports[0]['object_flags_uint64_hex'] ?? '')
        === '0000000400000000'
        && (string)($exports[0]['public_export_hash_uint64_hex'] ?? '') === 'fedcba9876543210',
        'UEDB5 transport must preserve fixed-width unsigned-64 hex identities verbatim.');
    $check('multi_block_file_verified', (int)$built['block_count'] === 3
        && strlen((string)$built['payload_sha256']) === 32,
        'Block-size 1 must produce independently verified blocks and a binary SHA-256.');

    $v4Path = BlockedCompressedMetadataContainer::path($tempRoot, $gameId, $fileId);
    $check('production_v4_identity_unchanged', BlockedCompressedMetadataContainer::FORMAT_VERSION === 4
        && str_ends_with($v4Path, '.uedb4'),
        'Production metadata runtime must remain format 4 until cutover.');
    $v4RejectedV5 = false;
    try {
        BlockedCompressedMetadataContainer::verifyFile($path, $fileId);
    } catch (RuntimeException) {
        $v4RejectedV5 = true;
    }
    $check('production_v4_rejects_v5_container', $v4RejectedV5,
        'The UEDB4 container verifier must not accept staged UEDB5 files.');

    $bytes = file_get_contents($path);
    $corruptionRejected = false;
    if (is_string($bytes) && $bytes !== '') {
        $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 0x01);
        try {
            Uedb5MetadataContainer::verifyBytes($bytes, $fileId);
        } catch (RuntimeException) {
            $corruptionRejected = true;
        }
    }
    $check('corruption_is_rejected', $corruptionRejected, 'Block checksum verification must detect changed bytes.');
} finally {
    if (is_dir($tempRoot)) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($tempRoot, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($tempRoot);
    }
}

$failed = array_values(array_filter($checks, static fn(array $row): bool => !$row['ok']));
echo json_encode(
    ['ok' => $failed === [], 'checks' => $checks],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
), PHP_EOL;
exit($failed === [] ? 0 : 2);
