<?php
declare(strict_types=1);
/** Real isolated UE5.8.3 Satisfactory V5 compact-search acceptance. */
if(PHP_SAPI!=='cli')exit(1);
function catalog_config():array{return ['storage_path'=>'C:/Temp/uedb5-iostore-retention-test'];}
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Search\Uedb5CatalogMetadataSearch;
$db=new PDO('mysql:host=127.0.0.1;port=3339;dbname=uedb5_isolated_integration','root','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$ident=$db->query('SELECT @@port port,@@datadir datadir')->fetch();
if((int)$ident['port']!==3339||stripos((string)$ident['datadir'],'uedb5-isolated-mysql')===false)throw new RuntimeException('Refusing application/PRD database');
$expected=(int)$db->query('SELECT COUNT(*) FROM ue_files WHERE game_id=2')->fetchColumn();
if($expected!==27240)throw new RuntimeException('Isolated real Satisfactory dataset missing');
$search=new Uedb5CatalogMetadataSearch($db);
$filters=['fields'=>['names','exports','imports'],'extensions'=>['zenpkg']];
$cases=[
 ['query'=>'Desc_RampInverted_8x4_Corner_02_C','file_id'=>3,'object'=>'Desc_RampInverted_8x4_Corner_02_C'],
 ['query'=>'Default__Desc_RampInverted_8x4_Corner_02_C','file_id'=>3,'object'=>'Default__Desc_RampInverted_8x4_Corner_02_C'],
 ['query'=>'BP_ResourceDeposit611','file_id'=>2,'object'=>'BP_ResourceDeposit611'],
];
$checks=[];$ok=true;
foreach($cases as $case){
 $results=$search->findMatches(2,$case['query'],60,$filters);
 $matches=(array)($results[$case['file_id']]??[]);
 $found=false;
 foreach($matches as $m){if(($m['field']??'')==='Export object'&&($m['value']??'')===$case['object'])$found=true;}
 $checks[]=['query'=>$case['query'],'expected_file_id'=>$case['file_id'],'found_export'=>$found,'candidate_count'=>count($results)];
 $ok=$ok&&$found;
}
$none=$search->findMatches(2,'None',10,['fields'=>['names'],'extensions'=>['zenpkg']]);
$nameFound=false;
foreach((array)($none[1]??[]) as $row){if(($row['field']??'')==='Name'&&($row['value']??'')==='None')$nameFound=true;}
$checks[]=['query'=>'None','found_name'=>$nameFound];$ok=$ok&&$nameFound;
$negative=$search->findMatches(2,'UE583_No_Such_Export_Fixture_XY987',20,$filters);
$checks[]=['query'=>'nonexistent_export','matches'=>count($negative)];$ok=$ok&&$negative===[];
echo json_encode(['ok'=>$ok,'read_only'=>true,'isolated'=>true,'fixture_count'=>$expected,'checks'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
