#!/usr/bin/env php
<?php
/** SHA-sealed original 1998 Unreal retail Core native loaded-object oracle. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G3-C98-RETAIL-ORIGINAL-NATIVE-LOADED.json'),true,512,JSON_THROW_ON_ERROR);
$n=0;$failed=[];
$check=static function(string $key,bool $pass) use (&$n,&$failed):void{$n++;if(!$pass)$failed[]=$key;};
$check('scope original JUL98 retail only, G3 still unaccepted',
    $m['status']==='C98_ORIGINAL_JUL1998_RETAIL_STOCK_UCONST_UENUM_LOADED_VALUES_NOT_G3_FULL'
    &&$m['G3_full_contracts_accepted']===0);
$check('Redump independent advertised original zip SHA1',
    $m['redump_published_zip_sha1']==='f200c1168aa95617c923d65f99a343bca7baa088');
$check('1998 retail Core native DLL pinned',
    $m['original_disc_and_files']['original_Core_dll']['sha256']==='c057a26f04988576681499d51174d7bc53fb6bfe3ddfa9c74c733982971c0545');
$check('1998 original Core.u pinned',
    $m['original_disc_and_files']['original_Core_u']['sha256']==='7c94b2a68d8f6fc0bfb5264c9e9aad4ce35ccf12a8b8057d456dacd33d74140c');
foreach($m['original_disc_and_files'] as $name=>$p){
    $check('original unmodified Redump media and Core binaries: '.$name,
        is_file($p['path']) && filesize($p['path'])===(int)$p['bytes']
        && hash_file('sha256',$p['path'])===$p['sha256']);
}
$zip=$m['original_disc_and_files']['verified_Redump_zip']['path'];
$check('original Redump ZIP SHA1 verified',hash_file('sha1',$zip)===$m['redump_published_zip_sha1']);
foreach($m['sealed_native_CDB_proof'] as $name=>$p){
    $file=$root.'/'.str_replace('/',DIRECTORY_SEPARATOR,$p['path']);
    $check('stock WinDbg x86 native CDB proof '.$name,
        is_file($file)&&filesize($file)===(int)$p['bytes']
        &&hash_file('sha256',$file)===$p['sha256']);
}
$readLog=static function(string $which)use($root,$m):string{
    $p=$root.'/'.$m['sealed_native_CDB_proof'][$which]['path'];
    $s=iconv('UTF-16','UTF-8',(string)file_get_contents($p));
    if(!is_string($s))throw new RuntimeException('Cannot decode stock WinDbg UTF16 trace '.$which);
    return $s;
};
$disasm=$readLog('native_disasm_log');
foreach(['Core!UConst::Serialize:','Core!UEnum::Serialize:'] as $symbol){
    $check('actual 1998 compiled binary disassembled '.$symbol,
        str_contains($disasm,$symbol));
}
$cLog=$readLog('native_const_log');
$parts=preg_split('/^C98_RETAIL_CONST_LOADED\h*\R/m',$cLog);
$check('exactly two original retail UConst stock loaded breakpoints',
    count($parts)===3);
$captures=[];
foreach(array_slice($parts,1) as $i=>$part){
    $found=preg_match('/(?m)^[0-9a-f]{8}\h+[0-9a-f]{8}\h+([0-9a-f]{8})\h+([0-9a-f]{8})\h*\R[0-9a-f]{8}\h+"([^"]+)"/i',$part,$a);
    $check('native original UConst FString memory recorded '.$i,$found===1);
    if(!$found)continue;
    $value=$a[3];$num=(int)hexdec($a[1]);$max=(int)hexdec($a[2]);
    $check('compiled FString Num/Max and ending NUL '.$i,
        $num===$max && $num===strlen($value)+1);
    $check('stock C98 distinct original UConst value '.$i,!isset($captures[$value]));
    $captures[$value]=['num'=>$num,'max'=>$max];
}
$expected=[];
foreach($m['compiled_UConst_Serialize']['loaded_values'] as $a){
    $value=$a['loaded_text'];$name=$a['name'];
    $expected[$name]=$a;
    $check('recorded compiled runtime replays original debugger '.$name,
        isset($captures[$value])
        &&$captures[$value]['num']===$a['loaded_FString_Num']
        &&$captures[$value]['max']===$a['loaded_FString_Max']);
}
$check('exactly two unique C98 native constants',count($expected)===2&&count($captures)===2);
$eLog=$readLog('native_enum_log');
$parts=preg_split('/^C98_RETAIL_ENUM_LOADED\h*\R/m',$eLog);
$check('exactly one original compiled UEnum object loaded',count($parts)===2);
$part=$parts[1]??'';
$pair=preg_split('/^C98_RETAIL_FNAME_GLOBAL\h*\R/m',$part);
$check('actual global FName lookup executed',count($pair)===2);
$rawRows=[];
foreach(preg_split('/\R/',$pair[0]??'') as $line){
    $line=trim($line);
    if(preg_match('/^[0-9a-f]{8}(?:\h+[0-9a-f]{8}){3,4}$/i',$line)){
        $words=preg_split('/\h+/',$line);
        $rawRows[]=array_map(static fn($v)=>(int)hexdec($v),array_slice($words,1));
    }
}
$check('original C98 UEnum header and archived C++ FName memory read',count($rawRows)>=3);
$head=$rawRows[0]??[];
$check('native FName TArray Num and Max 7',
    ($head[1]??0)===7&&($head[2]??0)===7);
$ids=array_slice(array_merge(...array_slice($rawRows,1)),0,7);
$check('native global FName IDs re-read',
    $ids===$m['compiled_UEnum_Serialize']['runtime_global_FName_IDs']);
preg_match_all('/(?m)^[0-9a-f]{8}\h+"([^"]+)"/i',$pair[1]??'',$names);
$actualNames=array_slice($names[1],0,7);
$check('seven native FName strings resolved',count($actualNames)===7);
$check('original native resolved FName index to real text',
    $actualNames===$m['compiled_UEnum_Serialize']['runtime_global_FName_text']);
$path=$m['original_disc_and_files']['original_Core_u']['path'];
$pkg=new Package($path);
$check('genuine Core.u header recognized',$pkg->validatePackage()===[]);
$exports=$pkg->getExports();$imports=$pkg->getImports();
$check('original July retail version 61',$pkg->getHeader()['version']===61);
$check('original July retail Core.u 458 exports',count($exports)===458);
$actual=[];$constantCount=0;$enumCount=0;
foreach($exports as $i=>$ex){
    $ci=(int)$ex['classIndex'];
    $class=$ci<0?(string)($imports[-$ci-1]['objectNameText']??''):'';
    if(!in_array(strtolower($class),['const','enum'],true))continue;
    $name=$ex['objectNameText'];
    $native=Native::inspect($path,$pkg,$i,Tags::inspect($path,$pkg,$i,'epic-ue1'),'epic-ue1');
    if(strtolower($class)==='const'){
        $constantCount++;
        $row=$expected[$name]??null;
        $check('original retail Const name present in compiled native evidence '.$name,$row!==null);
        if($row===null)continue;
        $check('C98 genuine stock loaded text equals archived '.$name,
            ($native['value']??null)===$row['loaded_text']);
        $check('C98 native FString Num matches original saved size '.$name,
            ($native['value_count']??null)===$row['loaded_FString_Num']);
        $check('C98 original saved string byte SHA and NUL match '.$name,
            ($native['value_sha256']??null)===hash('sha256',$row['loaded_text']."\0"));
        $check('C98 original exact archive record complete '.$name,
            ($native['status']??null)==='native_const_record_complete');
        $actual[$name]=['native_loaded_text'=>$row['loaded_text'],'real_export_index'=>$i,
            'FString_Num'=>$row['loaded_FString_Num']];
    }else{
        $enumCount++;
        $check('C98 original enum is ESheerAxis',$name==='ESheerAxis');
        $check('C98 original UEnum actual name values equal compiled original memory',
            array_column($native['names']??[],'name')===$actualNames);
        $check('native enum Num agrees with real package count',
            ($native['name_count']??null)===7);
        $check('package-local FName indices differ from runtime global name table',
            array_column($native['names']??[],'name_index')!==$ids);
        $check('C98 UEnum original source record fully consumed',
            ($native['status']??null)==='native_enum_record_complete');
        $actual[$name]=['real_export_index'=>$i,'native_loaded_names'=>$actualNames,
            'native_global_FName_indices'=>$ids];
    }
}
$check('every authentic C98 Core.u UConst and UEnum export covered',
    $constantCount===2 && $enumCount===1 && count($actual)===3);
$ok=$failed===[];
echo json_encode(['ok'=>$ok,'checks'=>$n,'passed'=>$n-count($failed),'failed'=>$failed,
 'scope'=>'Original European Unreal retail compiled July 1998 engine/Core.u version61: 2 Const, 1 Enum, original 7 native FNames',
 'actual_native_parity'=>$actual,'May_1998_v200_source_build_equivalence_proven'=>false,
 'malformed_original_native_behavior_proven'=>false,'G3_FULL'=>false],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
