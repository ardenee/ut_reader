<?php
/** Fixed G3 T068 cross-source original compiled compact count acceptance.
 * No original compiled native engine is executed by this verifier.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicClassMetadataReader as C;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root=dirname(__DIR__,2);$e=$root.'/catalog/compliance/evidence/o06/';
$checks=0;$errors=[];$ck=static function(bool $v,string $label)use(&$checks,&$errors){++$checks;if(!$v)$errors[]=$label;};
$pin=static function(string $path,string $sha)use($ck):void{$ck(is_file($path)&&hash_file('sha256',$path)===$sha, 'native/source original SHA '.basename($path));};
$native=static function(string $path):string{$s=(string)file_get_contents($path);return str_starts_with($s,"\xFF\xFE")?(string)iconv('UTF-16LE','UTF-8',substr($s,2)):$s;};
$parent='C:/Temp/UnrealDB/O06/Native-C98-1998-G304/System/G3C98Defaults.u';
$original=(string)file_get_contents($parent);
$ck(hash('sha256',$original)==='766e4654a9985cd241307fdf78f496f890fcde3706799f872b41c94cb97cbddf','original May 1998 UCC class file preserved');
$ck(hash_file('sha256','C:/Temp/UnrealDB/O06/Native-C98-1998-G304/System/Core.dll')==='9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92','actual May 17 1998 retail compiled C98 Core.dll');
$cpp='L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Core/Inc/Core.h';
$src=(string)file_get_contents($cpp);
$ck(str_contains($src,'Ar << AR_INDEX(A.ArrayMax);')&&str_contains($src,'for( INT i=0; i<A.ArrayMax; i++ )')&&str_contains($src,'A.Realloc( sizeof(T) );'),'authentic Epic C98 generic TArray<T> source loads count before per-element reference and physical reads');
$sourceClass='L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Core/Src/UnClass.cpp';
$ck(str_contains((string)file_get_contents($sourceClass),'Ar << Dependencies << PackageImports;'),'native Epic UClass serialized distinct TArray UObject-reference and FName operators');
$cases=[
 ['c98-dependencies-count-3',338,3,'3daeeaa46959085b419352f2a9412e671bf6131121b17b33c33955268b1e4814','9eefbcd566aaf3e1b622472a0505a0b301e1d8600f99e6fd1212e5205aa7faa5',null,null],
 ['c98-dependencies-count-63',338,63,'e2e2b9fa2f5342ab32c2ee030d79bdbc64d2fdb03a28f8ba7582d548aad2e3e9','cde455de6a89aa168dff379291118de54487ab27b9514b18463ebd4e17d9754b','UE1 Bad import index 1048574/7 at archive cursor 388','Bad import index 1048574/7'],
 ['c98-packageimports-count-63',357,63,'68b54b1a39f9b9e3a3c7c7f03e8b2d7a8cba4bbe1308bf12e915f935ec4dbd80','a17a8fcab51cdba3eb454457e4116dcb0cc76ae9d448faec8c730763b5b459fa','UE1 Bad name index 34/16 at archive cursor 363','Bad name index 34/16']
];
foreach($cases as [$id,$offset,$value,$sha,$logsha,$error,$nativeError]){
 $file=$e.'20261031-G3-T068-'.$id.'.u';$log=$e.'20261031-G3-T068-'.$id.'-Editor.log';
 $pin($file,$sha);$pin($log,$logsha);
 $bytes=(string)file_get_contents($file);$changes=[];
 if(strlen($bytes)===strlen($original))for($i=0;$i<strlen($original);$i++)if($bytes[$i]!==$original[$i])$changes[]=$i;
 $ck(strlen($bytes)===761&&$changes===[$offset]&&ord($bytes[$offset])===$value,'only one original compiler-produced archive byte changed '.$id);
 $pkg=new P($file);$h=$pkg->getHeader();
 $ck($h['version']===61&&$h['licensee']===0&&count($pkg->getImports())===7&&count($pkg->getNames())===16,'C98 original source version and linker map '.$id);
 $got=null;$metadata=null;try{$metadata=C::inspect($file,$pkg,0,true,'epic-ue1');}catch(Throwable $x){$got=$x->getMessage();}
 $nativeLog=(string)file_get_contents($log);
 $ck(str_contains($nativeLog,'Compiled: May 17 1998')&&str_contains($nativeLog,'Loading: Package G3C98Defaults'),'real original compiled engine loading mutated class '.$id);
 if($error===null){
    $ck($got===null&&count($metadata['dependencies']??[])===3,'native nonfatal count3 source reader accepts actual three records without invented error');
    $ck(!str_contains($nativeLog,'Critical:')&&str_contains($nativeLog,'Unloading: Package G3C98Defaults'),'real original C98 count3 completed without fatal error');
 }else{
    $ck($got===$error,'exact C98 original source selected native exception and physical Tell '.$id.': '.($got??'none'));
    $cursor=$offset===338?'LinkerLoad4 388 ':'LinkerLoad4 363 ';
    $ck(str_contains($nativeLog,'Critical: '.$nativeError)
        &&str_contains($nativeLog,$cursor)&&str_contains($nativeLog,'Critical: TArray<<')
        &&str_contains($nativeLog,'Critical: UClass::Serialize'),
        'original C98 authentic compiled UClass TArray linker error and physical cursor '.$id);
 }
}
$v432=$e.'20261029-G3-T048-T068-original-v432-native-count38pass.json';
$pin($v432,'f205e5f1e13c1a424c8c504769a676b6ed2d9a750ee7bb0cc7daa5c7ca4373ae');
$json=json_decode((string)file_get_contents($v432),true);
$ck(($json['ok']??false)===true&&($json['checks']??0)===38&&($json['cases'][0]['native_true_physical_Tell']??0)===259&&($json['cases'][1]['archive_only_count']??0)===-1,'reused previously accepted true original stock v432 TArray signed zero/-1 counts and physical Tell259 (not rerun)');
foreach(['array_count_zero','array_count_negative_one'] as $s){
 $log=$e.'20261029-G3-T048-T068-UT99-v432-original-'.$s.'.stdout.log';
 $ck(str_contains($native($log),'Got 8, Expected 29'),'original stock UT99 v432 compiled UClass script count shifted fatal mismatch '.$s);
}
$ck(hash_file('sha256','C:/Temp/UnrealDB/O06/Native-UCC/System/Core.dll')==='237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8','actual original compiled stock UT99 v432 binary source');
$ck(hash_file('sha256','C:/Temp/UnrealDB/O06/Native-227k/System/Core.dll')==='c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194','actual original compiled Unreal 227k core is distinct v69 source family');
$baseline=(string)file_get_contents('C:/Temp/UnrealDB/O06/Native-227k/System/G3Array227Runtime.u');
$ck(hash('sha256',$baseline)==='34a00b4f5bdf78dd56ef62a79f62684b6159fb0ecfc43e630eaa08c7ee09d8f7','actual original 227k native saved fixture restored');
foreach([['CountThree',3,'6c993b943a76a2729cf525abe58f79f5eaf685d31e764012d965485dc461c94c','247281e85e55776564dbd37b7c6d12ef6479407978af868b7b18951f57dc6018'],
 ['CountHuge',63,'14cb3bc6f680ed736d84a1ead059b45b6bfccbf3cc45ddcb96c2880a4a47e068','16e4a52a9e99fe7b5d6f5d125ebffc654001fa82ee9b439dce1684359be33cf3']] as [$id,$val,$h1,$h2]){
 $f=$e.'20261030-G3-COUNT-C227k-original-'.$id.'.u';$l=$e.'20261030-G3-COUNT-C227k-original-'.$id.'.log';
 $pin($f,$h1);$pin($l,$h2);
 $mut=(string)file_get_contents($f);$delta=[];
 if(strlen($mut)===strlen($baseline))for($i=0;$i<strlen($mut);$i++)if($mut[$i]!==$baseline[$i])$delta[]=$i;
 $ck($delta===[234]&&ord($mut[234])===$val,'one original C227k SavePackage dynamic array compact count mutated '.$id);
 $log=$native($l);$ck(str_contains($log,'Bad name index 6248/12')&&str_contains($log,'LinkerLoad 263)')
   &&str_contains($log,'bad=1'),'original 2026 227k compiled native array count rejects and true FArchive Tell263 '.$id);
 // Import/class schemas must resolve from the actual original native System
 // directory. SHA-verify the mutable location against immutable evidence.
 $runtime='C:/Temp/UnrealDB/O06/Native-227k/System/G3Array227Runtime'.$id.'.u';
 $ck(hash_file('sha256',$runtime)===$h1,'runtime fixture matches sealed immutable original '.$id);
 $p=new P($runtime);$got=null;try{T::inspect($runtime,$p,0,'unreal-v227k');}catch(Throwable $ex){$got=$ex->getMessage();}
 $ck($got==='UE1 v227 ULinkerLoad<<FName invalid name index 6248 at archive offset 263','reader 227k matching native source error and physical Tell263 '.$id);
}
$u=$e.'20261030-G3-COUNT-C227k-original-SignedUnicode.log';
$pin($u,'4fcda84026672f2adcefe6d3b9861c9d91c42499da414241c9352aa8d77f90a2');
$utf=$native($u);
$ck(str_contains($utf,'label=unicode_Omega')&&str_contains($utf,'originalWords=03A9,0000, loadedWords=03A9,0000')&&str_contains($utf,'archivedHex=82A9030000'),'original compiled C227k signed UTF16 count decoding native loaded Omega matches source codec');
$ck(str_contains($utf,'label=nonBMP_surrogate_pair')&&str_contains($utf,'loadedWords=D83D,DE80,0000'),'original compiled C227k signed UTF16 surrogate load preserved');
$ck(str_contains((string)file_get_contents($sourceClass),'Ar << Dependencies << PackageImports;'),'original C98 distinct array vs FName count ownership');
$ck(hash_file('sha256',$parent)==='766e4654a9985cd241307fdf78f496f890fcde3706799f872b41c94cb97cbddf','original C98 mutated executable fixture restored on DEV');
$out=['ok'=>$errors===[],'checks'=>$checks,'errors'=>$errors,
 'frozen_T068'=>$errors===[]?'PASS':'BLOCKED',
 'C98'=>'May1998 original compiled TArray dependency, name TArray PackageImports; native true Tell388/363',
 'UT99_v432'=>'previous sealed original compiled 38/38 signed negative and zero true Tell259 plus original count31 physical EOF',
 'Unreal_227k'=>'prior original compiled one-byte count3/63 false FName6248 at Tell263, signed UTF16 native values and original 227k negative allocator UB excluded',
 'negative_cpp_allocator_UB'=>'excluded; reject safely without inventing native FArchive Tell',
 'original_native_executions_by_this_verifier'=>0,
 'global_G3_accepted'=>false];
echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
