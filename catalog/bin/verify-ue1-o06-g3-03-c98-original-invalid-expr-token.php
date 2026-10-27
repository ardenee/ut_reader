#!/usr/bin/env php
<?php
/** Original 1998 C98 UStruct invalid opcode: real shipped Core.dll LOAD and Tell. */
declare(strict_types=1);
require_once dirname(__DIR__).'/bootstrap/autoload.php';
require_once dirname(__DIR__).'/src/Infrastructure/Readers/CatalogLegacyPackageReader.php';
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1PackageReader as Package;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicTaggedPropertyReader as Tags;
use UnrealDb\Catalog\Infrastructure\Readers\CatalogUE1ClassicNativeObjectReader as Native;
$root=dirname(__DIR__,2);
$source='L:/Source/Games/Unreal/Unreal [v1.200] [1998-05-19]/Core/Src/UnClass.cpp';
$system='C:/Temp/UE1-G3-NativeOracle/C98-USA-Retail-CD/retail-source/System/';
$core=$system.'Core.u';
$log=$root.'/catalog/compliance/evidence/o06/20261027-G3-03-C98-ORIGINAL-BAD-EXPR10-CURSOR-CDB.log';
$command=$root.'/catalog/compliance/evidence/o06/20261027-G3-03-C98-ORIGINAL-BAD-EXPR10-CURSOR-COMMAND.cmd';
$checks=0;$bad=[];
$ck=static function(bool $ok,string $note) use (&$checks,&$bad):void {
    $checks++;if(!$ok)$bad[]=$note;
};
foreach ([
    [$source,'ea4b7304d6cfe45f35929b59a10cefe51e5ee132f3603fc43e3f0e3d97aa1291','original May19 source'],
    [$system.'Core.dll','9e2dbefe1d203da3c98dddef04e8ced66b6a346954fa395e35ef714a68e9eb92','shipped May17 Core.dll'],
    [$system.'Unreal.exe','d631c8af81d2bba50131254e9770aceb0e59ebd3df97f4cb8560c9c6835e7c30','shipped May17 Unreal.exe'],
    [$core,'7c94b2a68d8f6fc0bfb5264c9e9aad4ce35ccf12a8b8057d456dacd33d74140c','untouched May17 original Core.u'],
    [$log,'81ee43b30a4543baab0564135e8ee5402fe4561552bdc786d3b3d503a4b42ef1','actual CDB cursor evidence'],
    [$command,'8eb4f37588de50535fb6807f8f0f24040b399e18fc6d2801c310bd571a20f74d','CDB breakpoint commands'],
] as [$file,$hash,$label]) {
    $ck(is_file($file)&&hash_file('sha256',$file)===$hash,$label.' SHA');
}
$src=(string)file_get_contents($source);
$ck(str_contains($src,'appErrorf( "Bad expr token %02x", Expr );'),'original source default error');
$native=(string)file_get_contents($log);
$ck(str_contains($native,'G303_C98_ORIGINAL_BAD10_ARCHIVE_CURSOR'),'authentic compiled-error stop');
$ck(str_contains($native,'"Bad expr token %02x"'),'compiled appErrorf formatter');
$ck(preg_match('/(?m)^[0-9a-f]{8}\h+[0-9a-f]{8}\h+[0-9a-f]{8}\h+00000010\b/i',$native)===1,'actual x86 argument 0x10');
$ck(preg_match('/(?m)^[0-9a-f]{8}\h+00005eae\b/i',$native)===1,'actual compiled original FArchive Tell=24238');
$ck(str_contains($native,'Core!appErrorf'),'error raised by shipped native Core');
$pkg=new Package($core);
$ck($pkg->validatePackage()===[],'genuine original Core.u valid');
$ex=$pkg->getExports()[456]??[];
$ck(($ex['objectNameText']??'')==='EndState'
    &&($ex['serialOffset']??-1)===24218
    &&($ex['serialSize']??-1)===35,'original export456 EndState boundaries');
$orig=(string)file_get_contents($core);
$ck(strlen($orig)===31472&&ord($orig[24237])===0x14,'original EndState 0x14 token at physical 24237');
$positive=Native::inspect($core,$pkg,456,Tags::inspect($core,$pkg,456,'epic-ue1'),'epic-ue1');
$ck(($positive['script_archive']['status']??null)==='script_archive_expressions_decoded'
    &&($positive['script_archive']['source_memory_bytes_hex']??null)==='14000415',
    'unmodified genuine EndState script positive control');
$tempDir='C:/Temp/UnrealDB/O06';
if(!is_dir($tempDir)){mkdir($tempDir,0777,true);}
$temp=tempnam($tempDir,'C98BAD');
if($temp===false){throw new RuntimeException('Cannot create isolated one-byte fixture');}
try {
    foreach([0x10,0x11] as $token){
        $bytes=$orig;$bytes[24237]=chr($token);
        file_put_contents($temp,$bytes);
        $ck(strlen($bytes)===strlen($orig)
            &&substr($orig,0,24237)===substr($bytes,0,24237)
            &&substr($orig,24238)===substr($bytes,24238),
            'isolated original one-byte mutation only '.dechex($token));
        if($token===0x10){
            $ck(hash('sha256',$bytes)==='df4747491bab186f0601a09ec7b6dc38aa05b7d76393c6fc6295880b318c3b0e',
                'CDB original source mutated input exact SHA');
        }
        $mutantPkg=new Package($temp);
        $ck($mutantPkg->validatePackage()===[],'mutant retains valid package envelope');
        $caught=null;
        try{
            Native::inspect($temp,$mutantPkg,456,
                Tags::inspect($temp,$mutantPkg,456,'epic-ue1'),'epic-ue1');
        }catch(RuntimeException $e){$caught=$e->getMessage();}
        $ck($caught!==null
            &&str_contains($caught,sprintf('Bad expr token %02x',$token))
            &&str_contains($caught,'archive cursor 24238'),
            'source-invalid opcode '.dechex($token).' refuses LOAD at exact original C98 cursor');
    }
}finally{unlink($temp);}
$ok=$bad===[];
echo json_encode(['ok'=>$ok,'checks'=>$checks,'failed'=>$bad,
    'profile'=>'authentic original May17 1998 C98 and May19 v1.200 source',
    'native_error'=>'Bad expr token 10','native_archive_cursor'=>24238,
    'original_reader_error'=>'source-invalid opcode throws at same physical archive cursor',
    'previously_sealed_native_proofs_reexecuted'=>0,
    'G3_03_full'=>'OPEN'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
exit($ok?0:1);
