#!/usr/bin/env php
<?php
/** Verifies UT3 provider identity is read from UEDB4, not per-export SQL identity columns. */
declare(strict_types=1);

use UnrealDb\Catalog\Infrastructure\Metadata\BlockedCompressedMetadataContainer;
use UnrealDb\Catalog\Infrastructure\Metadata\CatalogUnrealIdentityHash;
use UnrealDb\Catalog\Infrastructure\Persistence\PdoUe3VerifyImportProjectionResolver;

$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
require_once $root . '/bootstrap/autoload.php';

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "pdo_sqlite is required.\n");
    exit(2);
}
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE ue_games(id INTEGER PRIMARY KEY,slug TEXT NOT NULL)');
$db->exec('CREATE TABLE ue_files(id INTEGER PRIMARY KEY,game_id INTEGER NOT NULL,package_name TEXT,package_version INTEGER,scan_status TEXT)');
$db->exec('CREATE TABLE ue_file_metadata(file_id INTEGER PRIMARY KEY,format_version INTEGER,codec INTEGER,compressed_size INTEGER)');
$db->exec('CREATE TABLE ue_terms(id INTEGER PRIMARY KEY,value_hash BLOB,value_length INTEGER,value_prefix TEXT,is_overflow INTEGER)');
$db->exec('CREATE TABLE ue_export_lookup(file_id INTEGER,export_index INTEGER,object_term_id INTEGER,PRIMARY KEY(file_id,export_index))');
$db->exec("INSERT INTO ue_games VALUES(6,'ut3')");
$db->exec("INSERT INTO ue_files VALUES(42,6,'Pkg',512,'verified')");
$snapshot = [
    'file' => ['id'=>42,'game_id'=>6,'package_name'=>'Pkg','original_name'=>'Pkg.ut3'],
    'names' => [],
    'imports' => [],
    'exports' => [[
        'export_index'=>0,'class_name'=>'Core.Class','object_name'=>'PublicObject','outer_index'=>0,
        'local_path'=>'PublicObject','full_path'=>'Pkg.PublicObject','object_flags'=>'17179869184',
        'serial_size'=>10,'serial_offset'=>20,'class_index'=>0,'super_index'=>0,'template_index'=>0,
        'object_name_index'=>0,'verify_class_package'=>'','verify_class_name'=>'','verify_identity_hash'=>'',
        'path_hash_ci'=>CatalogUnrealIdentityHash::objectPathHex('PublicObject'),
    ]],
    'paths' => ['imports'=>[],'exports'=>[0=>['local'=>'PublicObject']]],
    'dependencies' => [],
];
$storage = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uedb-ue3-source-' . bin2hex(random_bytes(6));
$path = BlockedCompressedMetadataContainer::path($storage, 6, 42);
$built = BlockedCompressedMetadataContainer::buildToFile($snapshot, $path, 100);
$insertMeta = $db->prepare('INSERT INTO ue_file_metadata VALUES(?,?,?,?)');
$insertMeta->execute([42,BlockedCompressedMetadataContainer::FORMAT_VERSION,BlockedCompressedMetadataContainer::CODEC_BLOCK_GZIP,(int)$built['compressed_size']]);
$insertTerm = $db->prepare('INSERT INTO ue_terms VALUES(?,?,?,?,0)');
$insertTerm->execute([1,md5('PublicObject',true),strlen('PublicObject'),'PublicObject']);
$db->exec('INSERT INTO ue_export_lookup VALUES(42,0,1)');
$consumer = [
    ['import_index'=>0,'class_package'=>'Core','class_name'=>'Package','object_name'=>'Pkg','outer_index'=>0],
    ['import_index'=>1,'class_package'=>'Core','class_name'=>'Class','object_name'=>'PublicObject','outer_index'=>-1],
];
$matches = PdoUe3VerifyImportProjectionResolver::resolveProvider($db, 42, $consumer, [1], $storage);
$ok = ($matches[1] ?? null) === 0;
$result = [
    'ok' => $ok,
    'checks' => [[
        'check' => 'ut3_verifyimport_identity_comes_from_uedb4',
        'ok' => $ok,
        'detail' => 'SQL supplies only the ObjectName/export-index accelerator; class, outer and 64-bit RF_Public come from UEDB4.',
    ]],
];
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
@unlink($path);
@rmdir(dirname($path));
@rmdir(dirname(dirname($path)));
@rmdir(dirname(dirname(dirname($path))));
exit($ok ? 0 : 3);
