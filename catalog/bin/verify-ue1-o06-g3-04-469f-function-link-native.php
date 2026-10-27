#!/usr/bin/env php
<?php
/** UT99 469f shipped C++ UFunction/UState native post-load comparison. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicFunctionLinkReader as Link;
$r=dirname(__DIR__,2);$ev=$r.'/catalog/compliance/evidence/o06/20261027-';
$sys='C:/Temp/UnrealDB/O06/Native-469f/System/';
$hashes=[
 [$sys.'Core.dll','80ac3341f9826e227146d4ce3ecb34fa7f4ebd0fba4986e9b1478b07086c57d2'],
 [$sys.'Core.u','7b2d4962cb82dda81d522361ee9a1008605e35ba0aacbccd0d53d3b9854b73e0'],
 [$sys.'Engine.u','6466cc189183b4eaf36d80339ced9dac8b0711d6487930d6b213feccfb26620b'],
 [$sys.'UCC.exe','57d23855ca3f45a203458ccf737bae6d62c15c15f3de012c81f2bed12c4998ae'],
 ['L:/Source/Games/UT99/OldUnreal-UTPatch469f-SDK-Windows/Core/Inc/UnClass.h',
   '4258b41de0b688afd6e0b72c593f90cb0298a90c1eedefe6b4a4b47bcb91024f'],
 ['L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Core/Src/UnClass.cpp',
   '6563392fa15fb73d05b52267a00da5034651c47644f310857cc86f7b045f6380'],
 [$ev.'G3-04-T99-469f-function-state-original.log','c2298072e56daa1b961dcaa87e7f09812723e7b9e4f94025711e9bb488499df7'],
 [$ev.'G3-04-T99-469f-function-state-original.cmd','8689bdaeba8fdc10a63b1a3b26b4261876c2738c7fdc6985b5c3c852abc358db'],
 [$ev.'G3-04-T99-469f-function-state-parse.py','a64ffb2fc4335f5222ba2e239d420683d2446b1567649d9da44aeaa1b1ad0900'],
 [$ev.'G3-04-T99-469f-function-state-parsed.json','a1bd383d37fecec53d34dd420b28d28d7b8501737534f88c7807a82b91a6b98c'],
 [$ev.'G3-04-T99-469f-function-state-disasm.log','621557b3083df7f9f7a47a0b381f102d173ce1bf7b101b75b2c1087ef946e210'],
];
$checks=0;$bad=[];$fields=0;$matched=0;$functions=0;$states=0;$linked=0;$originalKinds=[];
$check=static function(bool $ok,string $name)use(&$checks,&$bad):void{
 ++$checks;if(!$ok&&count($bad)<20)$bad[]=$name;
};
foreach($hashes as [$path,$hash])$check(is_file($path)&&hash_file('sha256',$path)===$hash,'source/binary/evidence SHA '.basename($path));
$dis=(string)file_get_contents($ev.'G3-04-T99-469f-function-state-disasm.log');
$check(str_contains($dis,'Core!UFunction::Serialize+0xb8:')
 &&str_contains($dis,'Core!UFunction::Serialize+0xe3:')
 &&str_contains($dis,'Core!UFunction::Serialize+0x118:')
 &&str_contains($dis,'Core!UState::Serialize:'),
    'original compiled 469f UFunction param precomputation and UState serializer');
$original=(string)file_get_contents($ev.'G3-04-T99-469f-function-state-original.log');
$check(preg_match_all('/^G304_T99_469F_UFUNCTION_LOADED\s*$/m',$original)===168
 &&preg_match_all('/^G304_T99_469F_USTATE_LOADED\s*$/m',$original)===2,
 'real original 469f native original function and UClass counts');
$data=json_decode((string)file_get_contents($ev.'G3-04-T99-469f-function-state-parsed.json'),true,512,JSON_THROW_ON_ERROR);
$check(($data['summary']['events']??null)===170&&($data['summary']['parsed']??null)===170
 &&($data['summary']['errors']??null)===[],'native CDB parser exactly 170 with no errors');
$file=$sys.'Core.u';$p=new P($file);$exports=$p->getExports();$imports=$p->getImports();
$check(($p->getHeader()['version']??null)===69,'469f genuine Core.u version69');
foreach($data['records'] as $i=>$row){
 $check($row['linker']===105681488,'actual single original linker identity '.$i);
 $idx=$row['idx'];$x=$exports[$idx]??null;
 $check(is_array($x),'genuine original export '.$i);
 if(!is_array($x))continue;
 $ci=(int)$x['classIndex'];
 $name=$ci===0?'Class':($ci<0?($imports[-$ci-1]['objectNameText']??null):($exports[$ci-1]['objectNameText']??null));
 $function=$row['kind']==='function';
 $check($function?$name==='Function':$name==='Class','method class '.$i);
 try{
  $v=$ci===0?C::inspect($file,$p,$idx,false,'ut99-v469f')
   :N::inspect($file,$p,$idx,T::inspect($file,$p,$idx,'ut99-v469f'),'ut99-v469f');
 }catch(Throwable $e){$check(false,'native record parser '. $i.' '.$e->getMessage());continue;}
 $originalKinds[$name]=($originalKinds[$name]??0)+1;
 $keys=$function?['function_flags','native_index','operator_precedence']:
  ['state_flags','label_table_offset','probe_mask_hex','ignore_mask_hex'];
 if($function &&($row['native']['function_flags']&0x40))$keys[]='rep_offset';
 foreach($keys as $key){++$fields;
  if(($v[$key]??null)===$row['native'][$key])$matched++;
  $check(($v[$key]??null)===$row['native'][$key],'persisted '.$i.' '.$key);
 }
 if($function){
  ++$functions;
  $check(($v['source_function_link_status']??null)==='source_property_layout_computed'
    &&($v['source_function_loaded_fields_computed']??null)===true
    &&($v['runtime_function_param_chain_linked']??null)===false,
    '469f source-verified values without fabricated runtime pointers '.$i);
  foreach(['num_parms','parms_size','return_value_offset'] as $key){
   ++$fields;
   if(($v['loaded_'.$key]??null)===$row['native'][$key])$matched++;
   $check(($v['loaded_'.$key]??null)===$row['native'][$key],'original post-LOAD '.$i.' '.$key);
   ++$linked;
  }
 }else ++$states;
}
$check($originalKinds===['Function'=>168,'Class'=>2]
 ||($functions===168&&$states===2),'source kind/record count');
$check($functions===168&&$states===2&&$fields===1016&&$matched===1016
 &&$linked===504,'168 real functions 504 computed + 512 persisted =1016 native exact');
$rand=N::inspect($file,$p,629,T::inspect($file,$p,629,'ut99-v469f'),'ut99-v469f');
$invalid=Link::recompute($file,$p,629,array_replace($rand,['children_reference'=>-1]),
    'ut99-v469f');
$check(($invalid['source_function_link_status']??null)==='source_layout_unverified'
 &&!isset($invalid['loaded_num_parms'])
 &&($invalid['runtime_function_param_chain_linked']??null)===false,
 '469f imported-child negative stays explicitly unverified');
$ok=$bad===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'failed'=>$bad,
 'original_compiled_469f_events'=>170,'original_functions'=>168,'original_uclasses'=>2,
 'persisted_and_reconstructed_native_fields'=>$fields,'matches'=>$matched,
 'new_postload_values'=>$linked,'previous_completed_native_runs_reexecuted'=>0,
 'G3_04_full'=>'OPEN (remaining C227/469f Engine, SAVE, malformed branch coverage)'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
