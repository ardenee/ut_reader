#!/usr/bin/env php
<?php
/** Independent all-seven-gate UT99 v432 UFont loaded-value and error
 * acceptance. 66 genuine archives: 4 canonical native glyphs and
 * 62 NEW full compiled native glyph/remap/texture comparisons.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$root=dirname(__DIR__,2);
$manifest=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G5-T99-FONT-COMPILED-ALL-VALUES-REVIEW.json'),true,512,JSON_THROW_ON_ERROR);
$ok=0;$bad=[];
$ck=static function(string $name,bool $v)use(&$ok,&$bad):void {if($v)$ok++;else $bad[]=$name;};
$hashMatch=static fn(array $f):bool=>is_file($f['path'])&&hash_file('sha256',$f['path'])===$f['sha256'];
foreach(['original_compiled_core_and_Epic_sources_sha','positive_and_malformed_proof_sha'] as $part){
 foreach($manifest[$part] as $id=>$v)$ck('source_binary_SHA_'.$id,$hashMatch($v));
}
foreach($manifest['actual_compiled_native_load_logs_sha'] as $id=>$spec){
 $ck('original_native_Cplusplus_stdout_SHA_'.$id,
  is_file($spec['native_log'])&&hash_file('sha256',$spec['native_log'])===$spec['native_log_sha256']
  &&is_file($spec['original_archive'])&&hash_file('sha256',$spec['original_archive'])===$spec['original_sha256']);
}
foreach($manifest['original_packages_sha'] as $id=>$spec){$ck('original_genuine_PRD_SHA_'.$id,$hashMatch($spec));}
$proof=$manifest['positive_and_malformed_proof_sha'];
$source=$manifest['original_compiled_core_and_Epic_sources_sha'];
$fontSource=(string)file_get_contents($source['original_v400_Engine_UnFont_legacy_method']['path']);
$fontHeader=(string)file_get_contents($source['original_v432_Engine_UnTex_UFont_members']['path']);
$parent=(string)file_get_contents($source['original_v400_Core_UObject_method']['path']);
$ck('original_Epic_v400_Font_parent_then_pages_cpp',
 str_contains($fontSource,'Super::Serialize( Ar );')
 &&str_contains($fontSource,'Ar << Pages << CharactersPerPage;'));
$ck('original_v432_Font_modern_native_members',
 str_contains($fontHeader,'TMap<TCHAR,TCHAR> CharRemap;')
 &&str_contains($fontHeader,'UBOOL IsRemapped;')
 &&str_contains($fontHeader,'TArray<FFontPage> Pages;'));
$ck('Epic_super_without_stateframe_or_tag_for_actual_fonts',
 str_contains($parent,'if( GetFlags() & RF_HasStack )')
 &&str_contains($parent,'GetClass()->SerializeTaggedProperties( Ar, (BYTE*)this, Class );'));
$original=json_decode((string)file_get_contents($proof['original_PHP_66_inherited_envelopes']['path']),true,512,JSON_THROW_ON_ERROR);
$matches=json_decode((string)file_get_contents($proof['native_62_full_actual_value_parity']['path']),true,512,JSON_THROW_ON_ERROR);
$positives=json_decode((string)file_get_contents($proof['prior_4_fonts_loaded_full_glyph']['path']),true,512,JSON_THROW_ON_ERROR);
$nonempty=json_decode((string)file_get_contents($proof['prior_62_fonts_loaded_nonempty_maps']['path']),true,512,JSON_THROW_ON_ERROR);
$negative=json_decode((string)file_get_contents($proof['prior_compiled_valid_and_malformed']['path']),true,512,JSON_THROW_ON_ERROR);
$ck('all_native_original_62_full_loaded_positive',$matches['exact_object_count']===62
 &&$matches['errors']===0&&$matches['all_glyph_arrays_exact']
 &&$matches['all_remap_pairs_exact']&&$matches['all_full_texture_refs_exact']);
$totals=$matches['originals_native_loaded'];
$ck('genuine_full_native_loaded_62_fonts',$totals['full_native_font_objects']===62);
$ck('all_compiled_Font_remap_46434_pairs',$totals['remap_pairs']===46434
 &&$totals['remap_bytes']===185736);
$ck('actual_compiled_62_complete_glyph_arrays_1200128_bytes',$totals['glyph_bytes']===1200128);
$ck('four_canonical_original_Font_glyphs_and_native_textures',count($positives['font_records'])===4
 &&$positives['loaded_field_contract']['characters_per_page']===256);
$ck('two_genuine_remap_package_native_counts',count($nonempty['cases'])===2
 &&array_sum(array_column($nonempty['cases'],'font_count'))===62);
$ck('native_good_positive_4_remaps_and_native_bad_error',
 $negative['corrective_decision']['corrected_error']==='Font: Serial size mismatch: Got 4116, Expected 4112'
 &&$negative['native_malformed_serial_size']==='Font Engine.SmallFont: Serial size mismatch: Got 4116, Expected 4112'
 &&$negative['expected_pairs']=== [[65,97],[66,98],[67,99],[68,100]]);
$originalCounts=0;$lastMarkers=[];
foreach($original as $file=>$group){
 $spec=$manifest['original_packages_sha'][$file]??null;
 $ck('pinned_source_package_'.$file,$spec!==null
    &&$spec['sha256']===$group['sha256']
    &&$spec['version']===$group['version']
    &&$spec['fonts']===count($group['fonts']));
 $pkg=new P($group['source_file']);
 $ck('original_UE1_archive_v69_'.$file,(int)$pkg->getHeader()['version']===69);
 $exports=$pkg->getExports();
 foreach($group['fonts'] as $row){
  $i=(int)$row['index'];$exp=$exports[$i];
  $t=T::inspect($group['source_file'],$pkg,$i,'ut99-v432');
  $n=N::inspect($group['source_file'],$pkg,$i,$t,'ut99-v432');
  $ck('actual_inherited_UObject_no_tags_stack_'.$file.'_'.$i,
   $row['stateframe']===null&&$row['tags']===0
   &&($exp['objectFlags']&0x02000000)===0
   &&$t['tags']===[]&&$t['serialized_state_frame']===null
   &&$t['tag_end_offset']===$exp['serialOffset']+2);
  $ck('native_Font_exact_export_boundary_'.$file.'_'.$i,
   $n['status']==='native_font_record_complete'
   &&$n['extension_bytes']===0
   &&$row['native_status']==='native_font_record_complete'
   &&$exp['serialSize']===$row['serial_size']);
  $originalCounts++;
 }
 $ck('all_compiled_Font_ordinary_envelopes_'.$file,count($group['errors'])===0);
}
$ck('all_66_genuine_Font_inherited_envelopes',$originalCounts===66);
$ck('actual_native_full_PHP_62_typed_comparisons_pinned',
 count($matches['objects'])===62
 &&count(array_filter($matches['objects'],static fn(array $x):bool=>
  $x['native_Texture_full_export_reference']!=='EXACT'||$x['original_read_archive']!=='COMPLETE'))===0);
$ck('completed_actual_font_pages_and_maps_both_gate_profiles',
 $manifest['old_original_Engine_fonts_full_glyphs']===4
 &&$manifest['new_original_UWindowFonts_full_glyphs_and_map']===62);
$ck('source_v432_actual_compiled_loader_remap_branch',
 str_contains($manifest['source_defined_version_gate'],'0x846D0')
 &&str_contains($proof['native_62_full_actual_value_parity']['path'],'Parity'));
$ck('new_compiled_oracle_two_original_packages_only',
 $manifest['compiled_native_new_invocations']===2
 &&$manifest['old_native_CDB_glyph_or_maps_replayed']===false);
$ck('method_not_general_UObject_and_only_UT99',
 $manifest['method_scope_limit']!==''
 &&str_contains($manifest['source_profile'],'UT99 v432 only'));
$gates=array_fill_keys(['pinned_source_and_dispatch','complete_native_field_values_and_references',
 'archive_bytes_and_consumption','malformed_input_error_semantics','inherited_and_version_variant_semantics',
 'current_acceptance_tests','independent_full_method_review'],'VERIFIED');
$ck('seven_Epic_source_scoped_method_gates',count($gates)===7);
$summary=['ok'=>$bad===[],'checks'=>$ok+count($bad),'passed'=>$ok,'failed'=>$bad,
 'method'=>'T99:UFont::Serialize','source_profile'=>'UT99-v432',
 'verified_native_4_existing_plus_62_new_Font_objects'=>66,
 'new_complete_compiled_glyph_bytes'=>1200128,'new_complete_compiled_map_bytes'=>185736,
 'new_complete_compiled_map_pairs'=>46434,'original_inherited_UObject_no_stack_no_tags'=>66,
 'original_native_failure_cursor'=>'Got 4116, Expected 4112',
 'seven_gates'=>$gates,
 'review'=>$bad===[]?'FULL_SOURCE_PROFILE_ONLY':'BLOCKED',
 'general_T99_UObject'=>'BLOCKED_INDEPENDENTLY',
 'repeated_prior_font_native_CDB_runs'=>0];
echo json_encode($summary,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($bad===[]?0:1);
