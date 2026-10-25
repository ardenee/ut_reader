#!/usr/bin/env php
<?php
/**
 * UT99 v432 UAnimation whole-method loaded values, archive, and observed
 * malformed-input disposition. Runs NO compiled engine; consumes sealed
 * original native logs and invokes source-current SHA-pinned PHP comparators.
 * This is NOT a FULL verdict while exact native exception cursor remains open.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tag;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$repo=dirname(__DIR__,2);
$manifest=$repo.'/catalog/compliance/UE1-O06-G5-T99-ANIMATION-ONE-METHOD-NATIVE.json';
$m=json_decode((string)file_get_contents($manifest),true,512,JSON_THROW_ON_ERROR);
$checked=0;$errors=[];
$check=static function(string $label,bool $ok)use(&$checked,&$errors):void{
 $checked++;if(!$ok)$errors[]=$label;
};
$pin=static function(array $item,string $label)use($check):void{
 $path=(string)($item['path']??'');
 $ok=is_file($path);
 $check('pinned_exists_'.$label,$ok);
 if(!$ok)return;
 $check('pinned_length_'.$label,filesize($path)===(int)($item['bytes']??-1));
 $check('pinned_sha_'.$label,hash_file('sha256',$path)===(string)($item['sha256']??''));
};
$check('method_identifier',($m['method']??'')==='T99:UAnimation::Serialize');
$check('not_false_full_verdict',($m['verdict']??'')==='BLOCKED'
 &&($m['negative_result']['byte_exact_error_cursor_verified']??true)===false);
foreach($m['original_epic_source'] as $key=>$row)$pin($row,'epic_'.$key);
foreach($m['native_engine_sha'] as $key=>$row)$pin($row,'dll_'.$key);
foreach($m['compiled_probe'] as $key=>$row)$pin($row,'compiled_probe_'.$key);
foreach($m['source_comparison'] as $key=>$row)$pin($row,'comparator_'.$key);
foreach($m['native_cases'] as $i=>$row){
 $pin($row['source_package'],'original_'.$i);
 $pin($row['compiled_loaded_log'],'native_loaded_'.$i);
}
foreach($m['negative_cases'] as $i=>$row){
 $pin($row['source_original'],'negative_source_'.$i);
 $pin($row['corrupted_copied_package'],'mutated_'.$i);
 $pin($row['compiled_native_exception_log'],'native_error_'.$i);
 $source=file_get_contents($row['source_original']['path']);
 $copy=file_get_contents($row['corrupted_copied_package']['path']);
 $rebuilt=$source;
 foreach($row['edits'] as $edit){
  $off=(int)$edit['offset'];$old=(int)$edit['old'];$new=(int)$edit['new'];
  $check('original_mutant_byte_'.$i.'_'.$off,ord($rebuilt[$off])===$old);
  $rebuilt[$off]=chr($new);
 }
 if((int)$row['trim_bytes']>0)$rebuilt=substr($rebuilt,0,-(int)$row['trim_bytes']);
 $check('mutation_changes_only_documented_bytes_'.$i,$copy===$rebuilt);
 $log=file_get_contents($row['compiled_native_exception_log']['path']);
 $check('native_load_error_'.$i,
   str_contains($log,'ANIM_PACKAGE_EXCEPTION')
   &&str_contains($log,'ANIM_END success=0'));
}
$execComparator=static function(array $record)use($check):array{
 $pipes=[];$descs=[
  0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']
 ];
 $p=proc_open([PHP_BINARY,$record['path']],$descs,$pipes);
 if(!is_resource($p))throw new RuntimeException('Cannot start pinned DEV comparator');
 fclose($pipes[0]);
 $stdout=stream_get_contents($pipes[1]);fclose($pipes[1]);
 $stderr=stream_get_contents($pipes[2]);fclose($pipes[2]);
 $code=proc_close($p);
 $check('source_comparator_exit_'.basename($record['path']),$code===0);
 $check('source_comparator_no_errors_'.basename($record['path']),$stderr==='');
 return json_decode(ltrim((string)$stdout,"\xEF\xBB\xBF"),true,512,JSON_THROW_ON_ERROR);
};
$positive=$execComparator($m['source_comparison']['positive_php']);
$negative=$execComparator($m['source_comparison']['negative_php']);
$expected=$m['compiled_positive_result'];
$check('positive_source_current',$positive['ok']===true&&$positive['failed']===[]);
$check('all_genuine_object_values',
 $positive['verified_checks']===$expected['current_php_loaded_value_checks']
 &&count($positive['objects'])===$expected['all_original_animation_objects_loaded']);
$check('all_native_structure_values',
 $positive['verified_native_archive_records']===$expected['native_compared_elements_or_records']
 &&$positive['motion_chunks']===$expected['motion_chunks']
 &&$positive['native_compared_numeric_array_bytes']===$expected['numeric_array_bytes']);
$check('negative_current_source',
 $negative['ok']===true&&$negative['failed']===[]
 &&$negative['matched']===$m['negative_result']['php_rejected']
 &&$negative['total']===$m['negative_result']['native_rejected']);
$check('every_native_negative_compared',
 count($negative['details'])===count($m['negative_cases'])
 &&count(array_filter($negative['details'],static fn($x)=>(bool)($x['matched']??false)))
   ===count($m['negative_cases']));
$inherit=0;
foreach($m['native_cases'] as $row){
 $p=new Package($row['source_package']['path']);
 $headers=$p->getHeader();
 $check('genuine_package_version_'.$inherit,(int)$headers['version']===69);
 $target=pathinfo($row['original'],PATHINFO_FILENAME);
 $expectedIndex=['table'=>23,'EcoTerror'=>6,'d00d'=>7][$target]??null;
 $check('pinned_export_index_'.$inherit,is_int($expectedIndex));
 if($expectedIndex===null)continue;
 $e=$p->getExports()[$expectedIndex];
 $tag=Tag::inspect($row['source_package']['path'],$p,$expectedIndex,'ut99-v432');
 $native=Native::inspect($row['source_package']['path'],$p,$expectedIndex,$tag,'ut99-v432');
 $check('inherited_no_StateFrame_'.$inherit,(((int)$e['objectFlags'])&0x02000000)===0);
 $check('inherited_no_property_tags_'.$inherit,
  ($tag['status']??'')==='tag_envelope_decoded'&&count($tag['tags']??[])===0);
 $check('native_subclass_archive_complete_'.$inherit,
  ($native['status']??'')==='native_animation_record_complete'
  &&($native['extension_bytes']??-1)===0
  &&($native['archive_fields_in_order']??[])===['RefBones','Moves','AnimSeqs']);
 $inherit++;
}
$check('three_inherited_envelopes',$inherit===$m['inherited_envelopes']['verified_original_objects']);
$ok=$errors===[];
echo json_encode([
 'ok'=>$ok,'checks'=>$checked,'passed'=>$checked-count($errors),'failed'=>$errors,
 'original_compiled_loaded_animation_objects'=>count($m['native_cases']),
 'native_source_compared_loaded_value_checks'=>$positive['verified_checks']??0,
 'native_source_compared_record_lines'=>$positive['verified_native_archive_records']??0,
 'actual_native_nested_array_bytes'=>$positive['native_compared_numeric_array_bytes']??0,
 'compiled_original_native_rejected_mutations'=>$negative['matched']??0,
 'original_inherited_empty_UObject_envelopes'=>$inherit,
 'native_engine_reexecuted_by_acceptance'=>false,
 'full_method_verdict'=>'BLOCKED',
 'remaining'=>'Native exact malformed archive cursor/exception origin and other applicable source-legal inherited UObject/variant paths unverified',
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
