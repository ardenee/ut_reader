<?php
/** Distinct G3-08: genuine original C227k UButton custom native Caption after UProperty. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tagged;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as Source;
$root=dirname(__DIR__,2);
$manifest=json_decode((string)file_get_contents($root.'/catalog/compliance/UE1-O06-G3-C227K-TWO-GENUINE-UButton-Caption-Serialize.json'),true,512,JSON_THROW_ON_ERROR);
$count=0;$bad=[];
$check=static function(string $name,bool $success)use(&$count,&$bad):void{$count++;if(!$success&&count($bad)<25)$bad[]=$name;};
$check('original C227 loaded UButton source only, not full G3-08',
    $manifest['G3_08_full_contract_accepted']===false
    &&$manifest['native_runtime_original_file_loaded_button_memory_captured']===false);
$check('original two real persisted property declarations',
    count($manifest['real_original_scriptedAI_two_button_declaration_exports'])===2
    &&$manifest['real_original_button_declaration_exports_found_in_shipped_227k_corpus']===2);
foreach(['true_original_227k_Core_DLL','true_original_227k_SDK_UnType_header','true_original_227k_ScriptedAI_u'] as $name){
    $f=$manifest[$name];
    $check('genuine original source SHA256 not modified '.$name,
        is_file($f['path'])&&filesize($f['path'])===$f['bytes']
        &&hash_file('sha256',$f['path'])===$f['sha256']);
}
$check('matching actual authentic shipped Core.dll identity',
    $manifest['true_original_227k_Core_DLL']['sha256']==='c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194');
$check('actual original shipped ScriptedAI.u unchanged',
    $manifest['true_original_227k_ScriptedAI_u']['sha256']==='396abd174a6ec86631de7d7c442f9187c7658af4baf2eaa6a58a93eff9327d36');
$head=(string)file_get_contents($manifest['true_original_227k_SDK_UnType_header']['path']);
$at=strpos($head,'class CORE_API UButtonProperty : public UProperty');
$buttonBody=$at!==false?substr($head,$at,strpos($head,'};',$at)-$at):'';
$check('genuine 227k SDK adds explicit custom button native Serialize override',
    $at!==false&&str_contains($buttonBody,'FString Caption;')
    &&preg_match('/\bvoid Serialize\(FArchive& Ar\)/',$buttonBody)===1);
$check('matching actual original UObject subclass Caption memory offset used',
    $manifest['true_original_227k_compiled_Caption_member_offset']==='0x8c');
foreach(['original_compiled_UButton_227k_REA','original_compiled_FString_operator_227k_REA'] as $name){
    $pin=$manifest[$name];$p=$root.'/'.$pin['repo_path'];
    $check('real original exact REA input and output SHA '.$name,
        is_file($p)&&filesize($p)===$pin['bytes']
        &&hash_file('sha256',$p)===$pin['sha256']);
    $o=json_decode((string)iconv('UTF-16','UTF-8',(string)file_get_contents($p)),true,512,JSON_THROW_ON_ERROR);
    $body=(string)($o['normalized_result']??'');
    $check('matching original 227k compiled DLL being decompiled '.$name,
        ($o['subject']['digest']['sha256']??'')===$manifest['true_original_227k_Core_DLL']['sha256']);
    if($name==='original_compiled_UButton_227k_REA'){
        $check('original compiled calls inherited UProperty then FArchive << FString Caption +0x8c',
            strpos($body,'UProperty::Serialize')!==false
            &&strpos($body,'operator<<(param_1,(FString *)(this + 0x8c))')
                >strpos($body,'UProperty::Serialize'));
    }else{
        $check('authentic original 227k FString archive signed AR_INDEX count',
            str_contains($body,'(FCompactIndex *)&local_18')
            &&str_contains($body,'local_18 = -local_18'));
        $check('original native LOAD handles both ANSI and Unicode count signs',
            str_contains($body,'appFromAnsiInPlace')&&str_contains($body,'appFromUnicodeInPlace')
            &&str_contains($body,'if (0 < *(int *)(param_2 + 4))'));
    }
}
$file=$manifest['true_original_227k_ScriptedAI_u']['path'];
$package=new Package($file);
$check('genuine original whole ScriptedAI.u export map valid',$package->validatePackage()===[]);
$check('genuine source archive profile C227 version69/licensee227',
    $package->getHeader()['version']===69
    &&$package->getHeader()['licenseeVersion']===227);
$exports=$package->getExports();$seen=[];
foreach($manifest['real_original_scriptedAI_two_button_declaration_exports'] as $x){
    $id=$x['original_zero_based_export_index'];
    $e=$exports[$id]??null;
    $check('original on-disk C227 button class persisted real object #'.$id,
        is_array($e)&&$e['objectNameText']===$x['object_name']
        &&$e['serialOffset']===$x['original_serial_offset']
        &&$e['serialSize']===$x['original_serial_size']);
    $tag=Tagged::inspect($file,$package,$id,Source::UNREAL_V227);
    $native=Native::inspect($file,$package,$id,$tag,Source::UNREAL_V227);
    $check('original real complete native UButton custom Serialize after inherited property #'.$id,
        $native['status']==='native_buttonproperty_caption_record_complete'
        &&$native['property_kind']==='buttonproperty'&&$native['extension_bytes']===0);
    $check('real same class UProperty inherited prefix ArrayDim, flags and Category #'.$id,
        $native['array_dim']===1&&$native['property_flags']===12289
        &&$native['category_name']==='VAR_PointActor');
    $c=$native['subclass_fields'];
    $check('genuine original persisted caption bytes decoded correctly #'.$id,
        $c['caption']===$x['original_native_caption_value']
        &&$c['caption_count_signed']===$x['original_native_caption_count']
        &&$c['caption_unit_count']===$x['original_native_caption_count']);
    $check('genuine original persisted count and data byte layout exactly #'.$id,
        $c['caption_encoding']==='Epic-FromAnsi-bytewise'
        &&$c['caption_string_raw_offset']===$x['original_native_caption_data_offset']
        &&$c['caption_string_raw_length']===strlen(hex2bin($x['original_native_caption_bytes_hex']))
        &&$tag['tag_end_offset']<$x['original_native_caption_count_file_offset']);
    $raw=hex2bin($x['original_native_caption_bytes_hex']);
    $check('physical real original CString NUL and exact raw SHA #'.$id,
        hash('sha256',$raw)===$c['caption_string_raw_sha256']
        &&$c['caption_terminator_is_nul']===true&&$c['caption_text_decoded']===true);
    $check('do not falsely claim new UButton SerializeItem native semantics #'.$id,
        $c['runtime_serializeitem_verified']===false
        &&$native['new_v227_property_runtime_serializeitem_verified']===false);
    $seen[]=$id;
}
$check('exact two unique real source UButton objects verified',$seen===[2285,2286]);
$sourceBytes=(string)file_get_contents($file);
$mutate=static function(int $offset,string $replacement)use($sourceBytes,$file):array{
    $mutable=$sourceBytes;
    $mutable[$offset]=$replacement;
    $p=tempnam(sys_get_temp_dir(),'G3C227Btn');
    if(!is_string($p))throw new RuntimeException('Cannot allocate one-byte original fixture');
    if(file_put_contents($p,$mutable)!==strlen($mutable))throw new RuntimeException('Cannot write one-byte source fixture');
    return [$p,hash('sha256',$mutable)];
};
$decode=static function(string $path,int $index):array{
    $p=new Package($path);
    if($p->validatePackage()!==[])throw new RuntimeException('Fixture package export map corrupt');
    $t=Tagged::inspect($path,$p,$index,Source::UNREAL_V227);
    return Native::inspect($path,$p,$index,$t,Source::UNREAL_V227);
};
// Distinct compiled source-defined LOAD behavior, NOT claiming actual CDB
// execution of these synthetic branches. The operator always zeroes the
// last loaded TCHAR slot, so a nonzero terminator isn't an archive error.
[$badTerm,$badTermHash]=$mutate(293122,"X");
try{
    $got=$decode($badTerm,2286);
    $c=$got['subclass_fields'];
    $check('source-defined real button NUL mutation does not lose caption or invent native failure',
        $got['status']==='native_buttonproperty_caption_record_complete'
        &&$c['caption']==='Go to point'
        &&$c['caption_terminator_is_nul']===false);
    $check('one-byte malformed terminator original physical byte changed only',
        $sourceBytes[293122]==="\0"
        &&filesize($badTerm)===strlen($sourceBytes)
        &&$badTermHash!==hash('sha256',$sourceBytes));
}finally{@unlink($badTerm);}
// One unit too many spills out of the original export; the linker applies
// the serialized size boundary AFTER Serialize, not before the FString read.
[$excess,$excessHash]=$mutate(293037,chr(24));
try{
    $got=$decode($excess,2285);
    $check('source-defined overrun preserves physical archive cursor and classifies serial mismatch',
        $got['status']==='native_export_serial_size_mismatch'
        &&$got['serial_size_overrun_bytes']===1
        &&$got['expected_export_end']===293061
        &&$got['archive_cursor']===293062
        &&$got['source_serial_size_check_failed']===true);
    $check('count mutation is one byte, distinct from genuine 23-character original',
        $sourceBytes[293037]===chr(23)
        &&filesize($excess)===strlen($sourceBytes)
        &&$excessHash!==hash('sha256',$sourceBytes));
}finally{@unlink($excess);}
$check('original L source SHA unchanged after both ephemeral mutation branches',
    hash_file('sha256',$file)===$manifest['true_original_227k_ScriptedAI_u']['sha256']);
$ok=$bad===[];
echo json_encode([
    'ok'=>$ok,'checks'=>$count,'passed'=>$count-count($bad),'failed'=>$bad,
    'original_real_227k_UButtonProperty_positive_exports'=>$seen,
    'original_real_Caption_values'=>array_column($manifest['real_original_scriptedAI_two_button_declaration_exports'],'original_native_caption_value'),
    'matched_original_compiled_UButton_and_FString_operator_recovered'=>true,
    'native_CDB_execution_of_real_button_exports_proven'=>false,
    'two_distinct_source_defined_mutation_outcomes_checked'=>true,
    'whole_G3_08_complete'=>false
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
