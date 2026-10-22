#!/usr/bin/env php
<?php
/** Epic v1.200 and UT99 v400 UField::Serialize + UEnum::Serialize source-typed packages. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tag;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$compact=static function(int $n):string {
    $negative=$n<0;$value=abs($n);$first=($value&63)|($negative?128:0);
    $value>>=6;if($value===0){return chr($first);}
    $out=chr($first|64);
    do {$b=$value&127;$value>>=7;$out.=chr($b|($value!==0?128:0));}while($value!==0);
    return $out;
};
$build=static function(int $version,string $body)use($compact):string {
    $names=['None','Enum','EColor','Core','Class','Package','Red','Green'];
    $summarySize=$version>=68?56:44;
    $nameTable='';
    foreach($names as $n){
        $nameTable.=($version>=64?$compact(strlen($n)+1):'').$n."\0".pack('V',0);
    }
    $import=$compact(3).$compact(4).pack('V',0).$compact(1);
    $payload="\x00".($version<57?"\x00":"").($version<58?"\x00":"").$body;
    $namesOffset=$summarySize;$importOffset=$namesOffset+strlen($nameTable);
    $exportOffset=$importOffset+strlen($import);
    $export=$compact(-1).$compact(0).pack('V',0).$compact(2).pack('V',0)
        .$compact(strlen($payload)).$compact(512);
    $head=pack('V9',0x9e2a83c1,$version,0,count($names),$namesOffset,1,$exportOffset,1,$importOffset);
    $head.=$version>=68?str_repeat("\0",16).pack('V',0):pack('V2',0,0);
    $data=$head.$nameTable.$import.$export;
    if(strlen($data)>512){throw new RuntimeException('Fixture package overlap');}
    return $data.str_repeat("\0",512-strlen($data)).$payload;
};
$paths=[];$tests=[];$failed=[];
$check=static function(string $label,bool $ok)use(&$tests,&$failed):void {
    $tests[$label]=$ok;if(!$ok){$failed[]=$label;}
};
$parse=static function(int $version,string $body)use($build,&$paths):array {
    $path=tempnam(sys_get_temp_dir(),'uenum-g3');
    if($path===false){throw new RuntimeException('No temp package');}
    $paths[]=$path;
    file_put_contents($path,$build($version,$body));
    $pkg=new Package($path);
    if($pkg->validatePackage()!==[]){throw new RuntimeException('UE1 package layout invalid');}
    $tags=Tag::inspect($path,$pkg,0);
    if(($tags['status']??'')!=='tag_envelope_decoded'){
        throw new RuntimeException('UEnum inherited UObject property envelope unavailable');
    }
    return [Native::inspect($path,$pkg,0,$tags),$tags,$pkg];
};
$bad=static function(int $ver,string $body)use($parse):bool{
    try{$parse($ver,$body);return false;}catch(RuntimeException){return true;}
};
try{
    [$a,$tags]=$parse(50,$compact(0).$compact(0).$compact(2).$compact(6).$compact(7));
    $check('v50_super_next_and_two_names',($a['status']??null)==='native_enum_record_complete'
        && $a['super_field_reference']===0&&$a['next_field_reference']===0
        && $a['name_count']===2&&array_column($a['names'],'name')===['Red','Green']);
    $check('v50_legacy_UObject_names_before_UField',($tags['legacy_state_name']['text']??null)==='None'
        &&($tags['legacy_group_name']['text']??null)==='None'
        &&($tags['native_tail_bytes']??null)===5);
    [$b]=$parse(69,$compact(0).$compact(0).$compact(1).$compact(7));
    $check('v69_single_name',($b['status']??null)==='native_enum_record_complete'
        && $b['names'][0]['name_index']===7);
    [$c]=$parse(69,$compact(-1).$compact(1).$compact(0));
    $check('object_refs_retained_but_not_linked',$c['super_field_reference']===-1
        &&$c['next_field_reference']===1&&$c['name_count']===0);
    [$d]=$parse(69,$compact(0).$compact(0).$compact(0)."\xff");
    $check('extension_does_not_count_as_complete',($d['status']??null)==='native_enum_has_extension'
        &&$d['extension_bytes']===1);
    $check('out_of_range_negative_object_ref',$bad(69,$compact(-2).$compact(0).$compact(0)));
    $check('out_of_range_positive_object_ref',$bad(69,$compact(0).$compact(2).$compact(0)));
    $check('negative_array_count_rejected',$bad(69,$compact(0).$compact(0).$compact(-1)));
    $check('oversized_array_count_rejected',$bad(69,$compact(0).$compact(0).$compact(12).$compact(6)));
    $check('negative_name_index_rejected',$bad(50,$compact(0).$compact(0).$compact(1).$compact(-1)));
    $check('out_of_range_name_index_rejected',$bad(69,$compact(0).$compact(0).$compact(1).$compact(24)));
    $check('truncated_array_data_rejected',$bad(69,$compact(0).$compact(0).$compact(2).$compact(6)));
    $check('truncated_UField_refs_rejected',$bad(50,$compact(0)));
}finally{foreach($paths as $path){@unlink($path);}}
$ok=$failed===[];
echo json_encode(['ok'=>$ok,'passed'=>count($tests)-count($failed),'total'=>count($tests),
    'cases'=>$tests,'failures'=>$failed,'source'=>[
      'v1.200 Core/Src/UnClass.cpp:236-245','v1.200 Core/Src/UnObj.cpp:3076-3082',
      'UT99 v400 Core/Src/UnClass.cpp:239-248','UT99 v400 Core/Src/UnObj.cpp:3604-3610'
    ]],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
