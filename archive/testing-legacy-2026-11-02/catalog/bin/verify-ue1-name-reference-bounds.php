#!/usr/bin/env php
<?php
/**
 * Epic UT99 v400 Core/Src/UnLinker.h:994-1004 and v1.200
 * Core/Src/UnLinker.h:1015-1025: loading FName requires a name index
 * present in NameMap. An out-of-range index is a fatal source error.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;

$check=[];$failed=[];
$test=static function(string $label,bool $pass)use(&$check,&$failed):void{
    $check[$label]=$pass;if(!$pass){$failed[]=$label;}
};
$fixture=static function(string $which,int $invalidIndex):string {
    $names=['Core','Class','MyPackage','Example'];
    $nameBytes='';
    foreach($names as $name){
        $nameBytes.=chr(strlen($name)+1).$name."\0".pack('V',0x00070000);
    }
    $nameOffset=56;
    $importOffset=$nameOffset+strlen($nameBytes);
    $impName=$which==='import-name'?$invalidIndex:3;
    $impClass=$which==='import-class'?$invalidIndex:1;
    $import=chr(0).chr($impClass).pack('V',0).chr($impName);
    $exportOffset=$importOffset+strlen($import);
    $expName=$which==='export-name'?$invalidIndex:3;
    $export=chr(0).chr(0).pack('V',0).chr($expName).pack('V',0).chr(0);
    $header=pack('Vvv',0x9E2A83C1,68,0).pack('V',0)
        .pack('V2',count($names),$nameOffset)
        .pack('V2',1,$exportOffset)
        .pack('V2',1,$importOffset)
        .pack('V4',1,2,3,4).pack('V',0);
    return $header.$nameBytes.$import.$export;
};
$path=tempnam(sys_get_temp_dir(),'ue1_names_');
if(!is_string($path)){throw new RuntimeException('Temp file creation failed');}
try {
    file_put_contents($path,$fixture('valid',3));
    $reader=new CatalogUE1PackageReader($path);
    $test('valid_name_references_readable',$reader->validatePackage()===[]
        && ($reader->getImports()[0]['objectNameText']??null)==='Example'
        && ($reader->getExports()[0]['objectNameText']??null)==='Example');
    foreach(['import-name','import-class','export-name'] as $which){
        foreach([4,63] as $bad){
            file_put_contents($path,$fixture($which,$bad));
            $reader=new CatalogUE1PackageReader($path);
            $test($which.'_out_of_range_'.$bad,
                $reader->validatePackage()!==[]
                && str_contains(implode(' ', $reader->validatePackage()),'name index')
                && $reader->getImports()===[]
                && $reader->getExports()===[]);
        }
    }
    // Raw readers must retain source-indexed names, including those filtered
    // out in an explicit runtime context; do not drop serialized entries.
    file_put_contents($path,$fixture('valid',3));
    $reader=new CatalogUE1PackageReader($path);
    $test('raw_name_table_retained',count($reader->getNames())===4
        && ($reader->getNames()[3]['flags']??null)===0x00070000);
} finally {@unlink($path);}
echo json_encode(['ok'=>$failed===[],'passed'=>count($check)-count($failed),
    'total'=>count($check),'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
