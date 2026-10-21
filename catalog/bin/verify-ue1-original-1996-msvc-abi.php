#!/usr/bin/env php
<?php
/** 32-bit MSVC compile-time ABI proof for source-transcribed UE1 structs. */
declare(strict_types=1);
$root=dirname(__DIR__,2);
$source=$root.'/catalog/compliance/evidence/o06/ue1-1996-msvc-abi.cpp';
$compilers=glob('C:/Program Files/Microsoft Visual Studio/*/Community/VC/Tools/MSVC/14.*/bin/Hostx64/x86/cl.exe');
if(!is_file($source)||!is_array($compilers)||$compilers===[]){
    fwrite(STDERR,"Original v0.84a ABI probe or x86 MSVC compiler not found\n");exit(1);
}
sort($compilers,SORT_STRING);
$compiler=$compilers[0];
$obj=sys_get_temp_dir().DIRECTORY_SEPARATOR.'ue1-1996-native-abi-'.getmypid().'.obj';
$checks=[];$bad=[];
$check=static function(string $name,bool $result)use(&$checks,&$bad):void{
    $checks[$name]=$result;if(!$result)$bad[]=$name;
};
try{
    $sourceCode=file_get_contents($source);
    $check('compilation_uses_documented_cpp_probe',is_string($sourceCode)
        && str_contains($sourceCode,'#pragma pack(push,4)')
        && str_contains($sourceCode,'static_assert(sizeof(FBspNode)==64')
        && str_contains($sourceCode,'static_assert(sizeof(FBspSurf)==48')
        && str_contains($sourceCode,'static_assert(sizeof(FBoundingRect)==32'));
    $cmd='"'.$compiler.'" /nologo /c /W3 /Fo"'.$obj.'" "'.$source.'" 2>&1';
    $lines=[];$exit=255;
    exec($cmd,$lines,$exit);
    $check('msvc_x86_source_layout_static_asserts_compile',$exit===0);
    $check('msvc_x86_generates_object',is_file($obj)&&filesize($obj)>0);
    $check('msvc_x86_has_no_fatal_layout_error',
        !str_contains(implode("\n",$lines),'error C'));
    echo json_encode(['compiler'=>$compiler,'source_sha256'=>hash_file('sha256',$source),
        'ok'=>$bad===[],'passed'=>count($checks)-count($bad),'total'=>count($checks),
        'failures'=>$bad,'compiler_output'=>$lines],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
}finally{@unlink($obj);}
exit($bad===[]?0:1);
