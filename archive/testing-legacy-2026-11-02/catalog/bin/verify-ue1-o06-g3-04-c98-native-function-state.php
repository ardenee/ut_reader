#!/usr/bin/env php
<?php
/** Authentic May17 1998 Core.dll UFunction/UState native loaded-value oracle. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$r=dirname(__DIR__,2);
$ev=$r.'/catalog/compliance/evidence/o06/';
$sys='C:/Temp/UE1-G3-NativeOracle/C98-USA-Retail-CD/retail-source/System/';
$hashes=[
 [$sys.'Core.dll','9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92'],
 [$sys.'Core.u','7c94b2a68d8f6fc0bfb5264c9e9aad4ce35ccf12a8b8057d456dacd33d74140c'],
 [$sys.'Engine.u','bdccc8ee1557b758554714bcc676edb2cb4474239c70bedaaf81f07e9a419b7d'],
 [$sys.'Unreal.exe','d631c8af81d2bba50131254e9770aceb0e59ebd3df97f4cb8560c9c6835e7c30'],
 ['L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Core/Src/UnClass.cpp',
     'ea4b7304d6cfe45f35929b59a10cefe51e5ee132f3603fc43e3f0e3d97aa1291'],
 [$ev.'20261027-G3-04-C98-original-native-function-state.log','adcb6fd212ade3890959ab67b242bd5f91f23ac1de0fa99fb023358f93c748fc'],
 [$ev.'20261027-G3-04-C98-original-native-function-state.cmd','4b03582f106c627974ef5a833e29d0bf469a98f1cfff0944ffd1bacc7aec028d'],
 [$ev.'20261027-G3-04-C98-parse-function-state.py','79054c7706e608a0015206a7da5beb88dae3da7a69ceb53db7b4968dfd79fb78'],
 [$ev.'20261027-G3-04-C98-native-function-state-parsed.json','a0924c66115e6e8126a05814f4c1b34b1439c845027cb8d63108f7a816e79668'],
 [$ev.'20261027-G3-04-C98-original-NET-one.log','5c597d6bee86d8a92e48837daffcfc0f7e926f9eeb08f314dc21be305f9bb339'],
 [$ev.'20261027-G3-04-C98-original-NET-one.cmd','c3988911ecc9616358ac3deffbb1b1bc8a4b17855f9618b8cc386cfce0231360'],
];
$checks=0;$fail=[];$kinds=[];$valid=0;$excluded=0;
$ck=static function(bool $value,string $tag)use(&$checks,&$fail):void{
    ++$checks;if(!$value&&count($fail)<30)$fail[]=$tag;
};
foreach($hashes as [$path,$sha])$ck(is_file($path)&&hash_file('sha256',$path)===$sha,'immutable source/binary/evidence '.basename($path));
$native=json_decode((string)file_get_contents($ev.'20261027-G3-04-C98-native-function-state-parsed.json'),true,512,JSON_THROW_ON_ERROR);
$ck(($native['summary']['captures']??null)===256&&($native['summary']['parsed']??null)===256
    &&($native['summary']['bad']??null)===[], '256 parsed native events and no malformed records');
$raw=(string)file_get_contents($ev.'20261027-G3-04-C98-original-native-function-state.log');
$ck(preg_match_all('/^G304_C98_UFunction_NONNET\s*$/m',$raw)===243
    &&preg_match_all('/^G304_C98_UState_LOADED\s*$/m',$raw)===12
    &&preg_match_all('/^G304_C98_UFunction_NET\s*$/m',$raw)===1,
    'original shipped Core runtime method breakpoint event counts');
$files=[38002480=>'Core.u',37648608=>'Engine.u'];$packages=[];$exp=[];$imp=[];
foreach($files as $link=>$file){$packages[$link]=new P($sys.$file);$exp[$link]=$packages[$link]->getExports();$imp[$link]=$packages[$link]->getImports();
    $ck((int)($packages[$link]->getHeader()['version']??0)===61,'1998 version61 package '.$file);
}
$ck(($exp[38002480][456]['objectNameText']??null)==='EndState','native Core linker identity by original export456');
foreach($native['records'] as $row){
    $link=$row['linker'];$index=$row['id'];$kind=$row['kind'];
    if(!isset($packages[$link])){
        ++$excluded;
        $ck($kind==='G304_C98_UFunction_NET','exactly one initial EDI+0x68 debugger misbase excluded');
        continue;
    }
    $e=$exp[$link][$index]??null;
    $ck(is_array($e),'original file-backed linker export '.$link.':'.$index);
    if(!is_array($e))continue;
    $ci=(int)$e['classIndex'];
    $klass=$ci===0?'Class':($ci<0?($imp[$link][-$ci-1]['objectNameText']??null):($exp[$link][$ci-1]['objectNameText']??null));
    $isFunction=str_contains($kind,'UFunction');
    $ck($isFunction?$klass==='Function':in_array($klass,['State','Class'],true),
        'native type and original file class '.$files[$link].':'.$index);
    if($isFunction&&$klass!=='Function')continue;
    if(!$isFunction&&!in_array($klass,['State','Class'],true))continue;
    try{
        $reader=$ci===0?C::inspect($sys.$files[$link],$packages[$link],$index,false,'epic-ue1'):
          N::inspect($sys.$files[$link],$packages[$link],$index,
            T::inspect($sys.$files[$link],$packages[$link],$index,'epic-ue1'),'epic-ue1');
    }catch(Throwable $err){$ck(false,'reader '. $files[$link].':'.$index.' '. $err->getMessage());continue;}
    $key=$files[$link].':'.$klass;$kinds[$key]=($kinds[$key]??0)+1;
    $fields=$isFunction?[
        'function_flags'=>'functionFlags','legacy_parms_size'=>'parmsSize',
        'native_index'=>'nativeIndex','legacy_num_parms'=>'numParms',
        'operator_precedence'=>'precedence','legacy_return_value_offset'=>'returnOffset'
    ]:['state_flags'=>'stateFlags','label_table_offset'=>'labelOffset'];
    foreach($fields as $php=>$n){
        $ck(isset($reader[$php])&&$reader[$php]===$row[$n],$files[$link].':'.$index.' '. $php);
    }
    if(!$isFunction){
        foreach(['probe_mask_hex'=>0,'ignore_mask_hex'=>2] as $php=>$o){
            $memory=bin2hex(pack('V2',$row['raw'][$o],$row['raw'][$o+1]));
            $ck(strtolower((string)($reader[$php]??''))===$memory,$files[$link].':'.$index.' '.$php);
        }
    }
    ++$valid;
}
$ck($valid===255&&$excluded===1,'255 original file-backed positive events plus excluded misbased NET');
$ck($kinds===[
 'Core.u:Function'=>113,'Core.u:Class'=>1,
 'Engine.u:Function'=>130,'Engine.u:Class'=>8,'Engine.u:State'=>3
], 'all genuine Core and Engine class and function categories');
$netRaw=(string)file_get_contents($ev.'20261027-G3-04-C98-original-NET-one.log');
$ck(str_contains($netRaw,'G304_C98_UFunction_NET_CORRECTED_EdiMinus68')
    &&str_contains($netRaw,'G304_NET_EXIT'),'dedicated distinct FUNC_Net corrected EDI breakpoint completed');
$ck(preg_match('/(?m)^([0-9a-f]{8})\s+[0-9a-f]{8}\s+00000d40\s+[0-9a-f]{8}\s*$/i',$netRaw,$a)===1
    &&preg_match('/(?m)^([0-9a-f]{8})\s+00000042\s+000000f4\s+ffff025e\s+00000002\s*$/i',$netRaw,$b)===1
    &&hexdec($b[1])-hexdec($a[1])===0x50,
    'original NET object zero-based export3392 and disassembled two member memory addresses');
$v=N::inspect($sys.'Engine.u',$packages[37648608],3392,
    T::inspect($sys.'Engine.u',$packages[37648608],3392,'epic-ue1'),'epic-ue1');
$ck(($exp[37648608][3392]['objectNameText']??null)==='BroadcastMessage','original NET event unique function identity');
foreach(['function_flags'=>66,'legacy_parms_size'=>244,'native_index'=>0,
    'rep_offset'=>606,'legacy_return_value_offset'=>65535,
    'legacy_num_parms'=>2,'operator_precedence'=>0] as $name=>$value){
    $ck(($v[$name]??null)===$value,'original compiled NET BroadcastMessage '.$name);
}
$ok=$fail===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'failed'=>$fail,'native_method_events'=>256,
 'original_file_backed_nonnet_function'=>243,'native_state_uclass_prefix'=>12,
 'original_NET_RepOffset'=>606,'genuine_loaded_field_comparisons'=>1513,
 'source_lineage'=>'original May17 1998 x86 engine and May19 original game source (not asserted binary-identical)',
 'by_source_kind'=>$kinds,'previous_native_suites_rerun'=>0,
 'G3_04_full'=>'OPEN (other C227/T99 native source SAVE and malformed versions)'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
