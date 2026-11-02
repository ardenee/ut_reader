<?php
/** Actual May 1998 original compiled UClass::Serialize loaded class flags, record size and guid. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';

$repo=dirname(__DIR__,2);
$proof=json_decode((string)file_get_contents($repo.'/catalog/compliance/evidence/o06/20261101-G3-T072-T075-original-C98-1998-Core-Engine-compiled-full-cohort-manifest.json'),true,512,JSON_THROW_ON_ERROR);
foreach($proof['pins'] as $name=>$pin){
    if(str_ends_with($name,'_verifier'))continue;
    $source=$pin['location']==='repo'?$repo.'/'.$pin['path']:$pin['path'];
    $valid=is_file($source)&&filesize($source)===$pin['bytes']
      &&hash_file('sha256',$source)===$pin['sha256'];
    $gitAlt=isset($pin['canonical_git_blob_sha256'])&&is_file($source)
      &&filesize($source)===$pin['canonical_git_blob_bytes']
      &&hash_file('sha256',$source)===$pin['canonical_git_blob_sha256'];
    if(!$valid&&!$gitAlt)throw new RuntimeException('1998 C98 original native/source SHA pin changed: '.$name);
}
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$log=(string)file_get_contents($repo.'/'.$proof['pins']['1998_original_UClass_flags_guid_and_record_size_log']['path']);
$rows=[];
foreach(preg_split('/\r\n|\n|\r/',$log) as $line){
 if(preg_match('/^T072_C98_NEW_CLASS linker=([0-9a-f]{8}) idx=(\d+) flags=([0-9a-f]{8}) oldRecordSize=(-?\d+) classUnique=(-?\d+) guid=([0-9a-f]{32})$/i',$line,$m)){
 $rows[$m[1]][]=['id'=>(int)$m[2],'flags'=>hexdec($m[3]),'oldSize'=>(int)$m[4],
  'unique'=>(int)$m[5],'guidDwords'=>strtoupper($m[6])];
 }
}
$expected=['Core.u'=>2,'Engine.u'=>69];$checks=0;$err=[];$summary=[];$matching=0;$first=[];
$ck=static function(bool $ok,string $label)use(&$checks,&$err):void{++$checks;if(!$ok&&count($err)<35)$err[]=$label;};
foreach($expected as $name=>$count){
 $path=dirname($proof['pins']['1998_original_Core_u']['path']).'/'.$name;
 $p=new P($path);$exps=$p->getExports();$linker=null;
 foreach($rows as $ptr=>$v)if(count($v)===$count)$linker=$ptr;
 $ck($linker!==null,"original native ".$name." UClass callbacks");
 if($linker===null)continue;
 $seen=[];$match=0;$nativeGuidNonzero=0;
 foreach($rows[$linker] as $one){
  $id=$one['id'];$ck(!isset($seen[$id]),"$name unique native UClass object $id");$seen[$id]=1;
  $exp=$exps[$id]??null;
  $ck(is_array($exp)&&(int)$exp['classIndex']===0,"$name native UClass export type");
  try{$source=C::inspect($path,$p,$id,false,'epic-ue1');}
  catch(Throwable $e){if(count($err)<35)$err[]="$name $id ".$e->getMessage();continue;}
  $okFlags=$source['class_flags']===$one['flags'];
  $okSize=$source['old_class_record_size']===$one['oldSize'];
  // sprintf('%08x') prints each dword in host endian, reverse 4 bytes to match raw file hex.
  $rawGuid='';
  foreach(str_split($one['guidDwords'],8) as $dword){
    $rawGuid.=strtoupper(bin2hex(strrev(hex2bin($dword))));
  }
  $okGuid=$rawGuid===$source['class_guid_raw_hex'];
  $ck($okFlags,"$name $id native ClassFlags against archive");
  $ck($okSize,"$name $id native original ClassRecordSize against archive");
  $ck($okGuid,"$name $id native FGuid16 byte exact against archive");
  if($rawGuid!==str_repeat('0',32))$nativeGuidNonzero++;
  if($okFlags&&$okSize&&$okGuid){$match++;$matching++;}
  elseif(count($first)<15)$first[]=['file'=>$name,'id'=>$id,'name'=>$exp['objectNameText'],
    'flags_native'=>$one['flags'],'flags_file'=>$source['class_flags'],
    'record_native'=>$one['oldSize'],'record_file'=>$source['old_class_record_size'],
    'guid_native'=>$rawGuid,'guid_file'=>$source['class_guid_raw_hex']];
 }
 $ck(count($seen)===$count,"$name full expected original UClass export count");
 $summary[$name]=['linker'=>$linker,'observed_original_class_methods'=>count($seen),
  'exact_class_records'=>$match,'nonzero_guids'=>$nativeGuidNonzero];
}
$ck(count($rows)===2&&$matching===71,'original two genuine C98 Class loader linkers and all 71 match');
echo json_encode(['ok'=>$err===[],'checks'=>$checks,'source_native_class_records_matched'=>$matching,
 'original_loaded_class_details'=>$summary,'mismatch_examples'=>$first,'errors'=>$err,'full_T072'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($err===[]?0:1);
