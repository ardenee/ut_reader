#!/usr/bin/env php
<?php
/** One stock compiled v432 direct UStrProperty malformed physical-EOF oracle.
 * Native producer/loader already run once, logs SHA pinned. This verifier
 * MUST NOT re-run compiled engine or repeat historic nested FString cases.
 */
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
require dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
$root=dirname(__DIR__);
$m=json_decode((string)file_get_contents($root.'/compliance/UE1-O06-G5-T99-UOBJECT-DIRECT-STR-PHYSICAL-EOF.json'),true,512,JSON_THROW_ON_ERROR);
$checked=0;$fail=[];
$check=static function(string $name,bool $ok) use (&$checked,&$fail):void {
 $checked++;if(!$ok)$fail[]=$name;
};
$check('method',$m['method']==='T99:UObject::Serialize');
$check('scope',$m['stock_native_success']===true&&$m['stock_native_malformed_error']===true&&$m['prior_native_arrays_reused_no_reruns']===true);
foreach($m['originals'] as $name=>$asset){
 $path=$asset['path'];
 $check('sha_'.$name,is_file($path)&&hash_file('sha256',$path)===$asset['sha256']
    &&filesize($path)===$asset['bytes']);
}
$original=$m['originals']['native_generated_original']['path'];
$mutant=$m['originals']['malformed_one_byte_copy']['path'];
$before=file_get_contents($original);
$after=file_get_contents($mutant);
$at=(int)$m['mutation']['absolute_byte_offset'];
$check('exact_single_native_copy_mutation',
    strlen($before)===strlen($after)
    &&ord($before[$at])===$m['mutation']['before']
    &&ord($after[$at])===$m['mutation']['after']
    &&substr($before,0,$at)===substr($after,0,$at)
    &&substr($before,$at+1)===substr($after,$at+1));
$good=(string)file_get_contents($m['originals']['native_original_loaded_log']['path']);
$bad=(string)file_get_contents($m['originals']['native_mutated_error_log']['path']);
$check('original_compiled_real_loaded_string',
   str_contains($good,'G5_NATIVE_DIRECT_PACKAGE_RESULT ptr=')
   &&str_contains($good,'editor=1 native_error=0')
   &&str_contains($good,'Headline="'.$m['expected_loaded_value'].'"')
   &&str_contains($good,'G5_NATIVE_OBJECT_COUNT 1'));
$check('malformed_compiled_physical_eof',
   str_contains($bad,$m['mutation']['stock_native_error'])
   &&str_contains($bad,'ULinkerLoad::Serialize <- FString<<')
   &&str_contains($bad,'G5_NATIVE_DIRECT_PACKAGE_RESULT ptr=00000000')
   &&str_contains($bad,'native_error=1'));
$pkg=new Package($original);
$check('original_v432_header',(int)$pkg->getHeader()['version']===69);
$read=Tags::inspect($original,$pkg,0,'ut99-v432');
$tags=$read['tags'];
$check('original_native_tag_parsed',$read['status']==='tag_envelope_decoded'
   &&count($tags)===1&&$tags[0]['name']==='Headline'
   &&$tags[0]['type']==='StrProperty');
$check('original_native_policy_matched',
  $tags[0]['native_load_policy']==='native_source_property_SerializeItem');
$check('original_utf8_ansi_text_equals_stock_compiled',
  ($tags[0]['value']['ascii_text']??null)===$m['expected_loaded_value']
  &&($tags[0]['value']['signed_character_count']??null)===21);
$check('original_exact_archive_consumption',
  $read['native_tail_bytes']===0&&$tags[0]['on_disk_value_bytes']===22);
$pkg=new Package($mutant);
$err='';
try{Tags::inspect($mutant,$pkg,0,'ut99-v432');}catch(Throwable $e){$err=$e->getMessage();}
$check('malformed_error_from_physical_stream_not_export',
  $err==='UE1 v432 ULinkerLoad::Serialize physical EOF at archive offset '
     .$m['mutation']['package_physical_eof'].' during direct FString<<');
$check('bad_reference_not_fabricated',!str_contains($err,'SerialSize')
  &&!str_contains($err,'invalid object index'));
$ok=$fail===[];
echo json_encode(['ok'=>$ok,'checks'=>$checked,'passed'=>$checked-count($fail),
 'failures'=>$fail,'direct_native_original_loaded'=>true,
 'compiled_malformed_eof_cursor'=>$m['mutation']['package_physical_eof'],
 'native_reexecutions_by_verifier'=>0,'method_decision'=>'BLOCKED'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
