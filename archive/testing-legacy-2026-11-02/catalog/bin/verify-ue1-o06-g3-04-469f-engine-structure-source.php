#!/usr/bin/env php
<?php
/**
 * 469f Engine.u UFunction linked parameter shape - original SDK/persisted
 * source, NOT a new original compiled post-LOAD CDB oracle.
 *
 * Finite original corpus: all Engine.u Function and State export payloads.
 * This verifier does not repeat the sealed v432/469f Core/227 native suites.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicFunctionStructShapeReader as Shape;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as S;

$path='C:/Temp/UnrealDB/O06/Native-469f/System/Engine.u';
$source='L:/Source/Games/UT99/OldUnreal-UTPatch469f-SDK-Windows/udemo/inc/udemoprivate.h';
$checks=0;$failed=[];
$check=static function(bool $ok,string $why)use(&$checks,&$failed):void{
 ++$checks;if(!$ok && count($failed)<20)$failed[]=$why;
};
$check(is_file($path)&&hash_file('sha256',$path)==='6466cc189183b4eaf36d80339ced9dac8b0711d6487930d6b213feccfb26620b','original shipped Engine.u unchanged');
$check(is_file($source)&&hash_file('sha256',$source)==='c66e5370dfbca09abef728a1e0a286e5c160154f471219198d8a1e5e0aa1bc07','original 469f SDK C++ HUDLocalizedMessage 14-member declaration unchanged');
$sourceText=(string)file_get_contents($source);
$check((bool)preg_match('/struct HUDLocalizedMessage\s*\{(.+?)\};/s',$sourceText,$match),'SDK declares native struct');
$names=['Message','Switch','RelatedPRI','OptionalObject','EndOfLife','LifeTime','bDrawing','NumLines','StringMessage','DrawColor','StringFont','XL','YL','YPos'];
$last=0;
foreach($names as $member){$p0=strpos($match[1]??'',$member,$last);$check($p0!==false,'source C++ member in order '.$member);if($p0!==false)$last=$p0+strlen($member);}
$p=new P($path);$exports=$p->getExports();$imports=$p->getImports();
$check(($p->getHeader()['version']??null)===69&&($p->getHeader()['licenseeVersion']??null)===0,'original archive 69/0');
$fn=[];$states=0;$other=0;
foreach($exports as $idx=>$exp){
 $ci=(int)($exp['classIndex']??0);
 if($ci===0)continue; // Class defaults separately G3-05; not UState exports
 $name=$ci<0?($imports[-$ci-1]['objectNameText']??null):($exports[$ci-1]['objectNameText']??null);
 if($name!=='Function'&&$name!=='State')continue;
 try{$value=N::inspect($path,$p,$idx,T::inspect($path,$p,$idx,S::UT99_V469F),S::UT99_V469F);}
 catch(\Throwable $err){$check(false,"Engine $idx thrown: ".$err->getMessage());continue;}
 if($name==='State'){$states++;$check(($value['status']??null)==='native_state_record_complete',"Engine State $idx complete");continue;}
 $fn[$idx]=$value;
 $check(($value['status']??null)==='native_function_record_complete'
   &&($value['source_function_link_status']??null)==='source_property_layout_computed'
   &&($value['source_function_compiled_runtime_profile']??null)===S::UT99_V469F
   &&($value['source_function_loaded_fields_computed']??null)===true
   &&($value['runtime_function_param_chain_linked']??null)===false,
   "Engine UFunction source parameter coverage export $idx");
}
$check(count($fn)===1271&&$states===63,'all 1271 Function and 63 State original Engine.u exports handled, no default-class objects claimed');
$check(Shape::elementSize($path,$p,1012,'HUDLocalizedMessage',S::UT99_V469F)===64,
    'original Core.Color referenced 14-member script/native C++ HUDLocalizedMessage has 64-byte x86 layout');
$check(Shape::elementSize($path,$p,1012,'HUDLocalizedMessage',S::UT99_V432)===null,
    '469f C++-specific script structure not inferred for unverified v432 loader');
$check(Shape::elementSize($path,$p,1011,'HUDLocalizedMessage',S::UT99_V469F)===null,
    'wrong original struct index cannot be accepted on name alone');
foreach([4106=>['ClearMessage',1,64],4108=>['CopyMessage',2,128]] as $idx=>[$name,$num,$size]){
 $v=$fn[$idx]??[];
 $check(($exports[$idx]['objectNameText']??null)===$name,'actual original Engine.u method '.$name);
 $check(($v['loaded_num_parms']??null)===$num,'original source '.$name.' NumParms '.$num);
 $check(($v['loaded_parms_size']??null)===$size,'original SDK '.$name.' ParmsSize '.$size);
 $check(($v['loaded_return_value_offset']??null)===65535,'original '.$name.' no ReturnParm');
}
echo json_encode(['ok'=>$failed===[],'assertions'=>$checks,'failed'=>$failed,
  'original_469f_engine_functions'=>count($fn),'original_469f_engine_states'=>$states,
  'newly_source_resolved_functions'=>['ClearMessage'=>4106,'CopyMessage'=>4108],
  'HUDLocalizedMessage_x86_bytes'=>64,
  'original_compiled_postload_Engine_oracle'=>'NOT EXECUTED; remains unverified',
  'previous_sealed_native_test_runs'=>0],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed===[]?0:1);
