#!/usr/bin/env php
<?php
/** Source FStackNodePtr null/non-null and NestType union archive v22/v23. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileStackTreeDataReader as Tree;
$checks=[];$bad=[];
$check=static function(string $name,bool $ok)use(&$checks,&$bad):void{
 $checks[$name]=$ok;if(!$ok)$bad[]=$name;
};
$make=static function(int $v):array{
 $names='';
 foreach(['StackTree','TreeOne','ClassNode','FunctionNode'] as $s)$names.=$s."\0".pack('V',0);
 $stack=pack('V2',0,0).str_repeat("\xff",8);
 $native=pack('V3',2,2,1);
 $nodeA=pack('V2',1,7).pack('V4',0,0,0,0)
   .pack('v5',3,0xffff,0xfffe,5,10)
   .pack('C4',$v===22?1:2,1,0,3)
   .pack('V4',1,0,2,0);
 $nodeB=pack('V5',0,0,0,0,0)
   .pack('v5',4,2,3,6,7)
   .pack('C4',3,1,2,3)
   .pack('v5',11,12,13,14,15);
 if(strlen($nodeA)!==54||strlen($nodeB)!==44)
     throw new RuntimeException('Source stack node fixture lengths differ');
 $raw=$nodeA.$nodeB;
 $nameOff=56;$hOff=$nameOff+strlen($names);
 $dataOff=$hOff+strlen($stack)+strlen($native);
 $tableOff=$dataOff+strlen($raw);
 $table=pack('vvVV',2,1,0,$hOff).pack('V4',0,$dataOff,28,98);
 $sum=pack('a32V6',"Unrealfile\x1A",$v,1,4,$nameOff,$tableOff,0);
 return ['bytes'=>$sum.$names.$stack.$native.$raw.$table,
     'native'=>$hOff+16,'data'=>$dataOff,'table'=>$tableOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1stack97');
if(!is_string($tmp))throw new RuntimeException('No temp path');
try{
 foreach([22,23] as $v){
  $f=$make($v);file_put_contents($tmp,$f['bytes']);
  $r=Tree::read($tmp,true)['stacktree_records'][0]??[];
  $nodes=$r['records']??[];
  $check("v{$v}_source_header_and_variable_length",
     ($r['status']??null)==='native_stacktree_records_decoded'
     && ($r['record_count']??null)===2
     && ($r['record_capacity']??null)===2
     && ($r['serialized_data_bytes']??null)===98
     && ($r['class_ref_raw_one_based']??null)===1);
  $check("v{$v}_conditional_class_and_null_links",
     count($nodes)===2
     && ($nodes[0]['links']['parent_item']??null)===['class_ref'=>1,'node_index'=>7]
     && ($nodes[0]['links']['parent_nest']??null)===['class_ref'=>0,'node_index'=>null]
     && ($nodes[0]['source_union']['probe_mask_words_le']??null)===[1,0]
     && ($nodes[0]['source_union']['ignore_mask_words_le']??null)===[2,0]
     && ($nodes[0]['nest_type']??null)===($v===22?1:2));
  $check("v{$v}_function_union_and_names",
     ($nodes[1]['name']??null)==='FunctionNode'
     && ($nodes[1]['nest_type']??null)===3
     && ($nodes[1]['source_union']['locals_size']??null)===11
     && ($nodes[1]['source_union']['intrinsic_index']??null)===13
     && ($nodes[1]['source_union']['num_properties']??null)===15
     && ($r['runtime_stack_linked']??true)===false);
  $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
   $tmp,['format'=>'early_unrealfile','version'=>$v]
  );
  $s=$stage['native_metadata']['unrealfile_stacktree_records']['stacktree_records'][0]??[];
  $check("v{$v}_unverified_staging_keeps_typed_tree",
      ($s['record_count']??null)===2
      && ($stage['parse_complete']??true)===false);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',2),$f['data'],4));
  try{Tree::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$v}_invalid_class_reference_rejected",$reject);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('v',5),$f['data']+24,2));
  try{Tree::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$v}_invalid_name_reference_rejected",$reject);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',3),$f['native'],4));
  try{Tree::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$v}_count_gt_capacity_rejected",$reject);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',99),$f['table']+24,4));
  try{Tree::read($tmp,true);$reject=false;}catch(RuntimeException){$reject=true;}
  $check("v{$v}_unexpected_trailing_bytes_rejected",$reject);
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),
    'total'=>count($checks),'failures'=>$bad],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($bad===[]?0:1);
