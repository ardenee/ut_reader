#!/usr/bin/env php
<?php
/**
 * Frozen G3 T014: original compiled UT99 469f UClass LIVE fields versus
 * current source-selected reader and sealed original v432 UClass baseline.
 * No original engine is executed by this verifier.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$repo=dirname(__DIR__,2);
$system='C:/Temp/UnrealDB/O06/G3-REAL-T059-469F-ENTRY/System/';
$stock='C:/Temp/UnrealDB/O06/Native-UCC/System/';
$log=$repo.'/catalog/compliance/evidence/o06/20261029-G3-T014-original-469f-Engine-UClass-loaded-values.log';
$checks=0;$errors=[];$changes=[];$matched=[];
$ck=static function(bool $ok,string $why)use(&$checks,&$errors):void{
    ++$checks;if(!$ok)$errors[]=$why;
};
foreach([
 [$system.'Core.dll','80ac3341f9826e227146d4ce3ecb34fa7f4ebd0fba4986e9b1478b07086c57d2'],
 [$system.'Engine.dll','625dabea278e314714a45c931c98ba6282a9198378499144429aa979a1a0570f'],
 [$system.'Core.u','7b2d4962cb82dda81d522361ee9a1008605e35ba0aacbccd0d53d3b9854b73e0'],
 [$system.'Engine.u','6466cc189183b4eaf36d80339ced9dac8b0711d6487930d6b213feccfb26620b'],
 [$system.'G3-Original-469f-UClass-values.exe','cc9ae6fb849903859514e15eba04e5b73c7683f85291083c71c376464aa4eb10'],
 [$log,'0d5380ced4ff0178c925a767b25f7466661c3075118750606dde2b5e2cc54e57'],
 ['L:/Source/Games/UT99/OldUnreal-UTPatch469f-SDK-Windows/Core/Inc/UnClass.h','4258b41de0b688afd6e0b72c593f90cb0298a90c1eedefe6b4a4b47bcb91024f'],
 [$stock.'Engine.u','d6d016aa28cc3880862770c9965ce67a5feac1ef04f3412ddafe8fc14ea1f695'],
 [$repo.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicClassMetadataReader.php','08d0c883bdf5b91dfc2bfb4b342e9bc549f2f63e135b7ff961fa62664ee4d386'],
 [$repo.'/catalog/compliance/UE1-O06-G5-UCLASS-ENGINE-V69-SOURCE-PROFILE-FULL-ACCEPTANCE.json','772b85769a1773ab80bdfe2be278846e3d1c2977a5fd28eb2fef8e9df0049f7e']
] as [$file,$sha])$ck(is_file($file)&&hash_file('sha256',$file)===$sha,'Original stock native/source pin: '.basename($file));
$native=(string)file_get_contents($log);
$ck(str_contains($native,'G5_NATIVE_DIRECT_PACKAGE_RESULT ptr=')&&!str_contains($native,'native_error=1'),
  'original compiled 469f Engine.u load successful with no native error');
$ck(str_contains($native,'G5_NATIVE_OBJECT_COUNT 132'),
  'original compiled 469f exactly 132 actual UClass live Engine objects');
preg_match_all('/^T014_ACTUAL_469F_NATIVE_CLASS Class Engine\.([^|\r\n]+)\|flags=(\d+)\|within=([^|]+)\|config=([^|]+)\|guid=([0-9A-Fa-f]+)\|deps=(\d+)\|imports=(\d+)\s*$/m',$native,$events,PREG_SET_ORDER);
$live=[];
foreach($events as $m){
    $name=$m[1];
    $ck(!isset($live[$name]),'no duplicate original native Engine UClass '.$name);
    $live[$name]=[
        'flags'=>(int)$m[2],'within'=>$m[3],'config'=>$m[4],
        'guid'=>strtolower($m[5]),'deps'=>(int)$m[6],'imports'=>(int)$m[7]];
}
$ck(count($live)===132,'captured 132 genuine original 469f UClass live records');
$sourceSelected=static function(string $path,string $profile)use($ck):array{
    $pkg=new P($path);$exports=$pkg->getExports();
    $rows=[];
    foreach($exports as $i=>$ex){
        if((int)$ex['classIndex']!==0||(int)$ex['serialSize']<=0
           ||($ex['objectNameText']??'')==='None')continue;
        $x=C::inspect($path,$pkg,$i,false,$profile);
        $ref=(int)($x['class_within_reference']??0);
        $within='';
        if($ref<0){
            $name=(string)($pkg->getImports()[-$ref-1]['objectNameText']??'');
            $within='Class Core.'.$name;
        }elseif($ref>0){
            $name=(string)($pkg->getExports()[$ref-1]['objectNameText']??'');
            $within='Class Engine.'.$name;
        }else{$within='None';}
        $rows[(string)$ex['objectNameText']]=[
            'flags'=>(int)$x['class_flags'],'within'=>$within,
            'config'=>(string)($x['class_config_name']['text']??''),
            'guid'=>strtolower((string)($x['class_guid_raw_hex']??'')),
            'deps'=>count($x['dependencies']??[]),
            'imports'=>count($x['package_imports']??[]),
            'fully_consumed'=>$x['source_file_archive_complete']??false,
            'export_index'=>$i];
    }
    $ck(count($rows)===88,'88 genuinely serialized Engine.u UClass exports: '.$profile);
    return $rows;
};
$new=$sourceSelected($system.'Engine.u','ut99-v469f');
$old=$sourceSelected($stock.'Engine.u','ut99-v432');
$ck(count($new)===88&&count($old)===88,'selected v432 and 469f original each have 88 serialized genuine UClasses');
$ck(count(array_diff(array_keys($new),array_keys($old)))===0,
    'genuine same 88 UClass serialized identities across inherited v432 and 469f');
foreach($new as $name=>$actual){
    $observed=$live[$name]??null;
    $ck($observed!==null,'original compiled 469f loaded serialized UClass Engine.'.$name);
    if($observed===null)continue;
    foreach(['flags','within','config','guid','deps','imports'] as $field)
       $ck($actual[$field]===$observed[$field],
           'source-selected 469f original native '.$field.' for UClass Engine.'.$name);
    $ck($actual['fully_consumed']===true,
        'source-selected 469f full serialized UClass physical field consumption Engine.'.$name);
    $matched[]=$name;
    $baseline=$old[$name]??null;
    if($baseline!==null){
        $diff=[];
        foreach(['flags','within','config','guid','deps','imports'] as $field){
            if($actual[$field]!==$baseline[$field])$diff[$field]=[
                 'v432'=>$baseline[$field],'v469f'=>$actual[$field]];
        }
        if($diff)$changes[$name]=$diff;
    }
}
$ck(count($matched)===88,'every original serialized 469f UClass direct field set verified');
$ck(count($live)-count($matched)===44,
    '44 additional native registered nonserial Engine classes not falsified as archived UClass records');
$oldDecision=json_decode((string)file_get_contents($repo.'/catalog/compliance/UE1-O06-G5-UCLASS-ENGINE-V69-SOURCE-PROFILE-FULL-ACCEPTANCE.json'),true);
$ck(($oldDecision['decision']??null)==='FULL'
    &&($oldDecision['all_real_original_UClass_native_loaded']??null)===88,
    'reused pre-accepted original compiled v432 native 88 classes without replay');
$ok=$errors===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'errors'=>$errors,
    'fixed_test'=>'T014','status'=>$ok?'PASS':'BLOCKED',
    'original_469f_Engine_runtime_live_UClass_count'=>count($live),
    'original_469f_serialized_UClass_compared_to_native'=>count($matched),
    'original_469f_compiler_registered_only_UClass'=>44,
    'v432_original_previously_accepted_full_UClass'=>88,
    'actual_469f_vs_v432_archived_direct_field_changes_count'=>count($changes),
    'original_compiled_native_sessions_reexecuted_by_verifier'=>0,
    'actual_v469f_vs_v432_changes'=>$changes],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
