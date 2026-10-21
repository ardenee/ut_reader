<?php
declare(strict_types=1);
/** Native source: UT99 v400 Core/Inc/Core.h FromAnsi = (BYTE)In;
 * Core/Src/UnName.cpp FNameEntry operator<< (pre64 and >=64);
 * v1.200 Core/Inc/UnName.h FArchive::String.
 * UTF-8 recognition of serialized ANSI is NOT part of Epic's loader.
 */
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader;
$checks=[];$failed=[];
$check=static function(string $n,bool $ok)use(&$checks,&$failed):void{$checks[$n]=$ok;if(!$ok){$failed[]=$n;}};
$names=["\xC3\xA9","\xE9","\x80",'CaseSensitiveAbc'];
$compact=static function(int $num):string {return $num<64?chr($num):chr(($num&63)|64).chr($num>>6);};
$fixture=static function(int $version)use($names,$compact):string{
  $table='';
  foreach($names as $name){$table.=($version<64?$name."\0":$compact(strlen($name)+1).$name."\0").pack('V',0x70000);}
  $nameOffset=$version<68?44:56;
  $header=pack('Vvv',0x9E2A83C1,$version,0).pack('V',0)
    .pack('V2',count($names),$nameOffset).pack('V2',0,0).pack('V2',0,0);
  $header.=$version<68?pack('V2',0,0):pack('V4',1,2,3,4).pack('V',0);
  return $header.$table;
};
$path=tempnam(sys_get_temp_dir(),'ue1_ansi_');if(!is_string($path)){throw new RuntimeException('Temporary fixture missing');}
try {
  foreach([35,61,68,69] as $ver){
    file_put_contents($path,$fixture($ver));
    $r=new CatalogUE1PackageReader($path);
    $check('v'.$ver.'_reader_valid',$r->validatePackage()===[]);
    $raw=$r->getNames();
    $check('v'.$ver.'_ansi_two_bytes_not_utf8_codepoint',($raw[0]['name']??null)==="\xC3\x83\xC2\xA9");
    $check('v'.$ver.'_single_accent_byte',($raw[1]['name']??null)==="\xC3\xA9");
    $check('v'.$ver.'_0x80_is_control_not_euro',($raw[2]['name']??null)==="\xC2\x80");
    $check('v'.$ver.'_name_casing_preserved',($raw[3]['name']??null)==='CaseSensitiveAbc');
    $check('v'.$ver.'_flags_preserved',($raw[0]['flags']??null)===0x70000);
  }
}finally{@unlink($path);}
echo json_encode(['ok'=>$failed===[],'passed'=>count($checks)-count($failed),'total'=>count($checks),'failures'=>$failed],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($failed===[]?0:1);
