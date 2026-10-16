<?php
declare(strict_types=1);
require dirname(__DIR__).'/bootstrap/autoload.php';
use UnrealDb\Catalog\Infrastructure\Metadata\Uedb5GameDependencyPassService;
$ref=new ReflectionClass(Uedb5GameDependencyPassService::class);
$method=$ref->getMethod('resolverOptions');
$property=$ref->getProperty('config');
$run=static function(array $config,int $gameId=9001) use($ref,$method,$property):array {
    $service=$ref->newInstanceWithoutConstructor();
    $property->setValue($service,$config);
    return $method->invoke($service,$gameId);
};
$baseline=$run([]);
$other=$run(['zen_runtime_context_by_game'=>[9002=>['package_identity_context_authoritative'=>true,'evidence_source'=>'fixture']]]);
$known=$run(['zen_runtime_context_by_game'=>[9001=>[
 'package_identity_context_authoritative'=>true,
 'package_store_context_authoritative'=>false,
 'evidence_source'=>'isolated synthetic explicit fixture',
 'package_identity_rewrites'=>[],
]]]);
$reject=false;
try {$run(['zen_runtime_context_by_game'=>[9001=>['package_store_context_authoritative'=>true]]]);}
catch(RuntimeException $e){$reject=str_contains($e->getMessage(),'evidence_source');}
$ok=!isset($baseline['zen_runtime_context'])&&!isset($other['zen_runtime_context'])
    &&($known['zen_runtime_context']['package_identity_context_authoritative']??null)===true
    &&($known['zen_runtime_context']['package_store_context_authoritative']??null)===false
    &&$reject;
echo json_encode(['ok'=>$ok,'baseline_unknown'=>$baseline,'other_game_isolation'=>$other,'known_context'=>$known,'unsupported_authority_rejected'=>$reject],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
exit($ok?0:2);
