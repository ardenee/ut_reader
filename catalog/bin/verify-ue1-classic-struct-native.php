#!/usr/bin/env php
<?php
/** Classic UE1 UField/UStruct/UState/UFunction source-ordered archive fixtures. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$compact=static function(int $v):string {
    $neg=$v<0;$v=abs($v);$head=($v&63)|($neg?128:0);$v>>=6;
    if(!$v){return chr($head);}
    $out=chr($head|64);
    do{$b=$v&127;$v>>=7;$out.=chr($b|($v?128:0));}while($v);
    return $out;
};
$build=static function(string $kind,int $ver,string $payload)use($compact):string {
    $names=['None',$kind,'Instance','Core','Class','Friendly'];
    $summarySize=$ver>=68?56:44;
    $nam='';
    foreach($names as $n){$nam.=($ver>=64?$compact(strlen($n)+1):'').$n."\0".pack('V',0);}
    $import=$compact(3).$compact(4).($ver<50?$compact(0):pack('V',0)).$compact(1);
    $prefix=$ver<40?pack('V2',0,0):'';
    if($ver<47){$prefix.=$compact(0).$compact(0).str_repeat("\0",8);}
    $body=$prefix."\x00".($ver<57?"\x00":"").($ver<58?"\x00":"").$payload;
    $no=$summarySize;$io=$no+strlen($nam);$eo=$io+strlen($import);
    $exp=$compact(-1).$compact(0).($ver<50?'':pack('V',0)).$compact(2)
       .pack('V',0).$compact(strlen($body)).$compact(512);
    $sum=pack('V9',0x9e2a83c1,$ver,0,count($names),$no,1,$eo,1,$io);
    $sum.=$ver>=68?str_repeat("\0",16).pack('V',0):pack('V2',0,0);
    $table=$sum.$nam.$import.$exp;
    if(strlen($table)>512){throw new RuntimeException('Fixture overflow');}
    return $table.str_repeat("\0",512-strlen($table)).$body;
};
$field=static fn(int $super=0,int $next=0):string=>$compact($super).$compact($next);
$struct=static function(int $size=0,int $friendly=5,int $child=0)use($compact,$field):string {
    return $field().$compact(0).$compact($child).$compact($friendly).pack('V3',12,9,$size&0xffffffff);
};
$state=static fn():string=>str_repeat("\x33",8).str_repeat("\x44",8).pack('vV',17,0x99112233);
$function=static function(int $ver,bool $net=false)use($compact):string{
    $b=$ver<=63?pack('v',16):'';
    $b.=pack('v',7);
    if($ver<=63){$b.=chr(2);}
    $b.=chr(11);
    if($ver<=63){$b.=pack('v',3);}
    $b.=pack('V',$net?0x40:0x20);
    if($net){$b.=pack('v',22);}
    return $b;
};
$files=[];$cases=[];$fail=[];
$check=static function(string $label,bool $pass)use(&$cases,&$fail):void {
    $cases[$label]=$pass;if(!$pass)$fail[]=$label;
};
$inspect=static function(string $kind,int $ver,string $body)use($build,&$files):array{
    $p=tempnam(sys_get_temp_dir(),'ue1-struct');
    if(!$p){throw new RuntimeException('No temp file');}
    $files[]=$p;
    file_put_contents($p,$build($kind,$ver,$body));
    $pkg=new Package($p);
    if($pkg->validatePackage()!==[]){throw new RuntimeException('Malformed UE1 fixture summary');}
    $tags=Tags::inspect($p,$pkg,0);
    if(($tags['status']??'')!=='tag_envelope_decoded'){throw new RuntimeException('UObject tag envelope unavailable');}
    return [Native::inspect($p,$pkg,0,$tags),$tags];
};
$reject=static function(string $type,int $ver,string $bytes)use($inspect):bool{
    try{$inspect($type,$ver,$bytes);return false;}catch(RuntimeException){return true;}
};
try{
    [$f,$tags]=$inspect('Field',50,$field(-1,1));
    $check('v50_UField_inherited_refs',$f['status']==='native_field_record_complete'
        &&$f['super_field_reference']===-1&&$f['next_field_reference']===1
        &&$tags['legacy_state_name']['text']==='None');
    [$f]=$inspect('Field',69,$field());
    $check('v69_UField_empty',$f['status']==='native_field_record_complete');
    [$f]=$inspect('Field',69,$field()."\xaa");
    $check('UField_extension_not_complete',$f['status']==='native_field_has_extension'
        &&$f['extension_bytes']===1);
    [$s]=$inspect('Struct',50,$struct());
    $check('v50_UStruct_source_fields',$s['status']==='native_struct_record_complete'
        &&$s['script_size']===0&&$s['source_line']===12
        &&$s['source_text_pos']===9&&$s['friendly_name']['text']==='Friendly');
    [$s]=$inspect('Struct',69,$struct(2)."\x53");
    $check('UStruct_nonzero_code_not_accepted',$s['status']==='native_struct_script_expr_unimplemented'
        &&$s['script_size']===2
        &&$s['script_archive']['script_memory_decoded']===1
        &&$s['script_archive']['unsupported_branch']!==null);
    [$ss]=$inspect('Struct',50,$struct(5)."\x20".$compact(-1));
    $check('v50_UStruct_compact_object_ref_script_archive',
        $ss['status']==='native_struct_record_complete'
        &&$ss['script_archive']['script_memory_decoded']===5
        &&$ss['script_archive']['archive_bytes_consumed']===2
        &&$ss['script_archive']['expression_preview'][0]['value']===-1);
    [$ss]=$inspect('Struct',69,$struct(2)."\x25\x26");
    $check('v69_UStruct_source_token_expressions',
        $ss['status']==='native_struct_record_complete'
        &&$ss['script_archive']['script_memory_decoded']===2
        &&count($ss['script_archive']['expression_preview'])===2);
    [$st]=$inspect('State',69,$struct(2)."\x25\x26".$state());
    $check('v69_UState_script_then_StateFlags',
        $st['status']==='native_state_record_complete'
        &&$st['script_archive']['script_memory_decoded']===2
        &&$st['state_flags']===0x99112233);
    $check('reject_Struct_memwidth_overrun',
        $reject('Struct',69,$struct(1)."\x20".$compact(-1)));
    [$s]=$inspect('State',69,$struct().$state());
    $check('v69_UState_masks_and_flags',$s['status']==='native_state_record_complete'
        &&$s['probe_mask_hex']==='3333333333333333'
        &&$s['ignore_mask_hex']==='4444444444444444'
        &&$s['label_table_offset']===17&&$s['state_flags']===0x99112233);
    [$s]=$inspect('State',35,$struct().$state());
    $check('v35_UState_legacy_prefix',$s['status']==='native_state_record_complete');
    [$s]=$inspect('State',50,$struct().$state()."\xff");
    $check('UState_extensions_not_hidden',$s['status']==='native_state_has_extension'
        &&$s['extension_bytes']===1);
    [$fn]=$inspect('Function',50,$struct().$function(50,true));
    $check('v50_UFunction_old_fields_NET',$fn['status']==='native_function_record_complete'
        &&$fn['legacy_parms_size']===16&&$fn['native_index']===7
        &&$fn['legacy_num_parms']===2&&$fn['operator_precedence']===11
        &&$fn['legacy_return_value_offset']===3
        &&$fn['function_flags']===0x40&&$fn['rep_offset']===22);
    [$fn]=$inspect('Function',69,$struct().$function(69,false));
    $check('v69_UFunction_new_fields_no_net',$fn['status']==='native_function_record_complete'
        &&$fn['legacy_parms_size']===null&&$fn['legacy_num_parms']===null
        &&$fn['legacy_return_value_offset']===null&&$fn['native_index']===7
        &&$fn['operator_precedence']===11&&$fn['function_flags']===0x20&&$fn['rep_offset']===null);
    [$fn]=$inspect('Function',69,$struct().$function(69,true));
    $check('v69_UFunction_NET_conditional_rep',$fn['status']==='native_function_record_complete'
        &&$fn['rep_offset']===22);
    [$fn]=$inspect('Function',69,$struct().$function(69,true)."\xff");
    $check('UFunction_extra_bytes_not_complete',$fn['status']==='native_function_has_extension'
        &&$fn['extension_bytes']===1);
    $check('reject_UField_bad_import',$reject('Field',50,$field(-2,0)));
    $check('reject_UField_bad_export',$reject('Field',69,$field(0,2)));
    $check('reject_UStruct_bad_script_text',$reject('Struct',69,$field().$compact(-2)));
    $check('reject_UStruct_bad_children',$reject('Struct',69,$field().$compact(0).$compact(2)));
    $check('reject_UStruct_Friendly_None',$reject('Struct',50,$struct(0,0)));
    $check('reject_UStruct_negative_script_size',$reject('Struct',69,$struct(-1)));
    $check('reject_UState_truncated',$reject('State',69,$struct()."\0\0"));
    $check('reject_UFunction_missing_NET_rep',$reject('Function',69,$struct().substr($function(69,true),0,-2)));
    $check('reject_UFunction_truncated_old_fields',$reject('Function',50,$struct()."\0"));
    $check('reject_UFunction_truncated_UField',$reject('Function',69,$field(0)));
}finally{foreach($files as $p){@unlink($p);}}
$ok=$fail===[];
echo json_encode(['ok'=>$ok,'passed'=>count($cases)-count($fail),'total'=>count($cases),
 'failures'=>$fail,'cases'=>$cases,
 'sources'=>['Epic v1.200 Core/Src/UnClass.cpp:236,476,1358,1440',
   'UT99 v400 Core/Src/UnClass.cpp:239,593,712,1342, FUNC_Net=0x40']],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
