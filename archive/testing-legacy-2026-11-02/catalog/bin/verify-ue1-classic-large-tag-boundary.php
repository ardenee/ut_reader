#!/usr/bin/env php
<?php
/**
 * Test Epic FPropertyTag's NAME_None termination, without invented maximum
 * tag counts. C98 and T99 share the archive tag grammar.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$src98=@file_get_contents('L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Core/Src/UnClass.cpp');
$src99=@file_get_contents('L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Core/Src/UnClass.cpp');
$checks=[];$failed=[];$samples=[];
$check=static function(string $label,bool $ok)use(&$checks,&$failed):void{
 $checks[$label]=$ok;if(!$ok)$failed[]=$label;
};
foreach(['C98'=>$src98,'T99'=>$src99] as $family=>$source){
 $check('source_'.$family.'_uses_none_terminated_tag_stream',
  is_string($source)
  &&str_contains($source,'Ar << Tag;')
  &&str_contains($source,'Tag.Name == NAME_None'));
}
$compact=static function(int $n):string{
 $neg=$n<0;$n=abs($n);$first=($n&63)|($neg?128:0);$n>>=6;
 if(!$n)return chr($first);
 $s=chr($first|64);
 do{$b=$n&127;$n>>=7;$s.=chr($b|($n?128:0));}while($n);
 return $s;
};
$build=static function(int $ver,string $payload)use($compact):string{
 $names=['None','Class','Example','Core','Friendly','System','Package','MyInt'];
 $nameBytes='';
 foreach($names as $name){
  $nameBytes.=($ver>=64?$compact(strlen($name)+1):'').$name."\0".pack('V',0);
 }
 $headerSize=$ver>=68?56:44;
 $import=$compact(3).$compact(1).pack('V',0).$compact(1);
 $nameOff=$headerSize;$importOff=$nameOff+strlen($nameBytes);
 $exportOff=$importOff+strlen($import);
 $export=$compact(-1).$compact(0).pack('V',0).$compact(2).pack('V',0)
   .$compact(strlen($payload)).$compact(512);
 $head=pack('V9',0x9e2a83c1,$ver,0,count($names),$nameOff,
   1,$exportOff,1,$importOff);
 $head.=$ver>=68?str_repeat("\0",16).pack('V',0):pack('V2',0,0);
 $tables=$head.$nameBytes.$import.$export;
 if(strlen($tables)>512)throw new RuntimeException('Fixture export table too large');
 return $tables.str_repeat("\0",512-strlen($tables)).$payload;
};
$run=static function(int $ver,string $body,bool $missingNone=false)
  use($build,$check,&$samples):void {
 $path=tempnam(sys_get_temp_dir(),'UE1O06Tags');
 if($path===false)throw new RuntimeException('Cannot create test fixture');
 try{
  file_put_contents($path,$build($ver,$body));
  $p=new P($path);
  $check("v{$ver}_valid_package",$p->validatePackage()===[]);
  if($missingNone){
   foreach(['uobject','uclass_defaults'] as $mode){
    $bad=false;
    try{
     if($mode==='uobject')T::inspect($path,$p,0);
     else T::inspectClassDefaults($path,$p,512,512+strlen($body),$ver);
    }catch(RuntimeException $e){
     $bad=str_contains($e->getMessage(),'NAME_None terminator')
          ||str_contains($e->getMessage(),'exceeds export SerialSize')
          ||str_contains($e->getMessage(),'exceeds export');
    }
    $check("v{$ver}_unterminated_{$mode}_rejected",$bad);
   }
   return;
  }
  $normal=T::inspect($path,$p,0);
  $defaults=T::inspectClassDefaults($path,$p,512,512+strlen($body),$ver);
  $expected=intdiv(strlen($body)-1,6);
  $check("v{$ver}_uobject_{$expected}_tags",
    $normal['status']==='tag_envelope_decoded'
    &&count($normal['tags'])===$expected
    &&$normal['native_tail_bytes']===0);
  $check("v{$ver}_uclass_defaults_{$expected}_tags",
    count($defaults['tags'])===$expected
    &&$defaults['tag_end_offset']===512+strlen($body));
  $check("v{$ver}_first_last_value_types",
    $normal['tags'][0]['value']===0
    &&$normal['tags'][$expected-1]['value']===$expected-1
    &&$normal['tags'][$expected-1]['type']==='IntProperty'
    &&$defaults['tags'][$expected-1]['value']===$expected-1);
  $samples[]=['version'=>$ver,'tag_count'=>$expected,
    'archive_bytes'=>strlen($body),'first_value'=>0,
    'last_value'=>$expected-1];
 }finally{unlink($path);}
};
foreach([61,69] as $ver){
 $count=20001;$raw='';
 for($i=0;$i<$count;$i++){$raw.=$compact(7).chr(0x22).pack('V',$i);}
 $run($ver,$raw.$compact(0));
 $run($ver,$raw,true);
 $run($ver,$raw.$compact(7),true); // partial next tag: no info
}
echo json_encode(['ok'=>$failed===[],'checks'=>count($checks),
 'passed'=>count($checks)-count($failed),'failed'=>$failed,
 'samples'=>$samples,
 'epic_source_sha256'=>[
  'C98'=>is_string($src98)?hash('sha256',$src98):null,
  'T99'=>is_string($src99)?hash('sha256',$src99):null],
 'remaining'=>'Counts/bounds in C98/T99 FPropertyTag archives; property schema and compiled runtime parity remain separate O06 contracts.'
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed?1:0);
