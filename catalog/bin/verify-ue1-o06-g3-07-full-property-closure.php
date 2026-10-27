<?php
/** G3-07 one independent full native declaration-serializer acceptance.
 * Reuse sealed 1536 C98 + 668 C227 original oracle evidence by hash only;
 * verify NEW original 469f compiled property source identities and C227k
 * genuine-source v61 obsolete flag correction without replaying earlier tests.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as Family;
$root=dirname(__DIR__,2);$c=$root.'/catalog/compliance';
$gate=json_decode((string)file_get_contents($c.'/UE1-O06-G3-07-FULL-ACCEPTANCE-MATRIX.json'),true,512,JSON_THROW_ON_ERROR);
$source=json_decode((string)file_get_contents($c.'/UE1-O06-G3-07-T99-469F-490-ORIGINAL-COMPILED-UProperty.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$errors=[];
$check=static function(string $m,bool $v)use(&$checks,&$errors):void{$checks++;if(!$v&&count($errors)<25)$errors[]=$m;};
foreach($gate['existing_authentic_native_C98_1536_and_C227_668_and_new_T99_490_SHA_pins'] as $name=>$pin){
    $p=$root.'/'.$pin['repo_path'];
    $check('prior/original native authenticated method snapshot SHA '.$name,
        is_file($p)&&filesize($p)===$pin['bytes']&&hash_file('sha256',$p)===$pin['sha256']);
}
$check('genuine C98 and C227 original 1536/668 export native identities retained',
    $gate['new_original_native_loaded_counts_by_profile']===
        ['C98_May1998'=>1536,'T99_469f'=>490,'C227i'=>668]);
foreach($gate['already_accepted_focused_negative_and_native_tests_reused_without_execution'] as $name=>$pin){
    $p=$root.'/catalog/bin/'.$name;
    $log=$root.'/'.$pin['existing_DEV_log_path'];
    $check('unchanged original DEV acceptance verifier '.$name,
        is_file($p)&&hash_file('sha256',$p)===$pin['current_test_sha256']);
    $check('original DEV PASS result verified by exact SHA not rerun '.$name,
        is_file($log)&&hash_file('sha256',$log)===$pin['existing_DEV_log_SHA256']
        &&$pin['old_verifier_not_reexecuted']===true);
}
$reader=$root.'/'.$gate['production_UProperty_source_parser'];
$check('actual production source-specific serializer correction pinned',
    hash_file('sha256',$reader)===$gate['production_source_parser_sha256_after_227k_legacy_flag_fix']);
$actual=$gate['true_original_2026_227k_Core_dll'];
$check('genuine shipped original 227k Core DLL still SHA authentic',
    is_file($actual['path'])&&filesize($actual['path'])===$actual['bytes']
    &&hash_file('sha256',$actual['path'])===$actual['sha256']);
$dis=$gate['true_original_2026_227k_compiled_UProperty_full_machine_body'];
$path=$root.'/'.$dis['repo_path'];
$check('original 227k compiled UProperty method disassembly authentic SHA',
    filesize($path)===$dis['bytes']&&hash_file('sha256',$path)===$dis['sha256']);
$asm=(string)iconv('UTF-16','UTF-8',(string)file_get_contents($path));
$check('original 227k compiled archives actual base UField and ArrayDim/flags/category',
    str_contains($asm,'Core!UProperty::Serialize:')
    &&str_contains($asm,'Core!UField::Serialize')
    &&str_contains($asm,'[esi+3Ch]')
    &&str_contains($asm,'[esi+44h]')
    &&str_contains($asm,'[esi+60h]'));
$check('original 227k conditional CPF_Net archives RepOffset word',
    str_contains($asm,'test    byte ptr [ebx],20h')
    &&str_contains($asm,'[esi+4Ch]'));
$check('227k original compiled v<=61 legacy 0x00080040 clearing',
    str_contains($asm,'cmp     dword ptr [edi+4],3Dh')
    &&str_contains($asm,'and     dword ptr [ebx],0FFF7FFBFh'));
$check('227k original compiled resets per-loaded property Offset and linked field',
    str_contains($asm,'test    byte ptr [edi+10h],1')
    &&str_contains($asm,'[esi+48h]')&&str_contains($asm,'[esi+6Ch]'));
$check('property ElementSize is NOT a serialized member, no archive-only linked size fiction',
    !str_contains($asm,'call    Core!UProperty::Link')
    &&$gate['source_scope_versus_other_contracts']['G3-15']!=='');
$sourceFile=$source['original_Core_U']['path'];
$check('original 469f genuinely shipped Core.u unchanged',
    is_file($sourceFile)&&hash_file('sha256',$sourceFile)===$source['original_Core_U']['sha256']);
$check('genuine 469f original matching Core.dll unchanged',
    hash_file('sha256',$source['original_Core_DLL']['path'])===$source['original_Core_DLL']['sha256']);
foreach($source['compiled_original_core_disassembly_and_490_full_CDB_events'] as $name=>$pin){
    $p=$root.'/'.$pin['repo_path'];
    $check('NEW genuine original native loaded and category CDB source transcript '.$name,
        is_file($p)&&filesize($p)===$pin['bytes']&&hash_file('sha256',$p)===$pin['sha256']);
}
$check('real 469f source unique individual original property native observations 490 of 539',
    count($source['original_native_loaded_UProperty_per_exact_file_export_identity'])===490
    &&count($source['original_Source_Core_u_UProperty_exports_not_loaded_in_commandlet'])===49
    &&$source['genuine_unmodified_original_Core_u_property_export_count']===539);
$pkg=new Package($sourceFile);
$check('original 469f genuine package still valid',
    $pkg->validatePackage()===[]&&$pkg->getHeader()['version']===69
    &&$pkg->getHeader()['licenseeVersion']===0);
$exports=$pkg->getExports();$imports=$pkg->getImports();$seen=[];$categories=[];
foreach($source['original_native_loaded_UProperty_per_exact_file_export_identity'] as $row){
    $id=$row['original_zero_based_export_index'];$ex=$exports[$id]??null;
    $check('469f authentic native linker export exists and has unique identity '.$id,
        is_array($ex)&&!isset($seen[$id]));
    $seen[$id]=true;
    if(!is_array($ex))continue;
    $ci=(int)$ex['classIndex'];
    $kind=$ci<0?strtolower((string)($imports[-$ci-1]['objectNameText']??'')):'';
    $check('original on-disk source property subclass exact original native identity '.$id,
        $kind===$row['original_source_property_subclass']
        &&$ex['objectNameText']===$row['source_object_name']);
    $entry=Native::inspect($sourceFile,$pkg,$id,
        Tags::inspect($sourceFile,$pkg,$id,Family::UT99_V469F),Family::UT99_V469F);
    $check('real native loaded original UProperty ArrayDim and flags match '.$id,
        $entry['array_dim']===$row['original_compiled_loaded_ArrayDim_signed']
        &&$entry['property_flags']===$row['original_compiled_loaded_PropertyFlags']);
    $check('real native globally resolved Category FName text matches package-saved '.$id,
        $entry['category_name']===$row['original_compiled_loaded_Category_global_name_resolved']);
    $check('actual original completed native declaration (no invented extension) '.$id,
        $entry['status']==='native_property_record_complete'
        &&$entry['extension_bytes']===0);
    $check('real original compiled native postload Offset reset not invented link value '.$id,
        $row['original_compiled_loaded_Offset_at_plus48']===0
        &&$row['original_compiled_loaded_field_at_plus54']===0
        &&$entry['runtime_property_offsets_linked']===false);
    $categories[$row['original_compiled_loaded_Category_global_FName_ID']]=$row['original_compiled_loaded_Category_global_name_resolved'];
}
$check('490 actual compiled objects individually corroborated',count($seen)===490);
$check('8 real original 469f compiled global category identities resolved',
    count($categories)===8&&isset($categories[0])
    &&$categories[0]==='None'
    &&isset($categories[1237])&&$categories[1237]==='Scale');
$check('49 original source-valid but compiled-unobserved exports do NOT count as live native objects',
    count(array_intersect(array_keys($seen),
        array_column($source['original_Source_Core_u_UProperty_exports_not_loaded_in_commandlet'],
        'original_zero_based_export_index')))===0);
$check('no genuinely new bytewise serializer branch in those 49 saved fields',
    !array_filter($source['original_Source_Core_u_UProperty_exports_not_loaded_in_commandlet'],
        static fn($x)=>($x['saved_PropertyFlags']&0x20)!==0));
$check('native T99 source profile 469f explicitly selected for genuine file',
    $source['original_compiled_UProperty_method_address']==='0x10084ee0'
    &&$entry['selected_source_family']===Family::UT99_V432);
// The signed v61 archive is ambiguous; genuine SOURCE family decides the
// compiled LEGACY property flag behavior. This is a NEW 227k discrepancy test,
// not another C98/227i/469f loaded-value rerun.
$compact=static function(int $n):string{
 $neg=$n<0;$v=abs($n);$b=($v&63)|($neg?128:0);$v>>=6;
 if(!$v)return chr($b);
 $s=chr($b|64);do{$u=$v&127;$v>>=7;$s.=chr($u|($v?128:0));}while($v);return $s;
};
$fixture=static function(int $version,int $flags)use($compact):string{
 $names=['None','IntProperty','Test','Core','Class','Gameplay'];$base=$version>=68?56:44;$table='';
 foreach($names as $x){$table.=($version>=64?$compact(strlen($x)+1):'').$x."\0".pack('V',0);}
 $im=$compact(3).$compact(4).pack('V',0).$compact(1);
 $body="\0".$compact(0).$compact(0).pack('V2',1,$flags).$compact(5).pack('v',17);
 $nOff=$base;$iOff=$base+strlen($table);$eOff=$iOff+strlen($im);
 $ex=$compact(-1).$compact(0).pack('V',0).$compact(2).pack('V',0)
   .$compact(strlen($body)).$compact(512);
 $h=pack('V9',0x9e2a83c1,$version,0,count($names),$nOff,1,$eOff,1,$iOff);
 $h.=$version>=68?str_repeat("\0",16).pack('V',0):pack('V2',0,0);
 $raw=$h.$table.$im.$ex;
 if(strlen($raw)>=512)throw new RuntimeException('Fixture table overflow');
 return $raw.str_repeat("\0",512-strlen($raw)).$body;
};
$chosen=['epic-ue1'=>0x00080060,'ut99-v432'=>0x20,'unreal-v227'=>0x20];
$fixturefile=tempnam(sys_get_temp_dir(),'G307Ver');
if(!is_string($fixturefile))throw new RuntimeException('Cannot create source-version input fixture');
try{
 foreach([60,61,69] as $version){
    $raw=$fixture($version,0x00080060);
    file_put_contents($fixturefile,$raw);
    foreach($chosen as $family=>$v61expected){
        $fp=new Package($fixturefile);
        $check('source-specific v'.$version.' fixture original package archive valid '.$family,
            $fp->validatePackage()===[]);
        $tag=Tags::inspect($fixturefile,$fp,0,$family);
        $record=Native::inspect($fixturefile,$fp,0,$tag,$family);
        $expected=$version<=61?$v61expected:0x00080060;
        $check('genuine compiled C227/UT99 legacy vs C98 v'.$version.' source flag semantics '.$family,
            $record['status']==='native_property_record_complete'
            &&$record['property_flags']===0x00080060
            &&$record['effective_property_flags']===$expected
            &&$record['rep_offset']===17);
    }
 }
}finally{@unlink($fixturefile);}
$ok=$errors===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'passed'=>$checks-count($errors),
 'failed'=>$errors,'G3_07_FULL_METHOD_STATUS'=>$ok?'ACCEPTED':'BLOCKED',
 'new_original_469f_actual_compiled_loaded_properties'=>count($seen),
 'new_original_469f_actual_runtime_global_Category_names'=>count($categories),
 'original_469f_source_export_properties_unobserved_in_native'=>49,
 'old_C98_1536_and_C227i_668_native_tests_replayed'=>0,
 'new_original_227k_compiled_legacy_flags_discrepancy_fixed'=>true,
 'G3_14_and_G3_15_crosscutting_remain_open'=>true],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
