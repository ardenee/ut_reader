#!/usr/bin/env php
<?php
/** Actual original May17 compiled UE1 property reference load, exact individual exports. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$root=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G3-C98-MAY1998-600-REFERENCE-LOADED.json'),true,512,JSON_THROW_ON_ERROR);
$n=0;$failed=[];
$check=static function(string $name,bool $ok)use(&$n,&$failed):void {
    $n++;if(!$ok && count($failed)<30)$failed[]=$name;
};
$check('authentic stock May source and 600 captured, no entire G3 acceptance',
    ($m['status']??'')==='G3_C98_MAY17_600_TRUE_LOADED_CLASS_ENUM_STRUCT_REFERENCE_TARGETS_POSITIVE_ONLY'
    &&$m['G3_full_contracts_accepted']===0
    &&$m['actual_original_native_events']===600);
foreach($m['original_binary_packages'] as $name=>$pin){
    $path=(string)$pin['path'];
    $check('actual original May compiled/package SHA '.$name,
        is_file($path)&&filesize($path)===(int)$pin['bytes']
        &&hash_file('sha256',$path)===$pin['sha256']);
}
$check('May17 original compiled Core.dll SHA authentic',
    $m['original_binary_packages']['Core.dll']['sha256']
    ==='9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92');
foreach($m['source_pins'] as $name=>$pin){
    $path=(string)$pin['path'];
    $check('original May19 v1.200 source SHA '.$name,
        is_file($path)&&filesize($path)===(int)$pin['bytes']
        &&hash_file('sha256',$path)===$pin['sha256']);
}
$source=(string)file_get_contents($m['source_pins']['UnProp.cpp']['path']);
$namesource=(string)file_get_contents($m['source_pins']['UnName.cpp']['path']);
$check('Epic v1.200 UByte serializer archives Enum',
    preg_match('/void UByteProperty::Serialize\s*\( FArchive& Ar \).*?UProperty::Serialize\( Ar \);\s*Ar << Enum;/s',$source)===1);
$check('Epic v1.200 UObjectProperty serializer archives PropertyClass',
    preg_match('/void UObjectProperty::Serialize\s*\( FArchive& Ar \).*?UProperty::Serialize\( Ar \);\s*Ar << PropertyClass;/s',$source)===1);
$check('Epic v1.200 UClassProperty serializes MetaClass after UObjectProperty and validates nonnull',
    preg_match('/void UClassProperty::Serialize\s*\( FArchive& Ar \).*?UObjectProperty::Serialize\( Ar \);\s*Ar << MetaClass;\s*check\(MetaClass\);/s',$source)===1);
$check('Epic v1.200 UStructProperty serializer archives Struct',
    preg_match('/void UStructProperty::Serialize\s*\( FArchive& Ar \).*?UProperty::Serialize\( Ar \);\s*Ar << Struct;/s',$source)===1);
$check('original Epic FName interned case-insensitive source semantics',
    str_contains($namesource,'appStricmp( ValidName, Hash->Name )==0'));
foreach($m['native_cdb_evidence_pins'] as $name=>$pin){
    $path=$root.'/'.$pin['path'];
    $check('original May Microsoft CDB event or disasm SHA '.$name,
        is_file($path)&&filesize($path)===(int)$pin['bytes']
        &&hash_file('sha256',$path)===$pin['sha256']);
}
$disasm=iconv('UTF-16','UTF-8',(string)file_get_contents(
    $root.'/'.$m['native_cdb_evidence_pins']['real_compiled_4_serializer_disassembly']['path']));
$check('all four authentic compiled native bodies present',
    is_string($disasm) &&str_contains($disasm,'10141c0d')
    &&str_contains($disasm,'10142e0d')&&str_contains($disasm,'1014342d')
    &&str_contains($disasm,'10143f6d'));
$raw=iconv('UTF-16','UTF-8',(string)file_get_contents(
    $root.'/'.$m['native_cdb_evidence_pins']['real_native_600_transcript']['path']));
if(!is_string($raw))throw new RuntimeException('Invalid WinDbg UTF16 native transcript');
$sections=preg_split('/(?m)^G3_ORIG_(BYTE|OBJECT|CLASS_META|STRUCT)_REF\s*$/',$raw,-1,PREG_SPLIT_DELIM_CAPTURE);
$check('600 actually executed compiled method reference callbacks',
    is_array($sections)&&count($sections)===1201);
$records=[];$counts=[];$null=0;$malformed=0;
for($i=1;$i<count($sections);$i+=2){
    $kind=$sections[$i];$frag=$sections[$i+1];$heads=[];$names=[];
    foreach(preg_split('/\R/',$frag) as $line){
        $line=trim($line);
        if(preg_match('/^[0-9a-f]{8}\h+((?:[0-9a-f]{8}\h*){1,4})$/i',$line,$a)){
            $heads[]=array_map('hexdec',preg_split('/\h+/',trim($a[1])));
        }
        if(preg_match('/^[0-9a-f]{8}\h+"([^"]+)"/i',$line,$a))$names[]=$a[1];
    }
    $proper=count($heads)>=2 &&count($heads[0])===2
        &&count($heads[1])===($kind==='CLASS_META'?3:1)&&count($names)>=1;
    if(!$proper){$malformed++;continue;}
    $ptr=$kind==='CLASS_META'?$heads[1][2]:$heads[1][0];
    $target=$ptr===0?null:($names[1]??null);
    if($ptr!==0&&$target===null){$malformed++;continue;}
    if($ptr===0&&(!str_contains($frag,'G3_NULL_NATIVE_REF')||count($names)!==1)){
        $malformed++;continue;
    }
    $records[]=['kind'=>$kind,'linker'=>sprintf('%08x',$heads[0][0]),
        'export_index'=>$heads[0][1],'name'=>$names[0],'target_name'=>$target,'ptr'=>$ptr,
        'property_class_ptr'=>$kind==='CLASS_META'?$heads[1][0]:null];
    $counts[$kind]=($counts[$kind]??0)+1;
    if($ptr===0)$null++;
}
$check('all source/native real compiled reference rows parsed',
    $malformed===0&&count($records)===600);
foreach($m['original_compiled_ref_fields'] as $kind=>$v){
    $check('original compiled field type event count '.$kind,
        ($counts[$kind]??0)===$v['native_count']);
}
$check('original null UByte.Enum references found',$null===65);
$map=$m['native_original_linker_map'];$packages=[];$per=[];$seen=[];
foreach($map as $linker=>$name){
    $file=$m['original_binary_packages'][$name]['path'];
    $pkg=new Package($file);
    $check('original linked package valid '.$name,$pkg->validatePackage()===[]);
    $check('original file version61 '.$name,$pkg->getHeader()['version']===61);
    $packages[$linker]=['pkg'=>$pkg,'file'=>$file,
      'exports'=>$pkg->getExports(),'imports'=>$pkg->getImports()];
}
$selector=['BYTE'=>['ByteProperty','enum_reference'],
    'OBJECT'=>[null,'property_class_reference'],
    'CLASS_META'=>['ClassProperty','meta_class_reference'],
    'STRUCT'=>['StructProperty','struct_reference']];
foreach($records as $i=>$rec){
    $p=$packages[$rec['linker']]??null;
    $check('original native linker selects genuine archive '.$i,$p!==null);
    if(!$p)continue;
    $idx=(int)$rec['export_index'];$export=$p['exports'][$idx]??null;
    $check('original native zero-index export exists '.$i,$export!==null);
    if(!$export)continue;
    $dupe=$rec['kind'].':'.$rec['linker'].':'.$idx;
    $check('original native method loaded this object once '.$dupe,!isset($seen[$dupe]));
    $seen[$dupe]=true;
    $c=(int)$export['classIndex'];
    $type=$c<0?(string)($p['imports'][-$c-1]['objectNameText']??''):'';
    $want=$selector[$rec['kind']][0];$key=$selector[$rec['kind']][1];
    $check('actual native property serializer source type '.$i,
        $want!==null?$type===$want:in_array($type,['ClassProperty','ObjectProperty'],true));
    $check('actual original native source export name '.$i,
        strcasecmp((string)$export['objectNameText'],$rec['name'])===0);
    $saved=Native::inspect($p['file'],$p['pkg'],$idx,
        Tags::inspect($p['file'],$p['pkg'],$idx,'epic-ue1'),'epic-ue1');
    $ref=$saved['subclass_fields'][$key]??null;
    $check('source persisted correct subclass reference '.$i,is_int($ref));
    if(!is_int($ref))continue;
    $resolved=$ref<0?(string)($p['imports'][-$ref-1]['objectNameText']??''):
        ($ref>0?(string)($p['exports'][$ref-1]['objectNameText']??''):null);
    $check('compiled original native pointer null iff saved reference None '.$i,
        ($rec['ptr']===0)===($ref===0));
    $check('original live referenced class, enum or struct target FName '.$i,
        ($resolved===null&&$rec['target_name']===null)
        ||($resolved!==null&&$rec['target_name']!==null
            &&strcasecmp($resolved,$rec['target_name'])===0));
    $check('original exact property subclass saved reader archive complete '.$i,
        ($saved['status']??'')==='native_property_record_complete');
    if($rec['kind']==='CLASS_META'){
        $check('original compiled MetaClass nonnull after source check '.$i,$ref!==0&&$rec['ptr']!==0);
    }
    $per[$rec['kind']][$type]=($per[$rec['kind']][$type]??0)+1;
}
$check('all 600 original real native event identity validations counted',count($seen)===600);
$ok=$failed===[];
echo json_encode(['ok'=>$ok,'checks'=>$n,'passed'=>$n-count($failed),
  'failed'=>$failed,'original_native_observed'=>count($records),'by_field'=>$counts,
  'native_subclass_types'=>$per,'original_null_enum_fields'=>$null,
  'scope'=>'May17 original compiled 600 UByteProperty, UObjectProperty, UClassProperty MetaClass and UStructProperty pointer references identified by real original exports',
  'unobserved_malformed_or_other_versions_proven'=>false,'G3_FULL'=>false],
  JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
