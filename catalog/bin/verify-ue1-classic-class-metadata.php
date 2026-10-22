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
    $names=['None','Class','Example','Core','Friendly','System','Package'];
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
    $defaults=$compact((int)($opt['firstDefault']??0));
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
    [$de]=$parse(69,$body(69,['firstDefault'=>4]));
    $check('nonempty_defaults_never_falsely_complete',
        $de['status']==='class_default_tag_properties_unimplemented'
        &&$de['first_default_property_name']['text']==='Friendly');
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
