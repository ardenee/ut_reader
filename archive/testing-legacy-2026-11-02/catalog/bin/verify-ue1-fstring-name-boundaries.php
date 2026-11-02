<?php
declare(strict_types=1);
/** Native UT99 v400 Core/Src/UnName.cpp:212-236:
 * from package v64 FNameEntry is serialized FString and copied to
 * TCHAR Name[NAME_SIZE=64]. Reject invalid unsafe lengths/terminators.
 */
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;
$checks=[];$failures=[];
$check=static function(string $n,bool $v)use(&$checks,&$failures):void{$checks[$n]=$v;if(!$v){$failures[]=$n;}};
$index=static function(int $v):string{
  $neg=$v<0;$n=abs($v);$first=($n&63)|($neg?0x80:0);$n>>=6;
  if($n){$first|=0x40;return chr($first).chr($n);}
  return chr($first);
};
$fixture=static function(int $version,int $stringLen,string $payload)use($index):string{
  $header=pack('Vvv',0x9E2A83C1,$version,0).pack('V',0)
    .pack('V2',1,56).pack('V2',0,0).pack('V2',0,0)
    .pack('V4',1,2,3,4).pack('V',0);
  return $header.$index($stringLen).$payload.pack('V',0x70000);
};
$p=tempnam(sys_get_temp_dir(),'ue1_strbound_');if(!is_string($p)){throw new RuntimeException('Cannot create test file');}
try{
  foreach([68,69] as $v){
    $cases=[
      ['short',6,"Alpha\0",true,'Alpha'],
      ['max_63',64,str_repeat('A',63)."\0",true,str_repeat('A',63)],
      ['embedded_nul',8,"ABC\0XYZW",true,'ABC'],
      ['over_63',65,str_repeat('B',64)."\0",false,null],
      ['missing_nul',5,'abcde',false,null],
      ['wide',-3,pack('v3',65,66,0),true,'AB'],
      ['wide_without_nul',-2,pack('v2',65,66),false,null],
    ];
    foreach($cases as [$label,$len,$value,$valid,$expected]){
      file_put_contents($p,$fixture($v,$len,$value));
      $reader=new CatalogUE1PackageReader($p);
      $pass=$reader->validatePackage()===[];
      $check('v'.$v.'_'.$label,$pass===$valid
        && (!$valid || ($reader->getNames()[0]['name']??null)===$expected));
    }
  }
}finally{@unlink($p);}
echo json_encode(['ok'=>$failures===[],'passed'=>count($checks)-count($failures),'total'=>count($checks),'failures'=>$failures],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failures===[]?0:1);
