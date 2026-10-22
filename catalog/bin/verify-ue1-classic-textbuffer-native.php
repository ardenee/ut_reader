#!/usr/bin/env php
<?php
/**
 * Genuine UE1 layout (synthetic packages), source-defined classic UTextBuffer native tail.
 * Epic v1.200 Core/Src/UnObj.cpp:3033-3038, Inc/UnTemplate.h:416-418;
 * UT99 v400 Core/Src/UnObj.cpp:3588-3593, Src/UnMisc.cpp:173-198.
 */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tag;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;

$compact=static function(int $signed):string {
    $negative=$signed<0;
    $v=abs($signed);
    $head=($v&63)|($negative?128:0);
    $v>>=6;
    if($v===0){return chr($head);}
    $out=chr($head|64);
    do {
        $next=$v&127;$v>>=7;
        $out.=chr($next|($v!==0?128:0));
    }while($v!==0);
    return $out;
};
$native=static function(int $pos,int $top,int $length,string $bytes,string $extension='')use($compact):string {
    return pack('V2',$pos&0xffffffff,$top&0xffffffff).$compact($length).$bytes.$extension;
};
$make=static function(int $version,string $tail)use($compact):string {
    $names=['None','TextBuffer','ScriptText','Core','Class','Package'];
    $hdrSize=$version>=68?56:44;
    $nameBytes='';
    foreach($names as $n){
        $nameBytes.=($version>=64?$compact(strlen($n)+1):'').$n."\0".pack('V',0);
    }
    $import=$compact(3).$compact(4).pack('V',0).$compact(1);
    // Legacy UObject::Serialize pre-57/58 also writes StateName / GroupName.
    $object="\x00".($version<57?"\x00":"").($version<58?"\x00":"").$tail;
    $nameOffset=$hdrSize;
    $importOffset=$nameOffset+strlen($nameBytes);
    $exportOffset=$importOffset+strlen($import);
    $payloadOffset=512;
    $export=$compact(-1).$compact(0).pack('V',0).$compact(2).pack('V',0)
           .$compact(strlen($object)).$compact($payloadOffset);
    $head=pack('V9',0x9e2a83c1,$version,0,count($names),$nameOffset,1,$exportOffset,1,$importOffset);
    $head.=$version>=68?str_repeat("\0",16).pack('V',0):pack('V2',0,0);
    $tables=$head.$nameBytes.$import.$export;
    if(strlen($tables)>$payloadOffset){throw new RuntimeException('Test package tables overlap payload');}
    return $tables.str_repeat("\0",$payloadOffset-strlen($tables)).$object;
};
$cases=[];$errors=[];
$check=static function(string $label,bool $ok)use(&$cases,&$errors):void {
    $cases[$label]=$ok;
    if(!$ok){$errors[]=$label;}
};
$paths=[];
$inspect=static function(int $version,string $body)use($make,&$paths):array {
    $path=tempnam(sys_get_temp_dir(),'ue1g4tb');
    if($path===false){throw new RuntimeException('Cannot make fixture');}
    $paths[]=$path;
    file_put_contents($path,$make($version,$body));
    $pkg=new Package($path);
    if($pkg->validatePackage()!==[]){throw new RuntimeException('Fixture not a valid UE1 package: '.json_encode($pkg->validatePackage()));}
    $tag=Tag::inspect($path,$pkg,0);
    if(($tag['status']??'')!=='tag_envelope_decoded'){
        throw new RuntimeException('UE1 property stream did not decode');
    }
    return [Native::inspect($path,$pkg,0,$tag),$tag,$path];
};
$mustFail=static function(int $ver,string $body)use($inspect):bool {
    try{$inspect($ver,$body);return false;}
    catch(RuntimeException){return true;}
};
try {
    [$a,$tag,$path]=$inspect(50,$native(3,-2,7,"Alpha\n\0"));
    $check('v50_ansi_full_decode',($a['status']??'')==='native_textbuffer_record_complete'
        && $a['position']===3 && $a['top']===-2 && $a['text']==="Alpha\n"
        && $a['text_encoding']==='Windows-1252' && $a['text_character_count']===6);
    $check('v50_no_tag_terminator_mistaken_as_native',($tag['native_tail_bytes']??null)===16
        && $a['text_raw_offset']===524 && $a['extension_bytes']===0);
    [$b]=$inspect(69,$native(-19,42,5,"Caf\xe9\0"));
    $check('v69_ansi_windows_1252',($b['status']??'')==='native_textbuffer_record_complete'
        && $b['text']==="Caf\u{00e9}" && $b['position']===-19 && $b['top']===42);
    $wide=iconv('UTF-8','UTF-16LE',"\u{03a9}X\0");
    if(!is_string($wide)){throw new RuntimeException('Fixture iconv missing');}
    [$c]=$inspect(69,$native(7,8,-3,$wide));
    $check('v69_wide_text',($c['status']??'')==='native_textbuffer_record_complete'
        && $c['text']==="\u{03a9}X" && $c['text_encoding']==='UTF-16LE' && $c['text_raw_bytes']===6);
    [$d]=$inspect(50,$native(0,0,0,''));
    $check('v50_empty_text',($d['text']??null)===''&&$d['text_raw_bytes']===0);
    [$e]=$inspect(69,$native(1,2,2,"Z\0","\xff"));
    $check('extension_is_not_complete',($e['status']??'')==='native_textbuffer_has_extension'
        && $e['extension_bytes']===1);
    $check('v50_wide_version_rejected',$mustFail(50,$native(0,0,-2,"X\0\0\0")));
    $check('v50_count_overflow_rejected',$mustFail(50,$native(0,0,17,"X\0")));
    $check('v69_wide_count_overflow_rejected',$mustFail(69,$native(0,0,-9,"\0\0")));
    $check('v50_missing_ansi_nul_rejected',$mustFail(50,$native(0,0,2,"AB")));
    $check('v69_missing_wide_nul_rejected',$mustFail(69,$native(0,0,-2,"\x41\x00\x42\x00")));
    $check('v69_invalid_utf16_surrogate_rejected',$mustFail(69,$native(0,0,-2,"\x00\xd8\0\0")));
    $check('v50_truncated_fields_rejected',$mustFail(50,"\0\0\0"));
}finally {
    foreach($paths as $p){@unlink($p);}
}
$ok=$errors===[];
echo json_encode(['ok'=>$ok,'passed'=>count($cases)-count($errors),'total'=>count($cases),
    'failed'=>$errors,'cases'=>$cases,
    'epic_sources'=>['v1.200 Core/Src/UnObj.cpp:3033-3038',
        'v1.200 Core/Inc/UnTemplate.h:416-418',
        'UT99 v400 Core/Src/UnObj.cpp:3588-3593',
        'UT99 v400 Core/Src/UnMisc.cpp:173-198']],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
