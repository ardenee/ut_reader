#!/usr/bin/env php
<?php
/** Genuine UT99 v432 UClass default-value independent compiled oracle.
 * Compares every serialized tagged class default to live compiled FProperty
 * offset and UClass Defaults BYTE buffer in a pinned native process dump.
 * One explicit config override must match original genuine INI.
 * This is a SUBSET of the full method and must not become full acceptance.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-UCLASS-DEFAULTS-NATIVE-608.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$errors=[];$classes=0;$seen=[];$exact=0;$overrides=0;$tagsCount=0;$types=[];
$ck=static function(string $key,bool $pass)use(&$checks,&$errors):void{
    $checks++;
    if(!$pass&&count($errors)<25)$errors[]=$key;
};
$pin=static function(array $spec,string $key)use($ck):string{
    $buf=is_file($spec['path'])?file_get_contents($spec['path']):false;
    $ck('SHA256_'.$key,is_string($buf)&&hash('sha256',$buf)===$spec['sha256']);
    return is_string($buf)?$buf:'';
};
$packageBytes=$pin($m['original_package'],'original_Engine_v69');
$pin($m['original_compiled_core'],'native_original_Core_v432');
$memory=$pin($m['actual_compiled_session_memory'],'compiled_native_MEMORY64');
$log=$pin($m['native_class_log'],'compiled_UClass_90');
$ini=$pin($m['native_config_ini'],'original_engine_configuration');
if(str_starts_with($log,"\xff\xfe"))$log=(string)iconv('UTF-16LE','UTF-8',substr($log,2));
$ck('native_ClassName_and_Defaults_offsets_from_real_compiled_source',
    str_contains($log,'G5_UCLASS_NEST idx=')
    &&str_contains($log,'G5_UCLASS_NEST_COMPLETE count=90')
    &&str_contains($memory,'MDMP'));
$ck('native_inifile_contains_explicit_config_override',
    preg_match('/^ServerName=Another UT Server\r?$/m',$ini)===1);
$u32=static fn(string $b,int $i):int=>(int)unpack('V',substr($b,$i,4))[1];
$u64=static fn(string $b,int $i):int=>(int)unpack('P',substr($b,$i,8))[1];
$entries=$u32($memory,8);$dir=$u32($memory,12);$regions=[];
for($i=0;$i<$entries;$i++){
    $pos=$dir+12*$i;
    if($u32($memory,$pos)!==9)continue;
    $p=$u32($memory,$pos+8);$count=$u64($memory,$p);$offset=$u64($memory,$p+8);
    for($j=0;$j<$count;$j++){
        $entry=$p+16+$j*16;
        $addr=$u64($memory,$entry);$size=$u64($memory,$entry+8);
        $regions[]=[$addr,$addr+$size,$offset];$offset+=$size;
    }
}
usort($regions,static fn($a,$b)=>$a[0]<=>$b[0]);
$starts=array_column($regions,0);
$rd=static function(int $addr,int $size)use($regions,$starts,$memory):string{
    if($addr<0||$size<0||$size>1048576)throw new RuntimeException('Invalid VM read');
    $lo=0;$hi=count($starts)-1;$found=-1;
    while($lo<=$hi){$mid=($lo+$hi)>>1;if($starts[$mid]<=$addr){$found=$mid;$lo=$mid+1;}else{$hi=$mid-1;}}
    if($found<0||$addr+$size>$regions[$found][1])
        throw new RuntimeException('Unmapped VM address '.sprintf('%08x',$addr));
    return substr($memory,$regions[$found][2]+$addr-$regions[$found][0],$size);
};
$word=static fn(int $addr):int=>$u32($rd($addr,4),0);
$namesVA=hexdec(substr($m['compiled_FName_global_TArray_VA'],2));
$nameTable=$word($namesVA);$nameCount=$word($namesVA+4);$nameCache=[];
$ck('live_v432_FName_table', $nameCount>1000&&$nameCount<100000);
$nativeName=static function(int $idx)use(&$nameCache,$nameCount,$nameTable,$word,$rd):string{
    if($idx===0)return 'None';
    if(isset($nameCache[$idx]))return $nameCache[$idx];
    if($idx<0||$idx>=$nameCount)throw new RuntimeException('Bad native name idx');
    $ref=$word($nameTable+4*$idx);
    if($ref===0)throw new RuntimeException('Absent native FNameEntry');
    $utf16='';
    for($i=0;$i<256;$i++){
        $b=$rd($ref+12+2*$i,2);
        if($b==="\0\0")break;
        $utf16.=$b;
    }
    return $nameCache[$idx]=(string)iconv('UTF-16LE','UTF-8',$utf16);
};
$objName=static fn(int $ptr):string=>$ptr===0?'None':$nativeName($word($ptr+0x20));
$ck('native_global_FName_independent_resolver',
    $nativeName(1)==='ByteProperty'&&$nativeName(20)==='Core');
$pattern='/G5_UCLASS_NEST idx=(\d+) name=([A-Za-z_][A-Za-z0-9_]*) depdata=([0-9a-f]{8}) deps=(\d+) depmax=(\d+) impdata=([0-9a-f]{8}) imports=(\d+) impmax=(\d+) within=(\w+) config=(\w+)/i';
preg_match_all($pattern,$log,$events,PREG_SET_ORDER);
$ck('original_compiled_UClass_event_count',count($events)===90);
$pkg=new P($m['original_package']['path']);
$exports=$pkg->getExports();$imports=$pkg->getImports();$pkgNames=$pkg->getNames();
$foreign=[];
foreach($events as $ev){
    $id=(int)$ev[1];$class=$ev[2];$ex=$exports[$id]??null;
    if(!is_array($ex)||(int)$ex['classIndex']!==0||
        strcasecmp((string)$ex['objectNameText'],$class)!==0){
        $foreign[]=$id.':'.$class;continue;
    }
    $ck('original_UClass_unique_'.$class,!isset($seen[$id]));$seen[$id]=true;
    $classVM=$word(hexdec($ev[3]));
    $ck('live_native_self_UClass_identity_'.$class,$objName($classVM)===$class);
    $defaults=$word($classVM+0x4d8);$defaultsBytes=$word($classVM+0x4dc);
    $ck('native_Defaults_valid_size_'.$class,
        $defaults!==0&&$defaultsBytes>0&&$defaultsBytes<300000);
    $map=[];$links=[];$ptr=$word($classVM+0x64);
    while($ptr!==0&&!isset($links[$ptr])&&count($links)<10000){
        $links[$ptr]=true;
        $nm=$objName($ptr);
        $map[strtolower($nm)]=$ptr;
        $ptr=$word($ptr+0x4c);
    }
    $source=C::inspect($m['original_package']['path'],$pkg,$id,true,'ut99-v432');
    $ck('source_Class_defaults_archive_complete_'.$class,
        ($source['source_file_archive_complete']??false)===true);
    foreach($source['default_property_tags'] as $tag){
        $key=$class.'.'.$tag['name'];
        $type=(string)$tag['type'];$types[$type]=($types[$type]??0)+1;$tagsCount++;
        $prop=$map[strtolower((string)$tag['name'])]??0;
        $ck('actual_compiled_UProperty_found_'.$key,$prop!==0);
        if($prop===0)continue;
        $offset=$word($prop+0x48)+(int)$tag['array_index']*$word($prop+0x38);
        $ck('loaded_UProperty_Offset_in_Defaults_'.$key,
            $offset>=0&&$offset<$defaultsBytes);
        if($offset<0||$offset>=$defaultsBytes)continue;
        if($type==='BoolProperty'){
            $actual=($word($defaults+$offset)&$word($prop+0x5c))!==0;
            $expected=(bool)$tag['bool_value'];
        }elseif($type==='NameProperty'){
            $actual=$nativeName($word($defaults+$offset));
            $expected=(string)($pkgNames[(int)$tag['value']]['name']??'');
        }elseif($type==='ObjectProperty'){
            $ref=(int)$tag['value'];
            $referent=$ref>0?($exports[$ref-1]??null):($ref<0?($imports[-$ref-1]??null):null);
            $expected=$ref===0?'None':(string)($referent['objectNameText']??'');
            $actual=$objName($word($defaults+$offset));
        }elseif($type==='StrProperty'){
            $ptrStr=$word($defaults+$offset);$num=$word($defaults+$offset+4);
            $ck('native_string_bound_'.$key,$num<20000&&($num===0||$ptrStr!==0));
            $actual=($num>0&&$num<20000)?rtrim((string)iconv(
                'UTF-16LE','UTF-8',$rd($ptrStr,$num*2)),"\0"):'';
            $expected=(string)($tag['value']['ascii_text']??'');
        }else{
            $n=match($type){
                'ByteProperty'=>1,'FloatProperty','IntProperty'=>4,
                'StructProperty'=>(int)$tag['serialized_size'],
                default=>throw new RuntimeException('Unknown actual tagged type '.$type)
            };
            $expected=substr($packageBytes,(int)$tag['value_offset'],$n);
            $actual=$rd($defaults+$offset,$n);
        }
        if($expected===$actual){$exact++;continue;}
        $override=$m['config_overridden_original'];
        $known=($class===$override['class']
            &&$tag['name']===$override['property']
            &&$type===$override['type']
            &&$expected===$override['source']
            &&$actual===$override['compiled_runtime']
            &&preg_match('/^ServerName='.preg_quote($actual,'/').'\r?$/m',$ini)===1);
        $ck('only_native_config_override_is_source_proven_'.$key,$known);
        if($known)$overrides++;
    }
    $classes++;
}
sort($foreign);
$ck('foreign_Core_Class_disambiguation',$foreign===['2:Object','328:Subsystem']);
$ck('real_Engine_88_UClass_objects_loaded',$classes===$m['native_loaded_classes']);
$ck('all_source_default_tags_reached',$tagsCount===$m['original_class_defaults_tag_count']);
$ck('type_distribution_matches_genuine_source',$types==$m['expected_type_counts']);
$ck('607_native_exact_default_values',$exact===$m['expected_exact_serialized_to_native']);
$ck('one_verified_native_config_override',$overrides===$m['expected_config_override']);
$ck('method_still_has_unclosed_transitive_proofs',
    $m['strict_full_method_accepted']===false);
echo json_encode([
 'ok'=>$errors===[],'checks'=>$checks,'passed'=>$checks-count($errors),
 'failures'=>$errors,'original_Engine_classes'=>$classes,
 'source_class_default_tags'=>$tagsCount,'native_exact_value_matches'=>$exact,
 'native_config_ini_overrides'=>$overrides,
 'typed_default_values'=>$types,'resolved_native_FName_indices'=>count($nameCache),
 'compiled_memory_regions'=>count($regions),
 'full_method_accepted'=>false,
 'remaining'=>'43 genuine class exports, inherited loader and error/archive consumption/source variants'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
exit($errors===[]?0:1);
