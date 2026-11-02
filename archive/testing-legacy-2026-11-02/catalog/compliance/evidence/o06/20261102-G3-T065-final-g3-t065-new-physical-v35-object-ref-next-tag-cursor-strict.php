<?php
declare(strict_types=1);
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/bootstrap/autoload.php';
require_once 'C:/Users/arden/source/repos/ut_reader/catalog/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$meta=json_decode(file_get_contents('C:/Users/arden/source/repos/ut_reader/catalog/compliance/evidence/o06/20261101-G3-T065-local-original-historical-v35-v55to58-physical-61-summary.json'),true,512,JSON_THROW_ON_ERROR);
$groups=[];$samples=[];$errors=[];$totals=['v35_old_object_tag'=>0,'v35_ref_direct_on_disk_compact_width1'=>0,
 'v35_exact_next_physical_tag_offset'=>0,'v35_byte_ref_values_matched'=>0,'v37_plus_real_ordinary_object_ref_tags'=>0];
foreach($meta['genuine_physical_packages'] as $src){
 if(!in_array($src['archive_version'],[35,37,56,57,58],true))continue;
 $path=$meta['source_repository_directory'].'/'.$src['filename'];
 $pkg=new P($path);$fp=fopen($path,'rb');
 if(!$fp)throw new RuntimeException('read original file failed '.$path);
 foreach($pkg->getExports() as $idx=>$export){
  $v=T::inspect($path,$pkg,$idx,'epic-ue1');
  foreach($v['tags'] as $n=>$tag){
   if(($tag['type']??'')!=='ObjectProperty')continue;
   $version=(int)$src['archive_version'];
   $totals[$version===35?'v35_old_object_tag':'v37_plus_real_ordinary_object_ref_tags']++;
   $tagOffset=(int)$tag['tag_offset'];$valueOffset=(int)$tag['value_offset'];$count=(int)$tag['on_disk_value_bytes'];
   if($version!==35)continue;
   if($tag['serialized_size']!==4||$count!==1||$tag['value_kind']!=='v35_compact_object_reference'){
    $errors[]=['file'=>$src['filename'],'idx'=>$idx,'tag'=>$tag];continue;
   }
   $totals['v35_ref_direct_on_disk_compact_width1']++;
   if(fseek($fp,$valueOffset)!==0)throw new RuntimeException('physical offset not readable');
   $byte=fread($fp,1);
   if(strlen($byte)!==1)throw new RuntimeException('missing physical byte');
   $octet=ord($byte);
   if(($octet&0x40)!==0){$errors[]="unexpected multibyte original real v35 ref $path";continue;}
   $value=($octet&0x80)!==0?-(int)($octet&0x3f):(int)($octet&0x3f);
   if($value===$tag['value'])$totals['v35_byte_ref_values_matched']++;else $errors[]="physical ref mismatch $path $idx";
   if(isset($v['tags'][$n+1])){
    if((int)$v['tags'][$n+1]['tag_offset']===$valueOffset+1)$totals['v35_exact_next_physical_tag_offset']++;
    else $errors[]=['physical_old_ref_next_tag_cursor_bad'=>$path,'idx'=>$idx,'got'=>$v['tags'][$n+1]['tag_offset'],'expected'=>$valueOffset+1];
   }
   if(count($samples)<6)$samples[]=['package'=>$src['filename'],'file_sha256'=>hash_file('sha256',$path),
    'version'=>$version,'object_export_index'=>$idx,'tag_name'=>$tag['name'],
    'actual_tag_size_field'=>$tag['serialized_size'],'physical_single_byte_hex'=>bin2hex($byte),
    'actual_archive_reference'=>$value,'tag_offset'=>$tagOffset,'value_offset'=>$valueOffset,
    'next_tag_offset'=>$v['tags'][$n+1]['tag_offset']??null];
  }
 }
 fclose($fp);
}
echo json_encode(['ok'=>count($errors)===0,'physical_cursor_source_intersection'=>$totals,
 'genuine_old_original_samples'=>$samples,'errors'=>array_slice($errors,0,14),
 'original_compiled_C98_unknown_invalid_property_branch_executed_on_these_physical_packages'=>false,
 'source_only_physical_cursor_not_equivalent_to_native_loaded_value'=>true],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]&&$totals['v35_old_object_tag']>40?0:1);
