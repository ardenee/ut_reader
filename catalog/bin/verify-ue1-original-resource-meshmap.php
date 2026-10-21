#!/usr/bin/env php
<?php
/** Source-defined UE1 1996 v9/v10 UMeshMap header and refs. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1OriginalResourceMeshMapReader as Map;
$checks=[];$errors=[];
$check=static function(string $id,bool $ok)use(&$checks,&$errors):void{
 $checks[$id]=$ok;if(!$ok)$errors[]=$id;
};
$make=static function(int $version):array{
 $prefix="Unreal Resource\x1A\0";
 $names=pack('a32V','MESHMAP',0);$nameOff=strlen($prefix);
 $textureWords=pack('V3',1,2,0);
 $dataOff=$nameOff+strlen($names);
 $exportOff=$dataOff+strlen($textureWords);
 $export=pack('a32VVV','MMOne',1,32,104);
 $headOff=$exportOff+strlen($export);
 $head=pack('V2a32V9',0,32,'MMOne',0,0,1,0xffffffff,0,$dataOff,strlen($textureWords),0,0)
     .pack('V4g3',42,3,0x1234,0x5678,1.5,2.0,-3.0);
 if(strlen($head)!==104)throw new RuntimeException('Wrong UMeshMap native fixture header');
 $trailer=pack('V9',$version,1,0,1,$nameOff,0,$exportOff,$headOff,104)
   .str_repeat("\0",16).str_repeat("\0",80).pack('a32',"Unreal Resource\x1A");
 return ['bytes'=>$prefix.$names.$textureWords.$export.$head.'[SUMMARY]'.$trailer,
   'header'=>$headOff,'export'=>$exportOff];
};
$tmp=tempnam(sys_get_temp_dir(),'ue1meshmap');
if(!is_string($tmp))throw new RuntimeException('Cannot make fixture');
try{
 foreach([9,10] as $v){
  $fx=$make($v);file_put_contents($tmp,$fx['bytes']);
  $row=Map::read($tmp,true)['meshmap_records'][0]??[];
  $check("v{$v}_native_meshmap_header",
   ($row['status']??null)==='native_meshmap_decoded'
   && ($row['source_class']??null)==='UMeshMap'
   && ($row['mesh_reference_raw_word']??null)===42
   && ($row['texture_count']??null)===3
   && ($row['and_flags']??null)===0x1234
   && ($row['or_flags']??null)===0x5678
   && ($row['serialized_header_bytes']??null)===104);
  $check("v{$v}_native_scaling_and_unresolved_textures",
   ($row['scale']??null)===['x'=>1.5,'y'=>2.0,'z'=>-3.0]
   && ($row['texture_reference_raw_words']??null)===[1,2,0]
   && ($row['runtime_mesh_or_texture_references_resolved']??true)===false);
  $stage=\UnrealDb\Catalog\Infrastructure\Unverified\CatalogUE1OriginalStagingMetadata::decode(
    $tmp,['format'=>'early_unreal_resource','version'=>$v]
  );
  $stored=$stage['native_metadata']['resource_meshmap_records']['meshmap_records'][0]??[];
  $check("v{$v}_native_unverified_staging",
    ($stored['texture_count']??null)===3
    && ($stage['parse_complete']??true)===false
    && ($stage['native_metadata']['payload_decoded']??true)===false);
  file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',4),$fx['header']+80,4));
  try{Map::read($tmp,true);$bad=false;}catch(RuntimeException){$bad=true;}
  $check("v{$v}_wrong_max_textures_rejected",$bad);
  file_put_contents($tmp,substr_replace($fx['bytes'],pack('V',100),$fx['export']+40,4));
  $incomplete=Map::read($tmp,false)['meshmap_records'][0]??[];
  try{Map::read($tmp,true);$bad=false;}catch(RuntimeException){$bad=true;}
  $check("v{$v}_short_header_not_falsely_decoded",
   ($incomplete['status']??null)==='incomplete_source_meshmap_header'&&$bad);
 }
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$errors===[],'passed'=>count($checks)-count($errors),
'total'=>count($checks),'failures'=>$errors],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($errors===[]?0:1);
