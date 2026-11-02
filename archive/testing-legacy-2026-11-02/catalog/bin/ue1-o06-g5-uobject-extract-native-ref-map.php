<?php
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
$p=new UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader('C:/Temp/UnrealDB/O06/Native-UCC/System/Entry.unr');
$ex=$p->getExports();$im=$p->getImports();
$pathFor=static function(int $ref)use($ex,$im):?string{
 $nodes=[];$seen=[];
 for($i=0;$ref!==0&&$i<20;$i++){
  if(isset($seen[$ref]))return null;$seen[$ref]=true;
  $entry=$ref>0?($ex[$ref-1]??null):($im[-$ref-1]??null);
  if(!is_array($entry))return null;
  $nodes[]=$entry['objectNameText'];$ref=(int)$entry['outerIndex'];
 }
 if($ref!==0)return null;
 return implode('.',array_reverse($nodes));
};
$entries=[];
foreach($ex as $entry){
 $ref=(int)$entry['index']+1;
 $classref=(int)$entry['classIndex'];
 $klass=$classref===0?'Class':($classref>0?($ex[$classref-1]['objectNameText']??'Unknown'):($im[-$classref-1]['objectNameText']??'Unknown'));
 $entries[$ref]=['path'=>'Entry.'.($pathFor($ref)??$entry['objectNameText']),'class'=>$klass,'name'=>$entry['objectNameText']];
}
foreach($im as $i=>$entry){$ref=-($i+1);$entries[$ref]=['path'=>$pathFor($ref),'class'=>$entry['classNameText']??null,'name'=>$entry['objectNameText']];}
file_put_contents('C:/Temp/UnrealDB/O06/G5-UObject-ref-and-name-lookup.json',json_encode(['names'=>array_map(static fn($e)=>$e['nameText']??$e['name']??$e['text']??'', $p->getNames()),'refs'=>$entries],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo 'UE1_NAMES '.count($p->getNames()).' REFS '.count($entries).' FIRST '.json_encode(array_slice($entries,0,4,true)).PHP_EOL;
