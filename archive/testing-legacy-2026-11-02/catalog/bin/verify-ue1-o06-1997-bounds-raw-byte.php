#!/usr/bin/env php
<?php
/** Epic May FBoundingRect / July FBoundingBox: same 25-byte archive, raw BYTE IsValid. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalUnrealfileDatabaseDataReader as DB;
$checks=[];$failed=[];
$check=static function(string $name,bool $ok)use(&$checks,&$failed):void{
 $checks[]=$name;if(!$ok)$failed[]=$name;
};
$sourceRoots=[
 'may'=>'L:/Source/Games/Unreal/Unreal [v0.86x] [1997-05-25]/Src/Inc',
 'july'=>'L:/Source/Games/Unreal/Unreal [v0.867] [1997-07-25]/Src/Inc'
];
$hashes=[];
foreach($sourceRoots as $branch=>$dir){
 $math=file_get_contents($dir.'/UnMath.h');
 $obj=file_get_contents($dir.'/UnObj.h');
 $hashes[$branch]=[
   'UnMath.h'=>hash('sha256',$math),'UnObj.h'=>hash('sha256',$obj)];
 $type=$branch==='may'?'FBoundingRect':'FBoundingBox';
 $check($branch.'_declares_original_native_record',
     str_contains($obj,'DECLARE_DB_CLASS(UBounds,UDatabase,'.$type.',NAME_Bounds'));
 $check($branch.'_operator_archives_all_three_fields_in_order',
     preg_match('/operator<<\\s*\\([^{};]*FArchive[^{};]*'.$type.'[^{};]*\\)\\s*\\{\\s*return\\s+Ar\\s*<<\\s*Bound\\.Min\\s*<<\\s*Bound\\.Max\\s*<<\\s*Bound\\.IsValid\\s*;/s',$math)===1);
 $check($branch.'_byte_is_not_archival_bool_or_checked_01',
     preg_match('/\\bBYTE\\s+IsValid\\s*;/',$math)===1);
}
$make=static function(int $ver,int $valid):array{
 $names="Bounds\0".pack('V',0)."Boundary\0".pack('V',0);
 $header=pack('V2',0,0).str_repeat("\xff",8).pack('V2',1,1);
 $data=pack('g6',-1.5,2.25,3.5,4.5,-5.25,6.75).chr($valid);
 if(strlen($data)!==25)throw new RuntimeException('Wrong source bounds stride');
 $nameOff=56;$headOff=$nameOff+strlen($names);$dataOff=$headOff+strlen($header);
 $objOff=$dataOff+strlen($data);
 $entry=pack('vvVV',2,1,0,$headOff).pack('V4',0,$dataOff,strlen($header),strlen($data));
 $summary=pack('a32V6',"Unrealfile\x1a",$ver,1,2,$nameOff,$objOff,0);
 return ['bytes'=>$summary.$names.$header.$data.$entry,'table'=>$objOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1-97-bounds-');
if(!is_string($tmp))throw new RuntimeException('Cannot create bounds fixture');
try{
 foreach([22,23] as $ver){
  foreach([0,1,2,255] as $valid){
   $fx=$make($ver,$valid);file_put_contents($tmp,$fx['bytes']);
   try{$rows=DB::read($tmp,true)['database_records'];$item=$rows[0]['record_values'][0]??[];}
   catch(\Throwable $e){$rows=[];$item=[];}
   $check("v{$ver}_isvalid_{$valid}_raw_byte_preserved",
      count($rows)===1
      && ($rows[0]['status']??null)==='native_database_records_decoded'
      && ($rows[0]['serialized_record_bytes']??null)===25
      && ($item['min']??null)===[-1.5,2.25,3.5]
      && ($item['max']??null)===[4.5,-5.25,6.75]
      && ($item['is_valid']??null)===$valid
      && ($item['is_valid_raw_byte']??null)===$valid);
  }
  $fx=$make($ver,1);
  file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',24),$fx['table']+24,4));
  try{DB::read($tmp,true);$rejected=false;}catch(\RuntimeException){$rejected=true;}
  $check("v{$ver}_short_archive_rejected",$rejected);
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$failed===[],'passed'=>count($checks)-count($failed),
 'total'=>count($checks),'failures'=>$failed,'source_hashes'=>$hashes,
 'scope'=>'Same on-disk layout; no source-family determination, no runtime bool normalization'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed?1:0);
