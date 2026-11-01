<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$f=getenv('G3_C98_ORIGINAL_ENGINE_PACKAGE');
if(!$f||!is_file($f))throw new RuntimeException('Original 1998 C98 Engine.u immutable source package unavailable');
$p=new P($f);$ex=$p->getExports();$im=$p->getImports();$records=[];
foreach($ex as $idx=>$row){
 $c=(int)$row['classIndex'];
 $class=$c<0?($im[-$c-1]['objectNameText']??''):($c>0?($ex[$c-1]['objectNameText']??''):'Class');
 if(!in_array($class,['Const','Enum'],true))continue;
 $r=N::inspect($f,$p,$idx,T::inspect($f,$p,$idx,'epic-ue1'),'epic-ue1');
 $records[$idx]=['kind'=>$class,'object_name'=>$row['objectNameText'],
    'status'=>$r['status'],'extension_bytes'=>$r['extension_bytes'],
    'name_count'=>$r['name_count']??null,
    'names'=>array_map(static fn($a)=>$a['name'],$r['names']??[]),
    'value'=>$r['value']??null,'value_count'=>$r['value_count']??null];
}
echo json_encode(['file'=>'Engine.u','version'=>$p->getHeader()['version'],
 'records'=>$records],JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
