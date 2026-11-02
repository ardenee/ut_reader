#!/usr/bin/env php
<?php
/** Sealed 227i compiled UEnum loaded-name comparison; does not execute native engine. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';

use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;

$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G3-C227I-UENUM-NATIVE-LOADED.json'),
    true,512,JSON_THROW_ON_ERROR);
$checks=0;$fail=[];
$check=static function(string $key,bool $pass)use(&$checks,&$fail):void{
    ++$checks;if(!$pass){$fail[]=$key;}
};
$check('sealed exact 227i enum scope',
    ($m['status']??'')==='C227I_UENUM_TWO_NATIVE_LOADED_ARRAYS_TWELVE_NAMES_VERIFIED_NOT_FULL_G3'
    &&($m['G3_full_contracts_accepted']??null)===0);
foreach(['original_Core_dll','original_Core_u','original_UCC_exe'] as $key){
    $pin=$m[$key];
    $check('original 227i file SHA '.$key,is_file($pin['path'])
        &&filesize($pin['path'])===(int)$pin['bytes']
        &&hash_file('sha256',$pin['path'])===$pin['sha256']);
}
$check('official genuine 227i Core.dll SHA',
    $m['original_Core_dll']['sha256']==='287b5eb68c3a098b11eca55c84f7f9d5e29db51c7ec49475099b871044881897');
$check('genuine Core.u SHA',
    $m['original_Core_u']['sha256']==='bd42f3dff0a9ef5521623d45c29b51104b1ec366cf27b89bdeeb888b9ba3973b');
foreach($m['native_debugger_evidence'] as $key=>$pin){
    $p=$root.'/'.str_replace('/',DIRECTORY_SEPARATOR,$pin['path']);
    $check('immutable debugger proof '.$key,is_file($p)
        &&filesize($p)===(int)$pin['bytes']
        &&hash_file('sha256',$p)===$pin['sha256']);
}
$logPath=$root.'/'.$m['native_debugger_evidence']['native_enum_log']['path'];
$log=iconv('UTF-16','UTF-8',(string)file_get_contents($logPath));
if(!is_string($log)){throw new RuntimeException('Cannot decode native UTF-16 CDB log');}
$sections=preg_split('/^G3_C227_NATIVE_ENUM_LOADED\h*\R/m',$log);
$check('exactly two true compiled UEnum breakpoints',is_array($sections)&&count($sections)===3);
$map=[];$records=[];
foreach(($sections?:[]) as $i=>$section){
    if($i===0){continue;}
    $parts=explode('G3_C227_GLOBAL_NAMES_FROM_ORIGINAL_FNAME_TABLE',$section,2);
    $check('breakpoint also examined native FName table '.$i,count($parts)===2);
    if(count($parts)!==2){continue;}
    preg_match_all('/(?m)^[0-9a-f]{8}\h+((?:[0-9a-f]{8}\h*){3,4})/i',$parts[0],$rows,PREG_SET_ORDER);
    $check('captured native TArray header and payload '.$i,count($rows)>=2);
    if(count($rows)<2){continue;}
    $header=preg_split('/\s+/',trim($rows[0][1]));
    $num=hexdec($header[1]??'0');$max=hexdec($header[2]??'0');
    // Header has *three* machine words: data pointer, Num, Max.
    $body=[];
    for($j=1;$j<count($rows);$j++){
        foreach(preg_split('/\s+/',trim($rows[$j][1])) as $word){
            if($word!==''){$body[]=hexdec($word);}
        }
    }
    $body=array_slice($body,0,$num);
    $check('native FName array header coherent '.$i,$num===$max&&in_array($num,[5,7],true));
    $check('native FName array data all present '.$i,count($body)===$num);
    preg_match_all('/(?m)^[0-9a-f]{8}\h+"([^"]+)"/i',$parts[1],$names);
    $check('all twelve original global FName text lookups '.$i,count($names[1])>=12);
    if(count($names[1])<12){continue;}
    $global=array_column($m['native_global_FName_resolution_table'],'resolved_name','global_index');
    $loaded=[];
    foreach($body as $idx){$loaded[]=$global[$idx]??null;}
    $check('original global FName indices resolve '.$i,!in_array(null,$loaded,true));
    // Also compare the CDB-resolved global table contents, not just the manifest.
    $captureNames=array_slice($names[1],0,12);
    $check('native FName table bytes agree with sealed resolution '.$i,
        $captureNames===array_values($global));
    $key=$num===5?'eAnimNotifyEval':'ESheerAxis';
    $records[$key]=['count'=>$num,'global_indices'=>$body,'names'=>$loaded];
}
$manifestRecords=[];
foreach($m['native_loaded_UEnum'] as $v){
    $name=$v['matched_Core_u_enum'];
    $manifestRecords[$name]=$v;
    $record=$records[$name]??null;
    $check('sealed native values '.$name,$record!==null
        &&$record['count']===$v['loaded_array_num']
        &&$record['count']===$v['loaded_array_max']
        &&$record['global_indices']===$v['loaded_global_FName_indices']
        &&$record['names']===$v['loaded_resolved_FName_text']);
}
$check('two original native enum arrays',count($manifestRecords)===2&&count($records)===2);
$path=$m['original_Core_u']['path'];
$pkg=new Package($path);
$check('unmodified compiled Core.u validates',$pkg->validatePackage()===[]);
$exports=$pkg->getExports();$imports=$pkg->getImports();
$check('original Core.u 934 exports',count($exports)===934);
$actual=[];$found=0;
foreach($exports as $idx=>$ex){
    $type=(int)$ex['classIndex'];
    $class=$type<0?(string)($imports[-$type-1]['objectNameText']??''):'';
    if(strcasecmp($class,'Enum')!==0){continue;}
    ++$found;$name=(string)$ex['objectNameText'];
    $check('native original enum exported '.$name,isset($records[$name]));
    $tags=Tags::inspect($path,$pkg,$idx,'unreal-v227');
    $native=Native::inspect($path,$pkg,$idx,$tags,'unreal-v227');
    $expected=$records[$name]??null;
    if($expected===null){continue;}
    $localNames=array_column($native['names']??[],'name');
    $check('compiled loaded enum names equal PHP archived names '.$name,
        $localNames===$expected['names']);
    $check('compiled loaded enum array Num equals PHP '.$name,
        ($native['name_count']??null)===$expected['count']
        &&count($localNames)===$expected['count']);
    $check('source archive finished exact export '.$name,
        ($native['status']??null)==='native_enum_record_complete'
        &&($native['extension_bytes']??null)===0);
    $check('global and package-local FName identities distinguished '.$name,
        array_column($native['names'],'name_index')!==$expected['global_indices']);
    $actual[$name]=['export_index'=>$idx,'loaded_array_num'=>$expected['count'],
        'global_FName_indices'=>$expected['global_indices'],'names'=>$localNames];
}
$check('two distinct original Core.u UEnum exports',$found===2&&count($actual)===2);
$ok=$fail===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'passed'=>$checks-count($fail),
    'failed'=>$fail,'scope'=>'Original compiled 227i UEnum two loaded arrays and twelve resolved native global FNames',
    'native_comparison'=>$actual,'C227_other_methods_full_source_parity_proven'=>false,
    'C98_original_native_proven'=>false,'G3_FULL'=>false],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
