#!/usr/bin/env php
<?php
/** Original 2012 Unreal227i compiled UField::Serialize loaded SuperField/Next real per-export identity parity. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as Classes;
$repo=dirname(__DIR__,2);
$m=json_decode((string)file_get_contents(
    $repo.'/catalog/compliance/UE1-O06-G3-C227I-UFIELD-915-EXACT-NATIVE-REFERENCES.json'),
    true,512,JSON_THROW_ON_ERROR);
$checks=0;$errors=[];
$check=static function(string $label,bool $ok)use(&$checks,&$errors):void{
    $checks++;if(!$ok&&count($errors)<25)$errors[]=$label;
};
$check('actual original 2012 loaded-UField scope, full G3 explicitly unaccepted',
    $m['status']==='ORIGINAL_COMPILED_2012_C227I_UFIELD_915_AUTHENTIC_PER_OBJECT_SUPER_AND_NEXT_SAVED_REFERENCE_TARGETS_MATCH'
    &&$m['G3_ALL_FULL_accepted']===0
    &&$m['G3_03_full_accepted']===false
    &&$m['G3_15_full_accepted']===false);
foreach($m['original_2012_original_binary_package_pins'] as $name=>$p){
    $file=(string)$p['path'];
    $check('authentic 2012 original compiled DLL/UCC and Core.u SHA '.$name,
        is_file($file)&&filesize($file)===(int)$p['bytes']
        &&hash_file('sha256',$file)===$p['sha256']);
}
$check('pinned original 2012 compiled Core DLL precise identity',
    $m['original_2012_original_binary_package_pins']['Core.dll']['sha256']
    ==='287b5eb68c3a098b11eca55c84f7f9d5e29db51c7ec49475099b871044881897');
$check('pinned authentic original 227i Core.u SHA',
    $m['original_2012_original_binary_package_pins']['Core.u']['sha256']
    ==='bd42f3dff0a9ef5521623d45c29b51104b1ec366cf27b89bdeeb888b9ba3973b');
foreach($m['original_2012_915_real_compiled_native_source_logs'] as $name=>$p){
    $file=$repo.'/'.$p['path'];
    $check('actual original 2012 UField CDB x86 native or disassembly SHA '.$name,
        is_file($file)&&filesize($file)===(int)$p['bytes']
        &&hash_file('sha256',$file)===$p['sha256']);
}
foreach($m['later_v227_header_declarations_only_not_2012_compiled_source_body'] as $name=>$p){
    $file=(string)$p['path'];
    $check('later v227 declarations only, no false exact 2012 compiled equivalence '.$name,
        is_file($file)&&filesize($file)===(int)$p['bytes']
        &&hash_file('sha256',$file)===$p['sha256']);
}
$proof=$m['original_2012_915_real_compiled_native_source_logs'];
$trace=iconv('UTF-16','UTF-8',(string)file_get_contents(
    $repo.'/'.$proof['native_original_CDB_915_UField_loaded_memory']['path']));
$disasm=iconv('UTF-16','UTF-8',(string)file_get_contents(
    $repo.'/'.$proof['native_original_UField_UStruct_compiled_disassembly']['path']));
if(!is_string($trace)||!is_string($disasm)){
    throw new RuntimeException('Original compiled 227i WinDbg CDB UTF16 evidence unreadable');
}
$trace=str_replace(["\r\n","\r"],"\n",$trace);
$disasm=str_replace(["\r\n","\r"],"\n",$disasm);
$check('original compiled exact 2012 UField::Serialize x86 method body disassembled',
    str_contains($disasm,'Core!UField::Serialize:')
    &&str_contains($disasm,'10116850')
    &&str_contains($disasm,'10116890')
    &&str_contains($disasm,'101168aa')
    &&str_contains($disasm,'101168b0'));
$check('original compiled UField serializes two native object refs and loader flag conditional',
    preg_match('/10116890\h+8d462c/', $disasm)===1
    &&preg_match('/10116894\h+8d4e28/',$disasm)===1
    &&preg_match('/101168aa\h+837f1000/',$disasm)===1);
$check('authentic debugger after native SuperField and Next read, not before',
    str_contains($trace,'bp 101168aa ')&&str_contains($trace,'G3_ORIGINAL_227I_UFIELD_REAL_LOADED'));
$path=(string)$m['original_2012_original_binary_package_pins']['Core.u']['path'];
$pkg=new Package($path);
$check('genuine stock 227i Core.u original package validates',$pkg->validatePackage()===[]);
$check('original stock package must be UE1 v69',($pkg->getHeader()['version']??null)===69);
$exports=$pkg->getExports();$imports=$pkg->getImports();
$check('original genuine untouched 227i Core.u has 934 exported objects',count($exports)===934);
$parts=preg_split('/(?m)^G3_ORIGINAL_227I_UFIELD_REAL_LOADED\s*$/',$trace);
$check('full 915 genuine compiled original UField after-archive load callbacks',is_array($parts)&&count($parts)===916);
$seen=[];$linker=null;$nullSuper=0;$nullNext=0;$matched=0;$generic=0;$class=0;
foreach(array_slice($parts,1) as $i=>$fragment){
    preg_match_all('/(?m)^([0-9a-f]{8})\h+([0-9a-f]{8})\h+([0-9a-f]{8})$/i',
        $fragment,$hits,PREG_SET_ORDER);
    $pairs=array_map(static fn(array $row):array=>[
        hexdec($row[1]),hexdec($row[2]),hexdec($row[3])],$hits);
    $check('original CDB source and native pointer memory dwords present at each event '.$i,count($pairs)>=2);
    if(count($pairs)<2)continue;
    $srcLinker=$pairs[0][1];$id=$pairs[0][2];
    if($linker===null)$linker=$srcLinker;
    $check('single original genuine original 2012 Core.u linker for all real UField loads '.$i,
        $srcLinker===$linker);
    $check('each C227i original source export UField Serialize executed at most once '.$id,!isset($seen[$id]));
    $seen[$id]=true;
    $exp=$exports[$id]??null;
    $check('original native export id exists as genuine saved Core.u field '.$id,$exp!==null);
    if(!$exp)continue;
    $ptrSuper=$pairs[1][1];$ptrNext=$pairs[1][2];
    $cursor=2;$expected=[];
    foreach([['super',$ptrSuper,'ORIGINAL_SUPER_NULL'],['next',$ptrNext,'ORIGINAL_NEXT_NULL']] as [$key,$ptr,$nullMarker]){
        if($ptr===0){
            $check('actual original compiled '.$key.' pointer explicitly null '.$id,
                str_contains($fragment,$nullMarker));
            $expected[$key]=0;
            if($key==='super')$nullSuper++;else $nullNext++;
        }else{
            $target=$pairs[$cursor]??null;$cursor++;
            $check('source target object memory present for native '.$key.' '.$id,$target!==null);
            if(!$target){$expected[$key]=null;continue;}
            $check('actual 2012 dereferenced target UObject address +0x10 '.$key.' '.$id,
                $target[0]===$ptr+0x10);
            $check('source positive ref target resides in same original Core.u linker '.$key.' '.$id,
                $target[1]===$srcLinker);
            $check('native target zero-based reference points at real original export '.$key.' '.$id,
                isset($exports[$target[2]]));
            $expected[$key]=$target[2]+1;
        }
    }
    $idx=(int)$exp['classIndex'];
    if($idx===0){
        $record=Classes::inspect($path,$pkg,$id,false,'unreal-v227');
        $class++;
        $check('authentic original UClass has separate correctly source-parsed archive '.$id,
            in_array($id,[2,7],true));
    }else{
        $record=Native::inspect($path,$pkg,$id,Tags::inspect($path,$pkg,$id,'unreal-v227'),'unreal-v227');
        $generic++;
    }
    $recordSuper=$record['super_field_reference']??null;
    $recordNext=$record['next_field_reference']??null;
    $check('real 227i current UnrealDB reader independently saved UField.SuperField '.$id,
        is_int($recordSuper)&&$recordSuper===$expected['super']);
    $check('real 227i current UnrealDB reader independently saved UField.Next '.$id,
        is_int($recordNext)&&$recordNext===$expected['next']);
    if($recordSuper===$expected['super']&&$recordNext===$expected['next'])$matched++;
}
$check('all distinct native loaded UField object export indices',count($seen)===915);
$check('all original 2012 native loaded field refs exact to individual original Core.u exports',$matched===915);
$check('entire original compiled C227i 913 ordinary UField subclasses parsed via native production reader',$generic===913);
$check('special two original Class exports parsed via dedicated UClass archive reader',$class===2);
$check('actual original native SuperField=0 count 912',$nullSuper===912);
$check('actual original native Next=0 count 238',$nullNext===238);
$check('original 3 and 677 nonnull source-target instances exact',
    (915-$nullSuper)===3 &&(915-$nullNext)===677);
$check('genuine original 227i version/build differences cannot imply later source full',
    $m['native_malformed_errors_and_other_source_build_equivalence_verified']===false);
$ok=$errors===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'passed'=>$checks-count($errors),
    'failed'=>$errors,'original_compiled_UField_915_distinct_loaded_object_field_pairs_matched'=>$matched,
    'native_total'=>$matched,'inherited_native_reference_values_compared'=>$matched*2,
    'super_nonzero'=>915-$nullSuper,'next_nonzero'=>915-$nullNext,
    'generic_native_reader'=> $generic,'dedicated_class_reader'=>$class,
    'original_2012_Core_u_exports'=>count($exports),
    'only_2012_227i_this_method_and_these_observed_objects_proved'=>true,'G3_full'=>false],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
