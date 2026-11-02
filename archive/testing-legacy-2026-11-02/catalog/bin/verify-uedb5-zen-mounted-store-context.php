<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5ZenMountedStoreContextBuilder as Builder;
$source='AABBCCDDEEFF0011';
$map=static fn(string $target):array=>['source_package_id'=>$source,'target_package_id'=>$target];
$mounts=[
 ['container_id'=>'base','order'=>1,'sequence'=>0,'package_redirects'=>[$map('1111111111111111')],'localized_packages'=>[]],
 ['container_id'=>'patch-old','order'=>2,'sequence'=>1,'package_redirects'=>[$map('2222222222222222')],'localized_packages'=>[]],
 ['container_id'=>'patch-new','order'=>2,'sequence'=>2,'package_redirects'=>[$map('3333333333333333')],'localized_packages'=>[
    ['source_package_id'=>$source,'source_package_name'=>'/Game/Name']]],
];
$result=Builder::build($mounts);
$bad=false;
try{Builder::build([['container_id'=>'incomplete','order'=>1,'package_redirects'=>[],'localized_packages'=>[]]]);}
catch(RuntimeException){$bad=true;}
$ok=($result['mounted_container_order'][0]['container_id']??'')==='patch-new'
 &&($result['package_redirects'][0]['target_package_id']??'')==='3333333333333333'
 &&count($result['package_redirects'])===1&&count($result['localized_packages'])===1
 &&$result['package_store_context_authoritative']===false
 &&$result['package_identity_context_authoritative']===false&&$bad;
echo json_encode(['ok'=>$ok,'order'=>$result['mounted_container_order'],
 'redirects'=>$result['package_redirects'],'incomplete_rejected'=>$bad],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:2);
