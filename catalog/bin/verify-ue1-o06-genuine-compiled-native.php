#!/usr/bin/env php
<?php
/**
 * O06 authentic on-disk corpus, source-anchored and SHA-256 pinned.
 *
 * Genuine Unreal v61 .u scripts and UT99 v66/v68 .uax sounds.
 * All exports are read by the same production tagged/native readers.
 * Explicitly reports unverified Class default schemas (not O06 accepted).
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$repo=dirname(__DIR__,2);
$manifest=json_decode(
 (string)file_get_contents($repo.'/catalog/compliance/inventory/ue1-o06-genuine-compiled-nontexture.json'),
 true,512,JSON_THROW_ON_ERROR);
$checks=[];$failed=[];$errors=[];$fileRows=[];$statuses=[];$classes=[];
$sourceFiles=[];
$check=static function(string $key,bool $ok)use(&$checks,&$failed):void{
 $checks[$key]=$ok;if(!$ok)$failed[]=$key;
};
$sourceRoot='L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Engine/';
foreach(['Src/UnMesh.cpp','Src/UnFont.cpp','Src/UnTex.cpp','Inc/UnTex.h'] as $s){
 $data=@file_get_contents($sourceRoot.$s);
 $sourceFiles[$s]=$data===false?null:hash('sha256',$data);
 $check('pinned_epic_source_available_'.basename($s),is_string($data));
}
$meshSource=@file_get_contents($sourceRoot.'Src/UnMesh.cpp');
$fontSource=@file_get_contents($sourceRoot.'Src/UnFont.cpp');
$fontHeader=@file_get_contents($sourceRoot.'Inc/UnTex.h');
$check('epic_legacy_mesh_archive_array_order',
 is_string($meshSource)
 &&str_contains($meshSource,'Ar << Verts << Tris << AnimSeqs;')
 &&str_contains($meshSource,'Ar << Connects << BoundingBox << BoundingSphere << VertLinks << Textures;')
 &&str_contains($meshSource,'Ar << BoundingBoxes << BoundingSpheres;'));
$check('epic_legacy_font_inherits_texture_then_glyphs',
 is_string($fontSource)&&str_contains($fontSource,'UTexture::Serialize(Ar);')
 &&str_contains($fontSource,'Ar << Characters;')
 &&is_string($fontHeader)
 &&str_contains($fontHeader,'class ENGINE_API UFont : public UTexture')
 &&str_contains($fontHeader,'return Ar << Ch.StartU << Ch.StartV << Ch.USize << Ch.VSize;'));
$check('manifest_sixteen_source_archives',count($manifest['files']??[])===16);
$totals=['exports'=>0,'native_complete'=>0,'font'=>0,'mesh'=>0,'sound'=>0,
 'unverified_class_default_schemas'=>0,'class_no_script_no_defaults'=>0,
 'tag_count'=>0,'native_tail_bytes'=>0];
foreach($manifest['files'] as $spec){
 $path=$spec['path'];$label=basename($path);
 $exact=is_file($path)&&filesize($path)===(int)$spec['bytes']
   &&strtolower(hash_file('sha256',$path))===strtolower($spec['sha256']);
 $check('manifest_sha256_'.$label,$exact);
 if(!$exact){continue;}
 $pkg=new P($path);
 $header=$pkg->getHeader();
 $check('package_header_'.$label,$pkg->validatePackage()===[]);
 $check('package_version_'.$label,(int)$header['version']===(int)$spec['version']);
 $exports=$pkg->getExports();
 $check('export_map_count_'.$label,count($exports)===(int)$spec['exports']);
 $count=0;$nativeComplete=0;$localErrors=0;$localClasses=[];
 foreach($exports as $exp){
  $i=(int)$exp['index'];$count++;$totals['exports']++;
  try{
   $tag=T::inspect($path,$pkg,$i);
   $kind=(string)($tag['status']??'unknown');
   $statuses['tag:'.$kind]=($statuses['tag:'.$kind]??0)+1;
   if($kind==='class_default_tags_structurally_decoded_schema_unverified'){
    $totals['unverified_class_default_schemas']++;continue;
   }
   if($kind==='class_metadata_no_script_no_defaults_complete'){
    $totals['class_no_script_no_defaults']++;continue;
   }
   if($kind!=='tag_envelope_decoded'){
    throw new RuntimeException('Unsupported tag status '.$kind);
   }
   $totals['tag_count']+=count($tag['tags']??[]);
   $totals['native_tail_bytes']+=(int)($tag['native_tail_bytes']??0);
   $native=N::inspect($path,$pkg,$i,$tag);
   $status=(string)($native['status']??'');
   $class=(string)($native['class_name']??'');
   $statuses['native:'.$status]=($statuses['native:'.$status]??0)+1;
   $localClasses[$class]=($localClasses[$class]??0)+1;
   $classes[$class]=($classes[$class]??0)+1;
   if(!str_ends_with($status,'_complete')
    ||($native['extension_bytes']??-1)!==0){
    throw new RuntimeException('Native incomplete '.$class.' '.$status);
   }
   $nativeComplete++;$totals['native_complete']++;
   if($class==='Mesh'){
    $totals['mesh']++;
    if((int)$header['version']===61
      &&(($native['sphere_wire_layout']??'')!=='sphere_xyz_radius_16'
       ||!isset($native['mesh_bounding_sphere']['w'])
       ||($native['source_serialized_base_bytes']??0)!==41
       ||($native['verts']['count']??-1)<1)){
      throw new RuntimeException('Genuine v61 mesh sphere/array layout mismatch');
    }
   }
   if($class==='Font'){
    $totals['font']++;
    if(($native['archive_family']??'')!=='Unreal-v1200-UTexture-Characters'
      ||($native['character_count']??-1)!==256
      ||($native['glyph_data_bytes']??-1)!==4096
      ||($native['mip_count']??-1)<1
      ||count($native['glyphs_preview']??[])!==16){
      throw new RuntimeException('Genuine v61 texture-derived UFont glyph contract mismatch');
    }
   }
   if($class==='Sound'){$totals['sound']++;}
  }catch(Throwable $e){
   $localErrors++;
   if(count($errors)<32){
    $errors[]=['file'=>$label,'export'=>$i,
      'name'=>$exp['objectNameText']??'?','problem'=>$e->getMessage()];
   }
  }
 }
 $check('no_export_decoder_errors_'.$label,$localErrors===0);
 $fileRows[]=['file'=>$label,'version'=>$header['version'],'exports'=>$count,
    'native_complete'=>$nativeComplete,
    'exceptions'=>$localErrors,
    'sha256'=>$spec['sha256']];
}
$check('total_15194_export_records',$totals['exports']===15194);
$check('all_non_class_exports_native_complete',
 $totals['native_complete']+$totals['unverified_class_default_schemas']
  +$totals['class_no_script_no_defaults']===$totals['exports']);
$check('genuine_342_native_meshes',$totals['mesh']===342);
$check('genuine_8_texture_derived_fonts',$totals['font']===8);
$check('genuine_844_native_sound_exports',$totals['sound']===844);
$check('unverified_class_default_schema_gap_visible',
 $totals['unverified_class_default_schemas']===513);
$check('schema_free_class_exports_visible',
 $totals['class_no_script_no_defaults']===60);
ksort($statuses);ksort($classes);
echo json_encode([
 'ok'=>$failed===[],'checks'=>count($checks),
 'passed'=>count($checks)-count($failed),'failed'=>$failed,
 'errors'=>$errors,'totals'=>$totals,'files'=>$fileRows,
 'native_statuses'=>$statuses,'class_counts'=>$classes,
 'pinned_epic_source_sha256'=>$sourceFiles,
 'remaining'=>'513 class-default schemas structurally decoded but source/schema unverified; no compiled native oracle',
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($failed?1:0);
