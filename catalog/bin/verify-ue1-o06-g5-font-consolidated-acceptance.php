#!/usr/bin/env php
<?php
/** ONE complete T99:UFont::Serialize acceptance verdict.
 * Uses SHA-pinned existing compiled loaded/malformed native evidence.
 * Report BLOCKED, not FULL, until shared UObject is independently accepted.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
$base=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents($base.'/catalog/compliance/UE1-O06-G5-FONT-NONEMPTY-AND-MALFORMED.json'),true,512,JSON_THROW_ON_ERROR);
$gate=json_decode((string)file_get_contents($base.'/catalog/compliance/UE1-O06-G5-MULTIMETHOD-GATES.json'),true,512,JSON_THROW_ON_ERROR);
$errors=[];$checks=0;$matched=0;
$ck=static function(string $id,bool $good)use(&$checks,&$errors):void{$checks++;if(!$good)$errors[]=$id;};
$pin=static function(string $path,string $sha,string $label)use($ck):string{
    $buf=is_file($path)?file_get_contents($path):false;
    $ck('SHA256_'.$label,is_string($buf)&&hash('sha256',$buf)===$sha);
    return is_string($buf)?$buf:'';
};
$pin($m['pristine'],$m['pristine_sha256'],'original_Engine_u');
$pin($m['fixture'],$m['fixture_sha256'],'four_legal_compiled_font_remaps');
$pin($m['malformed'],$m['malformed_sha256'],'one_malformed_compiled_font_remap');
$native=$pin($m['malformed_native_log'],$m['malformed_native_log_sha256'],'unaltered_compiled_malformed_stdout');
$pin($m['valid_cdb_log'],$m['valid_cdb_log_sha256'],'native_4_remap_CDB');
$pin($m['valid_map_dump'],$m['valid_map_dump_sha256'],'native_4_real_TMap_entries');
$pin($m['valid_glyph_dump'],$m['valid_glyph_dump_sha256'],'native_original_ffontglyph_memory');
$pin($m['valid_cdb_stdout'],$m['valid_cdb_stdout_sha256'],'compiled_legal_UFont_loader');
$ck('compiled_method_error_identified_exactly',
    str_contains($native,$m['native_malformed_serial_size'])
    &&str_contains($native,'Exiting due to error'));
preg_match('/Serial size mismatch: Got (\d+), Expected (\d+)/',$native,$v);
$got=(int)($v[1]??-1);$expected=(int)($v[2]??-1);
$ck('original_native_actual_attempted_bytes_4116',$got===4116);
$ck('original_native_declared_bytes_4112',$expected===4112);
$p=new P($m['malformed']);
$tag=T::inspect($m['malformed'],$p,(int)$m['font_index'],'ut99-v432');
$e=$p->getExports()[$m['font_index']];
$ck('original_export_4112',$e['serialSize']===$expected
    &&$e['objectNameText']==='SmallFont'&&$tag['status']==='tag_envelope_decoded');
$actualError=null;
try{N::inspect($m['malformed'],$p,(int)$m['font_index'],$tag,'ut99-v432');}
catch(RuntimeException $err){$actualError=$err->getMessage();}
$ck('php_matches_compiled_archive_cursor_and_error',
    $actualError==='Font: Serial size mismatch: Got '.$got.', Expected '.$expected);
$originalSource=(string)file_get_contents($base.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicV432NativeDeltaReader.php');
$ck('native_cursor_is_modeled_no_outside_export_read',
    str_contains($originalSource,'// only the attempted logical cursor/error; NEVER read beyond the')
    &&str_contains($originalSource,'$physicalFileSize-$position-4')
    &&str_contains($originalSource,'$got=$position+$count*4+4-$exportSerialOffset;'));
$ck('real_v432_only_path',
    str_contains((string)file_get_contents($base.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicNativeObjectReader.php'),
      "\$nativeKind==='font'&&\$family===CatalogUE1ClassicSourceFamily::UT99_V432"));
$validPkg=new P($m['fixture']);
$validTag=T::inspect($m['fixture'],$validPkg,(int)$m['font_index'],'ut99-v432');
$valid=N::inspect($m['fixture'],$validPkg,(int)$m['font_index'],$validTag,'ut99-v432');
$ck('real_positive_4_remap_decodes',
    $valid['status']==='native_font_record_complete'&&$valid['char_remap']['count']===4
    &&$valid['is_remapped_raw']===1);
$ck('all_four_native_font_glyphs_ref_preserved',
    $valid['pages'][0]['glyph_count']===$m['expected_glyphs']
    &&$valid['characters_per_page']===$m['expected_characters_per_page']);
$journals=array_map('json_decode',file($base.'/catalog/compliance/UE1-O06-TEST-EVENTS.jsonl'));
foreach([
 'verify-ue1-o06-g5-native-v432-font-loaded-values.php',
 'verify-ue1-o06-g5-genuine-v432-nonempty-fonts.php'
] as $test){
    $last=null;
    foreach($journals as $j)if(($j->target??'DEV')==='DEV'
        &&($j->test??null)===$test&&($j->state??null)==='PASS')$last=$j;
    $ck('prior_compiled_fonts_source_pass_preserved_'.$test,$last!==null);
    if($last!==null){
        $evidence=$base.'/'.str_replace('\\','/',$last->log);
        $ck('old_SHA_pinned_result_integrity_'.$test,
            is_file($evidence)&&hash_file('sha256',$evidence)===$last->output_sha256
            &&hash_file('sha256',$base.'/catalog/bin/'.$test)===$last->test_sha256);
    }
}
$ck('inherited_UObject_gate_still_BLOCKED',
  ($gate['methods']['T99:UObject::Serialize']['decision']??null)==='BLOCKED');
$ck('UFont_method_not_falsely_full',
  ($gate['methods']['T99:UFont::Serialize']['closure_artifact']??null)===null);
echo json_encode([
 'ok'=>$errors===[],'checks'=>$checks,'passed'=>$checks-count($errors),
 'errors'=>$errors,'method'=>'T99:UFont::Serialize',
 'native_attempted_bytes'=>$got,'declared_bytes'=>$expected,
 'error_matches_native'=>$actualError==='Font: Serial size mismatch: Got 4116, Expected 4112',
 'verified_compiled_legal_remap_pairs'=>4,
 'prior_native_font_evidence_reused_without_replay'=>true,
 'single_consolidated_method_decision'=>'BLOCKED',
 'remaining_dependency'=>'T99:UObject::Serialize and unclosed other malformed/source branches',
 'new_native_compiled_captures'=>0
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
