<?php
declare(strict_types=1);

$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap/autoload.php';
require_once $root.'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';

use UnrealDb\Catalog\Infrastructure\Metadata\CatalogLegacyNameMapPreprocessor;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;

$checks=[];$failures=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failures):void{
    $checks[$name]=$ok;
    if(!$ok)$failures[]=$name;
};
$compact=static function(int $v):string{return pack('C',$v);};
$fixture=static function(bool $invalid,int $nameFlags)use($compact):string{
    $names=$invalid?['Outer']:['Core','Package','Provider'];
    $nameBytes='';
    foreach($names as $name){
        $nameBytes.=$compact(strlen($name)+1).$name."\0".pack('V',$nameFlags);
    }
    $nameOffset=56;
    $importOffset=$nameOffset+strlen($nameBytes);
    $import=$invalid
        ? $compact(0).$compact(0).pack('V',0).$compact(0)
        : $compact(0).$compact(1).pack('V',0).$compact(2);
    $exportOffset=$importOffset+strlen($import);
    $header=pack('Vvv',0x9E2A83C1,68,0).pack('V',0)
        .pack('V2',count($names),$nameOffset)
        .pack('V2',0,$exportOffset)
        .pack('V2',1,$importOffset)
        .pack('V4',1,2,3,4).pack('V',0);
    return $header.$nameBytes.$import;
};

$valid=tempnam(sys_get_temp_dir(),'ue1_valid_');
$rawInvalid=tempnam(sys_get_temp_dir(),'ue1_raw_invalid_');
$filteredInvalid=tempnam(sys_get_temp_dir(),'ue1_filtered_invalid_');
if(!is_string($valid)||!is_string($rawInvalid)||!is_string($filteredInvalid)){
    throw new RuntimeException('Could not create fixtures.');
}
try{
    file_put_contents($valid,$fixture(false,CatalogLegacyNameMapPreprocessor::ALL_LOAD_CONTEXTS));
    file_put_contents($rawInvalid,$fixture(true,CatalogLegacyNameMapPreprocessor::ALL_LOAD_CONTEXTS));
    file_put_contents($filteredInvalid,$fixture(true,0));

    $validReader=new CatalogUE1PackageReader($valid);
    $rawInvalidReader=new CatalogUE1PackageReader($rawInvalid);
    $filteredInvalidReader=new CatalogUE1PackageReader($filteredInvalid);

    $check('valid_core_package_root_is_structurally_accepted',$validReader->validatePackage()===[]);
    $check('raw_non_core_root_is_preserved_for_runtime_preprocessing',$rawInvalidReader->validatePackage()===[]);
    $check('context_filtered_non_core_root_is_not_rejected_by_raw_reader',$filteredInvalidReader->validatePackage()===[]);

    $stableMap=CatalogLegacyNameMapPreprocessor::effectiveNameMap($rawInvalidReader->getNames());
    $filteredMap=CatalogLegacyNameMapPreprocessor::effectiveNameMap($filteredInvalidReader->getNames());
    $check('all_context_name_is_retained',($stableMap[0]??null)==='Outer');
    $check('zero_context_name_maps_to_none',($filteredMap[0]??null)==='None');
}finally{
    @unlink($valid);@unlink($rawInvalid);@unlink($filteredInvalid);
}

$reader=(string)file_get_contents($root.'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php');
$promotion=(string)file_get_contents($root.'/src/Infrastructure/Unverified/CatalogUnverifiedPromotion.php');
$staging=(string)file_get_contents($root.'/src/Infrastructure/Unverified/CatalogUnverifiedStagingIndex.php');
$indexer=(string)file_get_contents($root.'/src/Infrastructure/Import/CatalogUnverifiedPackageIndexer.php');
$check('raw_reader_no_longer_applies_runtime_root_assertions',!str_contains($reader,'Invalid UE1 root import identity'));
$check('compatibility_stager_calls_reader_validation',str_contains($staging,'validatePackage'));
$check('bucket_indexer_calls_reader_validation',str_contains($indexer,'validatePackage'));
$parsePos=strpos($promotion,'$this->staging->parse(');
$movePos=strpos($promotion,"'storage', 38");
$check('promotion_revalidates_raw_bytes_before_storage',$parsePos!==false&&$movePos!==false&&$parsePos<$movePos);

echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
