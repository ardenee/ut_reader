#!/usr/bin/env php
<?php
/** Native original May 17 1998 Unreal retail, source-backed C98 O06 proof. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G3-C98-MAY1998-ORIGINAL-NATIVE-LOADED.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$failed=[];
$check=static function(string $id,bool $pass)use(&$checks,&$failed){$checks++;if(!$pass)$failed[]=$id;};
$check('May17 retail original, NOT full G3',
    $m['status']==='ORIGINAL_MAY1998_RETAIL_COMPILED_NATIVE_C98_UCONST_UENUM_VALUES_VERIFIED_NOT_G3_FULL'
    &&$m['G3_full_contracts_accepted']===0
    &&$m['native_malformed_error_behavior_proven']===false
    &&$m['all_C98_source_build_symbols_verified']===false);
$check('Redump authenticated original USA ZIP SHA1',
    $m['published_Redump_original_zip_sha1']==='dc783e4826b11e3b524324748d8f70c02336a588');
$check('May17 original compiled Core.dll SHA256',
    $m['disc_and_genuine_compiled_files']['original_May_1998_Core_dll']['sha256']==='9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92');
$check('authentic original Core.u binary SHA256',
    $m['disc_and_genuine_compiled_files']['original_May_1998_Core_u']['sha256']==='7c94b2a68d8f6fc0bfb5264c9e9aad4ce35ccf12a8b8057d456dacd33d74140c');
foreach($m['disc_and_genuine_compiled_files'] as $name=>$v){
    $check('source Redump media genuine SHA '.$name,
        is_file($v['path'])&&filesize($v['path'])===(int)$v['bytes']
        &&hash_file('sha256',$v['path'])===$v['sha256']);
}
$check('Redump SHA1 exact original May disc',
    hash_file('sha1',$m['disc_and_genuine_compiled_files']['Redump_original_USA_zip']['path'])
    ===$m['published_Redump_original_zip_sha1']);
foreach($m['original_Epic_v1200_source_files'] as $name=>$v){
    $check('original Epic source SHA '.$name,
        is_file($v['path'])&&filesize($v['path'])===(int)$v['bytes']
        &&hash_file('sha256',$v['path'])===$v['sha256']);
}
$source=$m['original_Epic_v1200_source_files'];
$unClass=(string)file_get_contents($source['UConst_original_v1200_UnClass_cpp']['path']);
$unObj=(string)file_get_contents($source['UEnum_original_v1200_UnObj_cpp']['path']);
$main=(string)file_get_contents($source['CD_required_original_v1200_Window_Main_cpp']['path']);
$check('v1.200 original UConst field and archive method',
    preg_match('/void UConst::Serialize\s*\(\s*FArchive& Ar\s*\).*?UField::Serialize\( Ar \);\s*Ar << Value;/s',$unClass)===1);
$check('v1.200 original UEnum field and archive method',
    preg_match('/void UEnum::Serialize\s*\(\s*FArchive& Ar\s*\).*?UField::Serialize\(\s*Ar\s*\);\s*Ar << Names;/s',$unObj)===1);
$check('stock CD-ROM check controlled by original source, not patched binary',
    str_contains($main,'"CdPath"') &&str_contains($main,'Palettes.utx')
    &&str_contains($main,'appFSize(Check)<=0'));
foreach($m['source_evidence'] as $name=>$v){
    $f=$root.'/'.str_replace('/',DIRECTORY_SEPARATOR,$v['path']);
    $check('original Microsoft CDB transcript SHA '.$name,
        is_file($f)&&filesize($f)===(int)$v['bytes']
        &&hash_file('sha256',$f)===$v['sha256']);
}
$load=static function(string $name) use ($m,$root):string {
    $raw=(string)file_get_contents($root.'/'.$m['source_evidence'][$name]['path']);
    $s=iconv('UTF-16','UTF-8',$raw);
    if(!is_string($s))throw new RuntimeException('Invalid original native debugger log '.$name);
    return $s;
};
$methods=$m['original_May_Core_compiled_method_export_jump_target'];
$check('original UConst export jumps to native compiled method body',
    $methods['UConst']['original_export_thunk']==='0x10101f69'
    &&$methods['UConst']['compiled_body_target']==='0x10114de0');
$check('original UEnum export jumps to native compiled method body',
    $methods['UEnum']['original_export_thunk']==='0x101024f0'
    &&$methods['UEnum']['compiled_body_target']==='0x101389d0');
$disasm=$load('original_May_thunk_followed_disassembly_CDB');
foreach(['10114e0d','10114e17','10138a02','10138a0c'] as $pc){
    $check('native compiled 1998 source order/address '.$pc,str_contains($disasm,$pc));
}
$structLog=$load('original_May_USTRUCT_compiled_loader_CDB');
$check('original May C98 UStruct compiled callback executed',
    substr_count($structLog,'MAY1998_ORIGINAL_USTRUCT_HIT')>=2);
$constLog=$load('original_May_stock_loaded_const_enum_CDB');
$chunks=preg_split('/^MAY1998_V200_ORIGINAL_NATIVE_UCONST_LOADED\h*\R/m',$constLog);
$check('exactly two original compiled UConst LOAD calls',count($chunks)===3);
$nativeStrings=[];
foreach(array_slice($chunks,1) as $i=>$segment){
    $found=preg_match('/(?m)^[0-9a-f]{8}\h+[0-9a-f]{8}\h+([0-9a-f]{8})\h+([0-9a-f]{8})\h*\R[0-9a-f]{8}\h+"([^"]+)"/i',$segment,$r);
    $check('native captured stock UConst memory '.$i,$found===1);
    if(!$found)continue;
    $v=$r[3];$num=(int)hexdec($r[1]);$max=(int)hexdec($r[2]);
    $check('native original May FString Num Max semantics '.$i,$num===$max&&$num===strlen($v)+1);
    $check('native original const not duplicate '.$i,!isset($nativeStrings[$v]));
    $nativeStrings[$v]=['Num'=>$num,'Max'=>$max];
}
$expected=[];
foreach($m['stock_loaded_UConst'] as $record){
    $name=$record['name'];$v=$record['native_loaded_text'];
    $check('authentic May stock C++ value replay '.$name,isset($nativeStrings[$v])
        &&$nativeStrings[$v]['Num']===(int)$record['native_Num']
        &&$nativeStrings[$v]['Max']===(int)$record['native_Max']);
    $expected[$name]=$record;
}
$check('May original 2 unique UConst values',count($nativeStrings)===2 &&count($expected)===2);
$enumLog=$load('original_May_compiled_global_FName_CDB');
$pieces=preg_split('/^MAY1998_ORIGINAL_UENUM_RESOLVED\h*\R/m',$enumLog);
$check('one original compiled May native UEnum breakpoint',count($pieces)===2);
$subs=preg_split('/^MAY1998_ORIGINAL_FNAME_TABLE\h*\R/m',$pieces[1]??'');
$check('original FName::Names entry lookup performed',count($subs)===2);
$rows=[];
foreach(preg_split('/\R/',$subs[0]??'') as $line){
    $line=trim($line);
    if(preg_match('/^[0-9a-f]{8}(?:\h+[0-9a-f]{8}){3,4}$/i',$line)){
        $rows[]=array_map(static fn($x)=>(int)hexdec($x),array_slice(preg_split('/\h+/',$line),1));
    }
}
$check('native May C++ FName memory header read',count($rows)>=3);
$check('original C++ UEnum TArray Num Max seven',($rows[0][1]??0)===7&&($rows[0][2]??0)===7);
$actualGlobal=array_slice(array_merge(...array_slice($rows,1)),0,7);
$nativeEnum=$m['stock_loaded_UEnum'];
$check('original global FName IDs equal debugger memory', $actualGlobal===$nativeEnum['native_global_FName_indices']);
preg_match_all('/(?m)^[0-9a-f]{8}\h+"([^"]+)"/i',$subs[1]??'',$nm);
$realNames=array_slice($nm[1],0,7);
$check('seven original compiled global FName names resolved',
    count($realNames)===7 &&$realNames===$nativeEnum['native_global_FName_names']);
$path=$m['disc_and_genuine_compiled_files']['original_May_1998_Core_u']['path'];
$pkg=new Package($path);
$check('original May stock Core.u reads',$pkg->validatePackage()===[]);
$ex=$pkg->getExports();$imports=$pkg->getImports();
$check('May Core.u genuine version 61',$pkg->getHeader()['version']===61);
$check('May Core.u genuine export count 458',count($ex)===458);
$actual=[];$ct=0;$et=0;
foreach($ex as $i=>$e){
    $ci=(int)$e['classIndex'];$kind=$ci<0?(string)($imports[-$ci-1]['objectNameText']??''):'';
    if(!in_array(strtolower($kind),['const','enum'],true))continue;
    $n=(string)$e['objectNameText'];
    $saved=Native::inspect($path,$pkg,$i,Tags::inspect($path,$pkg,$i,'epic-ue1'),'epic-ue1');
    if(strcasecmp($kind,'Const')===0){
        $ct++;$pin=$expected[$n]??null;
        $check('May real stock export Const named '.$n,$pin!==null);
        if($pin===null)continue;
        $v=$pin['native_loaded_text'];
        $check('May source archive exact compiled native FString text '.$n,($saved['value']??null)===$v);
        $check('May source archive exact native FString count '.$n,($saved['value_count']??null)===(int)$pin['native_Num']);
        $check('May source byte SHA exact stock loaded original '.$n,
            ($saved['value_sha256']??null)===hash('sha256',$v."\0"));
        $check('May source archive full native complete '.$n,
            ($saved['status']??null)==='native_const_record_complete');
        $actual[$n]=['export_index'=>$i,'native_loaded_text'=>$v,'FString_Num'=>$pin['native_Num']];
    }else{
        $et++;
        $check('May original Enum named ESheerAxis',$n==='ESheerAxis');
        $check('May compiled global FNames resolve same saved strings',
            array_column($saved['names']??[],'name')===$realNames);
        $check('May source enum count native seven',($saved['name_count']??null)===7);
        $check('May FName global indices NOT confused with local source name slot',
            array_column($saved['names']??[],'name_index')!==$actualGlobal);
        $check('May source native enum complete',($saved['status']??null)==='native_enum_record_complete');
        $actual[$n]=['export_index'=>$i,'original_global_FName_indices'=>$actualGlobal,
            'compiled_original_names'=>$realNames];
    }
}
$check('all three genuine original May Core.u objects covered',$ct===2 &&$et===1&&count($actual)===3);
$ok=$failed===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'passed'=>$checks-count($failed),'failed'=>$failed,
 'scope'=>'Authentic May 17 1998 stock compiled Core.dll 2 Const + one Enum with 7 original global names, original May 19 v1.200 archive source methods, one original Core.u',
 'native_original_object_parity'=>$actual,
 'C98_v1200_all_method_byte_exact_source_build_identical'=>false,
 'other_G3_property_classes_and_malformed_negative_proven'=>false,'G3_FULL'=>false],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
