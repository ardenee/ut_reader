<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(2);
require dirname(__DIR__).'/bootstrap/autoload.php';

use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ScriptImportPathHasher as Hasher;
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5SqlProjectionContract as Sql;

$checks=[];$fails=[];$drivers=[];$base=dirname(__DIR__);
$check=static function(string $name,bool $ok)use(&$checks,&$fails):void{
 $checks[$name]=$ok;if(!$ok)$fails[]=$name;
};
foreach([
 'native'=>'verify-uedb5-b013-script-hash-native-source.php',
 'index'=>'verify-uedb5-b004-operation-acceptance.php',
 'sql'=>'verify-uedb5-b003-script-hash-sql-projection.php',
 'registration'=>'verify-uedb5-b003-script-registration-source.php'
] as $key=>$script){
 $lines=[];$status=127;
 exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($base.'/bin/'.$script).' 2>&1',$lines,$status);
 $result=json_decode(implode("\n",$lines),true);
 $drivers[$key]=is_array($result)?$result:[];
 $check('hash_driver_'.$key.'_passes',$status===0&&($drivers[$key]['ok']??false)===true);
}
$has=static fn(string $d,string $k):bool=>($drivers[$d]['checks'][$k]??false)===true;
$check('hash_actual_epic_cityhash_98_native_vectors',
 $has('native','hash_native_ascii_unicode_all_cityhash_lengths_match')
 &&($drivers['native']['details']['vector_count']??null)===98);
$check('hash_tchar_unicode_ascii_only_lower_verified',
 $has('native','hash_epic_to_lower_only_ascii_not_unicode_fold')
 &&$has('native','hash_non_ascii_accent_casing_preserved_as_source_tchar'));
$check('hash_utf16_vs_verse_utf8_and_mask_verified',
 $has('native','hash_epic_verse_bytes_distinct_from_script_unicode')
 &&$has('native','hash_epic_mask_clears_upper_two_bits_both_branches'));
$check('hash_objectredirector_and_authentic_getrecipes_verified',
 Hasher::fromObjectPath('/Script/CoreUObject.ObjectRedirector')==='1D39669A89BAECB6'
 &&$has('native','hash_real_getrecipes_native_and_cooked_identity'));
$check('hash_script_sql_key_kind_distinct_from_public_export',
 Sql::OBJECT_KEY_SCRIPT_IMPORT_HASH===3
 &&Sql::OBJECT_KEY_SCRIPT_IMPORT_HASH!==Sql::OBJECT_KEY_PUBLIC_EXPORT_HASH
 &&$has('sql','d027_authentic_cooked_script_hash_index_payload'));
$check('hash_invalid_utf8_and_verse_raw_handled',
 $has('native','hash_invalid_utf8_tchar_conversion_fails_closed')
 &&$has('native','hash_invalid_utf8_verse_raw_bytes_hashed_without_assumptions'));
$root=dirname(__DIR__,2);$files=0;$dynamic=[];$serialized=[];
foreach(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/catalog/src',FilesystemIterator::SKIP_DOTS)) as $file){
 if(!$file->isFile()||strtolower($file->getExtension())!=='php')continue;
 $files++;$src=(string)file_get_contents($file->getPathname());
 $rel=str_replace('\\','/',substr($file->getPathname(),strlen($root)+1));
 if(str_contains($src,'Uedb5Ue5ScriptImportPathHasher::'))$dynamic[]=$rel;
 if(str_contains($src,"'script_import_hash'"))$serialized[]=$rel;
}
$check('hash_exhaustive_production_php_source_inventory',
 $files>=500
 &&$dynamic===['catalog/src/Infrastructure/Metadata/Uedb5Ue5ScriptRuntimeEnumerationAudit.php']
 &&in_array('catalog/src/Infrastructure/Metadata/Uedb5Ue5ScriptRuntimeEnumerationAudit.php',$serialized,true)
 &&in_array('catalog/src/Infrastructure/Metadata/Uedb5ZenPackageReader.php',$serialized,true)
 &&in_array('catalog/src/Infrastructure/Metadata/Uedb5Ue5ZenDependencyResolver.php',$serialized,true));
$check('hash_source_object_index_reader_still_passes',
 $has('index','b004_source_defines_complete_type_encoding')
 &&$has('index','b004_decoder_caller_inventory_complete'));
$check('hash_not_a_claim_of_live_uobject_registration',
 ($drivers['native']['epic_live_loader_executed']??true)===false
 &&($drivers['registration']['actual_epic_uobject_registry_executed']??true)===false);
echo json_encode(['ok'=>$fails===[],'operation'=>'UE5-583-SCRIPT-HASH-001',
 'checks'=>$checks,'failures'=>$fails,'php_files_scanned'=>$files,
 'dynamic_hasher_direct_callers'=>$dynamic,'script_import_hash_consumers'=>$serialized,
 'live_epic_registry_executed'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fails===[]?0:1);
