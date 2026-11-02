#!/usr/bin/env php
<?php
/**
 * Classic UE1 UProperty family source-typed, valid-layout package fixtures.
 * Unreal v1.200 Core/Src/UnProp.cpp and UT99 v400 Core/Src/UnProp.cpp.
 * Positive and negative LOAD/SAVE native-tail cases; no C++ runtime oracle.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tagged;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$compact=static function(int $value):string {
    $negative=$value<0;$n=abs($value);
    $first=($n&63)|($negative?128:0);$n>>=6;
    if($n===0){return chr($first);}
    $s=chr($first|64);
    do{$b=$n&127;$n>>=7;$s.=chr($b|($n?128:0));}while($n);
    return $s;
};
$build=static function(string $kind,int $ver,string $tail)use($compact):string {
    $names=['None',$kind,'TestProperty','Core','Class','Gameplay'];
    $nameTable='';
    foreach($names as $n){$nameTable.=($ver>=64?$compact(strlen($n)+1):'').$n."\0".pack('V',0);}
    $base=$ver>=68?56:44;
    $im=$compact(3).$compact(4).($ver<50?$compact(0):pack('V',0)).$compact(1);
    // v35 UObject::Serialize persists TempNum/TempMax and FMainFrame
    // before the ordinary property terminator and legacy state/group.
    $prefix=$ver<40?pack('V2',0,0):'';
    if($ver<47){$prefix.=$compact(0).$compact(0).str_repeat("\0",8);}
    $data=$prefix."\x00".($ver<57?"\x00":"").($ver<58?"\x00":"").$tail;
    $no=$base;$io=$no+strlen($nameTable);$eo=$io+strlen($im);
    $ex=$compact(-1).$compact(0).($ver<50?'':pack('V',0)).$compact(2).pack('V',0)
        .$compact(strlen($data)).$compact(512);
    $sum=pack('V9',0x9e2a83c1,$ver,0,count($names),$no,1,$eo,1,$io);
    $sum.=$ver>=68?str_repeat("\0",16).pack('V',0):pack('V2',0,0);
    $table=$sum.$nameTable.$im.$ex;
    if(strlen($table)>512){throw new RuntimeException('Test package tables overlap payload');}
    return $table.str_repeat("\0",512-strlen($table)).$data;
};
$base=static function(int $dim=1,int $flags=0,int $cat=5,int $super=0,int $next=0,string $rep='')use($compact):string {
    return $compact($super).$compact($next).pack('V2',$dim&0xffffffff,$flags&0xffffffff).$compact($cat).$rep;
};
$paths=[];$cases=[];$failed=[];
$check=static function(string $name,bool $ok)use(&$cases,&$failed):void {
    $cases[$name]=$ok;if(!$ok){$failed[]=$name;}
};
$parse=static function(string $kind,int $ver,string $tail)use(&$paths,$build):array {
    $path=tempnam(sys_get_temp_dir(),'ue1-props');
    if($path===false){throw new RuntimeException('Fixture file creation failed');}
    $paths[]=$path;file_put_contents($path,$build($kind,$ver,$tail));
    $pkg=new Package($path);
    if($pkg->validatePackage()!==[]){throw new RuntimeException('UE1 package fixture malformed');}
    // The synthetic archive has no genuine producer identity. Versions
    // 60/61 and 69 overlap engines; select the intended original C++
    // source for this fixture explicitly, never infer from version alone.
    $family=$ver>=62?'ut99-v432':'epic-ue1';
    $tags=Tagged::inspect($path,$pkg,0,$family);
    if(($tags['status']??null)!=='tag_envelope_decoded'){
        throw new RuntimeException('Inherited tagged property stream not decoded');
    }
    return [Native::inspect($path,$pkg,0,$tags,$family),$tags];
};
$reject=static function(string $kind,int $ver,string $body)use($parse):bool {
    try{$parse($kind,$ver,$body);return false;}catch(RuntimeException){return true;}
};
try {
    foreach([
        ['IntProperty',50,'',[]],
        ['FloatProperty',50,'',[]],
        ['BoolProperty',50,'',[]],
        ['NameProperty',50,'',[]],
        ['ByteProperty',50,$compact(0),['enum_reference'=>0]],
        ['ObjectProperty',50,$compact(-1),['property_class_reference'=>-1]],
        ['ClassProperty',50,$compact(-1).$compact(1),['property_class_reference'=>-1,'meta_class_reference'=>1]],
        ['StringProperty',50,pack('V',128),['string_size'=>128]],
        ['StructProperty',50,$compact(1),['struct_reference'=>1]],
        ['StrProperty',69,'',[]],
        ['ArrayProperty',69,$compact(1),['inner_reference'=>1]],
        ['FixedArrayProperty',69,$compact(1).pack('V',5),['inner_reference'=>1,'fixed_count'=>5]],
        ['MapProperty',69,$compact(1).$compact(-1),['key_reference'=>1,'value_reference'=>-1]],
    ] as [$kind,$ver,$extra,$expected]) {
        [$r]=$parse($kind,$ver,$base().$extra);
        $check("valid_{$ver}_{$kind}",$r['status']==='native_property_record_complete'
            &&$r['array_dim']===1&&$r['property_flags']===0
            &&$r['category_name']==='Gameplay'
            &&$r['super_field_reference']===0&&$r['next_field_reference']===0
            &&$r['rep_offset']===null
            &&$r['subclass_fields']===$expected
            &&$r['extension_bytes']===0);
    }
    [$legacy,$legacyTag]=$parse('IntProperty',35,$base());
    $check('v35_legacy_property_native_prefix',
        $legacy['status']==='native_property_record_complete'
        &&$legacy['array_dim']===1
        &&is_array($legacyTag['legacy_database']??null)
        &&is_array($legacyTag['serialized_state_frame']??null)
        &&$legacyTag['native_tail_bytes']===11);
    [$old]=$parse('StringProperty',61,$base().pack('V',12));
    $check('v61_fixed_UStringProperty_gate',
        $old['status']==='native_property_record_complete'
        &&$old['subclass_fields']['string_size']===12);
    [$new]=$parse('StrProperty',68,$base());
    $check('v68_dynamic_UStrProperty_gate',$new['status']==='native_property_record_complete'
        &&$new['subclass_fields']===[]);
    [$p,$tag]=$parse('ByteProperty',50,$base(3,0x00080060,5,-1,1,pack('v',65534)).$compact(1));
    $check('v50_replication_and_old_flags',($p['status']??'')==='native_property_record_complete'
        &&$p['array_dim']===3&&$p['rep_offset']===65534
        &&$p['property_flags']===0x00080060
        // Explicit v1.200 source preserves the saved flags verbatim.
        // UT99's v400 legacy loader instead clears 0x00080040.
        &&$p['effective_property_flags']===0x00080060
        &&$p['effective_property_flags_if_ut99_v400_load']===0x20
        &&$p['source_dependent_legacy_net_flags']===true
        &&$p['super_field_reference']===-1&&$p['next_field_reference']===1
        &&$p['subclass_fields']['enum_reference']===1);
    $check('v50_inherited_old_UObject_envelope',
        ($tag['legacy_state_name']['text']??'')==='None'
        &&($tag['legacy_group_name']['text']??'')==='None');
    [$p]=$parse('BoolProperty',69,$base(2,0x00080020,5,0,0,pack('v',17)));
    $check('v69_net_no_old_clear',($p['rep_offset']??null)===17
        &&$p['effective_property_flags']===0x00080020
        &&$p['array_dim']===2);
    [$p]=$parse('BoolProperty',69,$base()."\xaa");
    $check('bool_bitmask_not_serialized_on_LOAD_SAVE',
        $p['status']==='native_property_has_extension'
        &&$p['extension_bytes']===1
        &&$p['runtime_bool_bitmask_linked']===false);
    // Neither original C98 nor T99 UProperty::Serialize checks ArrayDim
    // positivity at the archived field; native post-load Link semantics
    // remain separate and must not be fabricated as an FArchive exception.
    [$zero]=$parse('IntProperty',69,$base(0));
    $check('v69_source_persists_zero_ArrayDim_without_early_archive_error',
        $zero['status']==='native_property_record_complete'
        &&$zero['array_dim']===0
        &&$zero['archive_array_dim_preserved_without_linkage_validation']===true
        &&$zero['extension_bytes']===0
        &&$zero['runtime_property_offsets_linked']===false);
    [$negative]=$parse('IntProperty',50,$base(-1));
    $check('v50_source_persists_negative_ArrayDim_without_early_archive_error',
        $negative['status']==='native_property_record_complete'
        &&$negative['array_dim']===-1
        &&$negative['archive_array_dim_preserved_without_linkage_validation']===true
        &&$negative['extension_bytes']===0
        &&$negative['runtime_property_offsets_linked']===false);
    $check('reject_invalid_name_index',$reject('BoolProperty',69,$base(1,0,40)));
    $check('reject_negative_name_index',$reject('IntProperty',69,$base(1,0,-1)));
    $check('reject_positive_super_ref',$reject('IntProperty',69,$base(1,0,5,2)));
    $check('reject_negative_next_ref',$reject('IntProperty',50,$base(1,0,5,0,-2)));
    $check('reject_missing_rep_offset',$reject('IntProperty',69,$base(1,0x20)));
    $check('reject_missing_enum_ref',$reject('ByteProperty',50,$base()));
    $check('reject_out_of_map_enum',$reject('ByteProperty',69,$base().$compact(5)));
    $check('reject_null_MetaClass',$reject('ClassProperty',69,$base().$compact(1).$compact(0)));
    [$emptyFixedString]=$parse('StringProperty',50,$base().pack('V',0));
    $check('v50_UStringProperty_zero_StringSize_source_persists',
        $emptyFixedString['status']==='native_property_record_complete'
        &&$emptyFixedString['subclass_fields']['string_size']===0
        &&$emptyFixedString['extension_bytes']===0);
    [$negativeFixedString]=$parse('StringProperty',50,$base().pack('V',0xffffffff));
    $check('v50_UStringProperty_negative_StringSize_source_persists',
        $negativeFixedString['status']==='native_property_record_complete'
        &&$negativeFixedString['subclass_fields']['string_size']===-1
        &&$negativeFixedString['extension_bytes']===0);
    $check('reject_truncated_FixedArray_count',$reject('FixedArrayProperty',69,$base().$compact(1)));
    [$zeroFixedArray]=$parse('FixedArrayProperty',69,$base().$compact(1).pack('V',0));
    $check('v69_FixedArrayProperty_zero_Count_source_persists',
        $zeroFixedArray['status']==='native_property_record_complete'
        &&$zeroFixedArray['subclass_fields']['inner_reference']===1
        &&$zeroFixedArray['subclass_fields']['fixed_count']===0
        &&$zeroFixedArray['extension_bytes']===0);
    [$negativeFixedArray]=$parse('FixedArrayProperty',69,$base().$compact(1).pack('V',0xffffffff));
    $check('v69_FixedArrayProperty_negative_Count_source_persists',
        $negativeFixedArray['status']==='native_property_record_complete'
        &&$negativeFixedArray['subclass_fields']['inner_reference']===1
        &&$negativeFixedArray['subclass_fields']['fixed_count']===-1
        &&$negativeFixedArray['extension_bytes']===0);
    $check('reject_map_second_ref_missing',$reject('MapProperty',69,$base().$compact(1)));
    $check('reject_truncated_UField',$reject('BoolProperty',69,$compact(0)));
    $check('reject_early_StrProperty',$reject('StrProperty',50,$base()));
    $check('reject_early_ArrayProperty',$reject('ArrayProperty',50,$base().$compact(1)));
    $check('reject_early_FixedArrayProperty',$reject('FixedArrayProperty',50,$base().$compact(1).pack('V',2)));
    $check('reject_early_MapProperty',$reject('MapProperty',50,$base().$compact(1).$compact(1)));
    $check('reject_late_UStringProperty',$reject('StringProperty',69,$base().pack('V',20)));
}finally{foreach($paths as $path){@unlink($path);}}
$ok=$failed===[];
echo json_encode(['ok'=>$ok,'passed'=>count($cases)-count($failed),'total'=>count($cases),
    'failed'=>$failed,'cases'=>$cases,
    'source'=>['v1.200 Core/Src/UnProp.cpp:101-114,208-214,298-305,390-395,460-466,519-526,576-582',
        'UT99 v400 Core/Src/UnProp.cpp:117-134,324-329,475-481,673-679,743-750,859-864,954-961,1099-1106,1282-1289,1491-1497']
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
