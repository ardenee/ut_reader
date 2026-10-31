<?php
declare(strict_types=1);
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/bootstrap/autoload.php';
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$b='C:/Temp/UnrealDB/O06/G3-BULK-T076-original-stock-v432-two-provider-variants/';
$original=[
 ['f'=>$b.'G3T076Dupe-original-compiled-A-sentinel-111.u','sentinel'=>111],
 ['f'=>$b.'G3T076Dupe-original-compiled-B-sentinel-222.u','sentinel'=>222]];
$tests=0;$fail=[];
foreach($original as $test){
 $r=new P($test['f']);$cls=null;
 foreach($r->getExports() as $i=>$e)if(($e['objectNameText']??'')==='G3T076DupeActor'&&(int)($e['classIndex']??1)===0)$cls=C::inspect($test['f'],$r,$i,false,'ut99-v432');
 $tags=$cls['default_property_tags']??[];
 $t=array_values(array_filter($tags,static fn($row)=>($row['name']??'')==='ProviderSentinel'));
 $found=count($t)===1&&$t[0]['type']==='IntProperty'&&$t[0]['value']===$test['sentinel'];
 $tests++;if(!$found)$fail[]='provider_'.$test['sentinel'];
}
$c=$b.'G3T076Consumer-original-v432-imported-superclass.u';$r=new P($c);
$im=$r->getImports();$names=array_map(static fn($i)=>$i['objectNameText']??'', $im);
foreach(['G3T076Dupe','G3T076DupeActor'] as $n){$tests++;if(!in_array($n,$names,true))$fail[]='true_archive_import_'.$n;}
$impPkg=null;$impClass=null;
foreach($im as $i=>$e){
 if(($e['objectNameText']??'')==='G3T076Dupe')$impPkg=$i;
 if(($e['objectNameText']??'')==='G3T076DupeActor')$impClass=$i;
}
$outer=($impClass!==null)?($im[$impClass]['packageIndex']??$im[$impClass]['outerIndex']??null):null;
$tests++;if($impPkg===null||$outer!==-($impPkg+1))$fail[]='imported_class_outer_package';
$sourceClass=null;
foreach($r->getExports() as $i=>$e)if(($e['objectNameText']??'')==='G3T076ConsumerActor'&&(int)($e['classIndex']??1)===0){$sourceClass=C::inspect($c,$r,$i,false,'ut99-v432');$ex=$e;break;}
$super=$sourceClass['super_field_reference']??null;
$tests++;if($super!==-($impClass+1))$fail[]='source_native_consumer_superfield_index';
$tests++;if($r->getHeader()['version']!==69)$fail[]='source_package_version_69';
echo json_encode(['ok'=>$fail===[],'checks'=>$tests,'errors'=>$fail,'original_source_UE1_family'=>'ut99-v432',
 'genuine_original_UCC_generated_provider_archive_values'=>[111,222],
 'actual_archived_consumer_package_import_name'=>'G3T076Dupe',
 'actual_archived_consumer_superclass_import_name'=>'G3T076DupeActor',
 'archive_loaded_superclass_record'=>$super,
 'original_stock_cpp_compiler_verifier_executed'=>false],JSON_PRETTY_PRINT|JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
exit($fail?1:0);
