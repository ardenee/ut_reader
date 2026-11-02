<?php
/** G3 fixed T010: current production script-size reading vs already-captured original native bytes.
 * Earlier native and CDB probes are NOT executed. Only two real original Core.u
 * samples per source family are inspected after Oct 27-28 parser changes.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tagged;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as ClassMetadata;

$repo=dirname(__DIR__,2);
$checkCount=0;$errors=[];$captures=[];
$check=static function(bool $ok,string $label)use(&$checkCount,&$errors):void{
    $checkCount++;
    if(!$ok)$errors[]=$label;
};
$sourceFiles=[
 'catalog/src/Infrastructure/Readers/CatalogUE1ClassicStructNativeReader.php',
 'catalog/src/Infrastructure/Readers/CatalogUE1ClassicScriptArchiveReader.php',
 'catalog/src/Infrastructure/Readers/CatalogUE1ClassicNativeObjectReader.php',
 'catalog/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php',
 'catalog/src/Infrastructure/Readers/CatalogUE1ClassicClassMetadataReader.php',
];
$readerHashes=[];
foreach($sourceFiles as $file){$readerHashes[$file]=hash_file('sha256',$repo.'/'.$file);}
$profiles=[
 [
  'build'=>'C98-May17-original','profile'=>'epic-ue1',
  'manifest'=>'UE1-O06-G3-03-C98-MAY1998-100-ORIGINAL-NATIVE-96-SCRIPTS.json',
  'source_key'=>'genuine_original_1998_shipped_DLL_packages_executables_SHA256',
  'evidence_key'=>'true_actual_original_compiled_CDB_stdout_scripts_parser_and_register_SHA256',
  'dataset_key'=>'original_compiled_100_true_UStruct_96_Script_register',
  'records'=>'native_records','size'=>'original_native_script_size',
  'samples'=>[['id'=>454,'zero'=>true],['id'=>449,'zero'=>false]],
 ],
 [
  'build'=>'C227i-2012-original','profile'=>'unreal-v227',
  'manifest'=>'UE1-O06-G3-03-C227I-237-ORIGINAL-NATIVE-223-SCRIPTS.json',
  'source_key'=>'native_original_shipped_binary_inputs',
  'evidence_key'=>'original_native_raw_logs_and_commands_SHA256',
  'dataset_key'=>'original_compiled_native_CDB_237_223_parsed_register',
  'records'=>'native_structs','size'=>'native_script_size',
  'samples'=>[['id'=>661,'zero'=>true],['id'=>915,'zero'=>false]],
 ]
];
foreach($profiles as $prof){
 $m=json_decode((string)file_get_contents($repo.'/catalog/compliance/'.$prof['manifest']),
    true,512,JSON_THROW_ON_ERROR);
 $genuine=null;
 foreach($m[$prof['source_key']] as $name=>$file){
    if(str_ends_with($name,'Core_u')||$name==='Core.u')$genuine=$file;
 }
 $check(is_array($genuine),'original Core.u provenance '.$prof['build']);
 if(!is_array($genuine))continue;
 $realFile=$genuine['path'];
 $check(is_file($realFile)&&hash_file('sha256',$realFile)===$genuine['sha256'],
    'shipped original Core.u SHA '.$prof['build']);
 $pin=$m[$prof['evidence_key']][$prof['dataset_key']];
 $regFile=$repo.'/'.$pin['repo_path'];
 $check(is_file($regFile)&&hash_file('sha256',$regFile)===$pin['sha256'],
    'actual original post-load CDB register SHA '.$prof['build']);
 if(!is_file($realFile)||!is_file($regFile))continue;
 $reg=json_decode((string)file_get_contents($regFile),true,512,JSON_THROW_ON_ERROR);
 $pkg=new Package($realFile);
 $check($pkg->validatePackage()===[],'original package validates '.$prof['build']);
 $exports=$pkg->getExports();
 foreach($prof['samples'] as $target){
    $id=$target['id'];
    $native=$reg[$prof['records']][(string)$id]??null;
    $check(is_array($native),'original native record exists '.$prof['build'].' '.$id);
    if(!is_array($native))continue;
    $exp=$exports[$id]??null;
    $check(is_array($exp),'genuine export exists '.$prof['build'].' '.$id);
    if(!is_array($exp))continue;
    try{
      $record=(int)$exp['classIndex']===0
        ? ClassMetadata::inspect($realFile,$pkg,$id,false,$prof['profile'])
        : Native::inspect($realFile,$pkg,$id,
           Tagged::inspect($realFile,$pkg,$id,$prof['profile']),$prof['profile']);
    }catch(Throwable $e){
      $check(false,'current reader crashed '.$prof['build'].' '.$id.': '.$e->getMessage());continue;
    }
    $size=$native[$prof['size']];
    $check($record['script_size']===$size,
       'original compiled Script.Num vs CURRENT decoder '.$prof['build'].' '.$id);
    $check(($size===0)===$target['zero'],'sample includes expected zero/positive boundary');
    $item=['build'=>$prof['build'],'export'=>$id,'original_native_script_num'=>$size,
           'current_reader_script_num'=>$record['script_size'],'zero'=>$target['zero']];
    if($size>0){
      $v=$record['script_archive']??null;
      $check(is_array($v),'nonzero current archive parsed '.$prof['build'].' '.$id);
      if(!is_array($v))continue;
      $check($v['status']==='script_archive_expressions_decoded',
         'fully decoded original archive '.$prof['build'].' '.$id);
      $check($v['source_memory_full_byte_reconstruction']===true,
         'complete source-memory reconstruction '.$prof['build'].' '.$id);
      $check($v['script_memory_decoded']===$size,
         'native loaded script memory bytecount '.$prof['build'].' '.$id);
      $arch=$v['archive_bytes_consumed']??null;
      $check(is_int($arch)&&$arch>0&&$arch<$size,
         'compact archived refs are smaller than native script memory '.$prof['build'].' '.$id);
      $bytes=hex2bin($v['source_memory_bytes_hex']??'');
      $mask=hex2bin($v['source_memory_known_mask_hex']??'');
      $originalHex=$prof['build']==='C98-May17-original'
         ? ($native['original_compiled_real_script_memory_bytes_hex']??'')
         : ($reg['native_script_first36_hex'][(string)$id]??'');
      $actual=hex2bin($originalHex);
      $good=is_string($bytes)&&is_string($mask)&&is_string($actual)
         &&strlen($bytes)===$size&&strlen($mask)===$size&&strlen($actual)>=$size;
      $check($good,'actual compiled memory bytes present and correctly sized '.$prof['build'].' '.$id);
      $known=0;$unknown=0;$match=true;
      if($good){
        for($i=0;$i<$size;$i++){
          if(ord($mask[$i])===1){
             $known++;
             if($bytes[$i]!==$actual[$i])$match=false;
          }else{$unknown++;}
        }
        $check($known>0&&$unknown>0,'nontrivial known bytes and authentic masked native pointer/FName '.$prof['build'].' '.$id);
        $check($match,'all known bytes same as original loaded compiled memory '.$prof['build'].' '.$id);
      }
      $item+=['archive_bytes_consumed'=>$arch,'native_compact_widening'=>$size-$arch,
             'real_native_known_bytes_matched'=>$known,'masked_native_words'=>$unknown];
    }
    $captures[]=$item;
 }
}
$ok=$errors==[]&&count($captures)===4;
echo json_encode(['ok'=>$ok,'checks'=>$checkCount,'errors'=>$errors,'captures'=>$captures,
 'current_reader_source_sha'=>$readerHashes,
 'native_executions_rerun'=>0,'old_acceptance_verifier_replays'=>0,
 'T010_scope'=>'script size and compact archive consumption ONLY; T009/G3-12 OPEN'],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
