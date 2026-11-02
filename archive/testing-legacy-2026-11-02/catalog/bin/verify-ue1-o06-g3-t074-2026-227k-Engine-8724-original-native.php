<?php
/** First never-before-inspected stock 2026 C227k genuine Engine.u native-loaded reconciliation. */
declare(strict_types=1);
$cat='C:/Users/arden/source/repos/ut_reader/catalog';
require_once $cat.'/bootstrap/autoload.php';
require_once $cat.'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$repo=dirname(__DIR__,2);
$manifest=json_decode((string)file_get_contents($repo.'/catalog/compliance/evidence/o06/20261101-G3-T074-T075-original-227k-Engine-8724-fields-2408-pointer-187-class-manifest.json'),true,512,JSON_THROW_ON_ERROR);
$f=$manifest['pins']['authentic_2026_original_Engine_package']['path'];
$raw=file_get_contents($repo.'/'.$manifest['pins']['original_2026_engine_property_struct_loader_trace']['path']);
$pinChecks=0;
foreach(['authentic_2026_original_Core_DLL','authentic_2026_original_Engine_DLL','authentic_2026_original_Engine_package','authentic_2026_original_commandlet',
'original_2026_engine_property_struct_loader_trace','original_2026_engine_pointer_class_loader_trace',
'original_2026_UnClass_header','original_2026_UnObjBas_header'] as $pinName){
 $pin=$manifest['pins'][$pinName];
 $disk=$pin['location']==='repo'?$repo.'/'.$pin['path']:$pin['path'];
 if(!is_file($disk)||filesize($disk)!==$pin['bytes']||hash_file('sha256',$disk)!==$pin['sha256']){
  throw new RuntimeException('Original compiled UE1 source/native SHA pin changed: '.$pinName);
 }
 ++$pinChecks;
}
$p=new P($f);$e=$p->getExports();$h=$p->getHeader();
$errors=[];$reasons=[];$linkers=[];$propRows=[];$structRows=[];
foreach(preg_split('/\r\n|\r|\n/',$raw) as $l){
 if(preg_match('/^T074_ENGINE_PROPERTY linker=([0-9a-f]{8}) idx=(\d+) dim=(-?\d+) flags=([0-9a-f]{8})$/i',$l,$m)){
    $linkers[$m[1]]['property']=($linkers[$m[1]]['property']??0)+1;
    $propRows[]=['linker'=>$m[1],'id'=>(int)$m[2],'dim'=>(int)$m[3],'flags'=>hexdec($m[4])];
 }
 if(preg_match('/^T074_ENGINE_STRUCT linker=([0-9a-f]{8}) idx=(\d+) child=([0-9a-f]{8}) script=([0-9a-f]{8}) line=(-?\d+) textpos=(-?\d+)$/i',$l,$m)){
    $linkers[$m[1]]['struct']=($linkers[$m[1]]['struct']??0)+1;
    $structRows[]=['linker'=>$m[1],'id'=>(int)$m[2],'child'=>hexdec($m[3]),'script'=>hexdec($m[4]),'line'=>(int)$m[5],'textpos'=>(int)$m[6]];
 }
}
$target=array_keys(array_filter($linkers,static fn($x)=>($x['property']??0)>1000));
$engineLinker=$target[0]??null;
$source='unreal-v227k';
$checks=0;$propMatches=0;$structMatches=0;$pointers=0;$strange=[];$seen=['property'=>[],'struct'=>[]];
$ck=static function(bool $ok,string $name)use(&$checks,&$errors):void{
 ++$checks;if(!$ok&&count($errors)<40)$errors[]=$name;
};
$ck($engineLinker!==null&&count($target)===1,'unique new engine linker not Core.u');
$ck(($h['version']??null)===69&&($h['licensee']??null)===227,'real original Engine.u v69 lic227');
$ck($p->validatePackage()===[],'Engine.u actual genuine physical header and maps valid');
$cache=[];$load=static function(int $id)use(&$cache,$e,$f,$p,$source):array{
 if(array_key_exists($id,$cache))return $cache[$id];
 $x=$e[$id]??null;
 if(!is_array($x))throw new RuntimeException('missing genuine export '.$id);
 if((int)$x['classIndex']===0)return $cache[$id]=C::inspect($f,$p,$id,false,$source);
 return $cache[$id]=N::inspect($f,$p,$id,T::inspect($f,$p,$id,$source),$source);
};
foreach($propRows as $row){
 if($row['linker']!==$engineLinker)continue;
 $id=$row['id'];$ck(isset($e[$id]),'property from original Engine.u export '.$id);
 $ck(!isset($seen['property'][$id]),'native property id unique '.$id);
 $seen['property'][$id]=true;
 try{$record=$load($id);}catch(Throwable $x){if(count($errors)<40)$errors[]="property inspect $id: ".$x->getMessage();continue;}
 $ok=($record['array_dim']??null)===$row['dim']
   &&($record['property_flags']??null)===$row['flags'];
 $ck($ok,'original Engine.u UProperty dim+flags '.$id);
 if($ok)$propMatches++;
 elseif(count($strange)<15)$strange[]=['kind'=>'property','id'=>$id,'file_name'=>$e[$id]['objectNameText']??null,'native'=>$row,'stored'=>[$record['array_dim']??null,$record['property_flags']??null],'status'=>$record['status']??null];
}
foreach($structRows as $row){
 if($row['linker']!==$engineLinker)continue;
 $id=$row['id'];$ck(isset($e[$id]),'struct from original Engine.u export '.$id);
 $ck(!isset($seen['struct'][$id]),'native struct id unique '.$id);$seen['struct'][$id]=true;
 try{$record=$load($id);}catch(Throwable $x){if(count($errors)<40)$errors[]="struct inspect $id: ".$x->getMessage();continue;}
 $lineOk=($record['source_line']??null)===$row['line'];
 $textOk=($record['source_text_pos']??null)===$row['textpos'];
 $childRef=$record['children_reference']??null;
 $scriptRef=$record['script_text_reference']??null;
 $childOk=is_int($childRef)&&($row['child']===0?($childRef===0):($childRef!==0));
 $scriptOk=is_int($scriptRef)&&($row['script']===0?($scriptRef===0):($scriptRef!==0));
 $ck($lineOk&&$textOk&&$childOk&&$scriptOk,'real native UStruct values/presence '.$id);
 if($lineOk&&$textOk&&$childOk&&$scriptOk)$structMatches++;
 elseif(count($strange)<15)$strange[]=['kind'=>'struct','id'=>$id,'file_name'=>$e[$id]['objectNameText']??null,'native'=>$row,'stored'=>[$record['source_line']??null,$record['source_text_pos']??null,$childRef,$scriptRef],'status'=>$record['status']??null];
 if($row['child']!==0)$pointers++;
}
$ck(($linkers[$engineLinker]['property']??0)===6316,'exact genuine 227k Engine.u property observed count');
$ck(($linkers[$engineLinker]['struct']??0)===2408,'exact genuine 227k Engine.u struct observed count');
$ck(count($seen['property'])===6316,'6316 distinct Engine.u property objects');
$ck(count($seen['struct'])===2408,'2408 distinct Engine.u struct objects');
echo json_encode(['ok'=>$errors===[],'source'=>'2026 compiled genuine C227k Engine.u',
 'engine_linker'=>$engineLinker,'physical_exports'=>count($e),
 'original_runtime_linker_counts'=>$linkers,'checks'=>$checks,'errors'=>$errors,
 'property_matches'=>$propMatches,'struct_matches'=>$structMatches,
 'children_nonzero'=>$pointers,'source_type_mismatch_samples'=>$strange,
 'frozen_full_T074_accepted'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
