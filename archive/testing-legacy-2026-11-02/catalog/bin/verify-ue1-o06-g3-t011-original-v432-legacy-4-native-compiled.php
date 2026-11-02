<?php
declare(strict_types=1);
// T011 real original stock v432 compiled native load v60-v63;
// read-only verification, NO reexecution of any compiled native tests.
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as N;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$repo=dirname(__DIR__,2);
$path=$repo.'/catalog/compliance/evidence/o06/20261031-G3-T011-T065-original-stock-v432-60-64-native-success-boundary-manifest.json';
$m=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
$errors=[];$count=0;$ck=static function(bool $ok,string $msg)use(&$count,&$errors){$count++;if(!$ok)$errors[]=$msg;};
$sys='C:/Temp/UnrealDB/O06/Native-UCC/System/';
$ck(hash_file('sha256',$sys.'Core.dll')==='237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8','exact original engine');
$ck(hash_file('sha256',$sys.'G304V432MethodReload.exe')==='fba945313b5642c4384698cd725002d08105f77c06d215ddf14532e580b7c377','original compiled loader unchanged');
$ck(hash_file('sha256',$sys.'G304V432FunctionStateFixture.u')==='545439a4526dd9108fd3c12f3b6f1eaf37ad2c91cf3729e684e12fc8dc903ad6','original real 69 engine-saved archive restored');
foreach([
 'L:/Source/Games/UT99/Unreal Tournament v432/Core/Inc/UnLinker.h'=>['ecc70ab300dd14994a3d648166652b571082f7ed5c04185b779fe251294e91a0','HeritageCount'],
 'L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Core/Src/UnName.cpp'=>['3d8ab9316fcb2bd88710f4c24762664c4423493db84876e96ef88a0069e302d3','Ar.Ver()<64'],
 'L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Core/Src/UnClass.cpp'=>['6563392fa15fb73d05b52267a00da5034651c47644f310857cc86f7b045f6380','Ar.Ver()<=63']
] as $src=>[$sha,$branch]){
 $ck(is_file($src)&&hash_file('sha256',$src)===$sha,'original source SHA '.basename($src));
 $ck(str_contains((string)file_get_contents($src),$branch),'source version branch '.basename($src));
}
$success=[];
foreach([60,61,62,63] as $v){
 $row=$m['older_versions'][(string)$v]??null;
 $ck(is_array($row)&&($row['version']??null)===$v,"one original native input $v");if(!is_array($row))continue;
 foreach(['console','engine'] as $type){
  $f=$repo.'/'.$row[$type.'_relative'];
  $ck(is_file($f)&&hash_file('sha256',$f)===$row[$type.'_sha256'],"original $v $type SHA");
  $ck(is_file($f)&&filesize($f)===$row[$type.'_bytes'],"original $v $type byte length");
 }
 $file=$row['archive_private_DEV_only_path'];
 $ck(is_file($file)&&hash_file('sha256',$file)===$row['archive_sha256'],"original-loader-accepted valid $v archive");
 if(!is_file($file))continue;
 $p=new P($file);$es=$p->getExports();$hdr=$p->getHeader();
 $ck(($hdr['version']??-1)===$v&&($hdr['licensee']??-1)===0,"selected version/family $v");
 $ck(count($p->getNames())===9&&count($es)===2&&count($p->getImports())===3,"linked table inventories $v");
 $ck($es[0]['objectNameText']==='G304v432Function'&&(int)$es[0]['outerIndex']===2
    &&(int)$es[0]['serialSize']===30,"original engine accepted v$v function export");
 $fn=N::inspect($file,$p,0,T::inspect($file,$p,0,'ut99-v432'),'ut99-v432');
 $st=N::inspect($file,$p,1,T::inspect($file,$p,1,'ut99-v432'),'ut99-v432');
 $ck(($fn['status']??'')==='native_function_record_complete'&&($st['status']??'')==='native_state_record_complete',"two current source records $v");
 $ck([$fn['legacy_parms_size'],$fn['legacy_num_parms'],$fn['legacy_return_value_offset']]===[4660,66,8738],"source-gated archived triplet $v");
 $ck([$fn['loaded_parms_size'],$fn['loaded_num_parms'],$fn['loaded_return_value_offset']]===[0,0,65535],"source re-computed loaded triplet $v");
 $ck($st['children_reference']===1&&$fn['children_reference']===0&&$fn['function_flags']===2,"source object/outer child map $v");
 $raw=(string)file_get_contents($repo.'/'.$row['console_relative']);
 $ck(str_contains($raw,'G304_469F_ORIGINAL_REAL_LOADER_FIELDS_MATCH=1'),"actual original native source succeeded $v");
 $ck(str_contains($raw,'NUMPARMS=0 PARMSIZE=0 RETURN=65535'),"original loaded native triplet agrees $v");
 $ck(preg_match('/STATE=[0-9A-F]{8} FUNCTION=([0-9A-F]{8})/',$raw,$a)===1
   &&preg_match('/CHILD=([0-9A-F]{8})/',$raw,$c)===1&&$a[1]===$c[1],"original native runtime Children pointer identity $v");
 $ck(!str_contains((string)file_get_contents($repo.'/'.$row['engine_relative']),'Critical:'),"original engine no fatal $v");
 $success[]=$v;
}
$side=$m['adjacent_original_compiled_version64'];
$ck(hash_file('sha256',$repo.'/'.$side['console_relative'])===$side['console_sha256'],'source-matched v64 original native nonlegacy SHA');
$ck(in_array('G304_469F_ORIGINAL_REAL_LOADER_FIELDS_MATCH=1',
   explode("\n",trim((string)file_get_contents($repo.'/'.$side['console_relative']))),true),'v64 adjacent original loaded');
$ck($success===[60,61,62,63],'four distinct genuine v60..63 native completed source loads');
$result=['ok'=>$errors===[],'checks'=>$count,'errors'=>$errors,'frozen_T011'=>$errors===[]?'PASS':'BLOCKED',
 'source_matched_native_legacy_versions'=>$success,'archived_legacy_triplet'=>[4660,66,8738],
 'original_and_reader_recomputed_triplet'=>[0,0,65535],
 'adjacent_original_native_version64_success'=>true,'original_engine_rerun_by_verifier'=>0,
 'T065_full_aggregate_source_matrix'=>'BLOCKED','overall_G3_accepted'=>false];
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
