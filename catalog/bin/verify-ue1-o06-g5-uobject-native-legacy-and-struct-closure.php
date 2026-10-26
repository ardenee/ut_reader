<?php
/** One source-anchored UObject v432 method-gap verdict. Immutable real stock
 * Core/Engine native load; DOES NOT pretend the allocator-hazard array<String>
 * branch has successful native-loaded values.
 */
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
require dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$m=json_decode(file_get_contents(dirname(__DIR__).'/compliance/UE1-O06-G5-T99-UOBJECT-FIXED-STRING-NATIVE-AND-STRUCT-EOF.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$fail=[];
$ck=static function(string $key,bool $valid)use(&$checks,&$fail):void{
 ++$checks;if(!$valid)$fail[]=$key;
};
$pin=static function(array $item,string $name)use($ck):string{
 $raw=is_file($item['path'])?file_get_contents($item['path']):false;
 $ck('native_SHA256_'.$name,is_string($raw)&&strlen($raw)===$item['bytes']
     &&hash('sha256',$raw)===$item['sha256']);
 return is_string($raw)?$raw:'';
};
$ck('method_source_profile',$m['method']==='T99:UObject::Serialize'
 &&$m['source_profile']==='ut99-v432'&&$m['version']===69);
$src=$pin($m['source_CPP'],'OriginalEpicSource_UnClass');
foreach($m['original_compiled_DLL'] as $k=>$item)$pin($item,'actualCompiled_'.$k);
$before=$pin($m['native_compiled_original_package'],'originalNativeCompiledPackage');
$mutated=$pin($m['original_derived_two_byte_legacy_package'],'OriginalDerivedLegacyFile');
$pin($m['native_compiled_exe'],'stockCompiledProbeExe');
$pin($m['native_compiled_Cpp'],'stockCompiledProbeSource');
$native=$pin($m['native_loaded_log'],'actualCompiledNativeLoadedLog');
$ck('original_v432_source_fixed_to_dynamic_special_case',
 str_contains($src,'Tag.Type==NAME_StringProperty && Property->GetID()==NAME_StrProperty')
 &&str_contains($src,'INT Count, MaxCount=Tag.Size ? Tag.Size+1 : ARRAY_COUNT(Temp);')
 &&str_contains($src,'Temp[Count] = FromAnsi(ACh);'));
$ck('original_and_mutation_same_length',strlen($before)===strlen($mutated)&&strlen($before)===283);
$diff=[];
for($i=0;$i<strlen($before);++$i)if($before[$i]!==$mutated[$i])$diff[]=$i;
$ck('exactly_two_changes_to_true_native_original',$diff===[230,232]);
$ck('source_native_tag_kind_fixed_vs_Str',
 ord($before[230])===0x5d&&ord($mutated[230])===0x57
 &&ord($before[232])===21&&ord($mutated[232])===81);
$ck('actual_v432_stock_compiled_success',
 str_contains($native,'G5_NATIVE_DIRECT_PACKAGE_RESULT ptr=')
 &&str_contains($native,'native_error=0')
 &&str_contains($native,'G5_NATIVE_OBJECT_COUNT 1'));
$ck('actual_loaded_native_value_same',
 str_contains($native,'G5_NATIVE_TAG_VALUE  Headline="'.$m['expected_loaded_value'].'"'));
$path=$m['original_derived_two_byte_legacy_package']['path'];
$p=new P($path);
$ck('native_original_derived_Package_version69',$p->getHeader()['version']===69
 &&$p->validatePackage()===[]);
$sourceResult=T::inspect($path,$p,0,'ut99-v432');
$tags=$sourceResult['tags'];
$ck('one_real_tag',count($tags)===1&&$tags[0]['name']==='Headline');
$tag=$tags[0];
$ck('source_real_legacy_type',$tag['type_id']===7&&$tag['type']==='StringProperty'
 &&$tag['serialized_size']===22);
$ck('true_Epic_loader_does_not_skip_legacy_type_mismatch',
 $tag['native_load_policy']==='native_source_fixed_StringProperty_to_StrProperty_upgrade');
$ck('source_exact_native_archive_read_bytes',
 $tag['on_disk_value_bytes']===22 &&$tag['tag_offset']===229
 &&$tag['value_offset']===232 &&$sourceResult['native_tail_bytes']===0);
$ck('actual_compiled_direct_native_string_value',
 $tag['value_kind']==='legacy_ansi_cstring_bytes'
 &&$tag['value']['ascii_text']===$m['expected_loaded_value']
 &&$tag['value']['nul_terminated']===true
 &&$tag['value']['nul_inserted_in_memory']===false);
$ck('native_fixed_conversion_no_memory_risk',$tag['legacy_fixed_string_native_runtime_risk']===null);
$ck('source_direct_bytes_preserved_from_original',
 $tag['value_sha256']===hash('sha256',substr($mutated,232,22)));
$structSource=$m['source_nested_struct_FString_physical_EOF_fix']['prior_source'];
$tagSource=$pin($structSource,'currentProductionTaggedReader');
$ck('nested_v432_struct_inner_uses_native_physical_EOF',
 str_contains($tagSource,'UE1 v432 ULinkerLoad::Serialize physical EOF at archive offset ')
 &&str_contains($tagSource,".' during nested struct FString<<'")
 &&!str_contains($tagSource,'UE1 v432 struct string exceeds export SerialSize'));
$ck('CPlusPlus_source_method_has_native_delegating_SerializeItem',
 str_contains($src,'void UStruct::SerializeTaggedProperties( FArchive& Ar, BYTE* Data, UClass* DefaultsClass )'));
foreach($m['previous_native_stable_array_and_string_evidence'] as $k=>$item){
 $data=json_decode($pin($item,'historicNative_'.$k),true,512,JSON_THROW_ON_ERROR);
 $ck('source_pinned_prior_native_'.$k,is_array($data)&&$data['method']===$m['method']);
}
$ck('immutable_Epic_DLL_stock_loaded',$m['native_stock_compiled_loaded_success']===true);
$ck('stock_array_FString_does_not_fabricate_success',
 $m['stock_array_FString_nondeterministic_native_loaded_value']==='NOT_VERIFIED'
 &&$m['full_global_method_decision']==='BLOCKED');
$ck('true_one_new_oracle_not_repeat',$m['new_native_executions']===1);
$pass=count($fail)===0;
echo json_encode(['ok'=>$pass,'checks'=>$checks,'passed'=>$checks-count($fail),
 'failed'=>$fail,'original_compiled_stock_loaded_legacy_fstring'=>$m['expected_loaded_value'],
 'nested_struct_FString_EOF_source_origin_corrected'=>true,
 'stock_native_array_FString_loaded_value'=>'NOT_VERIFIED',
 'global_T99_UObject_method'=>'BLOCKED',
 'native_stock_runtime_reexecutions_in_verifier'=>0,
 'prior_seven_FULL_methods_reexecuted'=>0],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($pass?0:1);
