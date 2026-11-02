#!/usr/bin/env php
<?php
/**
 * UE1 O06 G2 original 1997 source classification:
 * 1997 v0.86x/v0.867 inherited AActor-family archive vs transient
 * ULinker-family/UPlayer. Uses real Epic source; no invented native handlers.
 */
declare(strict_types=1);
$tree=[
    'U86'=>'L:\Source\Games\Unreal\Unreal [v0.86x] [1997-05-25]\Src',
    'U97'=>'L:\Source\Games\Unreal\Unreal [v0.867] [1997-07-25]\Src',
];
$repo=dirname(__DIR__);
$checks=[];$issues=[];$hashes=[];
$check=static function(string $label,bool $pass)use(&$checks,&$issues):void{
    $checks[$label]=$pass;
    if(!$pass)$issues[]=$label;
};
$actors=['AActor'=>'UObject','ABrush'=>'AActor','AMover'=>'ABrush','APawn'=>'AActor'];
$transient=['ULinker','ULinkerLoad','ULinkerSave','UPlayer'];
$register=json_decode((string)file_get_contents($repo.'/compliance/UE1-O06-SERIALIZER-REGISTER.json'),true,512,JSON_THROW_ON_ERROR);
$registered=[];
foreach($register['entries'] as $e){
    if(in_array($e['branch'],['U86','U97'],true))
        $registered[$e['branch'].':'.$e['symbol']]=$e;
}
foreach($tree as $branch=>$root){
    $read=static function(string $path)use($root,&$hashes,$branch):string {
        $f=$root.'/'.str_replace('/','\\',$path);
        $text=file_get_contents($f);
        if(!is_string($text))throw new RuntimeException('Epic source read failed: '.$f);
        $hashes[$branch][$path]=hash('sha256',$text);
        return $text;
    };
    $rootH=$read('Inc/Root.h');
    $obj=$read('Engine/UnObj.cpp');
    $link=$read('Inc/UnLinker.h');
    $player=$read('Inc/UnServer.h');
    $check($branch.'_inherited_UObject_SerializeHeader',
        preg_match('/void\s+UObject::SerializeHeader\s*\(\s*FArchive\s*&Ar\s*\)/',$obj)===1
        &&str_contains($obj,'GetClass()->SerializeBin( Ar, PROPBIN_PerObject, this, Defaults )'));
    $check($branch.'_default_UObject_SerializeData_is_empty',
        preg_match('/void\s+UObject::SerializeData\s*\([^)]*\)\s*\{[^}]*Nothing to do\./s',$obj)===1);
    foreach($actors as $class=>$parent){
        $regex='/class\s+(?:(?:UNENGINE_API|UNGAME_API)\s+)?'.preg_quote($class,'/').'\s*:\s*public\s+'.preg_quote($parent,'/').'\b/';
        $check($branch.'_'.$class.'_declares_inheritance',
            preg_match($regex,$rootH)===1);
        $check($branch.'_'.$class.'_no_unique_native_archive_override',
            preg_match('/\b'.preg_quote($class,'/').'::Serialize(?:Header|Data)\s*\(/',
                $obj.$rootH)===0);
        $entry=$registered[$branch.':'.$class]??[];
        $check($branch.'_'.$class.'_is_mapped_shared_handler',
            ($entry['status']??'')==='MAPPED'
            &&($entry['reader']??'')==='CatalogUE1OriginalUnrealfileBinaryPropertyReader.php');
    }
    $check($branch.'_ULinker_unsaveable_by_explicit_Epic_text',
        str_contains($link,'Since this object is not saveable')
        &&str_contains($link,'void SerializeHeader(FArchive& Ar)'));
    foreach(['ULinker','ULinkerLoad','ULinkerSave'] as $class){
        $pattern='/class\s+(?:UNENGINE_API\s+)?'.preg_quote($class,'/').'\s*:[^{]+\{[^}]*?BaseFlags\s*=\s*CLASS_Intrinsic\s*\|\s*CLASS_Transient/s';
        $check($branch.'_'.$class.'_explicit_transient_class',
            preg_match($pattern,$link)===1);
    }
    $check($branch.'_UPlayer_is_intrinsic_transient',
        preg_match('/class\s+UNENGINE_API\s+UPlayer\s*:\s*public\s+UObject\b[^{]*\{[^}]*BaseFlags\s*=\s*CLASS_Intrinsic\s*\|\s*CLASS_Transient/s',$player)===1);
    foreach($transient as $class){
        $entry=$registered[$branch.':'.$class]??[];
        $check($branch.'_'.$class.'_excluded_not_fabricated',
            ($entry['status']??'')==='EXCLUDED'
            &&($entry['reader']??'')==='');
    }
}
$ok=$issues===[];
echo json_encode([
    'ok'=>$ok,
    'gate'=>'UE1-O06-G2-native-shared-and-transient-classification',
    'sources'=>array_keys($tree),
    'actors_inherited'=>array_keys($actors),
    'source_excluded_transient'=>$transient,
    'passed'=>count($checks)-count($issues),
    'total'=>count($checks),
    'failures'=>$issues,
    'source_sha256'=>$hashes,
    'remaining'=>'Other U86/U97 class/source branches; real G2 v22/v23 packages; separate SerializeBin/default/expr and source families',
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:1);
