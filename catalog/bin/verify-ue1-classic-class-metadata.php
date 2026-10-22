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
    if($scriptSize!==0&&!array_key_exists('scriptBytes',$opt)){return $prefix."\x03";}
    $prefix.=$opt['scriptBytes']??'';
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
        &&$s['script_size']===1
        &&$s['script_archive']['unsupported_branch']!==null
        &&$s['script_archive']['script_memory_decoded']===1);
    [$op]=$parse(69,$body(69,['scriptSize'=>2,'scriptBytes'=>"\x25\x26"]));
    $check('v69_token_only_script_then_UClass_tail',
        $op['status']==='class_metadata_supported_script_no_defaults_decoded'
        &&$op['script_archive']['script_memory_decoded']===2
        &&$op['script_archive']['archive_bytes_consumed']===2
        &&$op['class_flags']===0x0f0f0f0f);
    [$i]=$parse(69,$body(69,['scriptSize'=>5,'scriptBytes'=>"\x1d".pack('V',1234)]));
    $check('v69_int_constant_typed_expression',
        $i['status']==='class_metadata_supported_script_no_defaults_decoded'
        &&$i['script_archive']['expression_preview'][0]['value']===1234
        &&$i['script_archive']['script_memory_decoded']===5);
    [$ref]=$parse(50,$body(50,['scriptSize'=>5,'scriptBytes'=>"\x20".$compact(-1)]));
    $check('v50_compact_object_script_archive_vs_memory_span',
        $ref['status']==='class_metadata_supported_script_no_defaults_decoded'
        &&$ref['script_archive']['script_memory_decoded']===5
        &&$ref['script_archive']['archive_bytes_consumed']===2
        &&$ref['script_archive']['expression_preview'][0]['value']===-1
        &&$ref['old_class_record_size']===64);
    [$nm]=$parse(69,$body(69,['scriptSize'=>5,'scriptBytes'=>"\x21".$compact(4)]));
    $check('v69_fname_constant_compact_archive',
        $nm['script_archive']['expression_preview'][0]['value']['name']==='Friendly'
        &&$nm['script_archive']['script_memory_decoded']===5
        &&$nm['script_archive']['archive_bytes_consumed']===2);
    [$str]=$parse(50,$body(50,['scriptSize'=>4,'scriptBytes'=>"\x1fHi\x00"]));
    $check('v50_terminated_ansi_script_constant',
        $str['script_archive']['expression_preview'][0]['value']==='Hi'
        &&$str['script_archive']['script_memory_decoded']===4);
    [$jmp]=$parse(69,$body(69,['scriptSize'=>3,'scriptBytes'=>"\x06".pack('v',123)]));
    $check('v69_jump_word_source_memory_archive',
        $jmp['script_archive']['expression_preview'][0]['value']===123
        &&$jmp['script_archive']['archive_bytes_consumed']===3);
    $check('reject_script_memory_overflow',
        $bad(69,$body(69,['scriptSize'=>1,'scriptBytes'=>"\x1d".pack('V',2)])));
    $check('reject_script_bad_object_ref',
        $bad(69,$body(69,['scriptSize'=>5,'scriptBytes'=>"\x20".$compact(-2)])));
    $check('reject_script_bad_name_ref',
        $bad(69,$body(69,['scriptSize'=>5,'scriptBytes'=>"\x21".$compact(200)])));
    // Independent source-ordered nested expression fixtures. The ScriptSize
    // column is the decoded C++ iCode in-memory width, not disk length.
    $nestedCases=[
        ['ut99_return_child',69,"\x04\x2c\x07",3,3],
        ['v1998_return_no_child',50,"\x04",1,1],
        ['ut99_let_property_then_int',69,"\x0f\x00".$compact(-1)."\x1d".pack('V',77),11,8],
        ['v1998_let_nested',50,"\x0f\x00".$compact(-1)."\x26",7,4],
        ['ut99_type_conversion',69,"\x3a\x24\x05",3,3],
        ['ut99_conversion_upper_range',69,"\x5a\x27",2,2],
        ['ut99_final_function_one_argument',69,"\x1c".$compact(-1)."\x26\x16",7,4],
        ['v1998_intrinsic_params',50,"\x70\x26\x16",3,3],
        ['ut99_extended_native_call',69,"\x60\x5f\x16",3,3],
        ['v1998_extended_intrinsic_call',50,"\x60\x5f\x16",3,3],
        ['ut99_context_null_skip',69,"\x19\x17".pack('v',9)."\x03\x26",6,6],
        ['ut99_iterator_then_word',69,"\x2f\x27".pack('v',44),4,4],
        ['ut99_case_no_condition',69,"\x0a\xff\xff",3,3],
        ['v1998_case_with_condition',50,"\x0a".pack('v',10)."\x27",4,4],
        ['ut99_fname_function_with_end_params',69,"\x1b".$compact(4)."\x26\x16",7,4],
        ['ut99_dynamic_array_two_args',69,"\x10\x25\x26",3,3],
        ['ut99_new_four_args',69,"\x11\x25\x26\x27\x28",5,5],
        ['v1998_begin_function_param_layout',50,"\x14\x04\x01\x02\x00\x00",6,6],
        ['ut99_letbool_two_args',69,"\x14\x25\x27",3,3],
        ['ut99_unicode_source_high_byte_termination',69,"\x34\x41\x00",3,3],
        ['ut99_struct_member_ref_nested',69,"\x36".$compact(-1)."\x17",6,3],
        ['ut99_struct_comparison_two_args',69,"\x32".$compact(-1)."\x25\x26",7,4],
        ['v1998_resize_string',50,"\x2b\x08\x26",3,3],
        ['ut99_eatstring_expression',69,"\x0e\x1fHi\x00",5,5],
        ['v1998_validate_object_nooperand',50,"\x0e",1,1],
        ['ut99_label_table_aligned',69,"\x25\x25\x25\x0c".$compact(4).pack('V',8).$compact(0).pack('V',0),20,14],
        ['ut99_switch_byte_and_expression',69,"\x05\x04\x26",3,3],
        ['ut99_meta_cast_class_expression',69,"\x13".$compact(-1)."\x27",6,3],
    ];
    foreach($nestedCases as [$label,$v,$exprBytes,$memoryBytes,$archiveBytes]){
        [$decoded]=$parse($v,$body($v,['scriptSize'=>$memoryBytes,'scriptBytes'=>$exprBytes]));
        $archive=$decoded['script_archive']??[];
        $check('source_nested_'.$label,
            $decoded['status']==='class_metadata_supported_script_no_defaults_decoded'
            &&($archive['script_memory_decoded']??-1)===$memoryBytes
            &&($archive['archive_bytes_consumed']??-1)===$archiveBytes
            &&($archive['unsupported_branch']??null)===null
            &&($decoded['class_flags']??null)===0x0f0f0f0f
            &&($decoded['defaults_tag_count']??null)===0
        );
    }
    $check('reject_truncated_ut99_nested_return_operand',
        $bad(69,$body(69,['scriptSize'=>1,'scriptBytes'=>"\x04"])));
    $check('reject_script_missing_end_function_params',
        $bad(69,$body(69,['scriptSize'=>6,'scriptBytes'=>"\x1c".$compact(-1)."\x26"])));
    [$unknownOld]=$parse(50,$body(50,['scriptSize'=>1,'scriptBytes'=>"\x34"]));
    $check('v1998_structconst_without_source_archive_case_stays_partial',
        $unknownOld['status']==='class_script_expr_unimplemented'
        &&$unknownOld['script_archive']['unsupported_branch']!==null);
    $withDefaults=$compact(7)."\x22".pack('V',1402).$compact(0);
    [$scriptDefaults]=$parse(69,$body(69,[
        'scriptSize'=>7,
        'scriptBytes'=>"\x1c".$compact(-1)."\x26\x16",
        'defaultRaw'=>$withDefaults,
    ]));
    $check('script_nested_function_then_nonempty_class_defaults',
        $scriptDefaults['status']==='class_default_tags_structurally_decoded_schema_unverified'
        &&$scriptDefaults['script_archive']['script_memory_decoded']===7
        &&$scriptDefaults['script_archive']['archive_bytes_consumed']===4
        &&$scriptDefaults['default_property_tags'][0]['value']===1402
        &&$scriptDefaults['defaults_schema_verified']===false);
    [$highUnicode]=$parse(69,$body(69,[
        'scriptSize'=>5, 'scriptBytes'=>"\x34\x01\x01\x41\x00",
    ]));
    $check('ut99_unicode_script_exact_cpp_high_byte_condition',
        $highUnicode['status']==='class_metadata_supported_script_no_defaults_decoded'
        &&$highUnicode['script_archive']['expression_preview'][0]['value']===[257,65]);
    [$nestedUnknown]=$parse(69,$body(69,[
        'scriptSize'=>2, 'scriptBytes'=>"\x39\x03",
    ]));
    $check('unknown_nested_expression_explicitly_partial',
        $nestedUnknown['status']==='class_script_expr_unimplemented'
        &&$nestedUnknown['script_archive']['script_memory_decoded']===2
        &&$nestedUnknown['script_archive']['unsupported_branch']!==null);
    $check('misaligned_UT99_label_table_rejected',
        $bad(69,$body(69,['scriptSize'=>9,
            'scriptBytes'=>"\x0c".$compact(0).pack('V',0)])));
    $check('excessive_nested_conversion_recursion_rejected',
        $bad(69,$body(69,['scriptSize'=>132,
            'scriptBytes'=>str_repeat("\x39",131)."\x25"])));
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
