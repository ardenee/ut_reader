<?php
declare(strict_types=1);
/**
 * B004: independent source-derived FPackageObjectIndex value oracle.
 * Intentionally does not use Uedb5ZenPackageReader for reference decoding.
 * The production parser is called by reflection only after expected result
 * is constructed from Epic AsyncLoading2.h bit fields / import reference.
 */
if(PHP_SAPI!=='cli'){exit(2);}
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenPackageReader;
$checks=[];$fail=[];
$check=static function(string $label,bool $ok)use(&$checks,&$fail):void{
 $checks[$label]=$ok;if(!$ok)$fail[]=$label;
};
$root=(string)(getenv('UNREALDB_EPIC_SOURCE_ROOT')?:'');
$source=$root.'/Engine/Source/Runtime/CoreUObject/Public/Serialization/AsyncLoading2.h';
$raw=is_file($source)?file_get_contents($source):false;
$check('source_583_header_hash_pinned',is_string($raw)
 &&hash('sha256',$raw)==='088f00a8c970458e4bb717490ac4d50361d635ddf8cb0b7b1a5c93ff4d9feef2');
$checksSource=[
 'two_bit_type_shift_62'=>'static constexpr uint64 IndexBits = 62ull;',
 'null_sentinel_all_ones'=>'static constexpr uint64 Invalid = ~0ull;',
 'export_tag_zero'=>'\t\tExport,',
 'script_tag_one'=>'\t\tScriptImport,',
 'package_tag_two'=>'\t\tPackageImport,',
 'null_tag_three'=>'\t\tNull,',
 'package_import_high32_low32'=>'GetImportedPackageIndex()) << 32 | PackageImportRef.GetImportedPublicExportHashIndex()',
 'imported_ref_extract_high30_low32'=>'(TypeAndId & IndexMask) >> 32',
 'raw_export_conversion_uint32'=>'return uint32(TypeAndId);',
];
foreach($checksSource as $name=>$fragment){
 $check('epic_'.$name,is_string($raw)&&str_contains($raw,str_replace('\\t',chr(9),$fragment)));
}
// The byte decoder is shared by main/optional ImportMap, CellImportMap,
// and all four Outer/Class/Super/Template fields of an ExportMapEntry.
$production=dirname(__DIR__).'/src/Infrastructure/Metadata/';
$readerSource=(string)file_get_contents($production.'Uedb5ZenPackageReader.php');
$builderSource=(string)file_get_contents($production.'Uedb5Ue5ZenIoStoreSnapshotBuilder.php');
$resolverSource=(string)file_get_contents($production.'Uedb5Ue5ZenDependencyResolver.php');
$check('all_five_object_index_decoder_call_sites_remain_mapped',
    preg_match_all('/self::decodeObjectIndex\\s*\\(/',$readerSource)===5);
$check('import_and_cell_import_spans_share_the_same_decoder',
    substr_count($readerSource,'self::readObjectIndexSpan(')===2
    &&str_contains($readerSource,"'CellImportMap'"));
$check('all_four_export_object_index_fields_are_decoded',
    str_contains($readerSource,'.OuterIndex')
    &&str_contains($readerSource,'.ClassIndex')
    &&str_contains($readerSource,'.SuperIndex')
    &&str_contains($readerSource,'.TemplateIndex'));
$check('main_optional_and_cell_index_metadata_remain_separately_classified',
    str_contains($builderSource,'classifiedObjectIndexes')
    &&str_contains($builderSource,"'PackageImport'")
    &&str_contains($builderSource,"'ScriptImport'")
    &&str_contains($builderSource,"'cell_verse'"));
$check('dependency_resolver_uses_original_decoded_object_index',
    str_contains($resolverSource,"['object_index']")
    &&str_contains($resolverSource,'ToPackageImportRef')===false
    &&str_contains($resolverSource,'provider_public_export_hash'));
$oracle=static function(string $rawHex,array $ids,array $hashes,?int $exports):array {
 $hex=strtoupper($rawHex);
 $hi=(int)hexdec(substr($hex,0,8));
 $lo=(int)hexdec(substr($hex,8,8));
 $tag=($hi>>30)&3;
 $high=$hi&0x3FFFFFFF;
 $value=sprintf('%08X%08X',$high,$lo);
 if($hex==='FFFFFFFFFFFFFFFF'){
  return ['type'=>'Null','type_tag'=>3,'value_u62'=>'3FFFFFFFFFFFFFFF'];
 }
 if($tag===3){throw new RuntimeException('reserved non-null type');}
 $result=['type'=>['Export','ScriptImport','PackageImport'][$tag],
  'type_tag'=>$tag,'value_u62'=>$value];
 if($tag===0){
  if($high!==0||($exports!==null&&$lo>=$exports)){
   throw new RuntimeException('Export id cannot select a local export');
  }
  $result['local_export_index']=$lo;
 }elseif($tag===1){
  $result['script_import_hash']=$value;
 }else{
  if(!isset($ids[$high],$hashes[$lo])){throw new RuntimeException('package import lookup out of bounds');}
  $result['imported_package_index']=$high;
  $result['imported_public_export_hash_index']=$lo;
  $result['provider_package_id']=$ids[$high];
  $result['provider_public_export_hash']=$hashes[$lo];
 }
 return $result;
};
$method=new ReflectionMethod(Uedb5ZenPackageReader::class,'decodeObjectIndex');
$ids=['1111222233334444','AAAABBBBCCCCDDDD','FEDCBA9876543210'];
$hashes=['0123456789ABCDEF','0000000000000000','FFFFFFFFFFFFFFFF'];
$cases=[
 ['null_exact_sentinel','FFFFFFFFFFFFFFFF',3],
 ['export_first','0000000000000000',3],
 ['export_last','0000000000000002',3],
 ['script_zero','4000000000000000',3],
 ['script_all_low62_bits','7FFFFFFFFFFFFFFF',3],
 ['script_high_bit_boundary','6000000000000001',3],
 ['package_zero_zero','8000000000000000',3],
 ['package_one_hash_two','8000000100000002',3],
 ['package_two_hash_one','8000000200000001',3],
];
foreach($cases as [$name,$hex,$exports]){
 try {
  $reference=$oracle($hex,$ids,$hashes,$exports);
  $actual=$method->invoke(null,$hex,$name,$ids,$hashes,$exports);
  $match=true;
  foreach($reference as $key=>$expected){
   if(!array_key_exists($key,$actual)||$actual[$key]!==$expected){$match=false;}
  }
  $check('reference_'.$name,$match&&count($reference)>0);
 }catch(Throwable $e){$check('reference_'.$name,false);}
}
$invalid=[
 ['reserved_type_zero','C000000000000000',3],
 ['reserved_type_near_null','FFFFFFFFFFFFFFFE',3],
 ['export_out_of_bounds','0000000000000003',3],
 ['export_62bit_noncanonical','0000000100000000',3],
 ['package_index_missing','8000000300000000',3],
 ['package_hash_missing','8000000000000003',3],
 ['package_index_high30','BFFFFFFF00000000',3],
];
foreach($invalid as [$name,$hex,$exports]){
 $oracleRejected=false;$readerRejected=false;
 try {$oracle($hex,$ids,$hashes,$exports);}catch(RuntimeException){$oracleRejected=true;}
 try {$method->invoke(null,$hex,$name,$ids,$hashes,$exports);}catch(RuntimeException){$readerRejected=true;}
 $check('malformed_'.$name,$oracleRejected&&$readerRejected);
}
echo json_encode(['ok'=>$fail===[],'independent_reference'=>true,
 'epic_source_file'=>'AsyncLoading2.h','checks'=>$checks,'failures'=>$fail],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
