#!/usr/bin/env php
<?php
/** Original stock v432 compiled engine negative dynamic-array count parity.
 * Two independent one-byte mutations of a genuine compiled script package.
 * DOES NOT rerun native executables; actual original stdout pinned in scratch.
 * Fixed T068/T048 remain OPEN until other source-family oracles complete.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as P;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as T;
$root='C:/Temp/UnrealDB/O06/';
$src=$root.'Native-UCC/System/';
$dir=$root.'G3-FAST-T041-T042-T048-v432-native/';
$checks=0;$fail=[];$cases=[];
$ck=static function(bool $value,string $description)use(&$checks,&$fail):void{
    ++$checks;if(!$value)$fail[]=$description;
};
$known=[
 [$src.'Core.dll','237dbe38e5dd8c03370626c85cbcbca42f2431e09e175f842944390ede8b8fc8'],
 [$src.'G5ArrayPayload.u','2436d2545135a8ca02d5f1e71252daf3314be6e5b02c371a7db8ae5450e16ff3'],
 [$src.'G5-UObject-Native-Array-Load.exe','9a7a7472c501a5e8ab052b18710aa29b66ab76644977c1eb1f5e2f897f2d3ff0'],
 ['L:/Source/Games/UT99/Unreal Tournament [v1.400] [1999-11-30]/Core/Src/UnProp.cpp',
  '693194ef489989f1c2ffee8542cf4c2d8950d07ed0b041eff154531c5b097457']
];
foreach($known as [$p,$sha])$ck(is_file($p)&&hash_file('sha256',$p)===$sha,'Original compiled binary and Epic source SHA: '.basename($p));
$original=(string)file_get_contents($src.'G5ArrayPayload.u');
$ck(ord($original[254])===3,'original generated actual archive array count3 at byte254');
$source=(string)file_get_contents($known[3][0]);
foreach(['Ar << AR_INDEX(n);','((FArray*)Value)->Add( n, c );','for( INT i=0; i<n; i++ )'] as $needle)
    $ck(str_contains($source,$needle),'Epic UArrayProperty original source: '.$needle);
foreach([
 ['array_count_zero',0,'49e55215ec66e85faa3f2531ba3464b54316d239202a0906b54d8ac2440ed550',true],
 ['array_count_negative_one',0x81,'2e8eea811371a7b6e33425115f2fdac3aa3b4cca1ca9a808c1f8c68f221d387d',false]
] as [$name,$byte,$sha,$loadedArrayAllowed]){
    $system=$dir.$name.'/System/';
    $path=$system.'G5ArrayPayload.u';
    $raw=(string)file_get_contents($path);
    $ck(hash('sha256',$raw)===$sha&&strlen($raw)===strlen($original),
        'genuine stock source-specific one-byte-mutated package SHA '.$name);
    $restored=$raw;
    $ck(ord($restored[254])===$byte,$name.' one changed original compact array count byte');
    $restored[254]=$original[254];
    $ck($restored===$original,$name.' exactly one byte different from genuine generated stock v432 package');
    $ck(hash_file('sha256',$system.'Core.dll')===$known[0][1],
        $name.' original shipped v432 Core.dll unmodified');
    $ck(hash_file('sha256',$system.'G5-UObject-Native-Array-Load.exe')===$known[2][1],
        $name.' source-built executable identical to original baseline');
    $log=$dir.$name.'/original-full-system-native-output.log';
    $native=(string)file_get_contents($log);
    $ck(hash('sha256',$native)==='35b2417e2be32bc2b919a6758b73e733e4381b67821dc0d43355184fa202907d',
        $name.' authentic original compiled v432 process stdout byte SHA');
    foreach([
      'G5_NATIVE_DIRECT_PACKAGE_BEGIN editor=1 client=0 server=0',
      'Serial size mismatch: Got 8, Expected 29',
      'G5ArrayPayload.G5DynamicActor 259==259/308 251 29',
      'G5_NATIVE_DIRECT_PACKAGE_RESULT ptr=00000000 editor=1 native_error=1'
    ] as $part)$ck(str_contains($native,$part),$name.' original x86 compiled failure/cursor: '.$part);
    $p=new P($path);
    $ex=$p->getExports()[0];
    $ck($p->validatePackage()===[]&&$ex['objectNameText']==='G5DynamicActor'
        &&$ex['serialOffset']===251&&$ex['serialSize']===29,
        $name.' genuine original one-byte-mutant UE1 package and exact original export');
    $decoded=T::inspect($path,$p,0,'ut99-v432');
    $tag=$decoded['tags'][0]??[];
    $value=$tag['value']??[];
    $ck(($decoded['status']??'')==='tag_envelope_decoded'
       &&($decoded['tag_end_offset']??null)===259,
       $name.' original source-defined 8-byte tag termination cursor equal to compiled FArchive::Tell');
    $ck(($decoded['native_tail_bytes']??null)===21
       &&($tag['name']??null)==='Numbers'
       &&($value['element_count']??null)===($byte===0?0:-1),
       $name.' exact signed on-disk native AR_INDEX count and trailing bytes');
    $ck(($value['all_values_previewed']??false)===$loadedArrayAllowed,
        $name.' current reader does not fabricate a successfully loaded negative-sized array');
    if(!$loadedArrayAllowed)$ck(
       ($value['native_loaded_value_parity']??'')==='NOT_VERIFIED',
       $name.' original negative FArray::Add source-native risk not fabricated as loaded');
    $cases[]=['case'=>$name,'original_compiled_x86_error'=>'Serial size mismatch: Got 8, Expected 29',
       'native_true_physical_Tell'=>259,'reader_on_disk_tag_cursor'=>$decoded['tag_end_offset'],
       'original_native_rejected_object'=>true,
       'archive_only_count'=>$value['element_count'],
       'reader_claims_native_loaded_value'=>false,
       'original_engine_reexecuted_during_verifier'=>false];
}
$ck(count($cases)===2,'two independent original compiled negative/zero count cases');
$ok=$fail===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'failures'=>$fail,
 'T048'=>'PARTIAL_V432_ONLY','T068'=>'PARTIAL_V432_ONLY',
 'native_shipped_compiled_v432_cases_verified'=>2,'compiled_UE1_engines_reexecuted'=>0,
 'original_mutant_archive_source_byte_offset'=>254,'current_reader_sha256'=>hash_file('sha256',dirname(__DIR__).'/src/Infrastructure/Readers/CatalogUE1ClassicTaggedPropertyReader.php'),
 'cases'=>$cases],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
