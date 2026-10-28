#!/usr/bin/env php
<?php
/**
 * G3-04 complete authentic UT99 469f Engine.u compiled post-LOAD oracle.
 * Seals 1271 Function, 63 State, 88 Class->UState with 8375 genuine native
 * fields from single fresh Microsoft CDB x86 process on original Core.dll.
 * Does NOT claim to have tested SAVE/error or non-469f branches.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as S;

$root=dirname(__DIR__,2);
$e=$root.'/catalog/compliance/evidence/o06/20261028-G3-04-469f-';
$local='C:/Temp/UnrealDB/O06/Native-469f/System/';
$source='L:/Source/Games/UT99/OldUnreal-UTPatch469f-SDK-Windows/';
$hashes=[
 $local.'Core.dll'=>'80ac3341f9826e227146d4ce3ecb34fa7f4ebd0fba4986e9b1478b07086c57d2',
 $local.'Core.u'=>'7b2d4962cb82dda81d522361ee9a1008605e35ba0aacbccd0d53d3b9854b73e0',
 $local.'Engine.u'=>'6466cc189183b4eaf36d80339ced9dac8b0711d6487930d6b213feccfb26620b',
 $local.'Engine.dll'=>'625dabea278e314714a45c931c98ba6282a9198378499144429aa979a1a0570f',
 $local.'G304469fEngineProbe.exe'=>'b901c14a9a40008ac8c1352b0beb3c8bb132f8151107bb7e4cdabf6b7582d189',
 $source.'Core/Lib/x86/Core.lib'=>'55ae95a080e56015af5e28aeb99637e6afa81063f2ff924b0e7fab6e9b24668a',
 $source.'udemo/inc/udemoprivate.h'=>'c66e5370dfbca09abef728a1e0a286e5c160154f471219198d8a1e5e0aa1bc07',
 $e.'native-engine-load.cpp'=>'f57f91435f5d5c9d4f7734680e119d1b91f9134961a6765784fd6fb065b43fda',
 $e.'native-engine-load-build.ps1'=>'ebe38de6e5dd465c47ef5cb1a4a17b3f919d998469d7bc8983d9fb49e572060a',
 $e.'native-engine-load-build.log'=>'a21c262d411a2f365c781db5abd92b953c61b28d73e44d4bf1567e3b30e48f94',
 $e.'native-engine-load-console.log'=>'f10519e4f43a5396869016c2012bfc1fa3734f4f988fc513b7b82eb17ac48b32',
 $e.'engine-native-load.cmd'=>'14aa0dcf5dd839251221ab64938fa7e11d731c536840651cf9fd80876c02e49b',
 $e.'engine-native-load.log'=>'304dff93c3362b3cfd2df68af328a391c6d2d79d288499cbdfe8e4e47f5de0de',
 $e.'engine-native-parse.py'=>'83c01da21cd2474a4cedc3f90c76ec5375ecf160e0eb32915b2b652be0e7cc27',
 $e.'engine-native-parsed.json'=>'d68f3b978e501a99bdf768a34394e5299151b45e6d1c26e9182ac7f4cf8bca21',
];
$checks=0;$fail=[];$compareFields=0;$matched=0;
$assert=static function(bool $test,string $name)use(&$checks,&$fail):void{
 ++$checks;if(!$test&&count($fail)<30)$fail[]=$name;
};
foreach($hashes as $p=>$sha)$assert(is_file($p)&&hash_file('sha256',$p)===$sha,'SHA-256 unmodified source/native/evidence: '.basename($p));
$build=(string)file_get_contents($e.'native-engine-load-build.ps1');
$cmd=(string)file_get_contents($e.'engine-native-load.cmd');
$log=(string)file_get_contents($e.'engine-native-load.log');
$console=(string)file_get_contents($e.'native-engine-load-console.log');
$assert(str_contains($build,'/Zc:wchar_t-') && str_contains($build,'Core.lib'),
    'original 469f MSVC x86 API ABI and shipped original SDK import library, not a PHP simulator');
// Windows PowerShell Tee-Object preserved the original host console as
// UTF-16LE; keep those SHA-pinned bytes, strip NUL only for ASCII markers.
$assert(str_starts_with($console,"\xff\xfe"),'native host console original UTF-16LE encoding');
$consoleText=str_replace("\0",'',$console);
$assert(str_contains($consoleText,'G304_469F_ENGINE_REAL_COMPILED_LOADER_PACKAGE=')
    &&str_contains($consoleText,'G304_469F_ENGINE_LOADER_RESULT=1'),
    'separate disposable native compiled host genuinely loaded original Engine.u');
$assert(str_contains($cmd,'Core!UFunction::Serialize+0x118')
    &&str_contains($cmd,'Core!UState::Serialize+0x77'),
    'x86 original compiler after-LOAD breakpoints in actual Core.dll');
$assert(preg_match_all('/^G304_469F_ENGINE_ORIGINAL_NATIVE_FUNCTION_POSTLOAD\s*$/m',$log)===1438
    &&preg_match_all('/^G304_469F_ENGINE_ORIGINAL_NATIVE_STATE_POSTLOAD\s*$/m',$log)===153,
    'one compiled engine startup emits 1591 method return records');
$data=json_decode((string)file_get_contents($e.'engine-native-parsed.json'),true,512,JSON_THROW_ON_ERROR);
$s=$data['summary']??[];
$assert(($s['events']??null)===1591&&($s['parsed']??null)===1591
    &&($s['errors']??null)===[], 'all original machine records correctly parsed');
$assert(($s['distinct_linker_export_kind']??null)===1591,'no duplicate native observation counted twice');
$engineLinker=50667088;$coreLinker=50670160;
$p=new P($local.'Engine.u');
$exports=$p->getExports();$imports=$p->getImports();
$assert(count($exports)===5740&&($p->getHeader()['version']??null)===69
    &&($p->getHeader()['licenseeVersion']??null)===0,'genuine original 469f Engine.u file header and exports');
$fn=0;$state=0;$class=0;$net=0;$covered=[];$ignoredCore=0;
foreach($data['records'] as $i=>$r){
 if(($r['linker']??null)===$coreLinker){++$ignoredCore;continue;}
 $assert(($r['linker']??null)===$engineLinker,"correct distinct Engine.u linker identity event $i");
 $idx=(int)$r['idx'];$x=$exports[$idx]??null;
 $assert(is_array($x),"genuine file-backed Engine export $i");
 if(!is_array($x))continue;
 $assert(!isset($covered[$idx]),"one unique original Engine method object $idx");
 $covered[$idx]=true;
 $ci=(int)$x['classIndex'];
 $kind=$ci===0?'Class':($ci<0
    ?($imports[-$ci-1]['objectNameText']??null)
    :($exports[$ci-1]['objectNameText']??null));
 $method=$r['kind'];
 $assert($method==='function'?$kind==='Function':in_array($kind,['State','Class'],true),
    "original compiled method and source class kind $idx");
 if($method==='function')++$fn;elseif($kind==='Class')++$class;else ++$state;
 try {
    $v=$ci===0?C::inspect($local.'Engine.u',$p,$idx,false,S::UT99_V469F)
        :N::inspect($local.'Engine.u',$p,$idx,
            T::inspect($local.'Engine.u',$p,$idx,S::UT99_V469F),
            S::UT99_V469F);
 }catch(\Throwable $exc){$assert(false,"cannot read original real export $idx: ".$exc->getMessage());continue;}
 $keys=$method==='function'
    ?['function_flags','native_index','operator_precedence','loaded_num_parms',
      'loaded_parms_size','loaded_return_value_offset']
    :['probe_mask_hex','ignore_mask_hex','label_table_offset','state_flags'];
 foreach($keys as $key){
    $nativeKey=str_starts_with($key,'loaded_')?substr($key,7):$key;
    $same=($v[$key]??null)===($r['native'][$nativeKey]??null);
    ++$compareFields;if($same)++$matched;
    $assert($same,"true original 469f native field $idx/$key");
 }
 if($method==='function' && (($r['native']['function_flags']&0x40)!==0)){
    ++$net;
    $same=($v['rep_offset']??null)===$r['native']['rep_offset'];
    ++$compareFields;if($same)++$matched;
    $assert($same,"genuine original 469f FUNC_Net RepOffset $idx");
 }
 if($idx===4106||$idx===4108){
    $expect=$idx===4106?[1,64]:[2,128];
    $assert(($v['loaded_num_parms']??null)===$expect[0]
        &&($v['loaded_parms_size']??null)===$expect[1],
        'original compiled ClearMessage/CopyMessage postload confirms fixed script struct size');
 }
}
$assert($fn===1271&&$state===63&&$class===88
    &&count($covered)===1422,'100% original 469f Engine.u 1271 Function + 63 State + 88 UClass native method identities');
$assert($ignoredCore===169,'original Core.u startup 169 method events isolated and not recounted or reverified');
$assert($compareFields===8375 && $matched===8375,
    '8375 genuine ORIGINAL COMPILED postload fields equal reader, zero mismatch');
$ok=$fail===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'failed'=>$fail,
  'original_native_CDB_events'=>1591,
  'new_distinct_original_engine_objects'=>count($covered),
  'engine_function_exports'=>$fn,'engine_state_exports'=>$state,
  'engine_class_inherited_state_exports'=>$class,'engine_net_functions'=>$net,
  'original_compiled_native_field_comparisons'=>$compareFields,
  'native_field_matches'=>$matched,'mismatches'=>$compareFields-$matched,
  'sealed_469f_Core_methods_reexecuted'=>false,
  'g3_04_FULL'=>'OPEN: other distinct SAVE/version/malformed branches remain'],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
