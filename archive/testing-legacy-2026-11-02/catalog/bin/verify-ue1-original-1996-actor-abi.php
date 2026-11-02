#!/usr/bin/env php
<?php
/** Isolated x86 MSVC compilation of source-extracted AActor layouts. */
declare(strict_types=1);
$root=dirname(__DIR__,2);
$source=$root.'/catalog/compliance/evidence/o06/ue1-1996-actor-layout.cpp';
$versions=glob('C:/Program Files/Microsoft Visual Studio/*/Community/VC/Tools/MSVC/14.*/bin/Hostx64/x86/cl.exe');
if(!is_file($source)||!is_array($versions)||$versions===[]){
    fwrite(STDERR,"Missing Epic-source actor ABI probe or MSVC x86 compiler\n");exit(1);
}
sort($versions,SORT_STRING);
$sourceText=file_get_contents($source);
$checks=[];$bad=[];
$check=static function(string $k,bool $ok)use(&$checks,&$bad):void{
    $checks[$k]=$ok;if(!$ok)$bad[]=$k;
};
$check('native_actor_source_has_layout_assertions',
    is_string($sourceText)
    && str_contains($sourceText,'static_assert(sizeof(AActorBase)==304')
    && str_contains($sourceText,'static_assert(sizeof(AActor)==1148')
    && str_contains($sourceText,'static_assert(sizeof(UClassLayout)==1272')
    && str_contains($sourceText,'static_assert(sizeof(UActorListLayout)==116')
    && str_contains($sourceText,'__builtin_offsetof(AActorBase,iMe)==152'));
$obj=sys_get_temp_dir().DIRECTORY_SEPARATOR.'ue1-1996-actor-'.getmypid().'.obj';
try{
    $cmd='"'.$versions[0].'" /nologo /c /W3 /Fo"'.$obj.'" "'.$source.'" 2>&1';
    $output=[];$exit=255;exec($cmd,$output,$exit);
    $check('original_1996_actor_abi_x86_compile',$exit===0);
    $check('original_actor_probe_produces_obj',is_file($obj)&&filesize($obj)>0);
    $check('no_x86_native_member_layout_errors',
        !str_contains(implode("\n",$output),'error C'));
    echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),
        'total'=>count($checks),'failures'=>$bad,
        'source_sha256'=>hash_file('sha256',$source),'compiler'=>$versions[0],
        'compiler_output'=>$output],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
}finally{@unlink($obj);}
exit($bad===[]?0:1);
