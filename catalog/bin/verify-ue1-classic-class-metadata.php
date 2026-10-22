#!/usr/bin/env php
<?php
/** Classic UE1 UClass classIndex=0 source-typed zero-script archive path. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tag;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$compact=static function(int $n):string{
    $negative=$n<0;$n=abs($n);
    $first=($n&63)|($negative?128:0);$n>>=6;
    if(!$n){return chr($first);}
    $s=chr($first|64);
    do{$v=$n&127;$n>>=7;$s.=chr($v|($n?128:0));}while($n);
    return $s;
};
$build=static function(int $ver,string $body,int $flags=0)use($compact):string{
    $names=['None','Class','Example','Core','Friendly','System','Package','MyInt','Flag','Color','Vector'];
    $nbytes='';
    foreach($names as $name){$nbytes.=($ver>=64?$compact(strlen($name)+1):'').$name."\0".pack('V',0);}
    $hdrSize=$ver>=68?56:44;
    $import=$compact(3).$compact(1).($ver<50?$compact(0):pack('V',0)).$compact(1);
    $nameOffset=$hdrSize;$importOffset=$nameOffset+strlen($nbytes);
    $exportOffset=$importOffset+strlen($import);
    $export=$compact(0).$compact(0).($ver<50?'':pack('V',0)).$compact(2)
           .pack('V',$flags).$compact(strlen($body)).$compact(512);
    $summary=pack('V9',0x9e2a83c1,$ver,0,count($names),$nameOffset,1,$exportOffset,1,$importOffset);
    $summary.=$ver>=68?str_repeat("\0",16).pack('V',0):pack('V2',0,0);
    $tables=$summary.$nbytes.$import.$export;
    if(strlen($tables)>512){throw new RuntimeException('Fixture tables too large');}
    return $tables.str_repeat("\0",512-strlen($tables)).$body;
};
$body=static function(int $ver,array $opt=[])use($compact):string{
    $scriptSize=(int)($opt['scriptSize']??0);
    $old=$ver<40?pack('V2',0,0):'';
    if($ver<47){$old.=$compact(0).$compact(0).str_repeat("\0",8);}
    if($ver<57){$old.=$compact(0);}
    if($ver<58){$old.=$compact(0);}
    $prefix=$old
          .$compact((int)($opt['super']??0)).$compact(0)
          .$compact(0).$compact((int)($opt['children']??0))
          .$compact((int)($opt['friendly']??4))
          .pack('V2',51,22).pack('V',$scriptSize&0xffffffff);
    if($scriptSize!==0){return $prefix."\x53";}
    $state=str_repeat("\x11",8).str_repeat("\x22",8).pack('vV',12,0x12345678);
    $native=($ver<=61?pack('V',64):'').pack('V',0x0f0f0f0f)
        .pack('V4',1,2,3,4);
    $dependencies=$compact((int)($opt['depCount']??1));
    if(!array_key_exists('depCount',$opt) || $opt['depCount']===1){
        $dependencies.=$compact((int)($opt['depRef']??-1)).pack('V2',1,0xdeadbeef);
    }
    $packageImports=$compact((int)($opt['importCount']??1))
      .(($opt['importCount']??1)===1?$compact((int)($opt['importName']??3)):'');
    $within=$ver>=62?$compact((int)($opt['within']??1)).$compact((int)($opt['config']??5)):'';
    $defaults=$opt['defaultRaw']??$compact((int)($opt['firstDefault']??0));
    return $prefix.$state.$native.$dependencies.$packageImports.$within.$defaults.($opt['extension']??'');
};
$paths=[];$checks=[];$fail=[];
$check=static function(string $key,bool $ok)use(&$checks,&$fail):void{
    $checks[$key]=$ok;if(!$ok){$fail[]=$key;}
};
$parse=static function(int $ver,string $data,int $flags=0)use($build,&$paths):array{
    $tmp=tempnam(sys_get_temp_dir(),'ue1class');
    if($tmp===false){throw new RuntimeException('No temp file');}
    $paths[]=$tmp;
    file_put_contents($tmp,$build($ver,$data,$flags));
    $pkg=new Package($tmp);
    if($pkg->validatePackage()!==[]){throw new RuntimeException('Synthetic UClass package invalid');}
    $tags=Tag::inspect($tmp,$pkg,0);
    $native=Native::inspect($tmp,$pkg,0,$tags);
    return [$tags,$native];
};
$bad=static function(int $ver,string $data)use($parse):bool{
    try{$parse($ver,$data);return false;}catch(RuntimeException){return true;}
};
try{
    [$f,$n]=$parse(50,$body(50));
    $check('v50_class_disk_fields',($f['status']??'')==='class_metadata_no_script_no_defaults_complete'
        &&$f['source_line']===51&&$f['source_text_pos']===22
        &&$f['old_class_record_size']===64&&$f['class_flags']===0x0f0f0f0f
        &&$f['friendly_name']['text']==='Friendly'&&$f['script_size']===0
        &&$f['state_flags']===0x12345678&&$f['label_table_offset']===12);
    $check('v50_dependency_and_package_import',
        count($f['dependencies'])===1&&$f['dependencies'][0]['class_reference']===-1
        &&$f['dependencies'][0]['deep']===1
        &&$f['dependencies'][0]['script_text_crc']===0xdeadbeef
        &&$f['package_imports'][0]['text']==='Core');
    $check('native_class_route_no_double_property_parse',
        $n['status']==='class_metadata_decoded_in_class_reader'
        &&$n['metadata_status']==='class_metadata_no_script_no_defaults_complete');
    [$u]=$parse(69,$body(69));
    $check('v69_class_source_gate',($u['status']??'')==='class_metadata_no_script_no_defaults_complete'
        &&$u['old_class_record_size']===null
        &&$u['class_within_reference']===1
        &&$u['class_config_name']['text']==='System'
        &&$u['class_guid_raw_hex']==='01000000020000000300000004000000');
    [$old]=$parse(35,$body(35));
    $check('v35_legacy_database_and_mainframe',
        ($old['status']??'')==='class_metadata_no_script_no_defaults_complete'
        &&isset($old['legacy_database'],$old['legacy_main_frame'],$old['legacy_state_name'],$old['legacy_group_name']));
    [$s]=$parse(69,$body(69,['scriptSize'=>1]));
    $check('nonempty_script_never_falsely_complete',
        $s['status']==='class_script_expr_unimplemented'
        &&$s['script_size']===1&&$s['unparsed_bytes']===1);
    $intTag=$compact(7)."\x22".pack('V',1234).$compact(0);
    [$de]=$parse(69,$body(69,['defaultRaw'=>$intTag]));
    $check('class_default_int_tag_decoded_schema_not_fabricated',
        $de['status']==='class_default_tags_structurally_decoded_schema_unverified'
        &&$de['defaults_tag_count']===1
        &&$de['default_property_tags'][0]['name']==='MyInt'
        &&$de['default_property_tags'][0]['value']===1234
        &&$de['default_property_tags'][0]['on_disk_value_bytes']===4
        &&$de['defaults_schema_verified']===false);
    $check('class_default_metadata_preserves_native_tail',
        $de['class_guid_raw_hex']==='01000000020000000300000004000000'
        &&$de['script_size']===0&&$de['unparsed_bytes']===0);
    $boolTag=$compact(8)."\x83".$compact(0);
    [$bool]=$parse(50,$body(50,['defaultRaw'=>$boolTag]));
    $check('class_default_bool_tag_no_payload',
        $bool['status']==='class_default_tags_structurally_decoded_schema_unverified'
        &&$bool['default_property_tags'][0]['bool_value']===true
        &&$bool['default_property_tags'][0]['on_disk_value_bytes']===0);
    $colorTag=$compact(9)."\x2a".$compact(9)."\x01\x02\x03\x04".$compact(0);
    [$color]=$parse(69,$body(69,['defaultRaw'=>$colorTag]));
    $check('class_default_struct_color_tag_shared_codec',
        $color['default_property_tags'][0]['struct_name']['text']==='Color'
        &&$color['default_property_tags'][0]['value_kind']==='fcolor_serialized_bytes'
        &&$color['default_property_tags'][0]['value']['serialized_channel_bytes']===[1,2,3,4]);
    $arrTag=$compact(7)."\xa2".chr(3).pack('V',99).$compact(0);
    [$arr]=$parse(69,$body(69,['defaultRaw'=>$arrTag]));
    $check('class_default_array_index_uses_same_epic_tag_codec',
        $arr['default_property_tags'][0]['array_index']===3
        &&$arr['default_property_tags'][0]['value']===99);
    [$ext]=$parse(69,$body(69,['defaultRaw'=>$intTag.'x']));
    $check('nonempty_class_defaults_with_unknown_extra_bytes_are_partial',
        $ext['status']==='class_default_tags_structurally_decoded_schema_unverified'
        &&$ext['unparsed_bytes']===1);
    $check('missing_default_tag_info_rejected',$bad(69,$body(69,['firstDefault'=>4])));
    $check('invalid_default_tag_type_rejected',$bad(69,$body(69,['defaultRaw'=>$compact(7)."\x00".$compact(0)])));
    $check('invalid_default_tag_name_rejected',$bad(69,$body(69,['defaultRaw'=>$compact(123).$compact(0)])));
    $check('default_tag_value_overrun_rejected',$bad(69,$body(69,['defaultRaw'=>$compact(7)."\x22"."\x01"])));
    $check('missing_default_None_terminator_rejected',$bad(69,$body(69,['defaultRaw'=>$compact(7)."\x22".pack('V',2)])));
    [$v35ref]=$parse(35,$body(35,['defaultRaw'=>$compact(7)."\x25".$compact(-1).$compact(0)]));
    $check('v35_legacy_compact_default_object_ref',
        $v35ref['default_property_tags'][0]['value_kind']==='v35_compact_object_reference'
        &&$v35ref['default_property_tags'][0]['value']===-1);
    [$ex]=$parse(69,$body(69,['extension'=>"\xab"]));
    $check('unexpected_extension_not_complete',
        $ex['status']==='class_metadata_has_extension' &&$ex['extension_bytes']===1);
    [$stack]=$parse(69,$body(69),0x02000000);
    $check('RF_HasStack_not_guessed',
        $stack['status']==='class_state_frame_unimplemented');
    $check('bad_super_ref_rejected',$bad(69,$body(69,['super'=>2])));
    $check('bad_children_ref_rejected',$bad(69,$body(69,['children'=>-2])));
    $check('friendly_None_rejected',$bad(69,$body(69,['friendly'=>0])));
    $check('negative_script_size_rejected',$bad(50,$body(50,['scriptSize'=>-1])));
    $check('dependency_count_overflow',$bad(69,$body(69,['depCount'=>100])));
    $check('dependency_ref_out_of_range',$bad(69,$body(69,['depRef'=>2])));
    $check('invalid_package_import_name',$bad(69,$body(69,['importName'=>100])));
    $check('invalid_config_name',$bad(69,$body(69,['config'=>100])));
    $check('invalid_class_within',$bad(69,$body(69,['within'=>2])));
    $check('negative_package_import_count',$bad(69,$body(69,['importCount'=>-1])));
    $check('truncated_body_rejected',$bad(69,substr($body(69),0,5)));
}finally{foreach($paths as $p){@unlink($p);}}
$ok=$fail===[];
echo json_encode(['ok'=>$ok,'passed'=>count($checks)-count($fail),
    'total'=>count($checks),'failures'=>$fail,'cases'=>$checks,
    'source'=>['v1.200 Core/Src/UnObj.cpp:433-521, UnClass.cpp:236,476,921,1067,1440',
        'UT99 v400 Core/Src/UnObj.cpp:478-546, UnClass.cpp:239,593,712,914,1048']],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
