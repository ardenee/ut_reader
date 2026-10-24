#!/usr/bin/env php
<?php
/** Independent v432 compiled UClass dependency/import array element oracle.
 * Uses real CDB Memory64 minidump, live UObject FName names and the existing
 * production UE1 class reader. All inputs SHA pinned; no synthetic values.
 * Full end-to-end G5 method acceptance remains OPEN.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-UCLASS-FULL-NESTED-MEMORY.json'),true,512,JSON_THROW_ON_ERROR);
$checked=0;$fail=[];$matched=0;$depRecords=0;$depFields=0;$importEntries=0;$withinEntries=0;
$ck=static function(string $label,bool $valid)use(&$checked,&$fail):void{
    $checked++;
    if(!$valid && count($fail)<40)$fail[]=$label;
};
$pin=static function(array $ref,string $name)use($ck):string{
    $file=$ref['path'];$raw=is_file($file)?file_get_contents($file):false;
    $ck('pinned_sha256_'.$name,is_string($raw)&&hash('sha256',$raw)===$ref['sha256']);
    return is_string($raw)?$raw:'';
};
$pin($m['compiled_core'],'Core432');
$pin($m['original_package'],'genuine_Engine_u');
$log=$pin($m['native_log'],'native_post_class_memory_log');
if(str_starts_with($log,"\xff\xfe"))$log=(string)iconv('UTF-16LE','UTF-8',substr($log,2));
$log=str_replace("\r",'',$log);
$memory=$pin($m['native_memory'],'native_MiniDump_MEMORY64');
$u32=static fn(string $blob,int $at):int=>(int)unpack('V',substr($blob,$at,4))[1];
$u64=static fn(string $blob,int $at):int=>(int)unpack('P',substr($blob,$at,8))[1];
$ck('dump_MDMP_header',substr($memory,0,4)==='MDMP');
$streamCount=$u32($memory,8);$streamDir=$u32($memory,12);$ranges=[];
for($i=0;$i<$streamCount;$i++){
    $header=$streamDir+$i*12;
    if($u32($memory,$header)!==9)continue;
    $section=$u32($memory,$header+8);
    $num=$u64($memory,$section);$offset=$u64($memory,$section+8);
    for($j=0;$j<$num;$j++){
        $at=$section+16+$j*16;
        $addr=$u64($memory,$at);$size=$u64($memory,$at+8);
        $ranges[]=[$addr,$addr+$size,$offset];$offset+=$size;
    }
}
usort($ranges,static fn(array $a,array $b)=>$a[0]<=>$b[0]);
$starts=array_column($ranges,0);
$readVA=static function(int $va,int $length)use($ranges,$starts,$memory):string{
    if($length<0||$length>5000000)throw new RuntimeException('Invalid memory read size');
    $low=0;$high=count($starts)-1;$hit=-1;
    while($low<=$high){$mid=($low+$high)>>1;
        if($starts[$mid]<=$va){$hit=$mid;$low=$mid+1;}else{$high=$mid-1;}
    }
    if($hit<0||$va+$length>$ranges[$hit][1])
        throw new RuntimeException('Absent native memory at '.sprintf('%08x',$va).'/'.$length);
    return substr($memory,$ranges[$hit][2]+$va-$ranges[$hit][0],$length);
};
$word=static fn(int $va):int=>$u32($readVA($va,4),0);
$nameGlobal=hexdec(substr($m['native_globals']['FName_Names_TArray_VA'],2));
$nameTable=$word($nameGlobal);
$nameCount=$word($nameGlobal+4);
$ck('compiled_name_table_array_bounds',
 $nameTable!==0&&$nameCount>1000&&$nameCount<150000);
$nameCache=[];
$nativeName=static function(int $index)use($word,$readVA,$nameTable,$nameCount,&$nameCache):string{
    if($index===0)return 'None';
    if(isset($nameCache[$index]))return $nameCache[$index];
    if($index<0||$index>=$nameCount)throw new RuntimeException('Invalid native FName index '.$index);
    $entry=$word($nameTable+4*$index);
    if($entry===0)throw new RuntimeException('Missing native FNameEntry');
    $text='';
    for($i=0;$i<256;$i++){
        $value=unpack('v',$readVA($entry+12+$i*2,2))[1];
        if($value===0)break;
        $text.=mb_chr($value,'UTF-8');
    }
    return $nameCache[$index]=$text;
};
$objectName=static function(int $pointer)use($word,$nativeName):string{
    return $pointer===0?'None':$nativeName($word($pointer+0x20));
};
$mapName=static function(int $ref,array $exports,array $imports):string{
    if($ref===0)return 'None';
    $row=$ref>0?$exports[$ref-1]??null:$imports[-$ref-1]??null;
    if(!is_array($row))throw new RuntimeException('Bad source reference '.$ref);
    return (string)($row['objectNameText']??'');
};
$ck('real_compiled_engine_memory_FName_resolution',
 $nativeName(1)==='ByteProperty'&&$nativeName(20)==='Core');
$ck('genuine_memory_dump_after_last_class_loaded',
 str_contains($log,'G5_UCLASS_NEST_DUMP_DONE')
 &&str_contains($log,'G5_UCLASS_NEST_COMPLETE count=90'));
$regex='/G5_UCLASS_NEST idx=(\d+) name=([A-Za-z_][A-Za-z0-9_]*) depdata=([0-9a-f]{8}) deps=(\d+) depmax=(\d+) impdata=([0-9a-f]{8}) imports=(\d+) impmax=(\d+) within=([A-Za-z_][A-Za-z0-9_]*) config=([A-Za-z_][A-Za-z0-9_]*)/i';
preg_match_all($regex,$log,$events,PREG_SET_ORDER);
$ck('ninety_native_UClass_finish_events',count($events)===90);
$original=$m['original_package']['path'];
$pkg=new P($original);$exports=$pkg->getExports();$imports=$pkg->getImports();
$seen=[];$foreign=[];
foreach($events as $line){
    $idx=(int)$line[1];$name=$line[2];$ex=$exports[$idx]??null;
    if(!is_array($ex)||(int)$ex['classIndex']!==0
       ||strcasecmp((string)$ex['objectNameText'],$name)!==0){
        $foreign[]=$idx.':'.$name;continue;
    }
    $prefix='class_'.$name.'_'.$idx;
    $ck('native_source_class_called_once_'.$prefix,!isset($seen[$idx]));$seen[$idx]=true;
    $src=C::inspect($original,$pkg,$idx,true,'ut99-v432');
    $ck('source_archive_complete_'.$prefix,
        ($src['source_file_archive_complete']??false)===true);
    $depBase=hexdec($line[3]);$depNum=(int)$line[4];$depMax=(int)$line[5];
    $impBase=hexdec($line[6]);$impNum=(int)$line[7];$impMax=(int)$line[8];
    $ck('class_dependency_count_'.$prefix,$depNum===count($src['dependencies']));
    $ck('class_import_count_'.$prefix,$impNum===count($src['package_imports']));
    $ck('native_TArray_capacity_valid_'.$prefix,$depNum<=$depMax&&$impNum<=$impMax);
    for($j=0;$j<$depNum;$j++){
        $actual=unpack('V3',$readVA($depBase+12*$j,12));
        $srcdep=$src['dependencies'][$j]??null;
        $ck('native_dep_deep_'.$prefix.'_'.$j,
            is_array($srcdep)&&(int)$actual[2]===(int)$srcdep['deep']);
        $ck('native_dep_crc_'.$prefix.'_'.$j,
            is_array($srcdep)&&(int)$actual[3]===(int)$srcdep['script_text_crc']);
        $nativeRef=$objectName((int)$actual[1]);
        $sourceRef=is_array($srcdep)?$mapName((int)$srcdep['class_reference'],$exports,$imports):'';
        $ck('native_dep_real_ref_name_'.$prefix.'_'.$j,
            $sourceRef!==''&&strcasecmp($nativeRef,$sourceRef)===0);
        $depRecords++;$depFields+=3;
    }
    for($j=0;$j<$impNum;$j++){
        $nameIdx=$word($impBase+4*$j);
        $expect=(string)($src['package_imports'][$j]['text']??'');
        $ck('native_import_real_fname_'.$prefix.'_'.$j,
            $expect!==''&&strcasecmp($nativeName($nameIdx),$expect)===0);
        $importEntries++;
    }
    $srcWithin=$mapName((int)$src['class_within_reference'],$exports,$imports);
    $ck('ClassWithin_live_native_referent_'.$prefix,
        strcasecmp($srcWithin,$line[9])===0);
    $withinEntries++;$matched++;
}
sort($foreign);
$ck('two_unrelated_core_class_linker_indices',
    $foreign===['2:Object','328:Subsystem']);
$ck('all_88_original_UClass_nested_buffers_compared',$matched===88);
$ck('no_original_class_covered_twice',count($seen)===$matched);
$ck('method_remains_OPEN',$m['accepted']===false);
echo json_encode([
  'ok'=>$fail===[], 'checks'=>$checked,'passed'=>$checked-count($fail),
  'failures'=>$fail,'source_Engine_original_UClass_records'=>$matched,
  'real_native_FDependency_elements'=>$depRecords,
  'real_native_FDependency_member_comparisons'=>$depFields,
  'real_native_PackageImport_FName_matches'=>$importEntries,
  'real_native_ClassWithin_referent_matches'=>$withinEntries,
  'actual_native_FName_lookup_count'=>count($nameCache),
  'dump_region_count'=>count($ranges),'foreign_Core_classes'=>$foreign,
  'full_G5_method_acceptance'=>'OPEN',
  'gap'=>'43 original Engine.u classes unvisited, full runtime defaults, inherited methods, malformed byte/error semantics and other versions remain OPEN'
],JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail===[]?0:1);
