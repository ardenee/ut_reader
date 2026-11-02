#!/usr/bin/env php
<?php
/** G3-04 actual original compiled C227i 2012 and C227k 2026 method values. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as S;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicFunctionLinkReader as Link;
$root=dirname(__DIR__,2);
$e=$root.'/catalog/compliance/evidence/o06/20261027-';
$ci='C:/Temp/UE1-G3-NativeOracle/original227i/System/';
$ck='C:/Temp/UnrealDB/O06/Native-227k/System/';
$sdk='L:/Source/Games/Unreal/OldUnreal-UnrealPatch227k-SDK-Windows/Core/Inc/';
$hashes=[
 [$ci.'Core.dll','287b5eb68c3a098b11eca55c84f7f9d5e29db51c7ec49475099b871044881897'],
 [$ci.'Core.u','bd42f3dff0a9ef5521623d45c29b51104b1ec366cf27b89bdeeb888b9ba3973b'],
 [$ci.'UCC.exe','69813336c9fd278f30939abde10477a37c5da951b2be8ca0c394b5bfd5cd6672'],
 [$ck.'Core.dll','c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194'],
 [$ck.'Core.u','28ab495d012200003686df0322b61b24ba9a99e5b178b92a635d76853e8acc1c'],
 [$ck.'UCC.exe','8377f0e1a8adcfb087a4ada317ff5dedbab406a7888226af904aca84908d907a'],
 [$sdk.'UnMath.h','2c53261003b3176e63fe7f8b9c47e7f8013cb460e7bdc6fb4433a895b49c91e0'],
 [$sdk.'UnType.h','2c78a2c6403ea68f3cc59d7e9d56005c740293aaa8ac22703b1dcef2fcb9543e'],
 [$e.'G3-04-C227i-original-function-state.log','86e1a164b36400e9a860c0c89c14ef1c1c59c1d3c4dcda9b84efcbc86e642384'],
 [$e.'G3-04-C227k-original-function-state.log','5b5170f382a48bccde9433a6cc554aaea2971335b9df50a70a716809faf5ce70'],
 [$e.'G3-04-C227i-original-function-state.cmd','1522e2c180db85ce2004bd59c31f597c4f39167f48fc4d7cf5dee1a8efe80d0b'],
 [$e.'G3-04-C227k-original-function-state.cmd','eacd42b8688e002f4086fdadd01378af56530de48e1a85976f3f10e3268fc817'],
 [$e.'G3-04-C227i-original-function-state-parsed.json','4f5d0fcb4efb462eff046f8eb32a18e7be0f20c46c24c5da64260b935eb46c33'],
 [$e.'G3-04-C227k-original-function-state-parsed.json','f1c50b2339bba57635cf8fe57ea2aa5dad15f74e9d3bf61b33a1802d5e5db351'],
 [$e.'G3-04-C227-parse-function-state.py','825088c4ac064c08e98daa0c8e89e81111c3b8228329e8e9580084ebb0091500'],
 [$e.'G3-04-C227i-function-state-disassembly.log','c94fcad89abe99c0402db22de3de4fbcd65901ef97917483e9e5e5626609701c'],
 [$e.'G3-04-C227k-function-state-disassembly.log','ae7e03f28f4b24b7d2c0c4da5e2ca301b0aa701f4cf0434d5cd7c3d7965dd1e6'],
];
$checks=0;$failed=[];$functions=0;$stored=0;$loaded=0;$states=0;$skipped227iState=0;$net=0;
$assert=static function(bool $condition,string $context)use(&$checks,&$failed):void{
 ++$checks;if(!$condition&&count($failed)<22)$failed[]=$context;
};
foreach($hashes as [$path,$hash])$assert(is_file($path)&&hash_file('sha256',$path)===$hash,'SHA256 '.basename($path));
$disI=(string)file_get_contents($e.'G3-04-C227i-function-state-disassembly.log');
$disK=(string)file_get_contents($e.'G3-04-C227k-function-state-disassembly.log');
$assert(str_contains($disI,'Core!UFunction::Serialize+0x110:')
 &&str_contains($disI,'1011847d')&&str_contains($disI,'Core!UState::Serialize:'),
 'real compiled C227i post-LOAD source member traversal');
$assert(str_contains($disK,'Core!UFunction::Serialize+0x16b:')
 &&str_contains($disK,'and     eax,580h')
 &&str_contains($disK,'cmp     eax,180h')
 &&str_contains($disK,'Core!UFunction::Serialize+0x1be:'),
 'real compiled C227k 0x580 out-parameter indirection ABI and loaded terminus');
$math=(string)file_get_contents($sdk.'UnMath.h');
$assert(str_contains($math,'FLOAT X, Y, Z, W;')&&str_contains($math,'class FCoords')
 &&str_contains($math,'TArray<FInterpCurvePoint> Points;'),
 'source-proven original Core 227k FQuat FCoords InterpCurve layouts');
$assert(S::selectFromHeader(['version'=>69,'licenseeVersion'=>0],S::UNREAL_V227I)===S::UNREAL_V227
 &&S::selectFromHeader(['version'=>69,'licenseeVersion'=>227],S::UNREAL_V227)===S::UNREAL_V227,
 'original v227i 69/0 and v227k 69/227 share Unreal source with distinct compiled runtime ABIs');
$ambiguous=false;
try{S::selectFromHeader(['version'=>69,'licenseeVersion'=>0],null);}
catch(InvalidArgumentException){$ambiguous=true;}
$assert($ambiguous,'original 2012 69/0 cannot silently identify Unreal v227i or UT99');
$assert(S::sourceLayers(69,227,S::UNREAL_V227I)['explicit_runtime_source_overlay']===S::UNREAL_V227I,
 'source layer retains original 2012 compiled runtime provenance');
foreach(['C227i'=>[$ci.'Core.u',S::UNREAL_V227I,223,225],
         'C227k'=>[$ck.'Core.u',S::UNREAL_V227,249,251]] as $tag=>[$path,$profile,$expectedFunctions,$events]){
 $j=json_decode((string)file_get_contents($e.'G3-04-'.$tag.'-original-function-state-parsed.json'),
    true,512,JSON_THROW_ON_ERROR);
 $assert(($j['summary']['parsed']??null)===$events
   &&($j['summary']['events']??null)===$events
   &&($j['summary']['errors']??null)===[],
   'original '.$tag.' unmutated real CDB events, no parse error');
 $p=new P($path);$exports=$p->getExports();$imports=$p->getImports();
 $expectedLicensee=$tag==='C227i'?0:227;
 $assert(($p->getHeader()['version']??null)===69
   &&($p->getHeader()['licenseeVersion']??null)===$expectedLicensee,
   'original genuine '.$tag.' archive version69/licensee'.$expectedLicensee);
 $count=0;
 foreach($j['records'] as $native){
  $idx=$native['idx'];$ex=$exports[$idx]??null;
  $assert(is_array($ex),$tag.' linked genuine original export '.$idx);
  if(!is_array($ex))continue;
  $ciIndex=(int)$ex['classIndex'];
  $class=$ciIndex===0?'Class':($ciIndex<0
      ?($imports[-$ciIndex-1]['objectNameText']??null)
      :($exports[$ciIndex-1]['objectNameText']??null));
  if($native['kind']!=='function'){
   if($tag==='C227i'){
    // The C227i state-site snapshots resolve to Struct exports rather
    // than actual state/class objects. They are NOT falsely certified.
    ++$skipped227iState;
    $assert($class==='Struct',
        'C227i state-site original two Struct origins excluded from native state acceptance');
    continue;
   }
   $assert($class==='Class',$tag.' actual UClass invoking UState::Serialize '.$idx);
   $v=C::inspect($path,$p,$idx,false,$profile);
   foreach(['probe_mask_hex','ignore_mask_hex','state_flags','label_table_offset'] as $key){
    $assert(($v[$key]??null)===$native['native'][$key],
        $tag.' UClass underlying native UState '.$idx.' '.$key);
   }
   ++$states;continue;
  }
  $assert($class==='Function',$tag.' original UFunction '.$idx);
  if($class!=='Function')continue;
  ++$count;++$functions;
  try{$v=N::inspect($path,$p,$idx,T::inspect($path,$p,$idx,$profile),$profile);}
  catch(Throwable $error){$assert(false,$tag.' original function reader exception '.$idx.' '.$error->getMessage());continue;}
  $assert(($v['source_function_compiled_runtime_profile']??null)===$profile
    &&($v['source_function_link_status']??null)==='source_property_layout_computed'
    &&($v['source_function_loaded_fields_computed']??null)===true
    &&($v['runtime_function_param_chain_linked']??null)===false,
    'profile-specific computed values never fabricate runtime UObject linking '.$tag.':'.$idx);
  $keys=['function_flags','native_index','operator_precedence'];
  if(($native['native']['function_flags']&0x40)!==0){$keys[]='rep_offset';++$net;}
  foreach($keys as $field){++$stored;
   $assert(($v[$field]??null)===$native['native'][$field],
     'original '.$tag.' persisted field '.$idx.' '.$field);
  }
  foreach(['num_parms','parms_size','return_value_offset'] as $field){++$loaded;
   $assert(($v['loaded_'.$field]??null)===$native['native'][$field],
      'original '.$tag.' compiled post-LOAD field '.$idx.' '.$field);
  }
 }
 $assert($count===$expectedFunctions,$tag.' correct distinct original compiled function count');
}
$assert($functions===472&&$loaded===1416&&$stored===1416+$net
    &&$states===2&&$skipped227iState===2,
    'C227i/k 472 distinct functions 1416 compiled loaded, 1416 plus NET saved and two actual C227k UClasses');
$pi=new P($ci.'Core.u');$pk=new P($ck.'Core.u');
$sample=824;$old=N::inspect($ci.'Core.u',$pi,$sample,
    T::inspect($ci.'Core.u',$pi,$sample,S::UNREAL_V227I),S::UNREAL_V227I);
$new=N::inspect($ci.'Core.u',$pi,$sample,
    T::inspect($ci.'Core.u',$pi,$sample,S::UNREAL_V227),S::UNREAL_V227);
$assert(($old['loaded_parms_size']??null)===36
    &&($new['loaded_parms_size']??null)===28
    &&($old['source_function_compiled_runtime_profile']??null)===S::UNREAL_V227I,
    'AllFiles original 2012 v227i must not silently take latest 227k ABI');
$neg=Link::recompute($ck.'Core.u',$pk,1061,
    array_replace(N::inspect($ck.'Core.u',$pk,1061,
      T::inspect($ck.'Core.u',$pk,1061,S::UNREAL_V227),S::UNREAL_V227),
      ['children_reference'=>count($pk->getExports())+999]),S::UNREAL_V227);
$assert(($neg['source_function_link_status']??null)==='source_layout_unverified'
   &&!isset($neg['loaded_num_parms']),
   'unresolved source field cannot invent loaded function runtime layout');
$ok=$failed===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'fail'=>$failed,
 'original_2012_C227i_functions'=>223,'original_2026_C227k_functions'=>249,
 'genuine_unique_function_events'=>$functions,'original_real_C227k_state_class_events'=>$states,
 'C227i_struct_state_breakpoint_events_EXCLUDED'=>$skipped227iState,
 'original_method_persisted_fields_matched'=>$stored,
 'new_original_compiled_loaded_function_fields_matched'=>$loaded,
 'all_method_native_function_fields_matched'=>$stored+$loaded,
 'new_native_debugger_runs_reused'=>0,
 'G3_04_Full'=>'OPEN (native SAVE / malformed/version cursor / 469f Engine and other loaded states)'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
