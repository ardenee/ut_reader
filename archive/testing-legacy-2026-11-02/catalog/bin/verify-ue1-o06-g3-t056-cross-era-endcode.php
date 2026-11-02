<?php
/** UE1 G3 fixed T056: authentic cross-era 1998 EX_EndCode byte in 469f compiled loader.
 * Read ORIGINAL 1998 FObjectManager-saved v61 archive, sealed compiled 469f
 * FAILURE transcript, and CURRENT script decoder; no native execution/replay.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicScriptArchiveReader as Script;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as Family;
$repo=dirname(__DIR__,2);
$genuine=$repo.'/catalog/compliance/evidence/o06/20261028-G3-04-C98-original-1998-native-SAVE.u';
$native='C:/Temp/UnrealDB/O06/Native-469f-V61/System/Core.dll';
$nativeLog=$repo.'/catalog/compliance/evidence/o06/20261028-G3-T056-469f-original-cross-era-badexpr15.log';
$historicalC98='L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Core/Src/UnClass.cpp';
$historicalUT99='L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Core/Src/UnClass.cpp';
$errors=[];$checks=0;
$ck=static function(bool $ok,string $why)use(&$errors,&$checks):void{
  $checks++;
  if(!$ok)$errors[]=$why;
};
$ck(is_file($genuine)&&hash_file('sha256',$genuine)==='4957034dc7629163ab7adabeedff1fc9cecc3def1d577c4aab31bec0763071a4','genuine 1998 original compiled writer .u');
$ck(is_file($native)&&hash_file('sha256',$native)==='80ac3341f9826e227146d4ce3ecb34fa7f4ebd0fba4986e9b1478b07086c57d2','unaltered actual original 469f Core.dll');
$ck(is_file($nativeLog)&&hash_file('sha256',$nativeLog)==='58d27495400ef3d0b9daeb61f407258bf7c9827336456d0f8857519410570cf9','actual compiled original 469f failure native transcript SHA');
$nativeText=is_file($nativeLog)?(string)file_get_contents($nativeLog):'';
$ck(str_contains($nativeText,'Version: 469')&&str_contains($nativeText,'Revision: f'),'actual 469f compiled original loader, not C98 process');
$ck(str_contains($nativeText,'Log: Bad expr token 15')&&str_contains($nativeText,'FailedLoadPackage'),'original compiled loader rejects old source EndCode; not a successful function LOAD');
$old=(string)file_get_contents($historicalC98);
$new=(string)file_get_contents($historicalUT99);
$ck(str_contains($old,'case EX_EndCode:')&&str_contains($old,'Bad expr token %02x'),'1998 source supports EX_EndCode');
$ck(!str_contains($new,'case EX_EndCode:')&&str_contains($new,'Bad expr token %02x'),'original UT99 switch lacks EX_EndCode and errors on undefined opcode');
$pkg=new Package($genuine);
$ck($pkg->validatePackage()===[]&&($pkg->getHeader()['version']??null)===61,'original genuine v61 package validates');
$positions=[797,854]; // two real C98 compiled writer UFunctions, not synthesized bytes.
$observations=[];
foreach($positions as $off){
  $byte=file_get_contents($genuine,false,null,$off,1);
  $ck($byte==="\x15","actual original compiled 1998 function EndCode byte at physical archive ".$off);
  foreach([
     ['name'=>'C98-1998','profile'=>Family::EPIC_UE1,'expect'=>'ACCEPT'],
     ['name'=>'UT99-v432','profile'=>Family::UT99_V432,'expect'=>'NATIVE_BAD_EXPR'],
     ['name'=>'UT99-469f','profile'=>Family::UT99_V469F,'expect'=>'NATIVE_BAD_EXPR'],
     ['name'=>'C227','profile'=>Family::UNREAL_V227,'expect'=>'SOURCE_UNVERIFIED'],
  ] as $profile){
     $pos=$off;$stream=fopen($genuine,'rb');
     if($stream===false)throw new RuntimeException('Cannot open genuine source package');
     $read=static function(int $n)use($stream,&$pos):string{
       fseek($stream,$pos);
       $bytes=fread($stream,$n);
       if(!is_string($bytes)||strlen($bytes)!==$n)throw new RuntimeException('Truncated original package');
       $pos+=$n;return $bytes;
     };
     $unused=static function():int{throw new RuntimeException('Unexpected compact operands for original EndCode');};
     $error=null;$record=null;
     try{
       $record=Script::inspect($pkg,61,1,$read,$unused,$pos,$profile['profile']);
     }catch(RuntimeException $e){$error=$e->getMessage();}
     finally{fclose($stream);}
     $check=$profile['expect'];
     $match=$pos===$off+1;
     if($check==='ACCEPT'){
       $match=$match&&$error===null&&$record['status']==='script_archive_expressions_decoded'
         &&$record['script_memory_decoded']===1
         &&$record['expression_preview'][0]['token']===0x15;
     }elseif($check==='NATIVE_BAD_EXPR'){
       $match=$match&&$error==='UE1 Bad expr token 15 at archive cursor '.($off+1)
         &&$record===null;
     }else{
       $match=$match&&$error===null&&$record['status']==='script_archive_token_unimplemented'
         &&str_contains($record['unsupported_branch'],'EX_MapKeyElement');
     }
     $ck($match,'one original 1998 EndCode token correctly classified for '.$profile['name'].' at '.$off);
     $observations[]=['archive_offset'=>$off,'source'=>$profile['name'],
       'classification'=>$check,'physical_cursor'=>$pos,'native_style_error'=>$error,
       'status'=>$record['status']??null];
  }
}
$ok=$errors==[]&&count($observations)===8;
echo json_encode(['ok'=>$ok,'checks'=>$checks,'errors'=>$errors,
  'original_compiled_native_rejection'=>'Bad expr token 15',
  'cross_family_reason'=>'1998 EndCode has no source case in 469f/v432 UT99; 227 uses distinct MapKeyElement opcode',
  'observations'=>$observations,
  'reader_source_sha256'=>hash_file('sha256',$repo.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicScriptArchiveReader.php'),
  'original_compiled_engines_reexecuted'=>0,'previous_native_acceptance_replayed'=>0,
  'remaining_T011'=>'UT99 v60-v63 real loadable legacy function; still BLOCKED'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
