<?php
declare(strict_types=1);
$root=realpath(dirname(__DIR__))?:dirname(__DIR__);
require_once $root.'/bootstrap/autoload.php';
require_once $root.'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;
$checks=[];$failures=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failures):void{$checks[$name]=$ok;if(!$ok)$failures[]=$name;};
$compact=static function(int $v):string{return pack('C',$v);};
$fixture=static function(bool $invalid)use($compact):string{
    $names=$invalid?['Outer']:['Core','Package','Provider'];
    $nameBytes='';foreach($names as $name){$nameBytes.=$compact(strlen($name)+1).$name."\0".pack('V',0);}
    $nameOffset=56;$importOffset=$nameOffset+strlen($nameBytes);
    $import=$invalid?$compact(0).$compact(0).pack('V',0).$compact(0):$compact(0).$compact(1).pack('V',0).$compact(2);
    $exportOffset=$importOffset+strlen($import);
    $header=pack('Vvv',0x9E2A83C1,68,0).pack('V',0).pack('V2',count($names),$nameOffset)
        .pack('V2',0,$exportOffset).pack('V2',1,$importOffset).pack('V4',1,2,3,4).pack('V',0);
    return $header.$nameBytes.$import;
};
$valid=tempnam(sys_get_temp_dir(),'ue1_valid_');$bad=tempnam(sys_get_temp_dir(),'ue1_bad_');
if(!is_string($valid)||!is_string($bad))throw new RuntimeException('Could not create fixtures.');
try{
    file_put_contents($valid,$fixture(false));file_put_contents($bad,$fixture(true));
    $validReader=new CatalogUE1PackageReader($valid);$badReader=new CatalogUE1PackageReader($bad);
    $check('valid_core_package_root_is_accepted',$validReader->validatePackage()===[]);
    $issues=$badReader->validatePackage();
    $check('non_core_package_root_is_rejected',count($issues)===1&&str_contains($issues[0],'Invalid UE1 root import identity'));
    $check('invalid_fixture_keeps_tables_for_diagnostics',count($badReader->getImports())===1&&count($badReader->getNames())===1);
}finally{@unlink($valid);@unlink($bad);}
$reader=(string)file_get_contents($root.'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php');
$promotion=(string)file_get_contents($root.'/src/Infrastructure/Unverified/CatalogUnverifiedPromotion.php');
$staging=(string)file_get_contents($root.'/src/Infrastructure/Unverified/CatalogUnverifiedStagingIndex.php');
$indexer=(string)file_get_contents($root.'/src/Infrastructure/Import/CatalogUnverifiedPackageIndexer.php');
$check('ue1_validation_is_source_gated',str_contains($reader,"\$this->engineKey !== 'UE1' || \$version < 50"));
$check('compatibility_stager_calls_reader_validation',str_contains($staging,"validatePackage"));
$check('bucket_indexer_calls_reader_validation',str_contains($indexer,"validatePackage"));
$parsePos=strpos($promotion,'$this->staging->parse(');$movePos=strpos($promotion,"'storage', 38");
$check('promotion_revalidates_raw_bytes_before_storage',$parsePos!==false&&$movePos!==false&&$parsePos<$movePos);
echo json_encode(['ok'=>$failures===[],'checks'=>$checks,'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
