<?php
declare(strict_types=1);
/** Verify UE2-owned UT2004 legacy wire compatibility without loading UE1 reader. */
require_once dirname(__DIR__,2).'/catalog/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2LegacyCompatibilityPackageReader;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5UE1TableSnapshotBuilder;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5UE2LegacyCompatibilityTableSnapshotBuilder;
$path='L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Textures/Female2Skins.utx';
if (!is_file($path)) throw new RuntimeException('Genuine legacy-format fixture absent');
$compat=new CatalogUE2LegacyCompatibilityPackageReader($path);
if (class_exists(CatalogUE1PackageReader::class,false)) throw new RuntimeException('UE2 compatibility loading imported the UE1 parser!');
$loaded=array_filter(get_included_files(), static fn(string $f):bool=>str_contains(str_replace('\\','/',$f),'/CatalogUE1PackageReader.php'));
if ($loaded) throw new RuntimeException('UE1 reader file included by UE2: '.implode(',',$loaded));
if ($compat->validatePackage() !== []) throw new RuntimeException('UE2-owned legacy reader rejected genuine 1998 fixture');
$file=['id'=>1,'game_id'=>5,'package_name'=>'Female2Skins','original_name'=>basename($path)];
$profile=['label'=>'UT2004 UE1 compatibility','policy'=>'ue1-ut2004-legacy-texture-profile-compatible','schema_prefix'=>'ue1.ut2004.compat'];
$ue2=Uedb5UE2LegacyCompatibilityTableSnapshotBuilder::build($compat,$file,$profile);
$ue1=Uedb5UE1TableSnapshotBuilder::build(new CatalogUE1PackageReader($path),$file,$profile);
if ($ue2 !== $ue1) throw new RuntimeException('UT2004 compatible UEDB5 snapshot changed during engine independence');
echo json_encode(['ut2004_legacy_format_sample'=>'original Unreal 1998 v61 .utx, format-equivalent only',
 'bytes_sha256'=>hash_file('sha256',$path),'parser_engines_independent'=>true,
 'ue1_module_loaded_when_ue2_compat_parsed'=>false,
 'ue2_compat_snapshot_sha256'=>hash('sha256',serialize($ue2)),
 'all_generated_fields_identical'=>true,
 'ut2004_profile_selection_database_tested'=>false],JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT),PHP_EOL;
