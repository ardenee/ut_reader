<?php
declare(strict_types=1);
/** Focused change test: reader dispatch and class ownership, NOT original-engine parity. */
require_once dirname(__DIR__, 2) . '/catalog/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogReaderResolver;
$root=dirname(__DIR__,2);
$cfg=['engine_readers'=>['UE4'=>['reader'=>'../UE4/UnrealPackageReader.php','class'=>'UnrealPackageReader4']]];
$routes=[
 'UE1'=>['UnrealDb\\Catalog\\Infrastructure\\Readers\\CatalogUE1PackageReader','catalog/src/Infrastructure/Readers/CatalogUE1PackageReader.php'],
 'UE2'=>['UnrealDb\\Catalog\\Infrastructure\\Readers\\CatalogUE2PackageReader','catalog/src/Infrastructure/Readers/CatalogUE2PackageReader.php'],
 'UE3'=>['CatalogUE3PackageReader','catalog/parsers/EpicUE3PackageReader.php'],
 'UE4'=>['UnrealPackageReader4','UE4/UnrealPackageReader.php'],
 'UE5'=>['UnrealPackageReader5','UE5/UnrealPackageReader.php']
];
foreach($routes as $engine=>$expected){
 $class=CatalogReaderResolver::resolve($cfg,$engine,'Missing reader','Missing class ',['UE4','UE5']);
 if($class!==$expected[0])throw new RuntimeException("$engine returned $class rather than ".$expected[0]);
 $reflection=new ReflectionClass($class);
 $actual=realpath((string)$reflection->getFileName());
 $required=realpath($root.'/'.$expected[1]);
 if($actual!==$required)throw new RuntimeException("$engine is not owned by its separate expected file $required ($actual)");
 echo $engine." READER_OWNER_PASS ".$expected[1].PHP_EOL;
}
$ue1='UnrealDb\\Catalog\\Infrastructure\\Readers\\CatalogUE1PackageReader';
$ue2='UnrealDb\\Catalog\\Infrastructure\\Readers\\CatalogUE2PackageReader';
if(!is_subclass_of($ue1,'UnrealDb\\Catalog\\Infrastructure\\Readers\\CatalogPackageTableReader')
||!is_subclass_of($ue2,'UnrealDb\\Catalog\\Infrastructure\\Readers\\CatalogPackageTableReader')){
 throw new RuntimeException('Shared bounded legacy core no longer present');
}
if(!is_file($root.'/catalog/parsers/UE3CatalogReader.php'))throw new RuntimeException('UE3 parser dispatch wrapper missing');
foreach ([$ue1,$ue2] as $engineClass) {
 $reflection = new ReflectionClass($engineClass);
 if ($reflection->getParentClass() !== false) throw new RuntimeException('Engine reader inherits a shared parser: '.$engineClass);
 foreach (['parse','readNames','readImports','readExports'] as $method) {
  $owner = (new ReflectionMethod($engineClass, $method))->getDeclaringClass()->getName();
  if ($owner !== $engineClass) throw new RuntimeException('Shared parser method found: '.$method.' declared by '.$owner);
 }
}
echo "UE1_UE2_SILO_CHECK_PASS; independent parsing methods, shared byte IO and table interface only\n";
echo "ROUTING_SCOPE_ONLY_5_OF_5_PASS; native byte/VerifyImport parity NOT asserted\n";
