#!/usr/bin/env php
<?php
/** G3 fixed T012: actual original May 1998 C98 UClass::Serialize loaded fields.
 * All real Core.u UClass exports, and source/compiled exclusion of intrinsic
 * C++ Field and Struct (they are NOT genuine serialized Core.u Class exports).
 * Reads sealed original x86 CDB source, does not rerun original compiled engine.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
$repo=dirname(__DIR__,2);
$core='C:/Temp/UnrealDB/O06/Native-C98-1998-G304/System/';
$log=$repo.'/catalog/compliance/evidence/o06/20261029-G3-T012-C98-Core-original-native.log';
$cmd=$repo.'/catalog/compliance/evidence/o06/20261029-G3-T012-C98-Core-original-native.cmd';
$checks=0;$failed=[];$records=[];
$ck=static function(bool $pass,string $message)use(&$checks,&$failed):void{
  ++$checks;if(!$pass)$failed[]=$message;
};
foreach([
 [$core.'Core.dll','9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92'],
 [$core.'Core.u','7c94b2a68d8f6fc0bfb5264c9e9aad4ce35ccf12a8b8057d456dacd33d74140c'],
 [$log,'dff15ea260e1846c51af819c6d54be89bb5ada00ee91a695902bca8616b20d85'],
 [$cmd,'319a4230a8091bc7cd82fbcedecd5054af9310d012afea9a3f4a09c78d5e6b82'],
 ['L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Core/Src/UnClass.cpp','ea4b7304d6cfe45f35929b59a10cefe51e5ee132f3603fc43e3f0e3d97aa1291'],
 [$repo.'/catalog/src/Infrastructure/Readers/CatalogUE1ClassicClassMetadataReader.php','08d0c883bdf5b91dfc2bfb4b342e9bc549f2f63e135b7ff961fa62664ee4d386']
] as [$path,$sha])$ck(is_file($path)&&hash_file('sha256',$path)===$sha,'original source/native SHA '.$path);
$p=new P($core.'Core.u');
$exports=$p->getExports();
$ck((int)$p->getHeader()['version']===61,'original May 1998 C98 Core.u v61 package');
$serialized=[];
foreach($exports as $i=>$ex)if((int)$ex['classIndex']===0)$serialized[$i]=$ex['objectNameText'];
$ck($serialized===[0=>'Object',229=>'Subsystem'],
    'true original stock C98 Core.u only Object, Subsystem are serialized UClass exports');
$binary=(string)file_get_contents($core.'Core.dll');
$ck(str_contains($binary,'?StaticClass@UField@@2PAVUClass@@A')
 &&str_contains($binary,'?StaticClass@UStruct@@2PAVUClass@@A'),
 'original compiled 1998 Core.dll UField/UStruct are intrinsic C++ StaticClass, not serialized Core.u Class exports');
$source=(string)file_get_contents('L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Core/Src/UnClass.cpp');
foreach(['void UClass::Serialize( FArchive& Ar )','Ar << ClassRecordSize << ClassFlags << ClassGuid;','Ar << Dependencies << PackageImports;'] as $needle)
 $ck(str_contains($source,$needle),'original Epic May1998 method source '.$needle);
$native=str_replace("\r\n","\n",(string)file_get_contents($log));
$parts=explode("G3_C98_NATIVE_CORE_UClass_REFS\n",$native);
$ck(count($parts)===3,'original compiled 1998 UClass::Serialize native two actual Core.u serialized class events');
$parseRows=static function(string $block):array{
  preg_match_all('/^\s*[0-9a-f]{8}\s+((?:[0-9a-f]{8}\s*){1,4})\s*$/mi',$block,$m);
  $result=[];
  foreach($m[1] as $line)foreach(preg_split('/\s+/',trim($line)) as $hex){
    if($hex!=='')$result[]=hexdec($hex);
  }
  return $result;
};
$readIndex=static function(string $chunk,string $marker)use($parseRows):?int{
   $a=explode($marker."\n",$chunk,2);
   if(count($a)!==2)return null;
   $values=$parseRows(explode("\n",$a[1],2)[0]);
   return $values[0]??null;
};
$captured=[];
foreach(array_slice($parts,1) as $chunk){
  $sections=explode("G3_C98_DEP_RAW\n",$chunk,2);
  $ck(count($sections)===2,'actual original May 1998 class dependency source field section');
  if(count($sections)!==2)continue;
  $header=$parseRows($sections[0]);
  $ck(count($header)===19,'genuine original C98 original UObject index + 12 class metadata dwords');
  if(count($header)!==19)continue;
  $id=$header[0];$flags=$header[1];$dwords=array_slice($header,1);
  $ck(isset($serialized[$id]),'original compiled Core.u real UClass export id '.$id);
  if(!isset($serialized[$id]))continue;
  $v=C::inspect($core.'Core.u',$p,$id,false,'epic-ue1');
  $ck($v['source_file_archive_complete']===true,'reader fully consumes original C98 Core.u UClass '.$id);
  $ck($flags===(int)$v['class_flags'],'original compiled source and reader loaded C98 ClassFlags '.$id);
  $ck($flags===19,'actual C98 original ClassFlags for class id '.$id);
  $ck($v['class_within_reference']===null,'C98 original v61 version excludes later ClassWithin field '.$id);
  $ck($v['class_guid_raw_hex']==='00000000000000000000000000000000','original source original class GUID zero '.$id);
  $ck(array_sum(array_slice($dwords,2,4))===0,'original compiled native GUID128 zero '.$id);
  $expectedDeps=$v['dependencies'];$expectedImports=$v['package_imports'];
  $ck($dwords[7]!==0&&$dwords[8]===count($expectedDeps)&&$dwords[9]>=count($expectedDeps),
    'original compiled original FDependency count and allocated TArray '.$id);
  $ck($dwords[10]!==0&&$dwords[11]===count($expectedImports)&&count($expectedImports)===1
       &&$expectedImports[0]['text']==='Core',
    'original compiled original PackageImports TArray count + reader source name Core '.$id);
  $depRaw=$parseRows(explode("G3_C98_DEP0_CLASS_OBJECT_INDEX\n",$sections[1])[0]);
  $ck(count($depRaw)===6,'genuine original native FDependency raw data captured '.$id);
  $captured[$id]=['archive'=>$v,'raw'=>$depRaw,
     'class_native_pointer'=>hexdec(substr($sections[0],0,0)), 'flags'=>$flags,'dwords'=>$dwords,
     'class_address'=>null,'index_source'=>$id,'chunk'=>$chunk];
  // Native original UObject.Index memory field is at this exact address +0x14.
  preg_match('/^([0-9a-f]{8})\s+[0-9a-f]{8}\s*$/mi',$sections[0],$indexAddr);
  $captured[$id]['class_address']=isset($indexAddr[1])?hexdec($indexAddr[1])-0x14:null;
}
$ck(array_keys($captured)===[0,229],'both authentic serialized C98 Core.u classes appear in compiled source order');
foreach($captured as $id=>$row){
  $expected=$row['archive']['dependencies'];
  $raw=$row['raw'];
  foreach($expected as $j=>$dep){
    $ref=(int)$dep['class_reference'];
    $classIndex=$ref-1;
    $ck(isset($captured[$classIndex]),'genuine native FDependency links to real serialized class '.$id.' dep'.$j);
    $ptr=$captured[$classIndex]['class_address']??null;
    $ck($ptr!==null&&$raw[$j*3]===$ptr,
       'original native same-process FDependency UObject pointer identity '.$id.' dep'.$j);
    $ck($raw[$j*3+1]===(int)$dep['deep'],
       'original native FDependency.Deep '.$id.' dep'.$j);
    $ck($raw[$j*3+2]===(int)$dep['script_text_crc'],
       'original native FDependency ScriptTextCRC '.$id.' dep'.$j);
    $nativeObjectIndex=$readIndex($row['chunk'],'G3_C98_DEP'.$j.'_CLASS_OBJECT_INDEX');
    $ck($nativeObjectIndex===$classIndex,
       'original compiled native referenced UObject.Index equals source export '.$id.' dep'.$j);
  }
  $records[]=['export_index'=>$id,'name'=>$serialized[$id],'flags'=>$row['flags'],
      'genuine_dependencies'=>count($expected),'source_file_archive_complete'=>true];
}
$ck(count($records)===2,'both original genuine C98 Core UClass records accepted');
$ok=$failed===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'failures'=>$failed,'T012'=>$ok?'PASS':'BLOCKED',
 'native_original_C98_serialized_Core_UClass_count'=>count($records),
 'source_nonexport_intrinsics'=>['Core.Field','Core.Struct'],
 'original_compiled_native_reruns_by_this_verifier'=>0,'source_version'=>61,'real_classes'=>$records],
 JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
