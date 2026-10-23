#!/usr/bin/env php
<?php
/**
 * G2: genuine May/July Epic FStackNode source branches, whole v22/23 Unrealfile fixtures.
 * May has four FStackNodeLink fields; July has five FStackNodePtr fields.
 * Both serialize NestType BEFORE StackNodeFlags. No compiled original oracle.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileStackTreeDataReader as Tree;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfilePropertySerializationGate as Gate;
$checks=[];$fail=[];
$check=static function(string $k,bool $ok)use(&$checks,&$fail):void{
 $checks[]=$k;if(!$ok)$fail[]=$k;
};
$root='L:/Source/Games/Unreal/';
$sources=[
 Gate::MAY=>$root.'Unreal [v0.86x] [1997-05-25]/Src/Engine/UnScript.cpp',
 Gate::JULY=>$root.'Unreal [v0.867] [1997-07-25]/Src/Engine/UnScript.cpp',
];
$hashes=[];
foreach($sources as $branch=>$file){
 $src=(string)file_get_contents($file);
 $hashes[$branch]=hash('sha256',$src);
 if($branch===Gate::MAY){
  $check('May_4_real_archived_FStackNodeLink_members',
      str_contains($src,'Ar << Node.Parent << Node.ChildFunctions << Node.ChildStates << Node.Next;')
      && !str_contains($src,'Ar << Node.ParentItem << Node.ParentNest << Node.ChildFunctions'));
 }else{
  $check('July_5_real_archived_FStackNodePtr_members',
      str_contains($src,'Ar << Node.ParentItem << Node.ParentNest << Node.ChildFunctions << Node.ChildStates << Node.Next;'));
 }
 $check($branch.'_NestType_before_StackNodeFlags',
     str_contains($src,'Ar << Node.NestType << Node.StackNodeFlags << Node.NumParms << Node.OperPrecedence;'));
}
$make=static function(int $v,string $branch,int $records=2):array{
 $names='';foreach(['StackTree','TreeOne','ClassNode','FunctionNode'] as $name)
     $names.=$name."\0".pack('V',0);
 $stack=pack('V2',0,0).str_repeat("\xff",8);
 $head=$stack.pack('V3',$records,$records,1);
 $numLinks=$branch===Gate::MAY?4:5;
 $a=pack('V2',1,7).str_repeat(pack('V',0),$numLinks-1)
   .pack('v5',3,0xffff,0xfffe,5,10)
   .pack('C4',1,9,0,3)
   .pack('V4',1,0,2,0);
 $b=str_repeat(pack('V',0),$numLinks)
   .pack('v5',4,2,3,6,7)
   .pack('C4',3,4,2,3)
   .pack('v5',11,12,13,14,15);
 $raw=$records===0?'':$a.$b;
 $expected=$records===0?0:($branch===Gate::MAY?90:98);
 if(strlen($raw)!==$expected)throw new RuntimeException('Source layout fixture length incorrect');
 $nameOff=56;$hOff=$nameOff+strlen($names);
 $dataOff=$hOff+strlen($head);$objOff=$dataOff+strlen($raw);
 $table=pack('vvVV',2,1,0,$hOff).pack('V4',0,$dataOff,strlen($head),strlen($raw));
 $summary=pack('a32V6',"Unrealfile\x1A",$v,1,4,$nameOff,$objOff,0);
 return ['bytes'=>$summary.$names.$head.$raw.$table,'data'=>$dataOff,
     'table'=>$objOff,'head'=>$hOff,'bytes_length'=>$expected,
     'num_links'=>$numLinks];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1g2stack');
if(!is_string($tmp))throw new RuntimeException('No temporary test file');
try{
 foreach([22,23] as $v)foreach([Gate::MAY,Gate::JULY] as $branch){
  $label="v{$v}_".($branch===Gate::MAY?'may':'july');
  $f=$make($v,$branch);file_put_contents($tmp,$f['bytes']);
  $explicit=Tree::read($tmp,true,$branch)['stacktree_records'][0]??[];
  $auto=Tree::read($tmp,true)['stacktree_records'][0]??[];
  $nodes=$explicit['records']??[];
  $check($label.'_explicit_native_two_source_nodes',
      ($explicit['status']??null)==='native_stacktree_records_decoded'
      &&($explicit['record_count']??null)===2
      &&($explicit['serialized_data_bytes']??null)===$f['bytes_length']
      &&($explicit['source_lineage']??null)===$branch
      &&($explicit['source_lineage_inferred_from_unique_archive_layout']??null)===false);
  $check($label.'_auto_unique_whole_archive_selects_source',
      ($auto['status']??null)==='native_stacktree_records_decoded'
      &&($auto['source_lineage']??null)===$branch
      &&($auto['source_lineage_inferred_from_unique_archive_layout']??null)===true
      &&($auto['source_compiled_runtime_oracle_verified']??null)===false);
  $parent=$branch===Gate::MAY?'parent':'parent_item';
  $check($label.'_exact_four_or_five_source_link_names',
      count($nodes)===2
      &&count($nodes[0]['links']??[])===$f['num_links']
      &&($nodes[0]['links'][$parent]['class_ref']??null)===1
      &&($nodes[0]['links'][$parent]['node_index']??null)===7
      &&array_key_exists('parent_nest',$nodes[0]['links'])===($branch===Gate::JULY)
      &&($nodes[1]['links']['next']['class_ref']??null)===0);
  $check($label.'_NestType_and_StackNodeFlags_not_swapped',
      ($nodes[0]['nest_type']??null)===1
      &&($nodes[0]['stack_flags']??null)===9
      &&($nodes[1]['nest_type']??null)===3
      &&($nodes[1]['stack_flags']??null)===4
      &&($nodes[1]['source_union']['locals_size']??null)===11
      &&($nodes[1]['source_union']['num_properties']??null)===15);
  $other=$branch===Gate::MAY?Gate::JULY:Gate::MAY;
  try{Tree::read($tmp,true,$other);$bad=false;}catch(RuntimeException){$bad=true;}
  $check($label.'_wrong_explicit_lineage_rejected',$bad);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',2),$f['data'],4));
  try{Tree::read($tmp,true);$bad=false;}catch(RuntimeException){$bad=true;}
  $check($label.'_invalid_class_ResMap_rejected',$bad);
  $nameOffset=$f['data']+8+($f['num_links']-1)*4;
  file_put_contents($tmp,substr_replace($f['bytes'],pack('v',5),$nameOffset,2));
  try{Tree::read($tmp,true);$bad=false;}catch(RuntimeException){$bad=true;}
  $check($label.'_invalid_name_map_rejected',$bad);
  file_put_contents($tmp,substr_replace($f['bytes'],chr(255),$nameOffset+10,1));
  try{Tree::read($tmp,true);$bad=false;}catch(RuntimeException){$bad=true;}
  $check($label.'_invalid_NestType_rejected',$bad);
  file_put_contents($tmp,substr_replace($f['bytes'],pack('V',$f['bytes_length']-1),$f['table']+24,4));
  try{Tree::read($tmp,true);$bad=false;}catch(RuntimeException){$bad=true;}
  $check($label.'_short_archive_rejected',$bad);
  file_put_contents($tmp,$f['bytes']);
  $staged=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
      $tmp,['format'=>'early_unrealfile','version'=>$v]);
  $row=$staged['native_metadata']['unrealfile_stacktree_records']['stacktree_records'][0]??[];
  $check($label.'_original_staging_keeps_source_without_runtime_claim',
      ($row['source_lineage']??null)===$branch
      &&($staged['parse_complete']??true)===false);
 }
 foreach([22,23] as $v){
  $f=$make($v,Gate::MAY,0);file_put_contents($tmp,$f['bytes']);
  $row=Tree::read($tmp,false)['stacktree_records'][0]??[];
  $check("v{$v}_empty_tree_two_source_layouts_ambiguous",
      ($row['status']??null)==='source_1997_stacktree_lineage_required'
      &&count($row['candidate_source_families']??[])===2);
  try{Tree::read($tmp,true);$bad=false;}catch(RuntimeException){$bad=true;}
  $check("v{$v}_strict_ambiguous_tree_fails_closed",$bad);
  $explicit=Tree::read($tmp,true,Gate::MAY)['stacktree_records'][0]??[];
  $check("v{$v}_explicit_empty_tree_source_accepted",
      ($explicit['status']??null)==='native_stacktree_records_decoded'
      &&($explicit['record_count']??null)===0);
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$fail===[],'checks'=>count($checks),
 'passed'=>count($checks)-count($fail),'failed'=>$fail,
 'epic_source_sha256'=>$hashes,
 'limitations'=>'May/July source-defined disk layout only; no original compiled oracle or source-of-file identity proof'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail?1:0);
