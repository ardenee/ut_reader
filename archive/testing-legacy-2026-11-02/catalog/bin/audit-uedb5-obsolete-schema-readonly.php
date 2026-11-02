<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require_once dirname(__DIR__).'/bootstrap.php';
$db=catalog_bootstrap(false)->db;
$rows=$db->query('SELECT table_name AS name,table_rows AS estimated_rows,data_length AS data_bytes,index_length AS index_bytes FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type="BASE TABLE" ORDER BY data_length+index_length DESC')->fetchAll(PDO::FETCH_ASSOC);
$legacy=[];$candidate=[];$other=[];$total=0;
foreach($rows as $r){$name=(string)$r['name'];$bytes=(int)$r['data_bytes']+(int)$r['index_bytes'];$total+=$bytes;
$entry=['name'=>$name,'estimated_rows'=>(int)$r['estimated_rows'],'estimated_bytes'=>$bytes];
if(preg_match('/uedb[1-4]|legacy/i',$name))$legacy[]=$entry;
elseif(str_contains($name,'candidate'))$candidate[]=$entry;
else $other[]=$entry;
}
echo json_encode(['read_only'=>true,'schema_estimated_bytes'=>$total,'table_count'=>count($rows),'legacy_named'=>$legacy,'candidate_named'=>$candidate,'largest_15'=>array_slice($other,0,15)],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
