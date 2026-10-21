#!/usr/bin/env php
<?php
/**
 * UE1 v0.867 (Unrealfile v22/v23) native UScript::SerializeExpr.
 * Valid and deliberately corrupt source-defined archived token streams.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileScriptExpressionReader as Script;
$checks=[];$fail=[];
$check=static function(string $name,bool $ok)use(&$checks,&$fail):void{
 $checks[$name]=$ok;if(!$ok)$fail[]=$name;
};
$make=static function(int $version):array{
 $labels=['Script','ScriptOne','FunctionLabel'];
 $names='';foreach($labels as $name){$names.=$name."\0".pack('V',0);}
 $data=
     chr(0x1d).pack('V',0xffffffd6)                      // EX_IntConst -42
    .chr(0x23).pack('g3',1.5,-2.0,3.0)                   // EX_VectorConst
    .chr(0x1b).pack('v',3).chr(0x24).chr(7).chr(0x16)    // EX_VirtualFunction
    .chr(0x1c).pack('V2',1,3).chr(0x25).chr(0x16)        // EX_FinalFunction
    .chr(0x38).chr(0x1d).pack('V',17)                    // conversion
    .chr(0x60).chr(0x01).chr(0x24).chr(4).chr(0x16)     // extended intrinsic
    .chr(0x70).chr(0x16)                                // normal intrinsic
    .chr(0x1f)."Hi\0"                                 // ANSI NUL literal
    .chr(0x22).pack('V3',0xffffffff,0,0x80000000)      // signed rotations
    .chr(0x2d).chr(0x20).pack('v',2)                    // bool variable
    .chr(0x0f).chr(4).chr(0x00).pack('v',1).chr(0x26);   // Let
 $nameOff=56;
 $headerOff=$nameOff+strlen($names);
 $header=pack('V2',0,0).str_repeat("\xff",8)
    .pack('V3',strlen($data),strlen($data),0);
 $dataOff=$headerOff+strlen($header);
 $objectOff=$dataOff+strlen($data);
 $objects=pack('vvVV',2,1,0,$headerOff)
    .pack('V4',0,$dataOff,strlen($header),strlen($data));
 $summary=pack('a32V6',"Unrealfile\x1A",$version,1,3,$nameOff,$objectOff,0);
 if(strlen($header)!==28)throw new RuntimeException('UScript native test header not 28 bytes');
 return ['bytes'=>$summary.$names.$header.$data.$objects,
  'data'=>$dataOff,'header'=>$headerOff,'stream'=>$data];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1scriptx');
if(!is_string($tmp))throw new RuntimeException('Cannot create temporary script fixture');
try{
 foreach([22,23] as $v){
  $f=$make($v);file_put_contents($tmp,$f['bytes']);
  $res=Script::read($tmp,true);
  $row=$res['script_expressions'][0]??[];
  $expr=$row['expression_tree']??[];
  $id="v{$v}";
  $check("{$id}_archive_structural_result",
   ($row['status']??null)==='native_script_expressions_decoded'
   && ($row['serialized_bytes_consumed']??null)===strlen($f['stream'])
   && ($row['expression_count']??null)===11
   && ($row['script_memory_num_bytes']??null)===strlen($f['stream'])
   && ($row['bytecode_executed']??true)===false);
  $check("{$id}_constants_and_signed_angles",
   ($expr[0]['fields']['int_constant']??null)===-42
   && ($expr[1]['fields']['vector_float']??null)===[1.5,-2.0,3.0]
   && ($expr[7]['fields']['ansi_hex']??null)==='4869'
   && ($expr[8]['fields']['rotation_signed']??null)===[-1,0,-2147483648]);
  $check("{$id}_nested_and_reference_token_order",
   ($expr[2]['fields']['function_name_raw']??null)===3
   && count($expr[2]['children']??[])===2
   && ($expr[3]['fields']['class_reference_raw']??null)===1
   && ($expr[3]['fields']['stack_node_index']??null)===3
   && ($expr[4]['children'][0]['fields']['int_constant']??null)===17
   && ($expr[5]['fields']['extended_intrinsic_low_byte']??null)===1
   && ($expr[9]['fields']['bool_variable_selector']??null)===0x20
   && ($expr[10]['fields']['assign_size']??null)===4);
  $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
    $tmp,['format'=>'early_unrealfile','version'=>$v]
  );
  $staged=$stage['native_metadata']['unrealfile_script_expressions']['script_expressions'][0]??[];
  $check("{$id}_stage_preserves_unverified_code",
    ($staged['expression_count']??null)===11
    && ($staged['bytecode_executed']??true)===false
    && ($stage['parse_complete']??true)===false);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',strlen($f['stream'])-1),$f['header']+16,4));
  try{Script::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("{$id}_native_num_must_match_script_archive_bytes",$reject);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',2),$f['header']+24,4));
  try{Script::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("{$id}_invalid_native_class_ref_rejected",$reject);
  file_put_contents($tmp,substr_replace($f['bytes'],"\x32",$f['data'],1));
  try{Script::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("{$id}_unknown_token_rejected",$reject);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('v',4),$f['data']+19,2));
  try{Script::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("{$id}_bad_name_ref_rejected",$reject);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',2),$f['data']+25,4));
  try{Script::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("{$id}_bad_object_ref_rejected",$reject);
  file_put_contents($tmp,substr_replace($f['bytes'],chr(0x1f),$f['data']+strlen($f['stream'])-1,1));
  try{Script::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("{$id}_unterminated_string_rejected",$reject);
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$fail===[],'passed'=>count($checks)-count($fail),'total'=>count($checks),
 'failures'=>$fail],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
