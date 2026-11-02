#!/usr/bin/env php
<?php
/** Source-defined v227-only property class overrides vs inherited UProperty::Serialize archive. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as Sources;
$repo=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents(
    $repo.'/catalog/compliance/UE1-O06-G3-UNREAL-CHRONOLOGICAL-SOURCE-CHAIN.json'),
    true,512,JSON_THROW_ON_ERROR);
$header=$m['revisions'][7]['source_files']['UnType.h'];
if(hash_file('sha256',$header['path'])!==$header['sha256'])throw new RuntimeException('Source modified');
$source=(string)file_get_contents($header['path']);
$checks=0;$failure=[];
$check=static function(string $what,bool $value)use(&$checks,&$failure):void{
    $checks++;if(!$value)$failure[]=$what;
};
$check('original Unreal v1.227 exact UnType.h pinned sha and byte count',
    strlen($source)===$header['bytes']);
$parts=[];
foreach([
 'pointer'=>'UPointerProperty',
 'button'=>'UButtonProperty',
 'any'=>'UAnyProperty',
] as $k=>$name){
    $start=strpos($source,'class CORE_API '.$name.' : public UProperty');
    $check('original 227 Core/Inc source declares '.$name,$start!==false);
    if($start===false)continue;
    $next=strpos($source,'class CORE_API U',$start+8);
    $parts[$k]=substr($source,$start,$next===false?null:$next-$start);
}
$check('only v227 UButtonProperty declares a new Serialize override',
    isset($parts['button'],$parts['pointer'],$parts['any'])
    &&str_contains($parts['button'],'void Serialize(FArchive& Ar);')
    &&!str_contains($parts['pointer'],'void Serialize(FArchive& Ar);')
    &&!str_contains($parts['any'],'void Serialize(FArchive& Ar);'));
$check('original v227 pointer and button tag aliases are NAME_IntProperty',
    str_contains($parts['pointer']??'','return NAME_IntProperty;')
    &&str_contains($parts['button']??'','return NAME_IntProperty;'));
$check('original v227 any property uses NAME_VectorProperty alias',
    str_contains($parts['any']??'','return NAME_VectorProperty;'));
$check('Unreal source defines CppType but pointer has no explicit Serialize',
    str_contains($parts['pointer']??'','FString CppType;')
    &&!str_contains($parts['pointer']??'','void Serialize(FArchive& Ar);'));
$compact=static function(int $value):string{
    $minus=$value<0;$value=abs($value);
    $first=($value&63)|($minus?128:0);$value>>=6;
    if(!$value)return chr($first);
    $s=chr($first|64);
    do{$b=$value&127;$value>>=7;$s.=chr($b|($value?128:0));}while($value);
    return $s;
};
$build=static function(string $kind,int $version,int $licensee,string $tail)use($compact):string{
    $names=['None',$kind,'TestProperty','Core','Class','Gameplay'];
    $nameTable='';
    foreach($names as $n)$nameTable.=$compact(strlen($n)+1).$n."\0".pack('V',0);
    $base=56;
    $imports=$compact(3).$compact(4).pack('V',0).$compact(1);
    $data="\0".$tail;
    $nameOffset=$base;$importOffset=$nameOffset+strlen($nameTable);
    $exportOffset=$importOffset+strlen($imports);
    $export=$compact(-1).$compact(0).pack('V',0).$compact(2).pack('V',0)
        .$compact(strlen($data)).$compact(512);
    $summary=pack('V9',0x9e2a83c1,$version,$licensee,6,$nameOffset,1,$exportOffset,1,$importOffset)
        .str_repeat("\0",16).pack('V',0);
    $table=$summary.$nameTable.$imports.$export;
    if(strlen($table)>512)throw new RuntimeException('Fixture table overlap');
    return $table.str_repeat("\0",512-strlen($table)).$data;
};
$base=$compact(0).$compact(0).pack('V2',1,0x20).$compact(5).pack('v',7);
$files=[];
$inspect=static function(string $kind,int $version,int $licensee,string $body,string $family)
        use($build,&$files):array{
    $path=tempnam(sys_get_temp_dir(),'u227src');
    if(!is_string($path))throw new RuntimeException('Cannot create source format fixture');
    $files[]=$path;
    file_put_contents($path,$build($kind,$version,$licensee,$body));
    $pkg=new Package($path);
    $errors=$pkg->validatePackage();
    if($errors!==[])throw new RuntimeException('Fixture malformed: '.json_encode($errors));
    $stream=Tags::inspect($path,$pkg,0,$family);
    return Native::inspect($path,$pkg,0,$stream,$family);
};
try{
    foreach(['PointerProperty'=>'NAME_IntProperty','AnyProperty'=>'NAME_VectorProperty'] as $kind=>$alias){
        $rec=$inspect($kind,69,227,$base,Sources::UNREAL_V227);
        $check('original v227 inherited UProperty LOAD completely read '.$kind,
            $rec['status']==='native_property_record_complete'
            &&$rec['property_kind']===strtolower($kind)
            &&$rec['array_dim']===1
            &&$rec['property_flags']===0x20
            &&$rec['rep_offset']===7
            &&$rec['super_field_reference']===0
            &&$rec['next_field_reference']===0
            &&$rec['category_name']==='Gameplay'
            &&$rec['subclass_fields']===[]
            &&$rec['extension_bytes']===0);
        $check('exact C227 declared 4-bit property class GetID alias '.$kind,
            $rec['declared_4bit_tag_alias']===$alias
            &&$rec['new_v227_property_runtime_serializeitem_verified']===false);
        foreach([[69,0,Sources::UT99_V432],[61,0,Sources::EPIC_UE1]] as [$v,$lic,$family]){
            $rejected=false;
            try{$inspect($kind,$v,$lic,$base,$family);}catch(RuntimeException $e){$rejected=true;}
            $check('source isolation rejects '.$kind.' as '.$family,$rejected);
        }
        $notCompleted=false;
        try{$extension=$inspect($kind,69,227,$base."\x7f",Sources::UNREAL_V227);
            $notCompleted=($extension['status']??'')!=='native_property_record_complete';}
        catch(RuntimeException $e){$notCompleted=true;}
        $check('inherits no additional native bytes, extra byte never reported complete '.$kind,$notCompleted);
    }
    // Originally this regression documented the then-unverified override.
    // A genuine matching 227k Core.dll has now proved the exact additional
    // FString Caption archive, including 2 genuinely shipped ButtonProperty
    // instances. Retain source isolation and verify the completed serializer.
    $button=$inspect('ButtonProperty',69,227,$base."\x05Test\x00",Sources::UNREAL_V227);
    $check('explicit C227 UButtonProperty Serialize reads inherited base AND Caption',
        $button['status']==='native_buttonproperty_caption_record_complete'
        &&$button['subclass_fields']['caption']==='Test'
        &&$button['subclass_fields']['caption_count_signed']===5
        &&$button['extension_bytes']===0
        &&$button['new_v227_property_runtime_serializeitem_verified']===false);
    $check('C227 compiled C++ UButton serializer provenance retained without inventing SerializeItem',
        $button['subclass_fields']['caption_serialize_source']
            ==='original-227k-Core-UButtonProperty-Serialize-0x10080010'
        &&$button['subclass_fields']['runtime_serializeitem_verified']===false);
}finally{
    foreach($files as $f)@unlink($f);
}
$ok=$failure===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'passed'=>$checks-count($failure),
    'failed'=>$failure,'introduced_227_properties_inherited_base'=>['UPointerProperty','UAnyProperty'],
    'explicit_changed_serialize_not_inferred'=>'UButtonProperty',
    'archive_source'=>'Unreal v1.227 Core/Inc/UnType.h',
    'not_a_full_G3_02_or_15_or_16_acceptance'=>true],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
