#!/usr/bin/env php
<?php
/** UE1 G5: genuine v432 loaded UClass inherited native stored fields,
 * resolved against the original production parser. 88 x 12 values.
 * Script token expansion and malformed consumed bytes remain OPEN.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-UCLASS-INHERITED-NATIVE-88.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$errors=[];$classes=0;$fields=0;$matchedByField=[];
$ck=static function(string $key,bool $passed)use(&$checks,&$errors):void{
    $checks++;if(!$passed&&count($errors)<32)$errors[]=$key;
};
$pin=static function(array $ref,string $name)use($ck):string{
    $raw=is_file($ref['path'])?file_get_contents($ref['path']):false;
    $ck('source_oracle_SHA256_'.$name,
        is_string($raw)&&hash('sha256',$raw)===$ref['sha256']);
    return is_string($raw)?$raw:'';
};
foreach($m['pinned_sources'] as $i=>$source)$pin($source,'original_cpp_'.$i);
$pin($m['genuine_package'],'genuine_Engine_u');
$pin($m['compiled_Core'],'compiled_v432_Core');
$bin=$pin($m['compiled_UClass_mem'],'native_MEMORY64_loaded_UClass');
$log=$pin($m['compiled_class_log'],'native_UClass_member_events');
if(str_starts_with($log,"\xff\xfe"))$log=(string)iconv('UTF-16LE','UTF-8',substr($log,2));
$ck('live_MINIDUMP_signature',substr($bin,0,4)==='MDMP');
$u32=static fn(string $s,int $o):int=>(int)unpack('V',substr($s,$o,4))[1];
$u64=static fn(string $s,int $o):int=>(int)unpack('P',substr($s,$o,8))[1];
$cnt=$u32($bin,8);$list=$u32($bin,12);$segments=[];
for($i=0;$i<$cnt;$i++){
    $dir=$list+$i*12;
    if($u32($bin,$dir)!==9)continue;
    $position=$u32($bin,$dir+8);
    $ranges=$u64($bin,$position);$offset=$u64($bin,$position+8);
    for($j=0;$j<$ranges;$j++){
        $p=$position+16+$j*16;
        $virtual=$u64($bin,$p);$size=$u64($bin,$p+8);
        $segments[]=[$virtual,$virtual+$size,$offset];
        $offset+=$size;
    }
}
usort($segments,static fn($a,$b)=>$a[0]<=>$b[0]);
$starts=array_column($segments,0);
$readVA=static function(int $virtual,int $size)use($segments,$starts,$bin):string{
    if($virtual<0||$size<0||$size>1000000)throw new RuntimeException('Invalid native VM request');
    $lo=0;$hi=count($starts)-1;$hit=-1;
    while($lo<=$hi){
        $mid=($lo+$hi)>>1;
        if($starts[$mid]<=$virtual){$hit=$mid;$lo=$mid+1;}else{$hi=$mid-1;}
    }
    if($hit<0||$virtual+$size>$segments[$hit][1])
        throw new RuntimeException('Unmapped native VM byte range '.sprintf('%08x',$virtual));
    return substr($bin,$segments[$hit][2]+$virtual-$segments[$hit][0],$size);
};
$word=static fn(int $va):int=>$u32($readVA($va,4),0);
$fnamesVA=hexdec('10295D30');
$fnames=$word($fnamesVA);$nameCount=$word($fnamesVA+4);$names=[];
$ck('compiled_runtime_NameTable_sane',
    $fnames!==0&&$nameCount>1000&&$nameCount<100000);
$nativeName=static function(int $idx)use(&$names,$fnames,$nameCount,$readVA,$word):string{
    if($idx===0)return 'None';
    if(isset($names[$idx]))return $names[$idx];
    if($idx<0||$idx>=$nameCount)throw new RuntimeException('Invalid native FName index');
    $entry=$word($fnames+4*$idx);
    if($entry===0)throw new RuntimeException('Null native FNameEntry');
    $chars='';
    for($i=0;$i<256;$i++){
        $pair=$readVA($entry+12+2*$i,2);
        if($pair==="\0\0")break;
        $chars.=$pair;
    }
    return $names[$idx]=(string)iconv('UTF-16LE','UTF-8',$chars);
};
$objectName=static fn(int $obj):string=>$obj===0?'None':$nativeName($word($obj+0x20));
$ck('native_live_object_name_resolution',
    $nativeName(20)==='Core'&&$nativeName(1)==='ByteProperty');
$pkgPath=$m['genuine_package']['path'];
$pkg=new P($pkgPath);$exports=$pkg->getExports();$imports=$pkg->getImports();
$refName=static function(int $ref)use($exports,$imports):string{
    if($ref===0)return 'None';
    $obj=$ref>0?($exports[$ref-1]??null):($imports[-$ref-1]??null);
    if(!is_array($obj))throw new RuntimeException('Unknown original export/import reference');
    return (string)$obj['objectNameText'];
};
$regex='/G5_UCLASS_NEST idx=(\d+) name=([A-Za-z_][A-Za-z0-9_]*) depdata=([0-9a-f]{8}) deps=(\d+) depmax=(\d+) impdata=([0-9a-f]{8}) imports=(\d+) impmax=(\d+) within=(\w+) config=(\w+)/';
preg_match_all($regex,$log,$events,PREG_SET_ORDER);
$ck('90_compiled_native_class_calls',count($events)===90);
$foreign=[];$seen=[];
$add=static function(string $field,bool $valid,string $class)use(&$fields,&$matchedByField,$ck):void{
    $fields++;
    if($valid)$matchedByField[$field]=($matchedByField[$field]??0)+1;
    $ck('live_inherited_'.$class.'_'.$field,$valid);
};
$meta=$m['field_map'];
foreach($events as $line){
    $id=(int)$line[1];$class=$line[2];
    $original=$exports[$id]??null;
    if(!is_array($original)||(int)$original['classIndex']!==0
      ||(int)$original['serialSize']<=0
      ||strcasecmp((string)$original['objectNameText'],$class)!==0){
        $foreign[]=$id.':'.$class;
        continue;
    }
    $ck('real_class_unique_'.$class,!isset($seen[$id]));$seen[$id]=true;
    $classVM=$word(hexdec($line[3]));
    $ck('native_class_self_FName_'.$class,$objectName($classVM)===$class);
    $source=C::inspect($pkgPath,$pkg,$id,true,'ut99-v432');
    $ck('existing_production_reader_archive_complete_'.$class,
        ($source['source_file_archive_complete']??false)===true);
    foreach([
        'super_field_ref_ptr'=>'super_field_reference',
        'next_field_ref_ptr'=>'next_field_reference',
        'script_text_ref_ptr'=>'script_text_reference',
        'children_ref_ptr'=>'children_reference'
    ] as $offset=>$key){
        $expected=$refName((int)$source[$key]);
        $actual=$objectName($word($classVM+$meta[$offset]));
        $add($key,$expected===$actual,$class);
    }
    $add('friendly_name',
        $nativeName($word($classVM+$meta['friendly_name_FName_index']))===(string)$source['friendly_name']['text'],$class);
    $add('script_size',
        $word($classVM+$meta['script_Num'])===(int)$source['script_size'],$class);
    foreach(['source_text_pos'=>'source_TextPos','source_line'=>'source_Line'] as $field=>$nativeOffset){
        $v=$word($classVM+$meta[$nativeOffset]);
        $signed=$v>=0x80000000?$v-0x100000000:$v;
        $add($field,$signed===(int)$source[$field],$class);
    }
    $add('probe_mask',
        bin2hex($readVA($classVM+$meta['probe_Mask_8bytes'],8))===(string)$source['probe_mask_hex'],$class);
    $add('ignore_mask',
        bin2hex($readVA($classVM+$meta['ignore_Mask_8bytes'],8))===(string)$source['ignore_mask_hex'],$class);
    $add('state_flags',
        $word($classVM+$meta['state_flags_u32'])===(int)$source['state_flags'],$class);
    $label=(int)unpack('v',$readVA($classVM+$meta['label_table_offset_low_u16'],2))[1];
    $add('label_table_offset',$label===(int)$source['label_table_offset'],$class);
    $classes++;
}
sort($foreign);
$ck('foreign_Core_class_records_only',$foreign===['2:Object','328:Subsystem']);
$ck('all_actual_88_Engine_UClass_loaded',
    $classes===$m['genuine_loaded_UClass_count']&&count($seen)===$classes);
$ck('every_inherited_source_field_compared',
    $fields===$m['expected_native_matches']
    &&count($matchedByField)===$m['native_comparisons_per_UClass']);
$ck('method_still_not_full_method',$m['full_method_accepted']===false);
ksort($matchedByField);
echo json_encode([
    'ok'=>$errors===[],'checks'=>$checks,'passed'=>$checks-count($errors),'failures'=>$errors,
    'real_original_UClass_verified'=>$classes,
    'compiled_inherited_fields_compared'=>$fields,
    'all_twelve_inherited_fields_by_name'=>$matchedByField,
    'native_name_indices_resolved'=>count($names),
    'full_method_accepted'=>false,
    'gaps'=>'Malformed errors/consumption, complete script bytecode element fixups and other UE1 game/version variants'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
