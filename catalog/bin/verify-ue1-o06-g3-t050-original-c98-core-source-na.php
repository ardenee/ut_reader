<?php
declare(strict_types=1);
/** SOURCE_NA T050: exact frozen original Core.u fixture does not exist.
 * Original 1998 compiled loaded real C98 Core.u function scripts already 96
 * SHA retained. This verifier reads them; never replays an original engine.
 * Mixed relocations are applicable in real Engine.u, separately T057 PASS. */
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$repo=dirname(__DIR__,2).'/';
$f='C:/Temp/UE1-G3-NativeOracle/C98-USA-Retail-CD/retail-source/System/Core.u';
$native=$repo.'catalog/compliance/evidence/o06/20261027-G3-03-C98-MAY17-100-UStruct-96-SCRIPTS-NATIVE-VALUES.json';
$other=$repo.'catalog/compliance/evidence/o06/20261028-G3-T057-original-native-three-families-112pass.json';
$codes=0;$bad=[];$ck=static function(bool $v,string $t)use(&$codes,&$bad):void{$codes++;if(!$v)$bad[]=$t;};
$nat=json_decode((string)file_get_contents($native),true,512,JSON_THROW_ON_ERROR);
$t57=json_decode((string)file_get_contents($other),true,512,JSON_THROW_ON_ERROR);
$ck(($nat['original_May1998_native_nonzero_script_count']??-1)===96,'96 true original C98 Core.u nonzero live compiled buffers');
$ck(($nat['original_May1998_Core_u_native_postload_struct_count']??-1)===100,'original native full 100 UStruct snapshot');
$ck(count($nat['native_records']??[])===100,'100 native records tied to authentic retail Core.u original compiled load');
$ck(($t57['ok']??false)===true&&($t57['checks']??0)===112&&($t57['errors']??['fail'])===[],'independently retained native mixed Engine.u proof T057');
$p=new P($f);$all=0;$both=0;$nameOnly=0;$objectOnly=0;$none=0;$natives=[];
foreach($p->getExports() as $idx=>$ex) {
 try {
  if((int)$ex['classIndex']===0) $r=C::inspect($f,$p,$idx,false,'epic-ue1');
  else $r=N::inspect($f,$p,$idx,T::inspect($f,$p,$idx,'epic-ue1'),'epic-ue1');
 }catch(Throwable $err){$bad[]='parsing original export index '.$idx.' '.$err->getMessage();continue;}
 $a=$r['script_archive']??null;
 if(!is_array($a))continue;
 $all++;
 $types=array_column($a['native_load_relocation_preview']??[],'kind');
 $n=in_array('fname',$types,true);$o=in_array('object_pointer',$types,true);
 if($n&&$o){$both++;$natives[]=['export'=>$idx,'name'=>$ex['objectNameText']];}
 elseif($n)$nameOnly++;elseif($o)$objectOnly++;else $none++;
}
$ck(count($p->getExports())===458,'genuine C98 Core.u export inventory exactly 458');
$ck($all===109,'all genuine retail C98 Core.u 109 script archive sequences inspected, zero skipped');
$ck($both===0,'NO original genuine C98 Core.u one script with both FName and UObject relocations');
$ck($nameOnly+$objectOnly+$none===$all,'complete disjoint relocation classifications');
$ck($both===0&&($t57['true_original_compiled_nested_script_samples']??0)===3,'actual C98 mixed script bytecode validation performed in genuine Engine.u under separately frozen accepted T057, not fabricated original Core.u');
echo json_encode(['ok'=>$bad===[],'checks'=>$codes,'errors'=>$bad,
 'T050'=>'SOURCE_NA','source_NA_exact_fixed_fixture'=>'original retail C98 Core.u 0 mixed-relocation scripts across 109, so requested specimen does not exist',
 'retail_original_Core_u_export_count'=>458,'original_Core_u_script_archive_count'=>109,'mixed_name_and_object_scripts'=>0,
 'fname_only_scripts'=>$nameOnly,'object_only_scripts'=>$objectOnly,'no_typed_relocation_scripts'=>$none,
 'original_compiled_C98_Core_live_script_count'=>96,
 'live_mixed_relocation_behavior_in_original_C98_Engine_u_owned_by'=>'T057',
 'previously_ACCEPTED_original_native_sessions_replayed'=>0,'overall_G3_acceptance'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($bad===[]?0:1);
