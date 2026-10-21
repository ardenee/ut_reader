#!/usr/bin/env php
<?php
/** Epic v0.84a compiled x86 MSVC AActorBase field/bit offsets. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalNativeActorValueReader as Reader;
$checks=[];$bad=[];
$check=static function(string $key,bool $pass)use(&$checks,&$bad):void{
    $checks[$key]=$pass;if(!$pass)$bad[]=$key;
};
$raw=str_repeat("\0",1148);
$put=static function(int $o,string $part)use(&$raw):void{
    $raw=substr_replace($raw,$part,$o,strlen($part));
};
$put(0,pack('V',99));
$put(4,pack('g3V',1.0,-2.5,3.25,0x12345678));
$put(20,pack('g3V',4.0,5.0,6.0,7));
$put(36,pack('V3',0xffffffff,0,0x80000000));
$put(60,pack('V2',0xfffffffe,17));
$put(68,pack('C2',8,9));
$put(72,pack('V3',101,102,103));
$put(84,pack('g',2.5));
$put(92,pack('V',104));
$put(96,pack('C3',8,9,10));
$put(100,pack('g2',1.25,-2.75));
$put(112,pack('V',0xfffffffd));
$put(116,pack('g2',24.0,48.0));
$put(136,pack('V2',(1<<0)|(1<<1)|(1<<5),2));
$put(144,pack('v2',7,8));
$put(148,pack('C',12));
$put(152,pack('V5',0xffffffff,2,3,4,5));
$put(172,pack('g2',60.0,1.0));
$put(180,pack('V',1|(1<<11)));
$put(184,pack('V4',6,7,8,0xffffffff));
$put(200,pack('V',2));
$put(204,pack('C2',13,14));
$put(208,pack('V',0xfffffffc));
$put(212,pack('v2',15,16));
$put(216,pack('V6',21,22,23,31,32,33));
$put(240,pack('C3',55,56,57));
$put(244,pack('V3',100,200,300));
$put(256,pack('C10',1,2,3,4,5,6,7,8,9,10));
$put(266,pack('C10',11,12,13,14,15,16,17,18,19,20));
$put(276,pack('C10',21,22,23,24,25,26,27,28,29,30));
$put(286,pack('C',5));
$put(288,pack('V',42));
$put(292,pack('C2',3,4));
$put(296,pack('V',0xffffffff));
$put(300,pack('C',6));
$put(948,pack('V3',10,11,12));
$result=Reader::read($raw);
$v=$result['native_base_members'];
$check('native_actor_abi_sizes',
    ($v['native_x86_base_size_bytes']??null)===304
    && ($result['unresolved_native_property_pad']['byte_length']??null)===643
    && ($result['unresolved_native_private_state']['byte_length']??null)===200);
$check('native_actor_vectors_and_rotations',
    ($v['location']['xyz']??null)===[1.0,-2.5,3.25]
    && ($v['location']['w_or_flags_raw_word']??null)===0x12345678
    && ($v['draw_rotation']??null)===[-1,0,-2147483648]);
$check('native_actor_resource_refs_and_indices',
    ($v['resource_pointers_raw_words']['Class']??null)===99
    && ($v['resource_pointers_raw_words']['Texture']??null)===101
    && ($v['resource_pointers_raw_words']['AmbientSound']??null)===104
    && ($v['index_members']['iParent']??null)===-2
    && ($v['index_members']['iPendingTeleporter']??null)===-1);
$check('native_actor_scalars',
    ($v['float_members']['DrawScale']??null)===2.5
    && ($v['float_members']['AnimBase']??null)===-2.75
    && ($v['float_members']['CollisionRadius']??null)===24.0
    && ($v['float_members']['Mass']??null)===60.0
    && ($v['signed_int_members']['AnimMessage']??null)===-3);
$check('native_actor_boolean_groups',
    $v['bool_flags_136']['bStatic']===true
    && $v['bool_flags_136']['bHidden']===true
    && $v['bool_flags_136']['bSelected']===true
    && $v['bool_flags_136']['bDirectional']===false
    && $v['bool_flags_140']['bPad2']===true
    && $v['bool_flags_180']['bGravity']===true
    && $v['bool_flags_180']['bCanBeTeleported']===true
    && $v['bool_flags_200']['bSleeping']===true);
$check('native_actor_names_touches_and_class_arrays',
    ($v['fname_index_raw_words']['Name']??null)===7
    && ($v['fname_index_raw_words']['TimerMessage']??null)===15
    && ($v['touching_actor_indices']??null)===[6,7,8,-1]
    && ($v['blocked_class_refs_raw_words']??null)===[21,22,23]
    && ($v['passed_class_refs_raw_words']??null)===[31,32,33]);
$check('native_actor_script_triggers_and_timer',
    ($v['trigger_sequences'][0]??null)===1
    && ($v['trigger_frames'][9]??null)===20
    && ($v['trigger_values'][9]??null)===30
    && ($v['byte_members']['WhichTriggers']??null)===5
    && ($v['signed_int_members']['TimerCountdown']??null)===-4);
$check('native_actor_opaque_fields_not_falsely_linked',
    ($result['unresolved_native_private_state']['property_bins_raw_pointer_words']??null)===[10,11,12]
    && $result['native_base_member_values_decoded']===true
    && $result['all_native_member_values_decoded']===false
    && $result['runtime_resource_refs_resolved']===false);
try{Reader::read(substr($raw,0,1147));$badLength=false;}
catch(RuntimeException){$badLength=true;}
$check('short_actor_record_rejected',$badLength);
try{Reader::read($raw."\0");$badLength=false;}
catch(RuntimeException){$badLength=true;}
$check('oversized_actor_record_rejected',$badLength);
echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),
 'total'=>count($checks),'failures'=>$bad],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($bad===[]?0:1);
