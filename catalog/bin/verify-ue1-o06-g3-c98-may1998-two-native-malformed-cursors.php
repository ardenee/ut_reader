#!/usr/bin/env php
<?php
/** Compiled original May17 UE1 x86 two actual malformed property reference fatal paths and cursors. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$repo=dirname(__DIR__,2);
$manifest=$repo.'/catalog/compliance/UE1-O06-G3-C98-MAY1998-TWO-NATIVE-MALFORMED-CURSORS.json';
$m=json_decode((string)file_get_contents($manifest),true,512,JSON_THROW_ON_ERROR);
$checks=0;$errors=[];
$check=static function(string $label,bool $ok)use(&$checks,&$errors):void{
    $checks++;
    if(!$ok)$errors[]=$label;
};
$check('strict original malformed C98 native subset only and no FULL-G3 acceptance',
    $m['status']==='ORIGINAL_COMPILED_C98_MAY17_TWO_DISTINCT_MALFORMED_SOURCE_NATIVE_FAILURE_CURSORS_VERIFIED'
    &&$m['G3_full_contracts_accepted']===0);
foreach($m['original_oracle_and_mutant_file_pins'] as $key=>$pin){
    $path=$pin['path'];
    $check('unchanged SHA original or isolated one-byte mutant '.$key,
        is_file($path)&&filesize($path)===(int)$pin['bytes']
        &&hash_file('sha256',$path)===$pin['sha256']);
}
$check('compiled May17 original Core.dll genuine SHA',
    $m['original_native_Core_dll_May17_sha']==='9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92');
$check('original untouched Core.u genuinely authentic',
    $m['original_untouched_original_package_sha']==='7c94b2a68d8f6fc0bfb5264c9e9aad4ce35ccf12a8b8057d456dacd33d74140c');
foreach($m['Epic_v1200_source_pins'] as $key=>$pin){
    $path=$pin['path'];
    $check('pinned true original Epic May19 v1200 source '.$key,
        is_file($path)&&filesize($path)===(int)$pin['bytes']
        &&hash_file('sha256',$path)===$pin['sha256']);
}
$source=(string)file_get_contents($m['Epic_v1200_source_pins']['Epic_May19_UnProp_cpp']['path']);
$linker=(string)file_get_contents($m['Epic_v1200_source_pins']['Epic_May19_UnLinker_h']['path']);
$archive=(string)file_get_contents($m['Epic_v1200_source_pins']['Epic_May19_UnArc_h']['path']);
$check('exact source inherited PropertyClass read',
    preg_match('/void UObjectProperty::Serialize\s*\( FArchive& Ar \).*?UProperty::Serialize\( Ar \);\s*Ar << PropertyClass;/s',$source)===1);
$check('source MetaClass follows inherited read then unconditional check',
    preg_match('/void UClassProperty::Serialize\s*\( FArchive& Ar \).*?UObjectProperty::Serialize\( Ar \);\s*Ar << MetaClass;\s*check\(MetaClass\);/s',$source)===1);
$check('Epic original package linker reads actual export offset and size',
    preg_match('/Seek\( Export.SerialOffset, Export.SerialSize \);/',$linker)===1);
$check('Epic compiled physical file archive Tell returns file position',
    preg_match('/INT Tell\(\)\s*\{\s*return appFtell\( File \);\s*\}/',$linker)===1);
$check('Epic original FArchive virtual Tell is source-defined',str_contains($archive,'virtual INT Tell()'));
$check('original linker imports resolve via IndexToObject and fail in loader',
    str_contains($linker,'IndexToObject( Import.PackageIndex )')&&str_contains($linker,'// Return the loaded object corresponding to an import index; any errors are fatal.'));
foreach($m['original_x86_CDB_real_failure_cursor_logs_and_commands'] as $key=>$pin){
    $path=$repo.'/'.$pin['path'];
    $check('sealed ORIGINAL authentic compiled native x86 transcript or breakpoint '.$key,
        is_file($path)&&filesize($path)===(int)$pin['bytes']
        &&hash_file('sha256',$path)===$pin['sha256']);
}
$log=static function(string $key)use($m,$repo):string{
    $p=$repo.'/'.$m['original_x86_CDB_real_failure_cursor_logs_and_commands'][$key]['path'];
    $s=iconv('UTF-16','UTF-8',(string)file_get_contents($p));
    if(!is_string($s))throw new RuntimeException('WinDbg original UTF-16 event missing');
    return $s;
};
$metaFatal=$log('source_native_MetaClass_null_fatal_log');
$metaCursor=$log('source_native_MetaClass_null_cursor_log');
$invalidFatal=$log('source_native_invalid_PropertyClass_fatal_log');
$invalidCursor=$log('source_native_invalid_PropertyClass_cursor_log');
$check('actual original stock null MetaClass loaded before guard',
    preg_match('/(?m)^G3_NEGATIVE_META_AFTER_READ\r?$/',$metaFatal)===1);
$check('actual original stock compiled check(MetaClass) zero branch',
    preg_match('/(?m)^G3_NEGATIVE_META_ZERO_BRANCH\r?$/',$metaFatal)===1);
$check('actual original stock compiled UClassProperty appErrorf callsite',
    preg_match('/(?m)^G3_NEGATIVE_UCLASSPROPERTY_EPIC_APPERRORF\r?$/',$metaFatal)===1);
$check('native invalid PropertyClass reaches original UObjectProperty serializer',
    preg_match('/(?m)^G3_INVALID_PROPERTYCLASS_REACHED_BEFORE_POINTER_READ\r?$/',$invalidFatal)===1);
$check('native invalid PropertyClass triggers compiled original Core appErrorf',
    preg_match('/(?m)^G3_INVALID_PROPERTYCLASS_EPIC_APPERRORF_ENTRY\r?$/',$invalidFatal)===1);
$check('native invalid import shows -63 original stack argument ffffffc1',
    preg_match('/(?m)^001abd44\h+10130f44\h+ffffffc1\h+/', $invalidFatal)===1);
$check('original invalid import never successfully completed native field LOAD',
    preg_match('/(?m)^G3_INVALID_PROPERTYCLASS_UNEXPECTED_SUCCESS\r?$/',$invalidFatal)===0);
$check('original MetaClass native archive absolute cursor exact 3574',
    preg_match('/(?m)^G3_ORIGINAL_CURRENT_ARCHIVE_CURSOR_3574_DEC\r?$/',$metaCursor)===1
    &&preg_match('/(?m)^[0-9a-f]{8}\h+00000df6\h+/', $metaCursor)===1);
$check('original invalid PropertyClass archive absolute cursor exact 3573',
    preg_match('/(?m)^C98_ORIGINAL_INVALID_REF_ARCHIVE_CURSOR_3573_DEC\r?$/',$invalidCursor)===1
    &&preg_match('/(?m)^[0-9a-f]{8}\h+00000df5\h+/', $invalidCursor)===1);
$check('mutated invalid PropertyClass source fatal before null MetaClass read',
    preg_match('/(?m)^C98_G3_INVALID_NEGATIVE_PROPERTYCLASS_NATIVE_FATAL\r?$/',$invalidCursor)===1);
$check('genuine source original stock native DLL unchanged in both mutated installations',
    $m['original_oracle_and_mutant_file_pins']['original_stock_May17_Core_dll']['sha256']
    ===$m['original_oracle_and_mutant_file_pins']['isolated_MetaClass_compiled_Core_dll']['sha256']
    &&$m['original_oracle_and_mutant_file_pins']['original_stock_May17_Core_dll']['sha256']
    ===$m['original_oracle_and_mutant_file_pins']['isolated_invalid_PropertyClass_compiled_Core_dll']['sha256']);
$originalPath=$m['original_oracle_and_mutant_file_pins']['original_untouched_Core_u']['path'];
$bytes=file_get_contents($originalPath);
$check('original unchanged Core.u bytes available and genuine exact size',is_string($bytes)&&strlen($bytes)===31472);
$origPackage=new Package($originalPath);
$check('original saved Core.u parses as genuine version61', $origPackage->validatePackage()===[]&&$origPackage->getHeader()['version']===61);
$export=$origPackage->getExports()[4]??null;
$check('real original negative source target ObjectClass native export index4',
    ($export['objectNameText']??null)==='ObjectClass'
    &&($export['serialOffset']??null)===3559
    &&($export['serialSize']??null)===15);
$check('original import table has exactly 16 genuine source imports',count($origPackage->getImports())===16);
$check('original 15-byte true archived ClassProperty field data',
    bin2hex(substr((string)$bytes,3559,15))==='000045070100000080000000008c01');
$positive=Native::inspect($originalPath,$origPackage,4,Tags::inspect($originalPath,$origPackage,4,'epic-ue1'),'epic-ue1');
$check('actual original unmodified ClassProperty decoded fully',
    ($positive['status']??null)==='native_property_record_complete'
    &&($positive['subclass_fields']['property_class_reference']??null)===-12
    &&($positive['subclass_fields']['meta_class_reference']??null)===1);
$tested=[];
foreach($m['isolated_mutations_one_byte_diff_from_unmodified_original'] as $key=>$mutation){
    $path=$m['original_oracle_and_mutant_file_pins'][$mutation['filename']]['path'];
    $bad=file_get_contents($path);
    $check('only isolated 1-byte variant '.$key,
        is_string($bad)&&strlen($bad)===strlen((string)$bytes));
    if(!is_string($bad))continue;
    $offset=(int)$mutation['absolute_byte_offset'];
    $check('exact original source archive cursor mutated byte '.$key,
        $offset>=3559&&$offset<3574
        &&bin2hex($bytes[$offset])===$mutation['old_byte']
        &&bin2hex($bad[$offset])===$mutation['new_byte']);
    $diff=0;
    for($j=0;$j<strlen((string)$bytes);$j++)if($bytes[$j]!==$bad[$j])$diff++;
    $check('no other byte in original package changed '.$key,$diff===1);
    $pkg=new Package($path);
    $check('single-byte original package still structurally valid '.$key,$pkg->validatePackage()===[]);
    $msg=null;
    try{Native::inspect($path,$pkg,4,Tags::inspect($path,$pkg,4,'epic-ue1'),'epic-ue1');}
    catch(RuntimeException $e){$msg=$e->getMessage();}
    $expected=$key==='MetaClass_zero'?'UClassProperty MetaClass must be non-null':
         'Invalid UObjectProperty.PropertyClass package reference';
    $check('actual production PHP fatal differs as Epic source requires '.$key,$msg===$expected);
    $tested[$key]=['source_error'=>$expected,'native_cursor'=>$mutation['expected_original_fatal_archive_cursor']];
}
$check('original negative source error branches have distinct native cursors',
    count($tested)===2 &&$tested['PropertyClass_minus63']['native_cursor']===3573
    &&$tested['MetaClass_zero']['native_cursor']===3574);
$ok=$errors===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'passed'=>$checks-count($errors),
  'errors'=>$errors,'original_source_package_sha256'=>$m['original_untouched_original_package_sha'],
  'original_compiled_Core_sha256'=>$m['original_native_Core_dll_May17_sha'],
  'mutations'=>$tested,'real_compiled_original_fatal_branches'=>2,
  'this_does_not_accept_G3_14_or_G3_16'=>true,'G3_FULL'=>false],
   JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
