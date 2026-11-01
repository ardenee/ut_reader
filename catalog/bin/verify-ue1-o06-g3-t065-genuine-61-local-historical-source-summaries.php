<?php
/** T065 distinct ORIGINAL physical 1997-98 UE1 package v35/55-58 source summary evidence.
 * This verifies original Epic v1200 FPackageFileSummary persisted fields,
 * not C98 compiled UClass::Serialize or a claim the 1998 runtime can load 1997 textures.
 */
declare(strict_types=1);
$repo=dirname(__DIR__,2);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
$manifest=json_decode((string)file_get_contents($repo.'/catalog/compliance/evidence/o06/20261101-G3-T065-local-original-historical-v35-v55to58-physical-61-summary.json'),true,512,JSON_THROW_ON_ERROR);
$checks=0;$fail=[];$versions=[];$exports=0;$imports=0;$oldKinds=[];$total=0;
$check=static function(bool $ok,string $name)use(&$checks,&$fail):void{
 ++$checks;
 if(!$ok && count($fail)<25)$fail[]=$name;
};
$check($manifest['t065_full_accepted']===false,'T065 remains BLOCKED (full method/version union)');
foreach($manifest['pins'] as $key=>$entry){
 $f=$entry['location']==='repo'?$repo.'/'.$entry['path']:$entry['path'];
 $check(is_file($f)&&filesize($f)===$entry['bytes']&&hash_file('sha256',$f)===$entry['sha256'],'source/reader PIN '.$key);
}
$source=(string)file_get_contents($manifest['pins']['original_Epic_v1200_FPackageFileSummary']['path']);
$check(str_contains($source,'Ar << Sum.Tag;')&&str_contains($source,'Ar << Sum.FileVersion;'),
 'original Epic FPackageFileSummary tag and version ordered');
$check(str_contains($source,'if( Sum.FileVersion >= 34 ) Ar << Sum.PackageFlags;'),
 'original Epic FPackageFileSummary >=34 flags branch');
$check(str_contains($source,'Ar << Sum.HeritageCount << Sum.HeritageOffset;'),
 'original Epic FPackageFileSummary heritage and order');
$check(count($manifest['genuine_physical_packages'])===61,'precisely 61 pinned genuine original physical packages');
foreach($manifest['genuine_physical_packages'] as $fixture){
 $path=$manifest['source_repository_directory'].'/'.$fixture['filename'];
 $bytes=@file_get_contents($path);
 $check(is_string($bytes)&&strlen($bytes)===$fixture['bytes'],'physical size '.$fixture['filename']);
 if(!is_string($bytes)||strlen($bytes)<44)continue;
 $check(hash('sha256',$bytes)===$fixture['sha256'],'physical SHA '.$fixture['filename']);
 $u32=static fn(int $at):int=>(int)unpack('V',substr($bytes,$at,4))[1];
 $sum=['signature'=>$u32(0),'version'=>$u32(4)&0xffff,
  'packageFlags'=>$u32(8),'nameCount'=>$u32(12),'nameOffset'=>$u32(16),
  'exportCount'=>$u32(20),'exportOffset'=>$u32(24),
  'importCount'=>$u32(28),'importOffset'=>$u32(32),
  'heritageCount'=>$u32(36),'heritageOffset'=>$u32(40)];
 $check($sum['signature']===0x9e2a83c1,'real native UE magic '.$fixture['filename']);
 $check($sum['version']===$fixture['archive_version'],'original physical header version '.$fixture['filename']);
 $check($sum['version']>=34 && $sum['version']<=61,'Epic <=61 v1200 summary applicable '.$fixture['filename']);
 $pkg=new Package($path);$header=$pkg->getHeader();$exps=$pkg->getExports();$imps=$pkg->getImports();
 foreach($sum as $field=>$value){
  $check(($header[$field]??null)===$value,'original Epic v1200 source summary '.$field.' '.$fixture['filename']);
 }
 $check(count($exps)===$fixture['exports'],'original ExportMap count '.$fixture['filename']);
 $check(count($imps)===$fixture['imports'],'original ImportMap count '.$fixture['filename']);
 $check($pkg->validatePackage()===[],'genuine source package valid read '.$fixture['filename']);
 $versions[$sum['version']]=($versions[$sum['version']]??0)+1;
 $exports+=count($exps);$imports+=count($imps);$total++;
 if(in_array($sum['version'],[35,55,56,57,58],true)){
  foreach($exps as $e){
   $id=(int)$e['classIndex'];
   $kind=$id===0?'Class':($id<0?($imps[-$id-1]['objectNameText']??'unknown')
                      :($exps[$id-1]['objectNameText']??'unknown'));
   $oldKinds[$kind]=($oldKinds[$kind]??0)+1;
   $check(in_array($kind,['Texture','Palette','FireTexture'],true),
      'old genuine file has no UClass/UStruct/UProperty direct export '.$fixture['filename']);
  }
 }
}
ksort($versions);ksort($oldKinds);
$check($versions===$manifest['version_distribution'],'all original historical version distributions');
$check($total===61&&$exports===671,'61 distinct true original files and 671 genuine exports');
$check(($versions[35]??null)===13,'13 actual physical original version35 packages');
$check(($versions[55]??0)+($versions[56]??0)+($versions[57]??0)+($versions[58]??0)===11,
 '11 actual physical original versions55 through58 packages');
$check(array_diff(array_keys($oldKinds),['Palette','Texture','FireTexture'])===[],
 'these original physical files cannot certify C98 UClass/UStruct/UProperty load values');
echo json_encode(['ok'=>$fail===[],'focused_source_summary_assertions'=>$checks,'failed'=>$fail,
 'original_physical_files'=>$total,'physical_export_count'=>$exports,'physical_import_count'=>$imports,
 'original_archive_versions'=>$versions,'version35_files'=>13,'versions55_through58_files'=>11,
 'version35_and55through58_runtime_export_types'=>$oldKinds,
 'original_compiled_C98_1998_native_object_loaded_claim'=>false,
 'T065_closed'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($fail===[]?0:1);
