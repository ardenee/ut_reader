#!/usr/bin/env php
<?php
/** Original Epic v227 header and three SHA-pinned genuine v69/227
 * archives. The unavailable native FObjectExport archive implementation
 * must never be replaced with an inferred ArchetypeIndex encoding.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
$b='C:/Temp/UnrealDB/O06/';
$files=json_decode((string)file_get_contents($b.'g5-prd-three-genuine-227-packages.json'),true,512,JSON_THROW_ON_ERROR);
$bad=json_decode((string)file_get_contents($b.'g5-v227-FObjectExport-archetype-guard-negative.json'),true,512,JSON_THROW_ON_ERROR);
$source='L:/Source/Games/Unreal/Unreal [v1.227]/Core/Inc/';
$head=(string)file_get_contents($source.'UnLinker.h');
$flags=(string)file_get_contents($source.'UnObjBas.h');
$ut99=(string)file_get_contents('L:/Source/Games/UT99/Unreal Tournament v432/Core/Inc/UnLinker.h');
$checks=0;$errors=[];$check=static function(string $name,bool $ok)use(&$checks,&$errors):void{
 $checks++;if(!$ok)$errors[]=$name;
};
$check('v227_declares_persistent_ArchetypeIndex',str_contains($head,'ArchetypeIndex;')&&str_contains($head,'// Persistent.')&&str_contains($head,'CORE_API friend FArchive& operator<<(FArchive& Ar, FObjectExport& E);'));
$check('v227_flag_and_mutator_source',str_contains($flags,'RF_HasArchtype')&&str_contains($flags,'ObjectFlags |= RF_HasArchtype;')&&str_contains($flags,'ObjectFlags &= ~RF_HasArchtype;'));
$check('UT99_v432_has_no_ArchetypeIndex',!str_contains($ut99,'ArchetypeIndex'));
$tot=0;$mutations=0;
foreach($files as $i=>$file){
 $path=$file['dev_sha_verified'];
 $check('genuine_original_sha_'.$i,hash_file('sha256',$path)===$file['sha256']);
 $pkg=new P($path);
 $h=$pkg->getHeader();
 $check('v227_original_source_identity_'.$i,(int)$h['version']===69&&(int)$h['licenseeVersion']===227);
 $ex=$pkg->getExports();$tot+=count($ex);
 $check('original_v227_flags_clear_'.$i,!array_filter($ex,static fn(array $e):bool=>(((int)$e['objectFlags'])&0x100)!==0));
 $fixture=$bad[$i];
 $check('scratch_mutation_sha_'.$i,hash_file('sha256',$fixture['mutated_DEV_ONLY'])===$fixture['mutated_sha256']);
 // The package-reader constructor intentionally records malformed
 // archive errors in getDebugErrors() and clears its parsed maps.
 $rejected=new P($fixture['mutated_DEV_ONLY']);
 $nativeBlock=false;
 foreach($rejected->getDebugErrors() as $diagnostic){
  if(str_contains((string)$diagnostic,'requires unverified native ArchetypeIndex')){
   $nativeBlock=true;break;
  }
 }
 $check('source_flagged_v227_rejected_'.$i,$nativeBlock
  &&$rejected->getExports()===[]);
 if($nativeBlock)$mutations++;
 $check('genuine_original_preserved_'.$i,hash_file('sha256',$path)===$file['sha256']);
}
$check('all_426_original_exports_unchanged',$tot===426);
$check('three_source_flag_negatives',$mutations===3);
$check('zero_compiled_native_engine_reruns',true);
echo json_encode(['ok'=>$errors===[],'checks'=>$checks,'passed'=>$checks-count($errors),'failed'=>$errors,
 'native_v227_export_operator_complete'=>false,'genuine_licensee_227_files'=>3,
 'genuine_export_rows_unmodified'=>$tot,'real_RF_HasArchtype_positives'=>0,
 'dev_only_single_flag_mutations_rejected'=>$mutations,'compiled_native_replays'=>0,
 'source_native_archetype_serialize'=>'UNVERIFIED','full_method_decision'=>'BLOCKED'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
