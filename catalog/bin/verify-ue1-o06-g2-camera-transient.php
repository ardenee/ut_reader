#!/usr/bin/env php
<?php
/**
 * O06 G2: original v0.86x/v0.867 runtime-only camera and undo buffers.
 * Their registrations do not imply a serialized Unrealfile object.
 */
declare(strict_types=1);
$roots=[
 'U86'=> 'L:\Source\Games\Unreal\Unreal [v0.86x] [1997-05-25]\Src',
 'U97'=> 'L:\Source\Games\Unreal\Unreal [v0.867] [1997-07-25]\Src',
];
$reg=json_decode((string)file_get_contents(dirname(__DIR__).'/compliance/UE1-O06-SERIALIZER-REGISTER.json'),true,512,JSON_THROW_ON_ERROR);
$by=[];foreach($reg['entries'] as $e)$by[$e['id']]=$e;
$pass=[];$fail=[];$sha=[];
$check=static function(string $name,bool $ok)use(&$pass,&$fail):void{
 if ($ok) { $pass[]=$name; } else { $fail[]=$name; }
};
foreach($roots as $branch=>$root){
 $read=static function(string $relative)use($root,&$sha,$branch):string {
    $file=$root.'\\'.$relative;
    if(!is_file($file))throw new RuntimeException('Missing Epic source '.$file);
    $text=(string)file_get_contents($file);
    $sha[$branch][$relative]=hash('sha256',$text);
    return $text;
 };
 $camera=$read('Inc\UnCamera.h');
 $cameraImpl=$read('Engine\UnCamera.cpp');
 $trans=$read('Inc\UnEdTran.h');
 $transImpl=$read('Editor\UnEdTran.cpp');
 $check($branch.'_UCamera_declared_intrinsic_transient_prekill',
     preg_match('/class\s+UNENGINE_API\s+UCamera\s*:\s*public\s+UObject\b.*?BaseFlags\s*=\s*CLASS_Intrinsic\s*\|\s*CLASS_Transient\s*\|\s*CLASS_PreKill/s',$camera)===1);
 $check($branch.'_UCamera_registered_but_unsaveable',
     str_contains($cameraImpl,'IMPLEMENT_CLASS(UCamera)'));
 $check($branch.'_UTransBuffer_declared_editor_intrinsic_transient',
     preg_match('/class\s+UNEDITOR_API\s+UTransBuffer\s*:\s*public\s+UObject\b.*?BaseFlags\s*=\s*CLASS_Intrinsic\s*\|\s*CLASS_Transient/s',$trans)===1);
 $check($branch.'_UTransBuffer_runtime_reference_archive_only',
     preg_match('/UTransBuffer::SerializeData\s*\(\s*FArchive\s*&\s*Ar\s*\).*?Since this object is not saveable/s',$transImpl)===1);
 $check($branch.'_UTransBuffer_registered_but_unsaveable',
     str_contains($transImpl,'IMPLEMENT_CLASS(UTransBuffer)'));
 foreach(['UCamera','UTransBuffer'] as $class){
    $e=$by[$branch.':'.$class]??[];
    $check($branch.'_'.$class.'_register_EXCLUDED',
        ($e['status']??null)==='EXCLUDED'&&($e['reader']??'')==='');
    $check($branch.'_'.$class.'_named_source_verifier',
        ($e['test']??'')==='verify-ue1-o06-g2-camera-transient.php');
 }
}
echo json_encode([
 'ok'=>$fail===[], 'gate'=>'UE1-O06-G2-source-nonsaveable-runtime-classes',
 'passed'=>count($pass),'total'=>count($pass)+count($fail),
 'failures'=>$fail,'source_sha256'=>$sha,
 'excluded_classes'=>['UCamera','UTransBuffer'],
 'remaining'=>'Other 1997 source-registered persistent serializers; no invented on-disk camera/transaction archive',
],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($fail===[]?0:1);
