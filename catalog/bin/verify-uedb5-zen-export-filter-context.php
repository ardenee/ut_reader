<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5Ue5ZenDependencyResolver as Resolver;
$method=new ReflectionMethod(Resolver::class,'shouldSkipLoadingExport');
$cases=[
 ['editor',['with_editor'=>true],2,false],
 ['dedicated',['with_editor'=>false,'ue_server'=>true],2,true],
 ['dedicated_client_flag',['with_editor'=>false,'ue_server'=>true],1,false],
 ['client_only',['with_editor'=>false,'ue_server'=>false,'with_server_code'=>false],1,true],
 ['combined_dedicated',['with_editor'=>false,'ue_server'=>false,'with_server_code'=>true,'is_server'=>true,'is_client'=>false],2,true],
 ['combined_client',['with_editor'=>false,'ue_server'=>false,'with_server_code'=>true,'is_server'=>false,'is_client'=>true],1,true],
 ['combined_both',['with_editor'=>false,'ue_server'=>false,'with_server_code'=>true,'is_server'=>true,'is_client'=>true],2,false],
 ['context_unknown',['with_editor'=>null,'ue_server'=>null,'with_server_code'=>null,'is_server'=>null,'is_client'=>null],2,null],
 ['unfiltered',['with_editor'=>null,'ue_server'=>null,'with_server_code'=>null,'is_server'=>null,'is_client'=>null],0,false],
];
$failed=[];
foreach($cases as [$name,$context,$flags,$expected]){
 $context+=['with_editor'=>null,'ue_server'=>null,'with_server_code'=>null,'is_server'=>null,'is_client'=>null];
 $actual=$method->invoke(null,$flags,$context);
 if($actual!==$expected){$failed[]=['case'=>$name,'actual'=>$actual,'expected'=>$expected];}
}
echo json_encode(['ok'=>$failed===[],'cases'=>count($cases),'failures'=>$failed],JSON_PRETTY_PRINT),PHP_EOL;
exit($failed===[]?0:1);
