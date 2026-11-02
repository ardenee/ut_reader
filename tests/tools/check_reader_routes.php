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
$ue2compat='UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2LegacyCompatibilityPackageReader';
$ue1stream='UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1BinaryStream';
$ue2stream='UnrealDb\Catalog\Infrastructure\Readers\CatalogUE2BinaryStream';
if (!class_exists($ue2compat) || !class_exists($ue1stream) || !class_exists($ue2stream)) throw new RuntimeException('Per-engine reader or private byte stream missing');
foreach ([$ue1,$ue2,$ue2compat] as $owner) {
 $text=file_get_contents((new ReflectionClass($owner))->getFileName());
 if (str_contains($text,'CatalogLegacyBinaryStream') || str_contains($text,'CatalogPackageTableReader'))
  throw new RuntimeException('Old cross-engine source dependency in '.$owner);
}

// Cross-generation compatibility belongs to the destination game's engine.
foreach ([
 ['ut2004','UE2','UE1',61,$ue2compat,'UE2'],
 ['unreal2','UE2','UE1',61,$ue2,'UE2'],
 ['ut2003','UE2','UE1',69,$ue2,'UE2'],
 ['ut2004','UE2','UE2',128,$ue2,'UE2'],
 ['ut99','UE1','UE1',69,$ue1,'UE1'],
] as [$source,$profile,$classified,$version,$expectedClass,$expectedEngine]) {
 [$actualClass,$actualEngine] = CatalogReaderResolver::resolveForGame(
  $cfg,$source,$profile,$classified,$version
 );
 if ($actualClass!==$expectedClass || $actualEngine!==$expectedEngine) {
  throw new RuntimeException('Game-owned cross-version dispatch mismatch for '.$source.' v'.$version);
 }
}
echo "UE2_GAME_OWNER_ROUTE_5_OF_5_PASS\n";
try {
 CatalogReaderResolver::resolveForGame($cfg,'unreal2','UE2','UE1',54);
 throw new RuntimeException('UE2 silently selected a foreign reader for a sub-v60 file');
} catch (RuntimeException $e) {
 if (!str_contains($e->getMessage(), 'below 60')) throw $e;
}
echo "UE2_UNCONFIRMED_PRE_V60_NOT_BORROWED_PASS\n";

if(!is_file($root.'/catalog/parsers/UE3CatalogReader.php'))throw new RuntimeException('UE3 parser dispatch wrapper missing');
foreach ([$ue1,$ue2,$ue2compat] as $engineClass) {
 $reflection = new ReflectionClass($engineClass);
 if ($reflection->getParentClass() !== false) throw new RuntimeException('Engine reader inherits a shared parser: '.$engineClass);
 foreach (['parse','readNames','readImports','readExports'] as $method) {
  $owner = (new ReflectionMethod($engineClass, $method))->getDeclaringClass()->getName();
  if ($owner !== $engineClass) throw new RuntimeException('Shared parser method found: '.$method.' declared by '.$owner);
 }
}
echo "UE1_UE2_SILO_CHECK_PASS; independent parsing methods and private per-engine byte streams\n";
echo "ROUTING_SCOPE_ONLY_5_OF_5_PASS; native byte/VerifyImport parity NOT asserted\n";
