#!/usr/bin/env php
<?php
/**
 * Genuine version-44 UE1 texture/palette negative mutations.
 * Mutate disposable file copies only, not Epic's corpus or PRD database.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tag;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$source='L:\\Source\\Engine\\UE1\\Source\\Unreal\\Textures\\Blood.utx';
if(!is_file($source)){throw new RuntimeException('Genuine Blood.utx fixture missing');}
$tmp=tempnam(sys_get_temp_dir(),'ue1o06neg');
if(!is_string($tmp)){throw new RuntimeException('Temporary file unavailable');}
$checks=[];$fail=[];
$check=static function(string $name,bool $ok)use(&$checks,&$fail):void{
 $checks[$name]=$ok;if(!$ok){$fail[]=$name;}
};
try {
 copy($source,$tmp);
 $reader=new Package($tmp);
 $check('genuine_structure_passes',$reader->validatePackage()===[]);
 $exports=$reader->getExports();
 $palette=null;$texture=null;
 foreach($exports as $e){
  $class=(int)$e['classIndex'];$imports=$reader->getImports();
  $className=$class<0?(string)($imports[-$class-1]['objectNameText']??''):'';
  if($className==='Palette'&&$palette===null){$palette=$e;}
  if($className==='Texture'&&$texture===null){$texture=$e;}
 }
 if(!is_array($palette)||!is_array($texture)){throw new RuntimeException('Genuine palette/texture records missing');}
 $paletteTags=Tag::inspect($tmp,$reader,(int)$palette['index']);
 $textureTags=Tag::inspect($tmp,$reader,(int)$texture['index']);
 $check('palette_valid_tag_envelope',($paletteTags['status']??null)==='tag_envelope_decoded');
 $check('texture_valid_tag_envelope',($textureTags['status']??null)==='tag_envelope_decoded'
     && count($textureTags['tags']??[])>0);
 $check('valid_palette_native_complete',
     (Native::inspect($tmp,$reader,(int)$palette['index'],$paletteTags)['status']??null)==='native_palette_record_complete');
 $check('valid_texture_native_complete',
     (Native::inspect($tmp,$reader,(int)$texture['index'],$textureTags)['status']??null)==='native_texture_record_complete');

 // Corrupt the first property name reference to an out-of-map value.
 $pos=(int)$textureTags['tags'][0]['tag_offset'];
 $bytes=file_get_contents($tmp);
 if(!is_string($bytes)){throw new RuntimeException('Cannot read test bytes');}
 file_put_contents($tmp,substr_replace($bytes,"\x3f",$pos,1));
 try{Tag::inspect($tmp,$reader,(int)$texture['index']);$rejected=false;}
 catch(RuntimeException){$rejected=true;}
 $check('invalid_property_name_rejected',$rejected);

 // Restore, then corrupt the palette TArray compact count negative.
 file_put_contents($tmp,$bytes);
 $paletteDataOffset=(int)$palette['serialOffset']+(int)$palette['serialSize']-(int)$paletteTags['native_tail_bytes'];
 file_put_contents($tmp,substr_replace($bytes,"\x81",$paletteDataOffset,1));
 try{Native::inspect($tmp,$reader,(int)$palette['index'],$paletteTags);$rejected=false;}
 catch(RuntimeException){$rejected=true;}
 $check('negative_native_color_count_rejected',$rejected);

 // Even with a valid tag None terminator, an invalid class-native body
 // cannot be promoted to a successful fully decoded export.
 $cmd=escapeshellarg(PHP_BINARY).' '.escapeshellarg(dirname(__DIR__).'/bin/inspect-unreal-file.php')
      .' --json --full '.escapeshellarg($source);
 $lines=[];$exit=0;exec($cmd,$lines,$exit);
 $inspected=json_decode(implode("\n",$lines),true);
 $check('production_cli_surfaces_tagged_and_native_structures',
     $exit===0&&is_array($inspected)
     && ($inspected['reader_engine']??null)==='UE1'
     && ($inspected['full_validation']??null)==='OK'
     && count($inspected['ue1_object_payload_preview']??[])===count($exports)
     && ($inspected['ue1_object_payload_preview'][0]['native_object']['status']??null)==='native_palette_record_complete'
     && ($inspected['ue1_object_payload_preview'][1]['status']??null)==='tag_envelope_decoded');
 $check('tag_only_is_not_native_verification',
     ($paletteTags['schema_verified']??true)===false);
}finally{@unlink($tmp);}
echo json_encode(['ok'=>$fail===[],'passed'=>count($checks)-count($fail),
  'total'=>count($checks),'failures'=>$fail],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
