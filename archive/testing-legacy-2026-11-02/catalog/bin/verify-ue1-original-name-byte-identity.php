<?php
declare(strict_types=1);
/** Native 1996 char[32] and 1997 FArchive::String names contain
 * 8-bit source bytes, not already UTF-8 nor a guessed Windows code page.
 */
require_once dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedStagingIndex;
use UnrealDb\Catalog\Infrastructure\Unverified\CatalogUnverifiedMetadataSnapshotBuilder;
$db=new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$stager=new CatalogUnverifiedStagingIndex($db,[]);
$builder=new CatalogUnverifiedMetadataSnapshotBuilder($db);
$checks=[];$fails=[];
$check=static function(string $n,bool $v)use(&$checks,&$fails):void{$checks[$n]=$v;if(!$v){$fails[]=$n;}};
$label="\xC3\xA9";
$expected="\xC3\x83\xC2\xA9";
$import="\x80";
$expectedImport="\xC2\x80";
$resource=static function(int $version)use($label,$import):string {
  $prefix="Unreal Resource\x1A\0";
  $nameOffset=strlen($prefix);
  $names=pack('a32V',$label,0x70000);
  $importOffset=$nameOffset+strlen($names);
  $imports=pack('a32VVV',$import,3,2,76);
  $exportOffset=$importOffset+strlen($imports);
  $exports=pack('a32VVV',$label,4,2,76);
  $dataOffset=$exportOffset+strlen($exports);
  $headerOffset=$dataOffset+4;
  $header=pack('V2a32V9',0,2,$label,0,0,4,0,0,$dataOffset,4,0,0);
  $tail=pack('V9',$version,1,1,1,$nameOffset,$importOffset,$exportOffset,$headerOffset,76)
    .str_repeat("\0",16).str_repeat("\0",80).pack('a32',"Unreal Resource\x1A");
  return $prefix.$names.$imports.$exports.'DATA'.$header.'[SUMMARY]'.$tail;
};
$unrealfile=static function(int $version)use($label,$import):string {
  $dependencies=$import.".u\0";
  $names=$label."\0".pack('V',0x10000).$import."\0".pack('V',0x20000);
  $nameOffset=56+strlen($dependencies);
  $objectOffset=$nameOffset+strlen($names);
  $object=pack('vvVV',1,2,0,0);
  return pack('a32V6',"Unrealfile\x1A",$version,1,2,$nameOffset,$objectOffset,1)
    .$dependencies.$names.$object;
};
$path=tempnam(sys_get_temp_dir(),'ue1_bytes_');
if(!is_string($path)){throw new RuntimeException('Cannot create temp fixture');}
try{
  foreach([9,10] as $version){
    file_put_contents($path,$resource($version));
    $p=$stager->parse($path,'OriginalResource.res',0,'');
    $native=$p['native_metadata'];
    $check('resource'.$version.'_name',$p['names'][0]['name']===$expected);
    $check('resource'.$version.'_import',$native['resource_imports'][0]['name']===$expectedImport);
    $check('resource'.$version.'_export_header',$native['resource_exports'][0]['name']===$expected
       && $native['resource_headers'][0]['resource_name']===$expected
       && $native['resource_headers'][0]['name_matches_table']);
    $snap=$builder->fromOriginalNative(1,'NativeResource',$p['names'],$native);
    $check('resource'.$version.'_snapshot_json',json_decode(json_encode($snap,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR)===$snap);
  }
  foreach([22,23] as $version){
    file_put_contents($path,$unrealfile($version));
    $p=$stager->parse($path,'OriginalUnrealfile.u',0,'');
    $native=$p['native_metadata'];
    $check('unrealfile'.$version.'_name',$p['names'][0]['name']===$expected);
    $check('unrealfile'.$version.'_other_name',$p['names'][1]['name']===$expectedImport);
    $check('unrealfile'.$version.'_dependency',$native['declared_dependencies'][0]===$expectedImport.'.u');
    $check('unrealfile'.$version.'_object',$native['object_bases'][0]['name']===$expected
        && $native['object_bases'][0]['class_name']===$expectedImport);
    $snap=$builder->fromOriginalNative(2,'NativeUnrealfile',$p['names'],$native);
    $check('unrealfile'.$version.'_snapshot_json',json_decode(json_encode($snap,JSON_THROW_ON_ERROR),true,512,JSON_THROW_ON_ERROR)===$snap);
  }
}finally{@unlink($path);}
echo json_encode(['ok'=>$fails===[],'passed'=>count($checks)-count($fails),'total'=>count($checks),'failures'=>$fails],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fails===[]?0:1);
