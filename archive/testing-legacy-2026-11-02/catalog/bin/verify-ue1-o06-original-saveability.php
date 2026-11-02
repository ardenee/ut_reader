#!/usr/bin/env php
<?php
/**
 * Original Epic v0.84a saveability gates. A resource with a direct
 * "not saveable" source-defined Flip must not be considered a missing
 * persistent on-disk O06 serializer.
 */
declare(strict_types=1);
$source='L:\\Source\\Games\\Unreal\\Unreal [v0.84a] [1996-12-11]\\Src';
$checks=[];$bad=[];$sources=[];
$check=static function(string $label,bool $pass)use(&$checks,&$bad):void{
    $checks[$label]=$pass;if(!$pass)$bad[]=$label;
};
$cases=[
    ['RES_Camera',25,'Engine/UnCamera.cpp','UCamera'],
    ['RES_Player',28,'Engine/UnServer.cpp','UPlayer'],
    ['RES_TransBuffer',31,'Editor/UnEdTran.cpp','UTransBuffer'],
];
foreach($cases as [$id,$value,$name,$class]){
    $path=$source.'/'.str_replace('/','\\',$name);
    $code=is_file($path)?file_get_contents($path):false;
    $sources[$name]=$code===false?null:hash('sha256',$code);
    $method=$class.'::Flip()';
    $pos=is_string($code)?strpos($code,'void '.$method):false;
    $scope=$pos===false?'':substr($code,$pos,450);
    $check($id.'_flip_explicitly_unsaveable',
        $pos!==false && stripos($scope,'resource type not saveable')!==false
           || $pos!==false && stripos($scope,'resource type is not saveable')!==false);
    $check($id.'_is_registered_but_not_serializable',
        is_string($code)
        && str_contains($code,'AUTOREGISTER_RESOURCE('.$id.','.$class.','));
}
$header=$source.'\\Inc\\UnRes.h';
$enum=is_file($header)?file_get_contents($header):false;
$sources['Inc/UnRes.h']=$enum===false?null:hash('sha256',$enum);
$check('RES_Ambient_enum_id_30',is_string($enum)
    && preg_match('/RES_Ambient\s*=\s*30\s*,/',$enum)===1);
// v0.84a includes Engine, Editor and Inc; no concrete UAmbient
// registration or class implementation was located by source scan.
$found=[];$count=0;
if(is_dir($source)){
 $iterator=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
     $source,FilesystemIterator::SKIP_DOTS));
 foreach($iterator as $file){
     if(!$file->isFile()||!preg_match('/\.(?:cpp|h)$/i',$file->getFilename()))continue;
     $count++;
     $data=file_get_contents($file->getPathname());
     if(is_string($data)&&preg_match('/AUTOREGISTER_RESOURCE\s*\(\s*RES_Ambient\b/', $data)){
         $found[]=$file->getPathname();
     }
 }
}
$check('RES_Ambient_has_no_registration_in_original_tree',$count>0&&$found===[]);
echo json_encode(['ok'=>$bad===[],'passed'=>count($checks)-count($bad),
    'total'=>count($checks),'failed'=>$bad,'files_scanned'=>$count,
    'ambient_registrations'=>$found,'source_sha256'=>$sources],
    JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($bad===[]?0:1);
