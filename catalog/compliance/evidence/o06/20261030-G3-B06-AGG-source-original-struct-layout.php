<?php
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/bootstrap/autoload.php';
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
$f='C:/Temp/UnrealDB/O06/Native-C227i-G304/System/G3Array227.u';
$p=new UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader($f);
foreach($p->getExports() as $i=>$e) {
 if(!in_array($e['objectNameText'],['Pairs','Strings','G3Tuple']))continue;
 $tag=UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader::inspect($f,$p,$i,'unreal-v227');
 $value=UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader::inspect($f,$p,$i,$tag,'unreal-v227');
 echo json_encode(['i'=>$i,'name'=>$e['objectNameText'],'kind'=>$value['property_kind']??null,'fields'=>$value['subclass_fields']??null]).PHP_EOL;
}
$layout=UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicStructArrayValueReader::resolve($f,$p,1,'unreal-v227');
echo 'SOURCE_STRUCT_LAYOUT '.json_encode($layout,JSON_UNESCAPED_SLASHES).PHP_EOL;
