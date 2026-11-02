<?php
declare(strict_types=1);
/** Narrow reader-module split regression on genuine UE1 or UE2 package bytes.
 * Not an original-engine value-parity certification. Does not write files or database.
 */
require_once dirname(__DIR__,2).'/catalog/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogReaderResolver;
if($argc!==3||!in_array(strtoupper($argv[1]),['UE1','UE2'],true)||!is_file($argv[2])){
 fwrite(STDERR,"Usage: php tests/tools/check_reader_fixtures.php UE1|UE2 <genuine package path>\n");exit(2);
}
$engine=strtoupper($argv[1]);$path=$argv[2];
$class=CatalogReaderResolver::resolve([], $engine,'No reader for','Missing class ',['UE4','UE5']);
$reader=new $class($path);
$issues=$reader->validatePackage();
if($issues!==[]){fwrite(STDERR,"Original file rejected by $engine reader: ".implode('; ',$issues)."\n");exit(1);}
$h=$reader->getHeader();$names=$reader->getNames();$imports=$reader->getImports();$exports=$reader->getExports();
if(!is_array($h)||!is_array($names)||!is_array($imports)||!is_array($exports)
 ||($h['version']??-1)<1||count($names)<1){
 throw new RuntimeException("Invalid original $engine package table result");
}
echo json_encode(['regression_scope'=>'engine_reader_entrypoint_and_existing_parser_real_input_only',
 'engine'=>$engine,'source_class'=>$class,
 'file_sha256'=>hash_file('sha256',$path),'version'=>$h['version'],
 'names'=>count($names),'imports'=>count($imports),'exports'=>count($exports),
 'original_epic_loaded_values_compared'=>false,
 'mysql_or_production_changed'=>false],JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT).PHP_EOL;
