<?php
/** Frozen G3 T064: original compiled C98 (1998), UT99 v432, Unreal 227k
 * native source-selected tagged-property type, array index, terminator errors.
 * All actual original x86 Core.dll test runs are already SHA-pinned;
 * this acceptance does not run any original native binaries or other T IDs.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as R;
$root=dirname(__DIR__,2);$dir=$root.'/catalog/compliance/evidence/o06/';
$checks=0;$errors=[];$assert=static function(bool $ok,string $label)use(&$checks,&$errors){++$checks;if(!$ok)$errors[]=$label;};
$readNativeLog=static function(string $file):string {
    $bytes=(string)file_get_contents($file);
    // Windows PowerShell 5.1 *> emits UTF-16LE, while Editor.log is ANSI.
    // Keep SHA checks on RAW bytes; decode solely for faithful native diagnostics.
    return str_starts_with($bytes,"\xFF\xFE")
        ?(string)iconv('UTF-16LE','UTF-8',substr($bytes,2)):$bytes;
};
$cases=[
 ['v432-type15','36dcd6a445cdb61ea04280a8d805192dd18ba4d6c5cf9733457988169022b667','1784c75c5fa9cb480d56c792baa65fe6bd0f8e7d90530fee7ffce01769aa03e7',230,95,null],
 ['v432-array21','8d397d8daF5d9c78cdbc523ce1fabfce50e3dda2d0e328f4d3a809caf28de424','36dfd8d14b67691aa3144471d03c549f336051db7033c09f95a3e3ed88ba7719',230,221,-5242879],
 ['v432-missing-terminator-knownname','c2988d60391274fc0752c6cd6ab744a672467b6cbb212a1571717e4bcf013620','37a864b839d4618451540106f90e2676fa0105c338f2369fde381dfae57f985c',254,0,-671088638]
];
$basePath='C:/Temp/UnrealDB/O06/Native-UCC/System/G5DirectStringPayload.u';
$base=(string)file_get_contents($basePath);
$assert(hash('sha256',$base)==='e494caf1c9eb86b5fb2fcf4f7af1e0e91c5b5b214066c670ef4917dc7a2cd933','v432 actual native-generated original source fixture restored');
$assert(hash_file('sha256','C:/Temp/UnrealDB/O06/Native-UCC/System/Core.dll')==='237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8','original STOCK v432 Core.dll');
foreach($cases as [$id,$fixtureSha,$nativeSha,$byteAt,$newByte,$badName]){
 $p=$dir.'20261031-G3-T064-'.$id.'.u';$n=$dir.'20261031-G3-T064-'.$id.'-native.log';
 $assert(is_file($p)&&hash_file('sha256',$p)===strtolower($fixtureSha),'v432 native original fixture SHA '.$id);
 $assert(is_file($n)&&hash_file('sha256',$n)===$nativeSha,'v432 original native output SHA '.$id);
 $bytes=(string)file_get_contents($p);$delta=[];
 if(strlen($bytes)===strlen($base))for($i=0;$i<strlen($bytes);$i++)if($base[$i]!==$bytes[$i])$delta[]=$i;
 $assert(strlen($bytes)===283&&$delta===[$byteAt]&&ord($bytes[$byteAt])===$newByte,'exactly one genuine original v432 native source archive byte changed '.$id);
 $pkg=new P($p);$h=$pkg->getHeader();
 $assert($h['version']===69&&$h['licensee']===0&&count($pkg->getNames())===9,'native originally version69 stock UT99 source lineage '.$id);
 $text=$readNativeLog($n);$err=null;$read=null;
 try{$read=R::inspect($p,$pkg,0,'ut99-v432');}catch(Throwable $e){$err=$e->getMessage();}
 if($badName===null){
  $assert($err===null&&($read['tags'][0]['type_id']??0)===15
    &&($read['tags'][0]['value_kind']??null)==='opaque','v432 unknown type warns/skips opaque, no invented native values');
  $same=$readNativeLog($dir.'20261031-G3-T064-v432-type15-same-package-native.log');
  $assert(hash_file('sha256',$dir.'20261031-G3-T064-v432-type15-same-package-native.log')==='54c3ee2ed9d225740b4422384e698485f26fb68286e0bc095ef8e9e2be37f5bb'
     &&str_contains($same,'G5_NATIVE_OBJECT_COUNT 1')&&str_contains($same,'native_error=0'),
     'real v432 native loaded actual G5DirectStringActor with malformed type and no fatal error');
 }else{
  $expect='UE1 v432 ULinkerLoad<<FName invalid name index '.$badName.' at archive offset 262';
  $assert($err===$expect,'native v432 exact bad name index and physical Tell262 '.$id.': '.($err??'none'));
  $assert(str_contains($text,'Bad name index '.$badName.'/9')
    &&str_contains($text,'LinkerLoad0 262))')&&str_contains($text,'native_error=1'),
    'native original stock compiled ULinkerLoad rejected malformed default index at Tell262 '.$id);
 }
}
$orig='C:/Temp/UnrealDB/O06/Native-227k/System/G3Array227Runtime.u';
$base=(string)file_get_contents($orig);
$assert(hash('sha256',$base)==='34a00b4f5bdf78dd56ef62a79f62684b6159fb0ecfc43e630eaa08c7ee09d8f7'
  &&hash_file('sha256','C:/Temp/UnrealDB/O06/Native-227k/System/Core.dll')==='c391cade201acea9dd4e6c9f19b752ab99d338bf9dafb533920c4701e78ca194','actual original compiled 2026 Unreal227k native source and native SavePackage input SHA');
$c227=[
 ['TagType','6b309e21be79f61f024498b620f7aa9564bf693e0f4bf1ce1c7c43c6899e962d','0047902c15710b3ad273c51e0b8cc60dd771c3e563de989bfeb4797dd178bd2c',232,95,null],
 ['ArrayIndex','e2ab5f944fad9a0f2f18abeabd52b137855722e56c2c69ec9c4a032ae5f6aab3','11e25e721a70c42ce97dec9b7cd6d8a9a59893fe19b2b93fc3a1e7300e156cb0',232,217,1497],
 ['Terminator','5b80a0c9ccc52c760067ea92b6f65a7cce01271d9c6f29c6e4bc7113632b71e9','89781b1955a8d081bf43064e8805f5c80d5841a96e7f6872bfa3bd8d6b465e55',279,63,63]
];
foreach($c227 as [$id,$fixtureSha,$nativeSha,$offset,$byte,$bad]){
 $prefix='20261030-G3-B08-T064-original-C227k-'.$id;
 $path=$dir.$prefix.'.u';$log=$dir.$prefix.'.log';
 $assert(is_file($path)&&hash_file('sha256',$path)===$fixtureSha,'genuine 227k native compiled input hash '.$id);
 $assert(is_file($log)&&hash_file('sha256',$log)===$nativeSha,'genuine 227k native compiled output SHA '.$id);
 $raw=(string)file_get_contents($path);$delta=[];
 if(strlen($raw)===strlen($base))for($i=0;$i<strlen($raw);$i++)if($raw[$i]!==$base[$i])$delta[]=$i;
 $assert(strlen($raw)===308&&$delta===[$offset]&&ord($raw[$offset])===$byte,'actual 2026 compiled native SavePackage mutated exactly one byte '.$id);
 $p=new P($path);$h=$p->getHeader();$assert($h['version']===69&&$h['licensee']===227,'Unreal 227k true lineage distinct from v432 with same numeric v69');
 $native=$readNativeLog($log);$got=null;$r=null;
 try{$r=R::inspect($path,$p,0,'unreal-v227k');}catch(Throwable $e){$got=$e->getMessage();}
 if($bad===null){
  $assert(($r['tags'][0]['type_id']??null)===15&&($r['tags'][0]['value_kind']??null)==='opaque'
     &&$got===null,'actual 227k native type mismatch source reader recovers without fabricated loaded value');
  $assert(str_contains($native,'runtime=')&&str_contains($native,'bad=0')
    &&str_contains($native,'G3_B06_227_RELOADED_COUNT 1'),'real 227k original compiled LOAD of tagged invalid type succeeded');
 }else{
  $tell=$id==='ArrayIndex'?256:280;
  $assert($got==='UE1 v227 ULinkerLoad<<FName invalid name index '.$bad.' at archive offset '.$tell,
       'real 227k malformed FName target and actual physical Tell '.$id.':'.($got??'none'));
  $assert(str_contains($native,'Bad name index '.$bad.'/12')
     &&str_contains($native,'LinkerLoad '.$tell.')')&&str_contains($native,'bad=1'),
     'source 227k original compiled native failure exact FArchive Tell '.$tell.' '.$id);
 }
}
$command=escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/catalog/bin/verify-ue1-o06-g3-t064-original-c98-tag-failures.php');
$lines=[];$rc=1;exec($command,$lines,$rc);
$partial=json_decode(implode("\n",$lines),true);
$assert($rc===0&&($partial['ok']??false)===true&&($partial['checks']??null)===24
  &&($partial['fixed_T064']??null)==='PARTIAL_C98_ONLY_NOT_FULL_PASS','reuse one existing original C98 24/24 completed native checks, no compiled rerun');
$result=['ok'=>$errors===[],'checks'=>$checks,'errors'=>$errors,
 'full_fixed_T064'=>$errors===[]?'PASS':'BLOCKED',
 'original_compiled_source_families'=>['C98-1998-version61-licensee0','UT99-stock-v432-version69-licensee0','Unreal-original-227k-version69-licensee227'],
 'C98_preaccepted_checks'=>24,
 'new_v432_compiled_native_cases'=>3,'previous_C227k_compiled_native_cases_reused'=>3,
 'original_native_engine_reexecutions_by_verifier'=>0,
 'no_fabricated_default_values'=>true,'original_binary_fixtures_sha_pinned'=>true,
 'global_G3_complete'=>false];
echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
