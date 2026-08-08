#!/usr/bin/env php
<?php
/**
 * UnrealDB PHP File Audit
 * Purpose: Verifies generated ZIP/UMOD/PAK writer boundaries and byte-level compatibility contracts.
 * Why: Generated artifacts previously failed when UMOD checksum/flags or ZIP contents drifted; these rules need executable regression coverage.
 * Role: Read-only CLI architecture/format verifier using temporary files only.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command may only run from the PHP CLI.\n");
    exit(1);
}

$catalogRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$repoRoot = dirname($catalogRoot);
require_once $catalogRoot . '/bootstrap/autoload.php';
require_once $catalogRoot . '/lib/CatalogSupport.php';

use UnrealDb\Catalog\Infrastructure\Downloads\CatalogGeneratedPackageDescriptor;
use UnrealDb\Catalog\Infrastructure\Downloads\CatalogGeneratedUmodWriter;
use UnrealDb\Catalog\Infrastructure\Downloads\CatalogPackageExportFormatPolicy;
use UnrealDb\Catalog\Infrastructure\Downloads\CatalogPayloadZipWriter;
use UnrealDb\Catalog\Infrastructure\Downloads\CatalogUmodBinaryCodec;
use UnrealDb\Catalog\Infrastructure\Downloads\CatalogUt4PakWriter;

$checks = [];
$failures = [];
$record = static function (string $name, bool $ok, string $detail = '') use (&$checks, &$failures): void {
    $checks[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail];
    if (!$ok) {
        $failures[] = $name . ($detail !== '' ? ': ' . $detail : '');
    }
};
$read = static function (string $relative) use ($repoRoot): string {
    $path = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $content = @file_get_contents($path);
    return is_string($content) ? $content : '';
};

$roundTripValues = [0, 1, 63, 64, 127, 128, 8192, -1, -63, -64, -8192];
$roundTripOk = true;
foreach ($roundTripValues as $value) {
    $encoded = CatalogUmodBinaryCodec::compactIndex($value);
    $offset = 0;
    $decoded = CatalogUmodBinaryCodec::readCompactIndex($encoded, $offset);
    if ($decoded !== $value || $offset !== strlen($encoded)) {
        $roundTripOk = false;
        break;
    }
}
$record(
    'umod_compact_index_round_trip',
    $roundTripOk,
    'legacy Unreal compact indices must round-trip unchanged'
);
$record(
    'umod_app_mem_crc_known_vector',
    CatalogUmodBinaryCodec::unrealMemCrc('123456789') === 0xFC891918,
    'Unreal appMemCrc("123456789") must remain 0xFC891918, not PHP reflected CRC32'
);

$descriptorOptions = [
    'name' => 'Test Package',
    'version' => '1.2-beta',
    'author' => 'UnrealDB',
];
$record(
    'descriptor_contract',
    CatalogGeneratedPackageDescriptor::generatedVersion(" 1.2 beta\r\n") === '1.2beta'
        && CatalogGeneratedPackageDescriptor::extension(CatalogPackageExportFormatPolicy::UT4MOD) === 'ut4mod'
        && CatalogGeneratedPackageDescriptor::downloadName(
            CatalogPackageExportFormatPolicy::DEPENDENCY_ZIP,
            $descriptorOptions
        ) === 'Test Package-with-dependencies.zip',
    'version/name/extension normalization must retain established output names'
);

$tempRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'unrealdb-package-writer-'
    . getmypid() . '-' . bin2hex(random_bytes(4));
if (!@mkdir($tempRoot, 0775, true) && !is_dir($tempRoot)) {
    fwrite(STDERR, "Could not create verifier temporary directory.\n");
    exit(2);
}

try {
    $payloadPath = $tempRoot . DIRECTORY_SEPARATOR . 'DM-Test.ut2';
    $payload = "UnrealDB package writer verifier payload\n";
    file_put_contents($payloadPath, $payload);
    $payloadSize = strlen($payload);

    $baseFile = [
        'id' => 1,
        'install_path' => 'Maps/DM-Test.ut2',
        'install_path_inferred' => false,
        'source_relative_path' => 'Maps/DM-Test.ut2',
        'package_name' => 'DM-Test',
        'original_name' => 'DM-Test.ut2',
        'md5' => md5($payload),
        'sha1' => sha1($payload),
        'package_guid' => '00000000-00000000-00000000-00000000',
        'file_size' => $payloadSize,
        'storage_path' => $payloadPath,
    ];
    $basePlan = [
        'format' => CatalogPackageExportFormatPolicy::UT4MOD,
        'root' => [
            'id' => 1,
            'package_name' => 'DM-Test',
        ],
        'game' => [
            'id' => 1,
            'name' => 'Unreal Tournament 2004',
            'slug' => 'ut2004',
            'engine_key' => 'UE2',
            'profile_name' => 'UT2004',
        ],
        'files' => [$baseFile],
        'file_count' => 1,
        'total_bytes' => $payloadSize,
        'blocked' => [],
        'missing' => [],
        'package_only' => [],
        'common' => [],
        'include_dependencies' => true,
        'transitive_dependencies' => true,
    ];

    $umodPath = $tempRoot . DIRECTORY_SEPARATOR . 'test.ut4mod';
    $umodWriter = new CatalogGeneratedUmodWriter();
    $umodValidation = $umodWriter->write($umodPath, $basePlan, $descriptorOptions);
    $umodEntries = [];
    foreach ((array)($umodValidation['entries'] ?? []) as $entry) {
        $umodEntries[strtolower(str_replace('/', '\\', (string)$entry['filename']))] = $entry;
    }
    $record(
        'generated_umod_round_trip',
        !empty($umodValidation['ok'])
            && isset($umodEntries['system\\manifest.ini'])
            && isset($umodEntries['system\\manifest.int'])
            && (int)$umodEntries['system\\manifest.ini']['flags'] === 3
            && (int)$umodEntries['system\\manifest.int']['flags'] === 3
            && (string)($umodValidation['package_version'] ?? '') === '1.2-beta',
        'generated UMOD must validate with Unreal appMemCrc, flags 3 and the requested package version'
    );

    if (class_exists(ZipArchive::class)) {
        $zipPlan = $basePlan;
        $zipPlan['format'] = CatalogPackageExportFormatPolicy::DEPENDENCY_ZIP;
        $zipPath = $tempRoot . DIRECTORY_SEPARATOR . 'test.zip';
        $zipValidation = (new CatalogPayloadZipWriter())->write($zipPath, $zipPlan);
        $zip = new ZipArchive();
        $opened = $zip->open($zipPath) === true;
        $onlyPayload = $opened
            && $zip->numFiles === 1
            && $zip->locateName('Maps/DM-Test.ut2') !== false
            && $zip->locateName('UnrealDB-Mod.json', ZipArchive::FL_NOCASE) === false
            && $zip->locateName('Readme.txt', ZipArchive::FL_NOCASE) === false;
        if ($opened) {
            $zip->close();
        }
        $record(
            'payload_zip_contains_no_generated_text_files',
            !empty($zipValidation['ok']) && $onlyPayload,
            'dependency ZIP must contain only selected payload files'
        );
    } else {
        $record(
            'payload_zip_contains_no_generated_text_files',
            false,
            'ZipArchive is unavailable; generated ZIP downloads require the PHP zip extension'
        );
    }

    $pakPayloadPath = $tempRoot . DIRECTORY_SEPARATOR . 'DM-Test.uasset';
    file_put_contents($pakPayloadPath, $payload);
    $pakFile = $baseFile;
    $pakFile['install_path'] = 'Maps/DM-Test.uasset';
    $pakFile['source_relative_path'] = 'UnrealTournament/Content/Maps/DM-Test.uasset';
    $pakFile['original_name'] = 'DM-Test.uasset';
    $pakFile['storage_path'] = $pakPayloadPath;
    $pakPlan = $basePlan;
    $pakPlan['format'] = CatalogPackageExportFormatPolicy::UT4_PAK;
    $pakPlan['root'] = ['id' => 1, 'package_name' => '/Game/Maps/DM-Test'];
    $pakPlan['game'] = [
        'id' => 2,
        'name' => 'Unreal Tournament',
        'slug' => 'ut4',
        'engine_key' => 'UE4',
        'profile_name' => 'UT4',
    ];
    $pakPlan['files'] = [$pakFile];
    $pakPath = $tempRoot . DIRECTORY_SEPARATOR . 'test.pak';
    $pakValidation = (new CatalogUt4PakWriter())->write(
        $pakPath,
        $pakPlan,
        $descriptorOptions,
        ['ut4_mount_point' => '../../../UnrealTournament/Content/']
    );
    $pakNames = array_map(
        static fn(array $entry): string => (string)$entry['filename'],
        (array)($pakValidation['entries'] ?? [])
    );
    $record(
        'ut4_pak_v3_round_trip',
        !empty($pakValidation['ok'])
            && (int)($pakValidation['version'] ?? 0) === 3
            && (string)($pakValidation['mount_point'] ?? '') === '../../../UnrealTournament/Content/'
            && in_array('Maps/DM-Test.uasset', $pakNames, true)
            && in_array('UnrealDB/Test Package/UnrealDB-Mod.json', $pakNames, true),
        'UT4 PAK must retain version 3, mount point, payload path and embedded manifest'
    );
} catch (Throwable $error) {
    $record('artifact_round_trip_exception', false, $error->getMessage());
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

$builder = $read('catalog/lib/GeneratedPackageBuilder.php');
$worker = $read('catalog/src/Infrastructure/Jobs/GeneratedPackageJobHandler.php');
$legacyUmod = $read('catalog/lib/LegacyUmodPackageBuilder.php');
$record(
    'active_writer_facade_is_thin',
    str_contains($builder, 'CatalogGeneratedUmodWriter')
        && str_contains($builder, 'CatalogPayloadZipWriter')
        && str_contains($builder, 'CatalogUt4PakWriter')
        && !str_contains($builder, 'stream_copy_to_stream(')
        && !str_contains($builder, 'new ZipArchive')
        && !str_contains($builder, 'ModPackageBuilder.php')
        && !str_contains($builder, 'LegacyUmodPackageBuilder.php'),
    'GeneratedPackageBuilder must dispatch only; archive I/O belongs under src/'
);
$record(
    'worker_uses_descriptor_not_procedural_naming',
    str_contains($worker, 'CatalogGeneratedPackageDescriptor::defaultOptions')
        && str_contains($worker, 'CatalogGeneratedPackageDescriptor::generatedVersion')
        && str_contains($worker, 'CatalogGeneratedPackageDescriptor::extension')
        && str_contains($worker, 'CatalogGeneratedPackageDescriptor::downloadName')
        && !str_contains($worker, '\\modpkg_default_options(')
        && !str_contains($worker, '\\modpkg_generated_version(')
        && !str_contains($worker, '\\modpkg_extension(')
        && !str_contains($worker, '\\modpkg_download_name('),
    'worker naming/options must stay out of the procedural builder layer'
);
$record(
    'legacy_umod_facade_uses_shared_codec',
    str_contains($legacyUmod, 'CatalogUmodBinaryCodec::unrealMemCrcStream')
        && str_contains($legacyUmod, 'CatalogUmodBinaryCodec::compactIndex')
        && !str_contains($legacyUmod, 'hash_init(\'crc32b\')'),
    'legacy compatibility writer must share Unreal appMemCrc and compact-index codec'
);

$syntaxFiles = [
    'catalog/lib/GeneratedPackageBuilder.php',
    'catalog/lib/LegacyUmodPackageBuilder.php',
    'catalog/src/Infrastructure/Downloads/CatalogGeneratedPackageDescriptor.php',
    'catalog/src/Infrastructure/Downloads/CatalogGeneratedUmodWriter.php',
    'catalog/src/Infrastructure/Downloads/CatalogPayloadZipWriter.php',
    'catalog/src/Infrastructure/Downloads/CatalogUmodBinaryCodec.php',
    'catalog/src/Infrastructure/Downloads/CatalogUt4PakWriter.php',
    'catalog/src/Infrastructure/Jobs/GeneratedPackageJobHandler.php',
];
foreach ($syntaxFiles as $relative) {
    $path = $repoRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
    $process = proc_open(
        [PHP_BINARY, '-l', $path],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $output = '';
    $exit = 1;
    if (is_resource($process)) {
        $output = trim((string)stream_get_contents($pipes[1]) . ' ' . (string)stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
    }
    $record(
        'php_syntax_' . str_replace(['/', '.php'], ['_', ''], $relative),
        $exit === 0,
        $exit === 0 ? '' : $output
    );
}

$result = [
    'ok' => $failures === [],
    'checks' => $checks,
    'failures' => $failures,
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failures === [] ? 0 : 2);
