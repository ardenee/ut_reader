#!/usr/bin/env php
<?php
/**
 * Epic native loader reference addressing:
 * v1.200 Core/Src/UnLinker.h FObjectImport/FObjectExport and
 * IndexToObject/GenerateExportInfo; UT99 v400 Core/Src/UnLinker.h
 * IndexToObject (positive one-based exports; negative -1-based imports).
 * Import.PackageIndex can be zero or a negative import reference.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/bootstrap/autoload.php';
require_once dirname(__DIR__) . '/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;
$results=[];$failed=[];
$check=static function(string $n,bool $v)use(&$results,&$failed):void {
    $results[$n]=$v;if(!$v){$failed[]=$n;}
};
$compact=static function(int $value):string {
    $neg=$value<0;$n=abs($value);$first=$n&63;$n>>=6;
    if($neg){$first|=0x80;}if($n){$first|=0x40;}
    $out=chr($first);
    while($n){$byte=$n&127;$n>>=7;if($n){$byte|=0x80;}$out.=chr($byte);}
    return $out;
};
$fixture=static function(int $v,string $field='',int $bad=0)use($compact):string {
    $labels=['Core','Package','Texture','SomePkg','MyTex','MyExport','OtherExport'];
    $names='';
    foreach($labels as $name){
        $names.=($v<64 ? $name."\0" : $compact(strlen($name)+1).$name."\0").pack('V',0);
    }
    $nameOff=$v<68?44:56;
    $importOff=$nameOff+strlen($names);
    $root=$compact(0).$compact(1);
    $child=$compact(0).$compact(2);
    if($v<50){
        $root.=$compact(3);$child.=$compact(3);
    }else{
        $root.=pack('V',0);
        $child.=pack('V',($field==='import.outer'?$bad:-1)&0xFFFFFFFF);
    }
    $root.=$compact(3);
    $child.=$compact(4);
    $imports=$root.$child;
    $exportOff=$importOff+strlen($imports);
    $export1=$compact($field==='export.class'?$bad:-2)
        .$compact($field==='export.super'?$bad:0);
    if($v>=50){$export1.=pack('V',($field==='export.outer'?$bad:0)&0xFFFFFFFF);}
    $export1.=$compact(5).pack('V',0);
    $size = $field==='export.serial_size' ? $bad :
        ($field==='export.serial_offset' || $field==='export.serial_negative_offset' ? 4 : 0);
    $export1.=$compact($size);
    if ($size !== 0) {
        $offset = $field==='export.serial_offset' ? $bad :
            ($field==='export.serial_negative_offset' ? -1 : $nameOff);
        $export1.=$compact($offset);
    }
    $export2=$compact(1).$compact(0);
    if($v>=50){$export2.=pack('V',1);}
    $export2.=$compact(6).pack('V',0).$compact(0);
    $summary=pack('Vvv',0x9E2A83C1,$v,0).pack('V',0)
        .pack('V2',count($labels),$nameOff)
        .pack('V2',2,$exportOff).pack('V2',2,$importOff);
    $summary.=$v<68?pack('V2',0,44):pack('V4',1,2,3,4).pack('V',0);
    return $summary.$names.$imports.$export1.$export2;
};
$file=tempnam(sys_get_temp_dir(),'ue1ref_');
if(!is_string($file)){throw new RuntimeException('Cannot allocate test file');}
try{
    foreach([49,61,68,69] as $v){
        file_put_contents($file,$fixture($v));
        $r=new CatalogUE1PackageReader($file);
        $check("v{$v}_valid_two_imports_exports",$r->validatePackage()===[]
            && count($r->getImports())===2 && count($r->getExports())===2);
        foreach([['export.class',-3],['export.class',3],
                 ['export.super',-3],['export.super',3]] as [$field,$bad]){
            file_put_contents($file,$fixture($v,$field,$bad));
            $r=new CatalogUE1PackageReader($file);
            $check("v{$v}_{$field}_{$bad}_rejected",
                $r->validatePackage()!==[]
                && str_contains(implode(' ',$r->validatePackage()),'reference')
                && $r->getImports()===[] && $r->getExports()===[]);
        }
        foreach([['export.serial_size',4096],['export.serial_offset',4096],
                 ['export.serial_negative_offset',-1]] as [$field,$bad]) {
            file_put_contents($file,$fixture($v,$field,$bad));
            $r=new CatalogUE1PackageReader($file);
            $check("v{$v}_{$field}_{$bad}_rejected",
                $r->validatePackage()!==[]
                && str_contains(implode(' ',$r->validatePackage()),'serial data')
                && $r->getImports()===[] && $r->getExports()===[]);
        }
        if($v>=50){
            foreach([['import.outer',1],['import.outer',-3],
                     ['export.outer',3],['export.outer',-3]] as [$field,$bad]){
                file_put_contents($file,$fixture($v,$field,$bad));
                $r=new CatalogUE1PackageReader($file);
                $check("v{$v}_{$field}_{$bad}_rejected",
                    $r->validatePackage()!==[]
                    && str_contains(implode(' ',$r->validatePackage()),'reference')
                    && $r->getImports()===[] && $r->getExports()===[]);
            }
        }
    }
}finally{@unlink($file);}
echo json_encode(['ok'=>$failed===[],'passed'=>count($results)-count($failed),
    'total'=>count($results),'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
