<?php
/** Distinct original UT99 469f compiled UArrayProperty v69/v70 byte branch; v70 is CONTROLLED mode, not genuine file. */
declare(strict_types=1);
$repo=dirname(__DIR__,2);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicPropertyNativeReader as Property;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicSourceFamily as Source;
$m=json_decode((string)file_get_contents(
    $repo.'/catalog/compliance/evidence/o06/20261101-G3-T073-469f-original-completed-version70-branch-manifest.json'
),true,512,JSON_THROW_ON_ERROR);
$checks=0;$errors=[];
$check=static function(bool $ok,string $label)use(&$checks,&$errors):void{
    $checks++;if(!$ok)$errors[]=$label;
};
foreach($m['pins'] as $name=>$pin){
    $path=$pin['location']==='repo'?$repo.'/'.$pin['path']:$pin['path'];
    $present=is_file($path);
    $size=$present?filesize($path):-1;
    $sha=$present?hash_file('sha256',$path):null;
    $exact=$sha===$pin['sha256']&&$size===$pin['bytes'];
    $canonical=isset($pin['canonical_git_blob_sha256'])
        &&$sha===$pin['canonical_git_blob_sha256']
        &&$size===$pin['canonical_git_blob_bytes'];
    $check($present&&($exact||$canonical),'original source/native SHA '.$name);
}
$source=(string)file_get_contents($repo.'/'.$m['pins']['469f_original_x86_method']['path']);
$check(str_contains($source,'Core!UArrayProperty::Serialize:'),
    'actual original compiled 469f method disassembled');
$check(str_contains($source,'cmp     dword ptr [esi+4],45h')
    &&str_contains($source,'lea     eax,[edi+64h]'),
    'original 469f exact >69 archive-version and +0x64 conditional member');
$native=(string)file_get_contents($repo.'/'.$m['pins']['469f_controlled_original_native_v70_read_log']['path']);
foreach([
 'T073_469F_ALTERNATE_VERSION_BEFORE id=5627 archiveVersion=69 isLoading=1 isSaving=0 isError=0',
 'T073_469F_ORIGINAL_EXTRA_READ_START id=5627 archiveVer=70 memberBefore=0',
 'T073_469F_ORIGINAL_EXTRA_READ_END id=5627 archiveVer=70 memberAfter=1342191717',
] as $line){
    $check(substr_count($native,$line)===1,'controlled original CDB actual branch '.$line);
}
$file=$m['pins']['original_469f_genuine_Engine_u']['path'];
$p=new Package($file);$idx=5627;$exp=$p->getExports()[$idx];
$check(($p->getHeader()['version']??null)===69
    &&($exp['objectNameText']??null)==='Touching','real stock 469f v69 Touching export');
$tag=Tags::inspect($file,$p,$idx,Source::UT99_V469F);
$live=Native::inspect($file,$p,$idx,$tag,Source::UT99_V469F);
$start=$exp['serialOffset']+$exp['serialSize']-$tag['native_tail_bytes'];
$length=$tag['native_tail_bytes'];
$fp=fopen($file,'rb');fseek($fp,$start);$base=fread($fp,$length);fclose($fp);
$check($length===15&&strlen($base)===15,'real physical v69 ArrayProperty precisely 15 native bytes');
$check($live['status']==='native_property_record_complete'
    &&$live['subclass_fields']['inner_reference']===5627
    &&$live['extension_bytes']===0,'real original v69 compiled and current reader archive identity');

$readAs=static function(string $input,int $version,string $profile)use($p):array{
    $position=0;$limit=strlen($input);
    $read=static function(int $n)use(&$position,$limit,$input):string{
        if($n<0||$position+$n>$limit){throw new RuntimeException('Original v70 simulated native archive EOF');}
        $out=substr($input,$position,$n);$position+=$n;return $out;
    };
    $byte=static fn():int=>ord($read(1));
    $compact=static function()use($byte):int{
        $first=$byte();$negative=($first&128)!==0;$value=$first&63;
        $more=($first&64)!==0;$shift=6;$n=1;
        while($more){
            if(++$n>5){throw new RuntimeException('Native UE1 compact length overflow');}
            $chunk=$byte();$value|=($chunk&127)<<$shift;
            $more=($chunk&128)!==0;$shift+=7;
        }
        return $negative?-$value:$value;
    };
    $u32=static fn():int=>(int)unpack('V',$read(4))[1];
    $result=Property::inspect('arrayproperty',$p,$version,$read,$compact,$u32,
        $position,$limit,$profile);
    return ['record'=>$result,'cursor'=>$position,'limit'=>$limit];
};
$normal=$readAs($base,69,Source::UT99_V469F);
$check($normal['cursor']===15&&$normal['record']['status']==='native_property_record_complete',
    'actual v69 saved archive fully consumed with no extra member');
$check(!array_key_exists('static_array_dim',$normal['record']['subclass_fields'])
    &&$normal['record']['subclass_fields']['inner_reference']===5627,
    'actual 469f v69 does not invent runtime-only static dimension');
$fixture=$base.pack('V',0x01020304);
$virtual=$readAs($fixture,70,Source::UT99_V469F);
$check($virtual['cursor']===19&&$virtual['record']['status']==='native_property_record_complete',
    'controlled 469f v70 19-byte exact native archive cursor');
$check(($virtual['record']['subclass_fields']['static_array_dim']??null)===0x01020304
    &&($virtual['record']['subclass_fields']['inner_reference']??null)===5627,
    'controlled 469f v70 additional signed 32bit member from true original 15-byte prefix');
$check(($virtual['record']['subclass_fields']['static_array_dim_archived_in_469f_above_v69']??null)===true,
    '469f compiled v70 source gate retained');
$other=$readAs($fixture,70,Source::UT99_V432);
$check($other['cursor']===15&&$other['record']['status']==='native_property_has_extension',
    'original v432 compiled never reads extra 469f-specific bytes');
$check(!isset($other['record']['subclass_fields']['static_array_dim']),
    'never silently equate v432 to later 469f');
$minus=$readAs($base.pack('V',0xFFFFFFFF),70,Source::UT99_V469F);
$check(($minus['record']['subclass_fields']['static_array_dim']??null)===-1
    &&$minus['cursor']===19,'signed value follows original 32-bit member semantics');
try{
    $readAs(substr($fixture,0,18),70,Source::UT99_V469F);
    $check(false,'v70 truncated missing final archive byte rejected');
}catch(RuntimeException $e){
    $check(str_contains($e->getMessage(),'native archive EOF'),'v70 truncated exact 4-byte field rejects');
}
$check($m['controlled_v70_not_genuine_physical_archive']===true
    &&$m['full_T073_pass']===false,'no false original physical v70 or full aggregate acceptance');
echo json_encode(['ok'=>$errors===[],'checks'=>$checks,'errors'=>$errors,
    'original_genuine_v69_native_bytes'=>15,'controlled_original_469f_70_mode_consumed_bytes'=>19,
    'original_469f_compiled_read_completed'=>true,'full_T073_pass'=>false,
    'real_archive_v70_verified'=>false],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($errors===[]?0:1);
