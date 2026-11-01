<?php
declare(strict_types=1);
/** C98 source-correct <36 unknown object tag: four original compiled 1998 method oracles.
 * Controlled FArchive probes are METHOD dispatch evidence, not genuine v34 package loads.
 */
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tag;
$compact=static function(int $n):string{
    $neg=$n<0;$n=abs($n);$b=($n&63)|($neg?128:0);$n>>=6;
    if($n===0)return chr($b);
    $raw=chr($b|64);
    do{$v=$n&127;$n>>=7;$raw.=chr($v|($n?128:0));}while($n);
    return $raw;
};
$build=static function(int $ver,string $tag)use($compact):string{
    $names=['None','Class','Example','Core','Friendly','System','Package','MyInt','Flag','Color','Vector'];
    $all='';
    foreach($names as $name)$all.=($ver>=64?$compact(strlen($name)+1):'').$name."\0".pack('V',0);
    $hdr=$ver>=68?56:44;
    $exportOffset=$hdr+strlen($all);
    $export=$compact(0).$compact(0).($ver<50?'':pack('V',0))
      .$compact(2).pack('V',0).$compact(strlen($tag)).$compact(512);
    $summary=pack('V9',0x9e2a83c1,$ver,0,count($names),$hdr,1,$exportOffset,0,$exportOffset+strlen($export));
    $summary.=$ver>=68?str_repeat("\0",16).pack('V',0):pack('V2',0,0);
    $table=$summary.$all.$export;
    if(strlen($table)>512)throw new RuntimeException('Unexpected C98 fixture header size');
    return $table.str_repeat("\0",512-strlen($table)).$tag;
};
$logPath='C:/Temp/UnrealDB/O06/G3-T065-original-C98-source-ABI-standalone/G3-T065-original-1998-C98-real-4-UStruct-tagged-archive-methods.log';
$log=(string)file_get_contents($logPath);
$expected=[
 [34,5,1,1,4],
 [35,5,1,1,4],
 [35,8,0,5,7],
 [36,5,0,5,7]
];
$checks=0;$errors=[];
$ck=static function(bool $yes,string $name)use(&$checks,&$errors):void{$checks++;if(!$yes)$errors[]=$name;};
$ck(hash('sha256',$log)==='46f5502faa1f392c95254b1154a742ee3d0a16239304bcae423e70795ddc1d37',
    'all four genuine compiled C98 method outputs SHA pinned');
foreach($expected as [$ver,$type,$refs,$bytes,$cursor]){
    $native="G3_T065_REAL_1998_NATIVE_METHOD_VER=$ver TYPE=$type REFS=$refs RAW=$bytes NAMES=2 CURSOR=$cursor";
    $ck(str_contains($log,$native),"original compiled method $ver/$type");
}
$ck(str_contains($log,'G3_T065_C98_ORIGINAL_NATIVE_UCLASS=1019E8F0'),'real original source class object pointer');
$ck(str_contains($log,'G3_T065_REAL_C98_METHOD_END'),'all native original method calls returned prior to host exit');
$dir=sys_get_temp_dir();$paths=[];
try{
    foreach([
      ['v34_unknown_object',34,5,false,4,1,'epic_skipped_unknown_object_reference'],
      ['v35_unknown_object',35,5,false,4,1,'epic_skipped_unknown_object_reference'],
      ['v35_unknown_class',35,8,false,4,4,'opaque'],
      ['v36_unknown_object',36,5,false,4,4,'opaque'],
      ['v35_unknown_object_size1',35,5,false,1,1,'epic_skipped_unknown_object_reference'],
      ['v35_declared_matching_object',35,5,true,4,4,'opaque'],
      ['v35_archive_only_legacy_preview',35,5,null,4,1,'v35_compact_object_reference'],
    ] as [$label,$ver,$type,$known,$declaredSize,$physical,$kind]){
        $info=($declaredSize===4?0x20:0)|$type;
        $value=($known===true||$type===8||$ver===36)?pack('V',0):$compact(0);
        $tag=$compact(7).chr($info).$value.$compact(0);
        $tmp=tempnam($dir,'g3c98');
        if(!is_string($tmp))throw new RuntimeException('No temporary file');
        $paths[]=$tmp;
        file_put_contents($tmp,$build($ver,$tag));
        $pkg=new Package($tmp);
        $errorsOnFile=$pkg->validatePackage();
        $ck($errorsOnFile===[],$label.' valid source-header fixture');
        $decl=$known===null?null:($known?[
            'myint'=>['kind'=>'objectproperty','array_dim'=>1,'property_flags'=>0]
        ]:[]);
        $res=Tag::inspectClassDefaults($tmp,$pkg,512,512+strlen($tag),$ver,'epic-ue1',$decl);
        $tagged=$res['tags'][0]??[];
        $ck(count($res['tags'])===1,$label.' exactly one archived tag');
        $ck(($tagged['on_disk_value_bytes']??-1)===$physical,
            $label.' exact original compiled branch consumed byte count');
        $ck(($tagged['value_kind']??'')===$kind,$label.' no invented original native loaded value');
        $ck(($res['tag_end_offset']??0)===512+strlen($tag),
            $label.' physical tag stream terminated exactly');
        if($known===false&&$ver<36&&$type===5)
            $ck(array_key_exists('value',$tagged)&&$tagged['value']===null
                &&($tagged['native_load_policy']??null)==='native_C98_lt36_unknown_object_reference_skip',
                $label.' original skipped object has no assigned native value');
    }
}finally{foreach($paths as $p)@unlink($p);}
$source=PathNothing();
function PathNothing():string{return 'L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Core/Src/UnClass.cpp';}
$original=(string)file_get_contents($source);
$ck(str_contains($original,'if( Ar.Ver()<36 && Tag.Type==NAME_ObjectProperty )'),
    'exact original Epic source condition matches original compiled method');
$compiledC98='C:/Temp/UnrealDB/O06/Native-C98-1998-G304/System/Core.dll';
$ck(hash_file('sha256',$compiledC98)==='9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92',
    'original C98 stock Core.dll canonical bytes unchanged');
echo json_encode(['ok'=>$errors===[],'checks'=>$checks,'errors'=>$errors,
 'four_original_compiled_C98_native_direct_method_controls'=>true,
 'original_native_process_status'=>'abnormal exit AFTER all four source method measurements',
 'full_T065_fixed_acceptance'=>'BLOCKED: remaining source methods/true v34 loaded package cursor not measured',
 'no_engine_binary_or_source_modified'=>true,
 'genuine_archive_fixture_origins'=>'controlled source-correct generated test streams, not retail v34'],JSON_PRETTY_PRINT).PHP_EOL;
exit($errors===[]?0:1);
