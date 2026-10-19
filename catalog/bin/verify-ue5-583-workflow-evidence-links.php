<?php
declare(strict_types=1);
/** Check recovery ledger evidence is navigable, not that every workflow is accepted. */
$root=dirname(__DIR__,2);
$ledger=json_decode((string)file_get_contents($root.'/catalog/compliance/RECOVERY.json'),true,512,JSON_THROW_ON_ERROR);
$names=[];$issues=[];$mapped=0;
foreach(($ledger['required_workflow_paths']??[]) as $row){
 $name=(string)($row['name']??'');
 if($name===''||isset($names[$name]))$issues[]='Invalid or duplicate workflow '.$name;
 $names[$name]=true;
 if(($row['coverage']??'')==='mapped')$mapped++;
 $targets=['production path'=>false,'acceptance evidence'=>false];
 foreach(($row['source_to_entry_point_map']??[]) as $entry){
  if(!is_string($entry))continue;
  foreach($targets as $kind=>$present){
   $prefix='UE5-subset '.$kind.': ';
   if(!str_starts_with($entry,$prefix))continue;
   $path=substr($entry,strlen($prefix));
   if(!str_starts_with($path,'catalog/')||str_contains($path,'..')||!is_file($root.'/'.$path))$issues[]=$name.' broken '.$kind.' '.$path;
   $targets[$kind]=true;
  }
 }
 foreach($targets as $kind=>$present)if(!$present)$issues[]=$name.' missing '.$kind;
}
$doc=(string)file_get_contents($root.'/catalog/compliance/UE583-WORKFLOW-REACHABILITY-20261019.md');
if(str_contains($doc,'**still lacks all seven**'))$issues[]='Stale migration blocker remains in reachability documentation';
if(!is_file($root.'/catalog/compliance/UE583-CERTIFICATION-STATUS.md'))$issues[]='Certification status documentation missing';
$ok=count($names)===16&&$mapped===16&&$issues===[];
echo json_encode(['ok'=>$ok,'expected_workflows'=>16,'mapped_workflows'=>$mapped,'evidence_links_checked'=>count($names)*2,'workflow_accepted_count'=>count(array_filter($ledger['required_workflow_paths']??[],fn($x)=>($x['coverage']??'')==='accepted')),'issues'=>$issues,'certification_claimed'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
