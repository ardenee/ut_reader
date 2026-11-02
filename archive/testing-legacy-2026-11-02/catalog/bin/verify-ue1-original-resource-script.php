#!/usr/bin/env php
<?php
/** Source-defined 1996 v9/v10 UScript and FStackNode structural records. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceScriptReader as Script;
$checks=[];$bad=[];
$check=static function(string $name,bool $ok)use(&$checks,&$bad):void{
 $checks[$name]=$ok;if(!$ok)$bad[]=$name;
};
$make=static function(int $ver):array{
 $prefix="Unreal Resource\x1A\0";
 $names=pack('a32V','SCRIPT',0);$nameOff=strlen($prefix);
 $stack=pack('V12',2,7,0,0,0,0,0,1,0,0,56,4)
    .pack('vC4',1,1,2,3,4)."\0\0";
 if(strlen($stack)!==56)throw new RuntimeException('Wrong FStackNode source fixture size');
 $data=$stack."\xaa\xbb";$dataOff=$nameOff+strlen($names);
 $exportOff=$dataOff+strlen($data);
 $export=pack('a32VVV','ScriptOne',1,9,112);
 $headOff=$exportOff+strlen($export);
 $hdr=pack('V2a32V9',0,9,'ScriptOne',0,0,1,0xffffffff,0,$dataOff,58,0,0)
    .pack('V9',3,58,1,56,0,0,0,0,0);
 if(strlen($hdr)!==112)throw new RuntimeException('Wrong native UScript header');
 $tail=pack('V9',$ver,1,0,1,$nameOff,0,$exportOff,$headOff,112)
    .str_repeat("\0",16).str_repeat("\0",80).pack('a32',"Unreal Resource\x1A");
 return ['bytes'=>$prefix.$names.$data.$export.$hdr.'[SUMMARY]'.$tail,
     'header'=>$headOff,'export'=>$exportOff,'data'=>$dataOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1script96');
if(!is_string($tmp))throw new RuntimeException('Cannot create script fixture');
try {
 foreach([9,10] as $ver){
  $f=$make($ver);file_put_contents($tmp,$f['bytes']);
  $entry=Script::read($tmp,true)['script_records'][0]??[];
  $check("v{$ver}_script_native_header",
    ($entry['status']??null)==='native_script_header_and_stack_decoded'
    && ($entry['script_length']??null)===58
    && ($entry['stack_node_count']??null)===1
    && ($entry['code_offset']??null)===56
    && ($entry['class_reference_raw_word']??null)===3
    && ($entry['code_region_bytes']??null)===2);
  $node=$entry['stack_nodes'][0]??[];
  $check("v{$ver}_source_stack_node_fields",
    ($node['child_functions']['resource_raw_word']??null)===2
    && ($node['child_functions']['node_index']??null)===7
    && ($node['property_count']??null)===1
    && ($node['code_start']??null)===56
    && ($node['name_index_raw_word']??null)===1
    && ($node['nest_type']??null)===4
    && ($node['stack_flags']??null)===1
    && ($node['operator_precedence']??null)===4);
  $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
   $tmp,['format'=>'early_unreal_resource','version'=>$ver]
  );
  $stored=$stage['native_metadata']['resource_script_records']['script_records'][0]??[];
  $check("v{$ver}_staging_unverified_native_script",
    ($stored['stack_node_count']??null)===1
    && ($stored['script_opcodes_decoded']??true)===false
    && ($stage['parse_complete']??true)===false);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',2),$f['header']+84,4));
  try{Script::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$ver}_bad_stack_count_rejected",$reject);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',55),$f['header']+88,4));
  try{Script::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$ver}_invalid_code_offset_rejected",$reject);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',108),$f['export']+40,4));
  $partial=Script::read($tmp,false)['script_records'][0]??[];
  try{Script::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$ver}_short_native_header_unverified",
    ($partial['status']??null)==='incomplete_source_script_header'&&$reject);
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),
    'total'=>count($checks),'failures'=>$bad],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($bad===[]?0:1);
